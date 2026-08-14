<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A centre you can walk through by hand.
 *
 * The roster is small enough to read at a glance and deliberately covers every
 * state the sheet can show: full-week and part-week children, a School Age room
 * that splits into AM/PM, a child who starts mid-week, one who leaves mid-month,
 * and one who is inactive and should never appear at all.
 *
 * One week is built — the current one (Monday to Friday). Nothing exists before
 * it and nothing after it, so the client opens the app on a single clean week of
 * real-looking attendance instead of a history they have to scroll through.
 *
 * Next week is deliberately left unopened. Opening it is the first thing the
 * walkthrough asks you to do, because that is where the copy-forward shows.
 */
class DemoScenarioSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * [last, first, room, pattern, enrolled_on, withdrawn_on, status, expected hours]
     *
     * Hours are what the parent contracted for: 45 a full week, 27 three days,
     * 18 two. Most match the pattern, and the ones that do not are the point —
     * they are what the week's projection is there to show up.
     */
    private const ROSTER = [
        ['Alvarez', 'Mia', 'Infant', 'full', null, null, 'Active', 45],
        // Contracted for four days but only ever comes three: the projection
        // reads 9 h short every week.
        ['Bennett', 'Noah', 'Infant', 'mwf', null, null, 'Active', 36],
        ['Cruz', 'Ava', 'Toddler', 'full', null, null, 'Active', 45],
        ['Diaz', 'Liam', 'Toddler', 'tth', null, null, 'Active', 18],
        ['Foster', 'Ella', 'Transition', 'full', null, null, 'Active', 45],
        ['Grant', 'Owen', 'PreK', 'mwf', null, null, 'Active', 27],
        ['Hayes', 'Sofia', 'PreK', 'full', null, null, 'Active', 45],
        // Nobody has agreed hours for Jack: the projection reports his days and
        // claims no target.
        ['Ibarra', 'Jack', 'UPK-4', 'tth', null, null, 'Active', null],
        ['Kim', 'Ruby', 'School Age', 'full', null, null, 'Active', 45],
        ['Lopez', 'Ethan', 'School Age', 'mwf', null, null, 'Active', 27],
        // Starts on the Wednesday of this week: Mon and Tue show nothing at all.
        // Hours on file, no attendance behind her — hours but no pattern.
        ['Patel', 'Nora', 'Toddler', 'full', '+2 days', null, 'Active', 45],
        // Leaves next Tuesday: he is here all of this week, and once next week is
        // opened the rest of it shows nothing.
        ['Reyes', 'Caleb', 'PreK', 'full', null, '+8 days', 'Active', 45],
        // Inactive: never on the sheet, in any week.
        ['Tan', 'Iris', 'Toddler', 'full', null, null, 'Inactive', null],
    ];

    private const PATTERNS = [
        'full' => [0, 1, 2, 3, 4],
        'mwf' => [0, 2, 4],
        'tth' => [1, 3],
    ];

    public function run(WeekSchedule $weeks): void
    {
        $thisWeek = ScheduleWeek::startOf(today()->toDateString());
        $dates = ScheduleWeek::datesOf($thisWeek);

        $this->makeRoster($thisWeek);

        // Only this week exists. Anything a previous run left behind is cleared
        // first, or last week's sheet would still be sitting there in the demo.
        $this->keepOnly($thisWeek);

        $weeks->open($thisWeek);
        $this->applyPatterns($thisWeek);
        $this->signInWeek($thisWeek);

        $this->command?->info('Demo centre ready.');
        $this->command?->line('  roster     : '.Child::where('status', 'Active')->count().' active, 1 inactive');
        $this->command?->line('  this week  : '.$dates[0]->toDateString().' to '.$dates[4]->toDateString().'  (the only week with data)');
        $this->command?->line('  next week  : '.Carbon::parse($thisWeek)->addWeek()->toDateString().'  (blank — not opened yet)');
    }

    /**
     * Wipe every week except the demo one.
     *
     * The seeder is meant to be re-runnable and still leave exactly one week on
     * the screen, so old weeks and the attendance recorded in them go — this is
     * sample data, not a centre's record.
     */
    private function keepOnly(string $weekStart): void
    {
        $dates = ScheduleWeek::datesOf($weekStart);

        ScheduleSlot::where('week_start', '!=', $weekStart)->delete();
        ScheduleWeek::where('week_start', '!=', $weekStart)->delete();

        Attendance::whereNotBetween('attendance_date', [
            $dates[0]->toDateString(),
            $dates[4]->toDateString(),
        ])->delete();
    }

    private function makeRoster(string $thisWeek): void
    {
        $monday = Carbon::parse($thisWeek);
        $lan = 1001;

        foreach (self::ROSTER as [$last, $first, $room, , $enrolled, $withdrawn, $status, $hours]) {
            // Enrolment dates are relative to this Monday so the demo lands on
            // the right days whenever it is run.
            Child::updateOrCreate(
                ['first_name' => $first, 'last_name' => $last],
                [
                    'lan' => (string) $lan++,
                    'status' => $status,
                    // The room follows the date of birth, so the demo roster has
                    // to be children of the right ages rather than one date for
                    // everyone — otherwise the whole centre is in one room.
                    'dob' => $this->dobForRoom($room, $monday),
                    'enrolled_on' => $enrolled ? $monday->copy()->modify($enrolled)->toDateString() : null,
                    'withdrawn_on' => $withdrawn ? $monday->copy()->modify($withdrawn)->toDateString() : null,
                    'expected_hours_per_week' => $hours,
                ],
            );
        }

        // The seeder runs without model events, so nothing has worked the rooms
        // out from the dates of birth yet. Do it before any week is opened: a
        // School Age child whose room is still blank is filled in with one FULL
        // box a day instead of AM and PM, and every week built after that has a
        // pattern the next one cannot match.
        ClassroomAssignment::syncAll();
    }

    /** An age that sits well inside the room's band, clear of both boundaries. */
    private function dobForRoom(string $room, Carbon $monday): string
    {
        $months = [
            'Infant' => 6,
            'Transition' => 21,
            'Toddler' => 30,
            'PreK' => 42,
            'UPK-4' => 54,
            'School Age' => 96,
        ];

        return $monday->copy()->subMonths($months[$room] ?? 30)->toDateString();
    }

    /** Tick each child's usual days in a week. */
    private function applyPatterns(string $weekStart): void
    {
        foreach (self::ROSTER as [$last, $first, , $pattern]) {
            $child = Child::where('first_name', $first)->where('last_name', $last)->first();

            foreach (ScheduleWeek::datesOf($weekStart) as $offset => $date) {
                if (! in_array($offset, self::PATTERNS[$pattern], true)) {
                    continue;
                }

                ScheduleSlot::where('child_id', $child->id)
                    ->where('slot_date', $date->toDateString())
                    ->update(['is_scheduled' => true]);
            }
        }
    }

    /**
     * Sign in the scheduled days that have already happened. One child in five
     * is left absent, because a sheet where everybody turned up teaches nothing.
     */
    private function signInWeek(string $weekStart): void
    {
        $slots = ScheduleSlot::where('week_start', $weekStart)->where('is_scheduled', true)->get();

        foreach ($slots as $index => $slot) {
            $date = $slot->slot_date;

            if ($date->toDateString() > today()->toDateString() || $index % 5 === 0) {
                continue;
            }

            $arrival = $slot->session === 'PM'
                ? $date->copy()->setTime(12, 30)->addMinutes(($slot->child_id * 5) % 40)
                : $date->copy()->setTime(7, 25)->addMinutes(($slot->child_id * 9) % 70);

            Attendance::firstOrCreate(
                [
                    'child_id' => $slot->child_id,
                    'attendance_date' => $date->toDateString(),
                    'session' => $slot->session,
                ],
                ['signed_in_at' => $arrival],
            );
        }
    }
}
