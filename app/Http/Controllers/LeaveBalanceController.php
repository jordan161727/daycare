<?php

namespace App\Http\Controllers;

use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\LeaveLedger;
use App\Services\PayPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Everybody's leave balances, and the two ways they move by hand.
 *
 * Accrual is the automatic one and runs off approved pay periods. The other is
 * a director typing a number, which is here because real balances start
 * somewhere: a centre adopting this app on a Tuesday has staff who already have
 * three weeks of vacation, and there has to be a way to say so that is not a
 * database console.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(private LeaveLedger $ledger) {}

    public function index(Request $request)
    {
        $staff = User::teachers()->get();
        $range = PayPeriod::containing($request->input('date') ?: today()->toDateString());
        $period = TimesheetPeriod::firstWhere('period_start', $range->key());

        return view('leave.balances', [
            'staff' => $staff,
            'balances' => $this->ledger->balancesFor($staff),
            'committed' => $staff->mapWithKeys(fn (User $person) => [$person->id => $this->ledger->committed($person->id)])->all(),
            'types' => config('daycare.leave.types'),
            'caps' => config('daycare.leave.cap'),
            'range' => $range,
            'period' => $period,
            // How much of each person's balance is already promised to leave
            // that has been granted but not yet taken.
            'upcoming' => $this->upcoming(),
        ]);
    }

    /** A director's own correction to somebody's balance. */
    public function adjust(Request $request, User $user)
    {
        $data = $request->validate([
            'leave_type' => ['required', Rule::in(array_keys(config('daycare.leave.types')))],
            'hours' => ['required', 'numeric', 'min:-200', 'max:200', 'not_in:0'],
            // Required, unlike everywhere else a note is optional. A balance
            // that moved by six hours for no recorded reason is the thing
            // somebody will be arguing about in November.
            'note' => ['required', 'string', 'max:200'],
        ]);

        $this->ledger->post(
            $user->id,
            $data['leave_type'],
            (float) $data['hours'],
            LeaveLedgerEntry::SOURCE_ADJUSTMENT,
            null,
            today()->toDateString(),
            $data['note'],
            $request->user()->id,
        );

        return back()->with('success', sprintf(
            '%s%sh of %s %s %s. Balance is now %sh.',
            $data['hours'] > 0 ? '+' : '',
            $data['hours'],
            strtolower(config('daycare.leave.types')[$data['leave_type']]),
            $data['hours'] > 0 ? 'added to' : 'taken from',
            $user->name,
            $this->ledger->balance($user->id, $data['leave_type']),
        ));
    }

    /** Earn a pay period's leave for everybody who worked it. */
    public function accrue(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $range = PayPeriod::containing($data['date']);
        $period = TimesheetPeriod::firstWhere('period_start', $range->key());

        if (! $period) {
            return back()->with('warning', 'There is no timesheet for '.$range->label().' yet, so nothing has been worked or approved in it.');
        }

        $lines = $this->ledger->accrue($period, $request->user());

        return back()->with($period->isApproved() ? 'success' : 'warning', implode(' ', $lines));
    }

    /**
     * Approved leave still in the future, by person and type.
     *
     * Already off the balance — it was spent when it was approved — so this is
     * shown next to it rather than subtracted from it. The distinction matters
     * when somebody asks why their card says twelve hours in a week they are
     * about to spend eight of.
     *
     * @return array<int, array<string, float>>
     */
    private function upcoming(): array
    {
        $hours = [];

        $requests = LeaveRequest::approved()
            ->where('ends_on', '>=', today()->toDateString())
            ->get();

        foreach ($requests as $request) {
            $hours[$request->user_id][$request->leave_type] =
                round(($hours[$request->user_id][$request->leave_type] ?? 0) + $request->paid_hours, 2);
        }

        return $hours;
    }
}
