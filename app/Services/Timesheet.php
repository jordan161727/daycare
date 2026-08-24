<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\StaffShift;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning the roster and the corrections made to it into hours payroll can pay.
 *
 * Two rules run through everything here:
 *
 * The schedule is a starting point, never an answer. Seeding copies the
 * published roster in as a draft so nobody types a fortnight of times from
 * scratch, and every copied day is marked as such until a person confirms it.
 * A period tells you how many of its days are still nobody's word.
 *
 * Overtime belongs to the week, pay belongs to the period. A semi-monthly
 * period cuts weeks in half, so hours are split into regular and overtime
 * across the whole workweek — including the days on the other side of the
 * boundary — and only then collected into the period. Working it out inside the
 * period would hand somebody 48 hours in a fortnight as 48 regular, or invent
 * overtime out of a week that was only half counted.
 *
 * The time clock feeds in through the same entries: a punched day arrives as
 * the clock's word and is counted like any other, and a day whose punches do
 * not add up arrives as an exception rather than as a number — see TimeClock.
 */
class Timesheet
{
    public function __construct(private TimeClock $clock) {}

    /**
     * Fill a period with the published roster.
     *
     * Only ever adds. A day somebody has already confirmed is left exactly as
     * they left it, so re-seeding after a roster change brings in what is new
     * without discarding an afternoon that was corrected by hand.
     */
    public function seed(TimesheetPeriod $period): int
    {
        if ($period->isApproved()) {
            return 0;
        }

        $range = $period->range();

        $shifts = StaffShift::whereBetween('shift_date', [
            $range->start->toDateString(),
            $range->end->toDateString(),
        ])->get();

        // Approved leave comes in the same pass. It is not on the roster — the
        // whole point of approving it was to take those shifts off — so a
        // period seeded from shifts alone would show somebody's vacation week
        // as five blank days and pay them nothing for it.
        $leaveDays = $this->seedLeave($period);

        if ($shifts->isEmpty()) {
            $period->forceFill(['seeded_at' => now()])->save();

            return $leaveDays;
        }

        // A day already in the table is somebody's answer — theirs or an
        // earlier seed's. Either way this pass does not overwrite it.
        $existing = TimesheetEntry::where('timesheet_period_id', $period->id)
            ->get()
            ->map(fn ($entry) => $entry->user_id.'|'.$entry->work_date->toDateString())
            ->flip();

        $rows = [];
        $now = now();

        foreach ($shifts->groupBy(fn ($shift) => $shift->user_id.'|'.$shift->shift_date->toDateString()) as $key => $day) {
            if ($existing->has($key)) {
                continue;
            }

            [$userId, $date] = explode('|', $key);

            // Split shifts collapse into one day with a gap in the middle,
            // which is how the day is worked and how it is paid. The span less
            // the minutes actually rostered is the break, so 9–12 and 1–5
            // arrives as 9:00–17:00 with an hour out.
            $starts = $day->min('starts_at');
            $ends = $day->max('ends_at');
            $worked = $day->sum(fn ($shift) => $shift->minutes());

            $rows[] = [
                'timesheet_period_id' => $period->id,
                'user_id' => (int) $userId,
                'work_date' => $date,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'break_minutes' => max(0, ($ends - $starts) - $worked),
                'leave_code' => null,
                'leave_minutes' => 0,
                'source' => TimesheetEntry::SOURCE_SCHEDULE,
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows, $period) {
            foreach (array_chunk($rows, 500) as $chunk) {
                TimesheetEntry::insert($chunk);
            }

            $period->forceFill(['seeded_at' => now()])->save();
        });

        return count($rows) + $leaveDays;
    }

    /**
     * Bring every approved absence inside a period onto the sheet.
     *
     * @return int Days written.
     */
    private function seedLeave(TimesheetPeriod $period): int
    {
        $range = $period->range();

        $requests = LeaveRequest::with('user')
            ->approved()
            ->overlapping($range->start->toDateString(), $range->end->toDateString())
            ->get();

        $written = 0;

        foreach ($requests as $request) {
            $written += $this->recordLeave($request)['written'];
        }

        return $written;
    }

    /**
     * Write one approved request onto the days it covers.
     *
     * Called when the leave is approved as well as when a period is seeded,
     * because leave is granted at both ends of the calendar: for a fortnight
     * that has not started, and for the Tuesday somebody was off sick last
     * week. Either way the day has to reach payroll under the right code, and
     * the day the balance did not stretch to has to reach it as unpaid rather
     * than as nothing.
     *
     * A day somebody has already spoken for is never overwritten. If a teacher
     * punched in on a day later granted as sick leave, that punch is a fact and
     * the approval is a decision about a different day — so the day is left
     * alone and named in the return, for a human to reconcile.
     *
     * @return array{written: int, skipped: list<string>}
     */
    public function recordLeave(LeaveRequest $request): array
    {
        if (! $request->isApproved()) {
            return ['written' => 0, 'skipped' => []];
        }

        $written = 0;
        $skipped = [];

        foreach ($request->workingDates() as $date) {
            $period = TimesheetPeriod::forDate($date);

            if ($period->isApproved()) {
                $skipped[] = Carbon::parse($date)->format('D j M').' falls in a pay period that has already been approved.';

                continue;
            }

            $entry = TimesheetEntry::firstOrNew([
                'user_id' => $request->user_id,
                'work_date' => $date,
            ]);

            // Somebody's own account of the day beats a decision made about it
            // afterwards. Only a day still carrying the roster's word — or no
            // word at all — is written over.
            if ($entry->exists && $entry->source !== TimesheetEntry::SOURCE_SCHEDULE) {
                if ($entry->leave_code !== $request->codeFor($date)) {
                    $skipped[] = Carbon::parse($date)->format('D j M').' already has hours on the timesheet; the leave was not written over them.';
                }

                continue;
            }

            $entry->fill([
                'timesheet_period_id' => $period->id,
                'starts_at' => null,
                'ends_at' => null,
                'break_minutes' => 0,
                'leave_code' => $request->codeFor($date),
                'leave_minutes' => $request->minutesPerDay(),
                'note' => $request->label().' — approved leave',
                // Confirmed, not draft. A director approving the request is a
                // person saying this is what happened, which is exactly what
                // confirming a day means — making them retype it on the
                // timesheet as well would be the rubber stamp that step exists
                // to avoid.
                'source' => TimesheetEntry::SOURCE_MANUAL,
                'confirmed_by' => $request->reviewed_by,
                'confirmed_at' => $request->reviewed_at ?? now(),
            ])->save();

            $written++;
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * Take a revoked absence back off the sheet.
     *
     * Only the days this request itself wrote, and only in periods still open.
     * A day that has since been corrected by a person belongs to them.
     *
     * @return int Days cleared.
     */
    public function clearLeave(LeaveRequest $request): int
    {
        $cleared = 0;

        foreach ($request->workingDates() as $date) {
            $period = TimesheetPeriod::forDate($date);

            if ($period->isApproved()) {
                continue;
            }

            $entry = TimesheetEntry::where('user_id', $request->user_id)
                ->where('work_date', $date)
                ->first();

            if (! $entry || $entry->leave_code === null || $entry->workedMinutes() > 0) {
                continue;
            }

            $entry->delete();
            $cleared++;
        }

        return $cleared;
    }

    /**
     * The summary payroll is handed: one line per employee.
     *
     * @return Collection<int, array>
     */
    public function summary(TimesheetPeriod $period): Collection
    {
        $range = $period->range();
        $staff = User::teachers()->get()->keyBy('id');
        $split = $this->splitByWorkweek($period, $staff->keys()->all());
        $exceptions = $this->clock->exceptions($range, $staff->keys()->all());

        return $staff->map(function (User $user) use ($split, $range, $exceptions) {
            $days = $split[$user->id] ?? [];

            $regular = 0;
            $overtime = 0;
            $paidLeave = 0;
            $unpaidLeave = 0;
            $unconfirmed = 0;
            $worked = 0;

            foreach ($days as $date => $day) {
                // Days from the neighbouring period were loaded to complete the
                // weeks. They are counted for overtime and paid by that period.
                if (! $range->contains($date)) {
                    continue;
                }

                $regular += $day['regular'];
                $overtime += $day['overtime'];
                $paidLeave += $day['paid_leave'];
                $unpaidLeave += $day['unpaid_leave'];
                $worked += $day['worked'];
                $unconfirmed += $day['unconfirmed'] ? 1 : 0;
            }

            $paidMinutes = $regular + $overtime + $paidLeave;

            // Days whose punches do not add up. Counted separately from the
            // unconfirmed ones because they are a different failure: one is
            // nobody's word yet, the other is somebody's word that cannot be
            // true. Both stop the period being approved.
            $broken = count($exceptions[$user->id] ?? []);

            return [
                'user' => $user,
                'clock_exceptions' => $broken,
                'regular_hours' => round($regular / 60, 2),
                'overtime_hours' => round($overtime / 60, 2),
                'paid_leave_hours' => round($paidLeave / 60, 2),
                'unpaid_leave_hours' => round($unpaidLeave / 60, 2),
                'worked_hours' => round($worked / 60, 2),
                'paid_hours' => round($paidMinutes / 60, 2),
                'unconfirmed_days' => $unconfirmed,
                'has_hours' => $paidMinutes > 0 || $unpaidLeave > 0,
                // Time and a half on the overtime. Null when no rate is on
                // file — an estimate of zero would read as "this person is
                // owed nothing", which is a different and much worse claim.
                'estimated_gross' => $user->pay_rate === null ? null : round(
                    ($regular / 60) * (float) $user->pay_rate
                    + ($overtime / 60) * (float) $user->pay_rate * 1.5
                    + ($paidLeave / 60) * (float) $user->pay_rate,
                    2,
                ),
            ];
        })->values();
    }

    /**
     * Split every day into regular and overtime, week by week.
     *
     * Loads whole workweeks, not the period, so a Monday that sits in the
     * previous period still counts towards the Thursday that tips this one into
     * overtime. Paid leave is deliberately left out of the running total: the
     * FLSA counts hours worked, and a holiday does not make the rest of the
     * week overtime.
     *
     * @return array<int, array<string, array>>
     */
    private function splitByWorkweek(TimesheetPeriod $period, array $userIds): array
    {
        $range = $period->range();

        // Reach out to the whole of the first and last weeks the period touches.
        $from = Carbon::parse(PayPeriod::workweekOf($range->start))->toDateString();
        $to = Carbon::parse(PayPeriod::workweekOf($range->end))->addDays(6)->toDateString();

        $entries = TimesheetEntry::whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')
            ->get();

        $threshold = (int) config('daycare.timesheet.overtime_after') * 60;
        $split = [];

        foreach ($entries->groupBy('user_id') as $userId => $forUser) {
            foreach ($forUser->groupBy(fn ($entry) => PayPeriod::workweekOf($entry->work_date)) as $week) {
                $running = 0;

                foreach ($week->sortBy(fn ($entry) => $entry->work_date->toDateString()) as $entry) {
                    $worked = $entry->workedMinutes();

                    // Whatever is left under the threshold is regular; the rest
                    // of the day spills into overtime.
                    $regular = max(0, min($worked, $threshold - $running));
                    $running += $worked;

                    $paidLeave = $entry->paidLeaveMinutes();

                    $split[$userId][$entry->work_date->toDateString()] = [
                        'worked' => $worked,
                        'regular' => $regular,
                        'overtime' => $worked - $regular,
                        'paid_leave' => $paidLeave,
                        'unpaid_leave' => $entry->leave_code !== null && $paidLeave === 0 ? $entry->leave_minutes : 0,
                        'unconfirmed' => $entry->needsConfirming(),
                    ];
                }
            }
        }

        return $split;
    }

    /**
     * Per-day totals for the grid: [user_id][date] => minutes paid.
     *
     * @return array<int, array<string, array>>
     */
    public function grid(TimesheetPeriod $period): array
    {
        $range = $period->range();
        $rows = [];

        foreach ($period->entries as $entry) {
            if (! $range->contains($entry->work_date)) {
                continue;
            }

            $rows[$entry->user_id][$entry->work_date->toDateString()] = $entry;
        }

        return $rows;
    }

    /**
     * The file payroll actually receives.
     *
     * Regular and overtime are separate columns because they are paid at
     * different rates, and paid leave is its own again because it is neither.
     *
     * @return array<int, array<int, string>>
     */
    public function exportRows(TimesheetPeriod $period): array
    {
        $range = $period->range();

        $rows = [[
            'Employee', 'Legal name', 'Aspire ID', 'Employment',
            'Period start', 'Period end',
            'Regular hours', 'Overtime hours', 'Paid leave hours', 'Unpaid leave hours',
            'Total paid hours', 'Pay rate', 'Estimated gross', 'Unconfirmed days',
            'Unresolved punch days',
        ]];

        // Somebody whose only days are broken ones has no payable hours and is
        // in the file anyway, at zero, with the last column saying why. Leaving
        // them out would be the file quietly agreeing they worked nothing.
        foreach ($this->rows($this->summary($period)) as $line) {

            $user = $line['user'];

            $rows[] = [
                $user->name,
                $user->payrollName(),
                $user->aspire_id ?? '',
                $user->employment ?? '',
                $range->start->toDateString(),
                $range->end->toDateString(),
                number_format($line['regular_hours'], 2, '.', ''),
                number_format($line['overtime_hours'], 2, '.', ''),
                number_format($line['paid_leave_hours'], 2, '.', ''),
                number_format($line['unpaid_leave_hours'], 2, '.', ''),
                number_format($line['paid_hours'], 2, '.', ''),
                $user->pay_rate === null ? '' : number_format((float) $user->pay_rate, 2, '.', ''),
                $line['estimated_gross'] === null ? '' : number_format($line['estimated_gross'], 2, '.', ''),
                (string) $line['unconfirmed_days'],
                (string) $line['clock_exceptions'],
            ];
        }

        return $rows;
    }

    /** Everybody the grid has something to say about. */
    public function rows(Collection $summary): Collection
    {
        return $summary->filter(fn ($line) => $line['has_hours'] || $line['clock_exceptions'] > 0)->values();
    }

    /** What the period totals to, for the header and the approve decision. */
    public function totals(Collection $summary): array
    {
        $withHours = $summary->filter(fn ($line) => $line['has_hours']);

        return [
            'employees' => $withHours->count(),
            // Counted across everybody, not only the people with hours: a day
            // whose punches do not add up is worth nothing until it is sorted
            // out, so somebody with nothing but broken days has no hours at all
            // and is exactly the person who must not be missed.
            'clock_exceptions' => $summary->sum('clock_exceptions'),
            'regular_hours' => round($withHours->sum('regular_hours'), 2),
            'overtime_hours' => round($withHours->sum('overtime_hours'), 2),
            'paid_leave_hours' => round($withHours->sum('paid_leave_hours'), 2),
            'paid_hours' => round($withHours->sum('paid_hours'), 2),
            'unconfirmed_days' => $withHours->sum('unconfirmed_days'),
            'estimated_gross' => round($withHours->sum(fn ($line) => $line['estimated_gross'] ?? 0), 2),
            'missing_rates' => $withHours->filter(fn ($line) => $line['estimated_gross'] === null)->count(),
        ];
    }
}
