<?php

namespace Database\Seeders;

use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\PayPeriod;
use App\Services\StaffSchedule;
use App\Services\TimeClock;
use App\Services\Timesheet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A fortnight of hours you can walk through by hand.
 *
 * Two pay periods are built, because the interesting states are spread across
 * both: the one just gone is finished, confirmed and approved — frozen, and
 * ready to export — while the one running now is half worked, so the sheet
 * shows every state a day can be in at once.
 *
 * Both are anchored to today, so whenever this is run the previous period has
 * genuinely ended and can genuinely be approved. Nothing here is a fixed date.
 *
 * Run StaffSeeder first: without staff there is no roster, and without a roster
 * there is nothing to turn into hours. DemoScenarioSeeder before that, because
 * the generator sizes the roster against the children actually booked in.
 */
class TimesheetDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(StaffSchedule $scheduler, Timesheet $timesheets): void
    {
        $staff = User::teachers()->orderBy('name')->get();

        if ($staff->count() < 4) {
            $this->command?->error('Not enough staff to build a payroll period.');
            $this->command?->line('  Run: php artisan db:seed --class=DemoScenarioSeeder');
            $this->command?->line('       php artisan db:seed --class=StaffSeeder');

            return;
        }

        $current = PayPeriod::containing(today()->toDateString());
        $previous = $current->previous();

        $this->command?->info('Building the roster both periods are drawn from…');
        $this->rosterFor($scheduler, $previous, $current);

        // Re-runnable: the two demo periods are rebuilt from scratch rather
        // than added to, or a second run would leave half-corrected days behind
        // and the walkthrough would not match what is on screen.
        $this->reset($previous, $current);

        $finished = $this->buildFinished($timesheets, $previous, $staff);
        $running = $this->buildRunning($timesheets, $current, $staff);

        $this->report($timesheets, $previous, $current, $finished, $running);
    }

    /** Generate every staff week the two periods touch. */
    private function rosterFor(StaffSchedule $scheduler, PayPeriod $previous, PayPeriod $current): void
    {
        $monday = Carbon::parse(PayPeriod::workweekOf($previous->start));
        $last = Carbon::parse(PayPeriod::workweekOf($current->end));

        for ($week = $monday->copy(); $week->lte($last); $week->addWeek()) {
            $scheduler->generate($week->toDateString());
        }
    }

    private function reset(PayPeriod $previous, PayPeriod $current): void
    {
        foreach ([$previous, $current] as $range) {
            $period = TimesheetPeriod::firstWhere('period_start', $range->key());

            if ($period) {
                $period->entries()->delete();
                $period->delete();
            }

            // The punches too, or a second run would stack another day's worth
            // on top of the first and every clocked day would read as somebody
            // clocking in twice.
            TimePunch::whereBetween('work_date', [$range->start->toDateString(), $range->end->toDateString()])->delete();
        }
    }

    /**
     * The period just gone: every day confirmed, signed off, frozen.
     *
     * This is what "ready to hand to payroll" looks like when it is finished —
     * the CSV downloads from here, and the sheet refuses to be edited.
     */
    private function buildFinished(Timesheet $timesheets, PayPeriod $range, $staff): TimesheetPeriod
    {
        $period = TimesheetPeriod::forDate($range->key());
        $timesheets->seed($period);

        // One person took a day off in the period that has already been paid,
        // so the approved CSV carries paid leave as well as worked hours.
        $entry = TimesheetEntry::where('timesheet_period_id', $period->id)
            ->where('user_id', $staff->first()->id)
            ->orderBy('work_date')
            ->skip(2)
            ->first();

        $entry?->update([
            'starts_at' => null,
            'ends_at' => null,
            'break_minutes' => 0,
            'leave_code' => 'PTO',
            'leave_minutes' => 8 * 60,
            'note' => 'Booked in June',
        ]);

        // Everything in a finished period has been looked at — that is what
        // made it approvable.
        TimesheetEntry::where('timesheet_period_id', $period->id)
            ->update(['source' => TimesheetEntry::SOURCE_MANUAL]);

        $period->forceFill([
            'status' => TimesheetPeriod::STATUS_APPROVED,
            'approved_by' => User::where('role', 'admin')->value('id'),
            'approved_at' => now()->subDay(),
        ])->save();

        return $period;
    }

    /**
     * The period running now: half worked, and deliberately messy.
     *
     * Every state the grid can show is here at once — confirmed hours, days
     * still carrying the roster's word, all three kinds of leave, and somebody
     * over forty hours in a single week.
     */
    private function buildRunning(Timesheet $timesheets, PayPeriod $range, $staff): TimesheetPeriod
    {
        $period = TimesheetPeriod::forDate($range->key());
        $timesheets->seed($period);

        $entries = TimesheetEntry::where('timesheet_period_id', $period->id)
            ->orderBy('work_date')
            ->get()
            ->groupBy('user_id');

        // Only the people actually rostered. The centre also holds a login per
        // room — "PreK Teacher" and the rest — who never work a shift, and
        // picking one of those for a demo state would stage nothing at all.
        $rostered = $staff->filter(fn (User $person) => $entries->has($person->id))->values();

        if ($rostered->isEmpty()) {
            return $period;
        }

        // Confirm everybody except the last of them, who is left exactly as the
        // roster made them: the sheet counts their days in amber, and approving
        // the period is refused because of them.
        $unconfirmed = $rostered->last();

        TimesheetEntry::where('timesheet_period_id', $period->id)
            ->where('user_id', '!=', $unconfirmed->id)
            ->update(['source' => TimesheetEntry::SOURCE_MANUAL]);

        // Real days rarely match the roster to the minute. A late start and an
        // early finish give the summary something to disagree with.
        $this->nudge($entries[$rostered[0]->id], 0, 25, 0);
        $this->nudge($entries[$rostered[0]->id], 1, 0, -40);

        $this->leave($entries[$rostered[0]->id], 3, 'SICK', 'Called in at 6am');

        if ($rostered->count() > 1) {
            $this->leave($entries[$rostered[1]->id], 2, 'UNPAID', 'Personal, unpaid by agreement');
        }

        $this->overtimeWeek($period, $rostered[0], $range);

        // Somebody who works the clock rather than being corrected onto the
        // sheet: clean punched days, one with a punch a supervisor had to
        // move, and one where they forgot to clock out at all.
        if ($rostered->count() > 1) {
            $this->clockedDays($rostered[1], $period);
        }

        // Somebody with hours and no pay rate, so the Gross column has to show
        // "no rate" rather than $0.00 — the state that is easy to get wrong and
        // impossible to notice once it reads as a number.
        if ($rostered->count() > 3) {
            $rostered[2]->forceFill(['pay_rate' => null])->save();
        }

        return $period;
    }

    /**
     * One person's period, worked on the time clock instead of corrected onto
     * the sheet by hand.
     *
     * Four days, and each is a state the clock can be in:
     *
     *   1. Punched clean — in, a short break, lunch, out.
     *   2. Punched, then corrected — the clock-out was wrong, so a supervisor
     *      voided it and put another one in its place. Both are still on the
     *      day, which is the whole reason the trail exists.
     *   3. Never clocked out. Worth nothing, flagged red on the grid, and it
     *      refuses to let the period be approved.
     *   4. Punched clean again, so the exception is plainly the odd one out.
     */
    private function clockedDays(User $person, TimesheetPeriod $period): void
    {
        $clock = app(TimeClock::class);
        $director = User::where('role', 'admin')->first();

        // Only days already worked, and only days the roster gave them times
        // on — punching a day nobody was scheduled would be a different demo.
        $days = TimesheetEntry::where('timesheet_period_id', $period->id)
            ->where('user_id', $person->id)
            ->whereNotNull('starts_at')
            ->whereNull('leave_code')
            ->whereDate('work_date', '<', today())
            ->orderByDesc('work_date')
            ->take(4)
            ->get()
            ->reverse()
            ->values();

        foreach ($days as $index => $entry) {
            $date = $entry->work_date->toDateString();
            $start = $entry->starts_at;
            $end = $entry->ends_at;

            $punch = fn (string $type, int $minute) => $clock->punch(
                user: $person,
                type: $type,
                at: Carbon::parse($date)->startOfDay()->addMinutes($minute),
                ip: '10.0.0.14',
            );

            // A minute or two either side of the rostered time, because nobody
            // presses a button at exactly seven.
            $punch(TimePunch::IN, $start + 2);
            $punch(TimePunch::BREAK_START, $start + 150);
            $punch(TimePunch::BREAK_END, $start + 165);       // 15 min, paid
            $punch(TimePunch::LUNCH_START, $start + 300);
            $punch(TimePunch::LUNCH_END, $start + 330);       // 30 min, unpaid

            // The third day is the one they walked out of without pressing
            // anything, which is the exception a time clock actually creates.
            if ($index === 2) {
                continue;
            }

            $out = $punch(TimePunch::OUT, $index === 1 ? $end + 95 : $end - 3);

            if ($index !== 1 || $director === null) {
                continue;
            }

            // The wrong clock-out, and the correction that put it right.
            $clock->void($out, $director, 'Clocked out for the closing room by mistake');

            $clock->punch(
                user: $person,
                type: TimePunch::OUT,
                at: Carbon::parse($date)->startOfDay()->addMinutes($end - 5),
                source: TimePunch::SOURCE_SUPERVISOR,
                by: $director,
                reason: 'Left at the rostered time — checked with the room lead',
                corrects: $out,
            );
        }

        // The clock's word beats the roster's, but not a person's, and the
        // sweep above has already marked these days as somebody's. Forcing the
        // rebuild is what a supervisor acting on the punches does.
        foreach ($days as $entry) {
            $clock->rebuild($person, $entry->work_date->toDateString(), force: true);
        }
    }

    /** Move one day's start or end, the way a real day drifts from the plan. */
    private function nudge($forUser, int $index, int $startShift, int $endShift): void
    {
        $entry = $forUser->values()->get($index);

        if (! $entry || $entry->starts_at === null) {
            return;
        }

        $entry->update([
            'starts_at' => $entry->starts_at + $startShift,
            'ends_at' => $entry->ends_at + $endShift,
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);
    }

    private function leave($forUser, int $index, string $code, string $note): void
    {
        $entry = $forUser->values()->get($index);

        $entry?->update([
            'starts_at' => null,
            'ends_at' => null,
            'break_minutes' => 0,
            'leave_code' => $code,
            'leave_minutes' => 8 * 60,
            'note' => $note,
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);
    }

    /**
     * Push one person over forty hours in a single week.
     *
     * Their rostered days are stretched to nine hours and a Saturday is added,
     * so the week crosses the threshold and the overtime column has something
     * in it. Which period pays that overtime depends on which side of the 15th
     * the Saturday falls — which is the whole reason the split exists.
     */
    private function overtimeWeek(TimesheetPeriod $period, User $person, PayPeriod $range): void
    {
        // The last whole Monday-to-Friday inside the period, so the week the
        // overtime lands in is one the grid actually shows.
        $friday = Carbon::parse($range->end);

        while ($friday->dayOfWeek !== Carbon::FRIDAY || $friday->gt($range->end)) {
            $friday->subDay();
        }

        $monday = $friday->copy()->startOfWeek(Carbon::MONDAY);

        for ($day = $monday->copy(); $day->lte($friday); $day->addDay()) {
            $entry = TimesheetEntry::where('user_id', $person->id)
                ->where('work_date', $day->toDateString())
                ->first();

            if (! $entry || $entry->starts_at === null) {
                continue;
            }

            $entry->update([
                'ends_at' => $entry->starts_at + 9 * 60 + $entry->break_minutes,
                'leave_code' => null,
                'leave_minutes' => 0,
                'note' => 'Covered the late shift',
                'source' => TimesheetEntry::SOURCE_MANUAL,
            ]);
        }

        // A Saturday nobody was rostered for. It is inside the period, so this
        // period pays the overtime it creates.
        $saturday = $friday->copy()->addDay();

        if (! $range->contains($saturday)) {
            return;
        }

        TimesheetEntry::updateOrCreate(
            ['user_id' => $person->id, 'work_date' => $saturday->toDateString()],
            [
                'timesheet_period_id' => $period->id,
                'starts_at' => 8 * 60,
                'ends_at' => 13 * 60,
                'break_minutes' => 0,
                'leave_code' => null,
                'leave_minutes' => 0,
                'note' => 'Saturday open day',
                'source' => TimesheetEntry::SOURCE_MANUAL,
            ],
        );
    }

    private function report(Timesheet $timesheets, PayPeriod $previous, PayPeriod $current, TimesheetPeriod $finished, TimesheetPeriod $running): void
    {
        $done = $timesheets->totals($timesheets->summary($finished));
        $live = $timesheets->totals($timesheets->summary($running));

        $this->command?->info('Payroll preparation ready.');
        $this->command?->line('  roster       : '.StaffShift::count().' shifts across '.StaffShift::distinct()->count('user_id').' staff');
        $this->command?->line('  '.str_pad($previous->label(), 18).' : approved — '
            .number_format($done['paid_hours'], 2).' paid h, '
            .number_format($done['overtime_hours'], 2).' OT, across '.$done['employees'].' people  (frozen — try the CSV)');
        $this->command?->line('  '.str_pad($current->label(), 18).' : draft — '
            .number_format($live['paid_hours'], 2).' paid h, '
            .number_format($live['overtime_hours'], 2).' OT, '
            .$live['unconfirmed_days'].' day(s) still the roster\'s word, '
            .$live['clock_exceptions'].' day(s) of punches that do not add up');
        $this->command?->line('  time clock   : '.TimePunch::count().' punches, '
            .TimePunch::whereNotNull('voided_at')->count().' voided and replaced');
        $this->command?->newLine();
        $this->command?->line('  Open  /timesheets  as the director. Approving the live period is refused');
        $this->command?->line('  twice over — amber days nobody has confirmed, and a red ! where somebody');
        $this->command?->line('  forgot to clock out. Both refusals are the point. Click the ! to put it');
        $this->command?->line('  right, and  /time-clock  is the same clock as a teacher sees it.');
    }
}
