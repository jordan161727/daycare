<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\LeaveLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Somebody's own leave: what they have, what they have asked for, and the form
 * for asking again.
 *
 * Deliberately separate from the director's queue. This screen answers "how
 * much sick time do I have left and did anyone look at my request yet", which
 * is the question that otherwise arrives as a note on the staffroom door.
 */
class LeaveController extends Controller
{
    public function __construct(private LeaveLedger $ledger) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('leave.index', [
            'staff' => $user,
            'balances' => $this->ledger->balances($user->id),
            'committed' => $this->ledger->committed($user->id),
            'statement' => $this->ledger->statement($user->id),
            'requests' => LeaveRequest::with('reviewer')
                ->where('user_id', $user->id)
                ->orderByDesc('starts_on')
                ->get(),
            'types' => config('daycare.leave.types'),
            'dayHours' => config('daycare.leave.day_hours'),
            'caps' => config('daycare.leave.cap'),
        ]);
    }

    /**
     * Ask for time off.
     *
     * The balance is not checked here, and that is on purpose: asking for leave
     * you have not earned yet is a normal thing to do in March for a holiday in
     * August, and refusing it at the form would make people ask by text message
     * instead. What the balance covers is settled at the decision, where a
     * director can see it and say so.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'leave_type' => ['required', Rule::in(array_keys(config('daycare.leave.types')))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'hours_per_day' => ['nullable', 'numeric', 'min:0.5', 'max:12'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $starts = Carbon::parse($data['starts_on'])->startOfDay();
        $ends = Carbon::parse($data['ends_on'])->startOfDay();

        if ($starts->diffInDays($ends) > 90) {
            return back()->withInput()->with('warning', 'A single request covers at most 90 days. Split a longer absence into two.');
        }

        // Vacation is asked for before it is taken; sickness is not planned and
        // is routinely filed the morning after. Both limits come from config
        // rather than from here, because they are the centre's policy.
        $backdate = (int) (config('daycare.leave.backdate_days')[$data['leave_type']] ?? 0);
        $earliest = today()->subDays($backdate);

        if ($starts->lt($earliest)) {
            return back()->withInput()->with('warning', $backdate === 0
                ? config('daycare.leave.types')[$data['leave_type']].' cannot start in the past. Ask a director to record it for you if it has already happened.'
                : 'That is more than '.$backdate.' days ago. Ask a director to put it on your timesheet as a correction instead.');
        }

        $clashes = LeaveRequest::clashesFor($user->id, $starts->toDateString(), $ends->toDateString());

        if ($clashes->isNotEmpty()) {
            return back()->withInput()->with('warning',
                'You already have a request over those days: '.$clashes->first()->rangeLabel()
                .' ('.$clashes->first()->status.'). Cancel it first if you want to change it.');
        }

        $leave = new LeaveRequest([
            'user_id' => $user->id,
            'leave_type' => $data['leave_type'],
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
            'hours_per_day' => $data['hours_per_day'] ?? config('daycare.leave.day_hours'),
            'status' => LeaveRequest::STATUS_PENDING,
            'reason' => $data['reason'] ?? null,
        ]);

        // Every day of it is a weekend or a day the centre is shut, so it would
        // cost nothing and mean nothing. Said plainly rather than saved as a
        // request for zero hours that a director has to puzzle over.
        if ($leave->days() === 0) {
            return back()->withInput()->with('warning', 'Those dates are all weekends or days the centre is closed, so there is no leave to take.');
        }

        $leave->save();

        return redirect()->route('leave.index')->with('success',
            'Requested '.$leave->hours().'h of '.strtolower($leave->label()).' over '.$leave->days()
            .' day(s), '.$leave->rangeLabel().'. A director will decide on it.');
    }

    /** Withdraw a request nobody has decided on yet. */
    public function destroy(Request $request, LeaveRequest $leave)
    {
        if ($leave->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $leave->isPending()) {
            return back()->with('warning', 'Only a request still waiting for a decision can be withdrawn. Ask a director about this one.');
        }

        $leave->forceFill(['status' => LeaveRequest::STATUS_CANCELLED])->save();

        return back()->with('success', 'Withdrawn — '.$leave->rangeLabel().'.');
    }
}
