<?php

namespace Database\Seeders;

use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * This week's punches, so the Staff Timesheets grid has every state on it.
 *
 * Built from the generated roster: each person is punched against their own
 * shift, so "late" is late against the hour they were due and "on time" is
 * on time against it. Days already gone are mostly clean, with one late
 * arrival, one missing clock-out and one day a supervisor corrected. Today
 * is live: openers are on shift, one of them late, one has gone home early,
 * two have not come in, and closers are on shift only once their shift has
 * started. A re-run clears the week's punches first, so it never doubles up.
 */
class TimesheetWeekDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(TimeClock $clock): void
    {
        $monday = today()->startOfWeek(Carbon::MONDAY);
        $friday = $monday->copy()->addDays(4);
        $now = now()->hour * 60 + now()->minute;
        $director = User::where('role', 'admin')->first();

        $shifts = StaffShift::whereBetween('shift_date', [$monday->toDateString(), $friday->toDateString()])
            ->orderBy('shift_date')->orderBy('starts_at')
            ->get()
            ->groupBy('user_id');

        if ($shifts->isEmpty()) {
            $this->command?->warn('No staff shifts this week. Generate the week schedule first.');

            return;
        }

        TimePunch::whereIn('user_id', $shifts->keys())
            ->whereBetween('work_date', [$monday->toDateString(), $friday->toDateString()])
            ->delete();

        $index = 0;

        foreach ($shifts as $userId => $days) {
            $person = User::find($userId);
            $index++;

            foreach ($days as $shift) {
                $date = $shift->shift_date->toDateString();
                $start = (int) $shift->starts_at;
                $end = (int) $shift->ends_at;
                $punch = fn (string $type, int $minute, bool $corrected = false) => $clock->punch(
                    $person,
                    $type,
                    Carbon::parse($date)->startOfDay()->addMinutes($minute),
                    $corrected ? TimePunch::SOURCE_SUPERVISOR : TimePunch::SOURCE_CLOCK,
                    $corrected ? $director : null,
                    $corrected ? 'FORGOT' : null,
                );

                if ($date > today()->toDateString()) {
                    continue;
                }

                if ($date < today()->toDateString()) {
                    $yesterday = $date === today()->subDay()->toDateString();

                    match (true) {
                        // Went home without clocking out.
                        $yesterday && $index % 7 === 2 => $punch(TimePunch::IN, $start - 4),
                        // In late, against the hour the roster says.
                        $yesterday && $index % 7 === 1 => $this->fullDay($punch, $start + 23, $end),
                        // A supervisor typed the clock-out in afterwards.
                        $yesterday && $index % 7 === 3 => $this->fullDay($punch, $start - 2, $end, correctedOut: true),
                        default => $this->fullDay($punch, $start - (($index * 3) % 7), $end - (($index * 5) % 6)),
                    };

                    continue;
                }

                // Today, live against the clock.
                if ($start > $now) {
                    continue;                                    // shift not started: "not in"
                }

                match (true) {
                    $index % 11 === 4 || $index % 11 === 9 => null,                 // never came in
                    $index % 11 === 6 => $this->fullDay($punch, $start - 3, min($end, $now - 40)), // went home early
                    $index % 11 === 2 => $punch(TimePunch::IN, $start + 19),         // on shift, late
                    default => $punch(TimePunch::IN, $start - (($index * 3) % 7)),  // on shift
                };
            }
        }

        $this->command?->info('Punched '.$shifts->count().' staff for '.$monday->format('M j').' – '.$friday->format('M j').'.');
    }

    /** A clean day: in, a thirty-minute lunch around the middle, out. */
    private function fullDay(callable $punch, int $in, int $out, bool $correctedOut = false): void
    {
        $punch(TimePunch::IN, $in);
        $lunch = intdiv($in + $out, 2) - 15;
        $punch(TimePunch::LUNCH_START, $lunch);
        $punch(TimePunch::LUNCH_END, $lunch + 30);
        $punch(TimePunch::OUT, $out, $correctedOut);
    }
}
