<?php

namespace App\Http\Controllers;

use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\LeaveLedger;
use App\Services\PayPeriod;
use App\Services\TimeClock;
use App\Services\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll preparation: approved hours and leave, per pay period, ready to hand over.
 *
 * The flow is deliberately three separate acts. Seeding copies the roster in as
 * a draft. Correcting says what actually happened, one person at a time. Only
 * then can the period be approved, and approving is what freezes it — after
 * that the hours have gone to payroll and people are being paid on them.
 *
 * Nothing here is automatic, and the page counts the days still carrying the
 * roster's word rather than somebody's, because "we approved a fortnight of
 * guesses" is the failure this exists to prevent.
 */
class TimesheetController extends Controller
{
    public function __construct(
        private Timesheet $timesheets,
        private TimeClock $clock,
        private LeaveLedger $ledger,
    ) {}

    public function index(Request $request)
    {
        $range = PayPeriod::containing($request->input('date') ?: today()->toDateString());
        $period = TimesheetPeriod::forDate($range->key());

        $summary = $this->timesheets->summary($period);

        return view('timesheets.index', [
            'period' => $period,
            'range' => $range,
            'dates' => $range->dates(),
            'summary' => $summary,
            'rows' => $this->timesheets->rows($summary),
            'totals' => $this->timesheets->totals($summary),
            'grid' => $this->timesheets->grid($period),
            'exceptions' => $this->clock->exceptions($range),
            'leaveCodes' => config('daycare.timesheet.leave_codes'),
        ]);
    }

    /** Copy the published roster into the period as a draft. */
    public function seed(Request $request, TimesheetPeriod $period)
    {
        if ($period->isApproved()) {
            return back()->with('warning', 'That period has been approved and can no longer be changed.');
        }

        $added = $this->timesheets->seed($period);

        return redirect()
            ->route('timesheets.index', ['date' => $period->period_start->toDateString()])
            ->with($added > 0 ? 'success' : 'warning', $added > 0
                ? $added.' day(s) brought in from the roster. Every one of them still needs confirming.'
                : 'Nothing to bring in — either the roster is empty for these dates, or every day is already on the sheet.');
    }

    /** One person, one period: the day-by-day form. */
    public function edit(Request $request, TimesheetPeriod $period, User $user)
    {
        $range = $period->range();

        $entries = TimesheetEntry::where('timesheet_period_id', $period->id)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($entry) => $entry->work_date->toDateString());

        return view('timesheets.edit', [
            'period' => $period,
            'range' => $range,
            'dates' => $range->dates(),
            'staff' => $user,
            'entries' => $entries,
            'leaveCodes' => config('daycare.timesheet.leave_codes'),
            'summary' => $this->timesheets->summary($period)->firstWhere('user.id', $user->id),
            // Which days have punches behind them, so a row can offer the
            // clock's own account of itself rather than only the number it
            // came out as.
            'clockDays' => $this->clockDays($user, $range),
        ]);
    }

    /**
     * Save a person's days.
     *
     * Every day in the period arrives at once, so clearing a box is as much an
     * instruction as filling one in. Each day that carries anything is marked
     * confirmed — that is what the save means.
     */
    public function update(Request $request, TimesheetPeriod $period, User $user)
    {
        if ($period->isApproved()) {
            return back()->with('warning', 'That period has been approved and can no longer be changed.');
        }

        $data = $request->validate([
            'days' => ['required', 'array'],
            'days.*.starts_at' => ['nullable', 'date_format:H:i'],
            'days.*.ends_at' => ['nullable', 'date_format:H:i'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'days.*.leave_code' => ['nullable', Rule::in(array_keys(config('daycare.timesheet.leave_codes')))],
            'days.*.leave_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'days.*.note' => ['nullable', 'string', 'max:120'],
        ]);

        $range = $period->range();
        $saved = 0;
        $problems = [];

        foreach ($data['days'] as $date => $day) {
            if (! $range->contains($date)) {
                continue;
            }

            $starts = $this->minutes($day['starts_at'] ?? null);
            $ends = $this->minutes($day['ends_at'] ?? null);

            // Half a day is a slip, not an instruction. Say which half is
            // missing rather than saving a shift with no end.
            if (($starts === null) !== ($ends === null)) {
                $problems[] = Carbon::parse($date)->format('D j M').' has only '.($starts === null ? 'an out' : 'an in').' time.';

                continue;
            }

            if ($starts !== null && $ends !== null && $ends <= $starts) {
                $problems[] = Carbon::parse($date)->format('D j M').' ends before it starts.';

                continue;
            }

            $leaveCode = $day['leave_code'] ?? null;

            $leaveMinutes = $leaveCode === null ? 0 : (int) round(
                (float) ($day['leave_hours'] ?? config('daycare.timesheet.default_leave_hours')) * 60,
            );

            $entry = TimesheetEntry::firstOrNew([
                'user_id' => $user->id,
                'work_date' => $date,
            ]);

            $entry->fill([
                'timesheet_period_id' => $period->id,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'break_minutes' => (int) ($day['break_minutes'] ?? 0),
                'leave_code' => $leaveCode,
                'leave_minutes' => $leaveMinutes,
                'note' => $day['note'] ?? null,
                // Saving the form is the act of somebody saying so. A day left
                // blank stays blank rather than becoming a confirmed nothing,
                // and the person who said it is recorded next to it — including
                // when what they said overrules the clock.
                'source' => TimesheetEntry::SOURCE_MANUAL,
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            if ($entry->isEmpty() && ! $entry->exists) {
                continue;
            }

            $entry->save();
            $saved++;
        }

        $redirect = redirect()->route('timesheets.edit', [
            'period' => $period->id,
            'user' => $user->id,
        ]);

        if ($problems !== []) {
            return $redirect->with('warning', 'Saved what was valid. '.implode(' ', $problems));
        }

        return $redirect->with('success', $saved.' day(s) confirmed for '.$user->name.'.');
    }

    /**
     * Sign the period off.
     *
     * Refused while any day is still the roster's word rather than a person's,
     * and refused before the period has finished — approving hours for days
     * that have not happened is how a centre pays for a shift nobody worked.
     */
    public function approve(Request $request, TimesheetPeriod $period)
    {
        if ($period->isApproved()) {
            return back()->with('warning', 'That period is already approved.');
        }

        $range = $period->range();

        if (! $range->hasEnded()) {
            return back()->with('warning', 'The period of '.$range->label().' has not finished yet. Approve it once the last day is done.');
        }

        $summary = $this->timesheets->summary($period);
        $totals = $this->timesheets->totals($summary);

        if ($totals['unconfirmed_days'] > 0) {
            return back()->with('warning', $totals['unconfirmed_days']
                .' day(s) are still as the roster left them. Open each person and confirm their days before approving.');
        }

        // A day whose punches do not add up is paying nothing at all until
        // somebody says what happened on it. Approving over the top of one
        // would send payroll a short week and call it finished.
        if ($totals['clock_exceptions'] > 0) {
            return back()->with('warning', $totals['clock_exceptions']
                .' day(s) have punches that do not add up — a missing clock-out, or two of the same in a row.'
                .' Open the day from the grid and put the punches right before approving.');
        }

        if ($totals['paid_hours'] <= 0) {
            return back()->with('warning', 'There are no hours in this period to approve.');
        }

        $period->forceFill([
            'status' => TimesheetPeriod::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        // Leave is earned by hours that have been signed off, so approving is
        // the moment it becomes real. Doing it here rather than on a schedule
        // means a balance can never be built on hours a correction later took
        // away — and posting is idempotent, so reopening and re-approving the
        // same period does not pay the accrual twice.
        $accrued = $this->ledger->accrue($period, $request->user());

        return back()
            ->with('success', 'Approved — '.number_format($totals['paid_hours'], 2)
                .' paid hours across '.$totals['employees'].' employee(s). The period is now frozen.')
            ->with('accrual', $accrued);
    }

    /** Unlock an approved period, for the correction that arrives too late. */
    public function reopen(TimesheetPeriod $period)
    {
        if (! $period->isApproved()) {
            return back()->with('warning', 'That period is not approved.');
        }

        $period->forceFill([
            'status' => TimesheetPeriod::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return back()->with('warning', 'Reopened. If these hours have already gone to payroll, tell them what changed.');
    }

    /** The file payroll receives. */
    public function export(TimesheetPeriod $period): StreamedResponse
    {
        $rows = $this->timesheets->exportRows($period);
        $name = 'timesheet-'.$period->period_start->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /**
     * The clock's account of each day in a period, for the day form.
     *
     * Only days that were actually punched appear. A day with no punches has
     * nothing to disagree with and gets no row of its own here.
     *
     * @return array<string, array>
     */
    private function clockDays(User $user, PayPeriod $range): array
    {
        $punches = TimePunch::live()
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$range->start->toDateString(), $range->end->toDateString()])
            ->orderBy('punched_at')
            ->get()
            ->groupBy(fn (TimePunch $punch) => $punch->work_date->toDateString());

        $days = [];

        foreach ($punches as $date => $onDay) {
            $days[$date] = $this->clock->day($user->id, $date, $onDay);
        }

        return $days;
    }

    /** "07:30" to minutes past midnight, matching StaffShift. */
    private function minutes(?string $time): ?int
    {
        if (blank($time)) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
