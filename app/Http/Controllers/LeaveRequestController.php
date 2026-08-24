<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\LeaveLedger;
use App\Services\StaffSchedule;
use App\Services\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The director's side of leave: the queue, and the decision.
 *
 * Approving is the moment three other things move — the balance, the published
 * roster and the timesheet — and it is the only place in the app where they all
 * move at once. So each of them is reported back in the flash message rather
 * than done quietly: a director who approves a week off should be told the
 * shifts came out of Tuesday's roster, because their next job is covering them.
 */
class LeaveRequestController extends Controller
{
    public function __construct(
        private LeaveLedger $ledger,
        private StaffSchedule $scheduler,
        private Timesheet $timesheets,
    ) {}

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'approved', 'denied', 'cancelled', 'all'], true)
            ? $request->query('status')
            : 'pending';

        $requests = LeaveRequest::with(['user', 'reviewer'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')
            ->get();

        // The balance each request would be decided against, so the queue can
        // say "8h asked for, 4h on the card" without a director opening
        // another screen to find out.
        $balances = $this->ledger->balancesFor($requests->pluck('user')->filter()->unique('id')->values());

        return view('leave.requests', [
            'requests' => $requests,
            'balances' => $balances,
            'status' => $status,
            'pendingCount' => LeaveRequest::where('status', LeaveRequest::STATUS_PENDING)->count(),
        ]);
    }

    /**
     * Grant the leave.
     *
     * Whatever the balance covers is paid leave and comes off the card; any
     * remainder is approved as an unpaid absence, which the director has to ask
     * for explicitly. Refusing outright would be tidier and wrong — people do
     * take unpaid days, and a system that cannot record one sends them off the
     * books entirely.
     */
    public function approve(Request $request, LeaveRequest $leave)
    {
        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:200'],
            'allow_unpaid' => ['nullable', 'boolean'],
        ]);

        if (! $leave->isPending()) {
            return back()->with('warning', 'That request has already been decided.');
        }

        // Nobody signs off their own time off. A director who needs leave is
        // asking the centre for it like everybody else, and the second director
        // is who they are asking.
        if ($leave->user_id === $request->user()->id) {
            return back()->with('warning', 'You cannot approve your own leave. Another director has to decide on it.');
        }

        $available = $this->ledger->balance($leave->user_id, $leave->leave_type);
        $split = $leave->allocate($available);

        if ($split['unpaid_hours'] > 0 && ! $request->boolean('allow_unpaid')) {
            return back()->with('warning', sprintf(
                '%s has %sh of %s and this request is worth %sh. Tick "approve the shortfall as unpaid" to grant it anyway — %sh would be paid and %sh unpaid.',
                $leave->user->name,
                $available,
                strtolower($leave->label()),
                $leave->hours(),
                $split['paid_hours'],
                $split['unpaid_hours'],
            ));
        }

        DB::transaction(function () use ($leave, $split, $data, $request) {
            $leave->forceFill([
                'status' => LeaveRequest::STATUS_APPROVED,
                'paid_hours' => $split['paid_hours'],
                'unpaid_hours' => $split['unpaid_hours'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'decision_note' => $data['decision_note'] ?? null,
            ])->save();

            $this->ledger->spend($leave, $split['paid_hours'], $request->user());
        });

        // The roster and the timesheet, in that order: the shifts stop being
        // planned before the days start being paid.
        $weeks = $this->scheduler->applyLeave($leave->fresh('user'));
        $written = $this->timesheets->recordLeave($leave->fresh());

        $message = sprintf(
            'Approved — %sh of %s for %s, %s.',
            $split['paid_hours'],
            strtolower($leave->label()),
            $leave->user->name,
            $leave->rangeLabel(),
        );

        if ($split['unpaid_hours'] > 0) {
            $message .= ' '.$split['unpaid_hours'].'h of it is unpaid, beyond what their balance covered.';
        }

        if ($weeks !== []) {
            $message .= ' Their shifts were taken off '.count($weeks).' published week(s) — regenerate '
                .implode(' and ', $weeks).' to cover those rooms.';
        }

        if ($written['written'] > 0) {
            $message .= ' '.$written['written'].' day(s) went onto the timesheet.';
        }

        $redirect = back()->with('success', $message);

        return $written['skipped'] === []
            ? $redirect
            : $redirect->with('warning', implode(' ', $written['skipped']));
    }

    public function deny(Request $request, LeaveRequest $leave)
    {
        $data = $request->validate([
            'decision_note' => ['nullable', 'string', 'max:200'],
        ]);

        if (! $leave->isPending()) {
            return back()->with('warning', 'That request has already been decided.');
        }

        $leave->forceFill([
            'status' => LeaveRequest::STATUS_DENIED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'decision_note' => $data['decision_note'] ?? null,
        ])->save();

        return back()->with('success', 'Denied — '.$leave->user->name.', '.$leave->rangeLabel()
            .'. The request stays on their leave page with your note, so nobody has to remember what was said.');
    }

    /**
     * Take back an approval.
     *
     * The hours go back on the balance and the days come off the timesheet, but
     * the shifts that were removed from the roster do not come back — they were
     * deleted, and re-inventing them would be a guess at a week that has since
     * moved on. Regenerating the week is the honest way to put them back, and
     * the message says so.
     */
    public function revoke(Request $request, LeaveRequest $leave)
    {
        if (! $leave->isApproved()) {
            return back()->with('warning', 'Only approved leave can be revoked.');
        }

        $restored = $leave->paid_hours;

        DB::transaction(function () use ($leave, $request) {
            $this->ledger->restore($leave, $request->user());

            $leave->forceFill([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'decision_note' => 'Revoked by '.$request->user()->name,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();
        });

        $cleared = $this->timesheets->clearLeave($leave);

        return back()->with('success', sprintf(
            'Revoked — %sh went back onto %s\'s balance and %d day(s) came off the timesheet. '
            .'Their shifts were not restored: regenerate the affected week if they are working it after all.',
            $restored,
            $leave->user->name,
            $cleared,
        ));
    }
}
