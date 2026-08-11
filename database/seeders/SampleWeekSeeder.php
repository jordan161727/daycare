<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\WeekSchedule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A worked month: every week of the month gets its own ticked pattern, and the
 * days that have already happened get signed in.
 *
 * Each week's patterns are rotated one step from the week before, so no two
 * weeks look alike — copy one week onto another and the change is visible on
 * the sheet straight away.
 *
 * Seed a different month with SAMPLE_MONTH=2026-09 php artisan db:seed --class=SampleWeekSeeder
 */
class SampleWeekSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Weekday offsets (0 = Monday) each pattern covers. */
    private const PATTERNS = [
        'Full week (M–F)' => [0, 1, 2, 3, 4],
        'M/W/F' => [0, 2, 4],
        'T/Th' => [1, 3],
        'M–Th' => [0, 1, 2, 3],
    ];

    public function run(WeekSchedule $weeks): void
    {
        $children = Child::where('status', 'Active')->orderBy('id')->get();

        if ($children->isEmpty()) {
            $this->command?->warn('No active children on file — nothing to schedule.');

            return;
        }

        $month = Carbon::parse((env('SAMPLE_MONTH') ?: today()->format('Y-m')).'-01');
        $weekStarts = $this->weekStartsIn($month);

        $this->command?->info($month->format('F Y').' — '.count($weekStarts).' weeks');

        foreach ($weekStarts as $weekIndex => $weekStart) {
            // The same call the attendance sheet makes when a teacher opens a
            // week for the first time. Seed oldest first so each week inherits
            // the one before it, exactly as it would in the app.
            $weeks->open($weekStart);

            [$ticked, $signedIn] = $this->fillWeek($children, $weekStart, $weekIndex);

            $this->command?->line(sprintf(
                '  %s  %3d days ticked, %3d sign-ins',
                Carbon::parse($weekStart)->format('M j').' – '.Carbon::parse($weekStart)->addDays(4)->format('M j'),
                $ticked,
                $signedIn,
            ));
        }

        $this->command?->info('Open any of these weeks and use "Copy from another week" — the ticks change, the sign-ins stay put.');
    }

    /** Every Monday whose Mon–Fri run touches the month. */
    private function weekStartsIn(Carbon $month): array
    {
        $cursor = Carbon::parse(ScheduleWeek::startOf($month->toDateString()));
        $end = $month->copy()->endOfMonth();
        $starts = [];

        while ($cursor <= $end) {
            // A week starting the previous month only counts if a weekday of it
            // actually lands in this one.
            if ($cursor->copy()->addDays(4) >= $month->copy()->startOfMonth()) {
                $starts[] = $cursor->toDateString();
            }

            $cursor->addWeek();
        }

        return $starts;
    }

    /**
     * Tick one week's pattern and sign in the days already past.
     *
     * Every slot is cleared first so a re-run reproduces the same week rather
     * than piling new ticks on top of whatever it inherited.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Child>  $children
     * @return array{0: int, 1: int} ticked, signed in
     */
    private function fillWeek(Collection $children, string $weekStart, int $weekIndex): array
    {
        ScheduleSlot::where('week_start', $weekStart)->update(['is_scheduled' => false]);

        $names = array_keys(self::PATTERNS);
        $ticked = 0;
        $signedIn = 0;

        foreach ($children as $index => $child) {
            // Rotate by week so week 2 is not a carbon copy of week 1.
            $pattern = self::PATTERNS[$names[($index + $weekIndex) % count($names)]];

            foreach (ScheduleWeek::datesOf($weekStart) as $offset => $date) {
                if (! in_array($offset, $pattern, true)) {
                    continue;
                }

                foreach ($child->sessions() as $session) {
                    // School Age rooms split into AM/PM; send half of them home
                    // after lunch on Friday so both halves of a day are exercised.
                    if ($session === 'PM' && $offset === 4 && $child->id % 2 === 0) {
                        continue;
                    }

                    $ticked += ScheduleSlot::where('child_id', $child->id)
                        ->where('slot_date', $date->toDateString())
                        ->where('session', $session)
                        ->update(['is_scheduled' => true]);

                    if ($this->signIn($child, $date, $session)) {
                        $signedIn++;
                    }
                }
            }
        }

        return [$ticked, $signedIn];
    }

    /**
     * Sign a scheduled day in, but only for days that have already happened —
     * a future sign-in would be a record of something that never took place.
     * Roughly one child in nine is left absent so the sheet is not a solid block.
     */
    private function signIn(Child $child, Carbon $date, string $session): bool
    {
        if ($date->toDateString() > today()->toDateString() || ($child->id + $date->dayOfYear) % 9 === 0) {
            return false;
        }

        $minutes = ($child->id * 7) % 75;
        $arrival = match ($session) {
            'AM', 'FULL' => $date->copy()->setTime(7, 20)->addMinutes($minutes),
            'PM' => $date->copy()->setTime(12, 30)->addMinutes($minutes % 45),
        };

        Attendance::firstOrCreate(
            [
                'child_id' => $child->id,
                'attendance_date' => $date->toDateString(),
                'session' => $session,
            ],
            ['signed_in_at' => $arrival],
        );

        return true;
    }
}
