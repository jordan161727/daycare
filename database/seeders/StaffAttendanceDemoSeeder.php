<?php

namespace Database\Seeders;

use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Two weeks of staff attendance, so Live and History both have a week to read.
 *
 * Last week and this week, against the roster: last week's shifts are copied
 * from this week's where a person has none. Every day already gone is punched
 * — mostly clean (in a few minutes early, a half-hour lunch, out near the
 * end), with one late arrival a day, one missing clock-out yesterday, one day
 * last week nobody punched at all, one day a supervisor typed the clock-out
 * in, one sick day and one late start entered as time off. Today is punched
 * against the clock only if nothing has punched it yet (the floor-plan demo
 * does that during the day): openers on shift, one late, one gone home
 * early, one who never came in.
 *
 * Re-runnable: the two weeks' past punches and this seeder's own time off
 * are cleared first; today's punches are left alone when there are any.
 *
 *     php artisan db:seed --class=StaffAttendanceDemoSeeder --force
 */
class StaffAttendanceDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const NOTE = 'Staff attendance demo';

    public function run(TimeClock $clock): void
    {
        $tz = config('app.timezone');
        $now = now()->timezone($tz);
        $today = $now->toDateString();
        $minuteNow = $now->hour * 60 + $now->minute;

        // DEMO_NOW=15:30 paints today as of that hour whatever the clock says
        // (for looking at Live outside working hours); today's punches are
        // replaced then.
        $forced = env('DEMO_NOW');
        if ($forced && preg_match('/^(\d{1,2}):(\d{2})$/', $forced, $m)) {
            $minuteNow = (int) $m[1] * 60 + (int) $m[2];
        }
        $thisMonday = $now->copy()->startOfWeek(Carbon::MONDAY);
        $lastMonday = $thisMonday->copy()->subWeek();
        $days = config('daycare.days');
        $director = User::where('role', 'admin')->orderBy('id')->first();

        $teachers = User::teachers()->get()->filter(fn (User $u) => StaffShift::where('user_id', $u->id)->where('week_start', $thisMonday->toDateString())->exists())->values();

        if ($teachers->isEmpty()) {
            $this->command?->warn('No staff shifts this week. Generate and publish the week schedule first.');

            return;
        }

        // Last week's roster, copied from this week's where there is none.
        foreach ($teachers as $person) {
            $shifts = StaffShift::where('user_id', $person->id)->where('week_start', $thisMonday->toDateString())->get();
            foreach ($shifts as $shift) {
                $date = $shift->shift_date->copy()->subWeek();
                if (StaffShift::where('user_id', $person->id)->whereDate('shift_date', $date->toDateString())->exists()) {
                    continue;
                }
                StaffShift::create([
                    'week_start' => $lastMonday->toDateString(), 'user_id' => $person->id, 'shift_date' => $date->toDateString(),
                    'day' => $days[min($date->dayOfWeekIso - 1, count($days) - 1)],
                    'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'classroom' => $shift->classroom, 'role' => $shift->role,
                ]);
            }
        }

        // A clean slate for the days gone, and for this seeder's own time off.
        $yesterday = $now->copy()->subDay()->toDateString();
        TimePunch::whereIn('user_id', $teachers->pluck('id'))->whereBetween('work_date', [$lastMonday->toDateString(), $forced ? $today : $yesterday])->delete();
        StaffTimeOff::where('note', self::NOTE)->delete();

        // Time off: a sick day last Wednesday for one person, a late start
        // yesterday (doctor until 10:00) for another.
        $sick = $teachers[1 % $teachers->count()];
        $late = $teachers[4 % $teachers->count()];
        $sickDay = $lastMonday->copy()->addDays(2)->toDateString();
        foreach ([
            [$sick, $sickDay, 'all', null, null, 'sick', true],
            [$late, $yesterday, 'until', 10 * 60, null, 'appointment', false],
        ] as [$who, $date, $kind, $from, $to, $reason, $paid]) {
            StaffTimeOff::create([
                'user_id' => $who->id, 'date' => $date, 'until' => null, 'kind' => $kind,
                'from_minute' => $from, 'to_minute' => $to, 'reason' => $reason, 'paid' => $paid,
                'note' => self::NOTE, 'created_by' => $director?->id,
            ]);
        }

        // Today is left alone once anything has punched it (the floor-plan
        // demo does, during the day) — unless DEMO_NOW asked for it.
        $todayTaken = ! $forced && TimePunch::where('work_date', $today)->whereIn('user_id', $teachers->pluck('id'))->exists();

        $punched = 0;
        $index = 0;

        foreach ($teachers as $person) {
            $index++;
            $shifts = StaffShift::where('user_id', $person->id)
                ->whereBetween('shift_date', [$lastMonday->toDateString(), $today])
                ->orderBy('shift_date')->orderBy('starts_at')->get()->groupBy(fn (StaffShift $s) => $s->shift_date->toDateString());

            foreach ($shifts as $date => $own) {
                $start = (int) $own->min('starts_at');
                $end = (int) $own->max('ends_at');
                $punch = function (string $type, int $minute, bool $corrected = false) use ($clock, $person, $date, $tz, $director, &$punched) {
                    $clock->punch(
                        $person, $type, Carbon::parse($date, $tz)->startOfDay()->addMinutes($minute),
                        $corrected ? TimePunch::SOURCE_SUPERVISOR : TimePunch::SOURCE_CLOCK,
                        $corrected ? $director : null,
                        $corrected ? 'forgot_out' : null,
                    );
                    $punched++;
                };

                if ($date < $today) {
                    if ($person->is($sick) && $date === $sickDay) {
                        continue;                                                    // out sick: no punches
                    }
                    if ($person->is($late) && $date === $yesterday) {
                        $this->fullDay($punch, 10 * 60 + 2, $end - 3);                // in after the appointment: not late
                        continue;
                    }

                    $dayNo = Carbon::parse($date)->dayOfWeekIso;                       // 1..5
                    $isYesterday = $date === $yesterday;

                    match (true) {
                        $isYesterday && $index % 7 === 2 => $punch(TimePunch::IN, $start - 4),          // went home without clocking out
                        ! $isYesterday && $index % 9 === 5 && $dayNo === 2 => null,                      // never touched the clock that day
                        $index % 3 === 0 && $index % 5 === $dayNo % 5 => $this->fullDay($punch, $start + 14 + $index % 9, $end - 1), // late, against the roster
                        $index % 7 === 3 && $dayNo === 4 => $this->fullDay($punch, $start - 2, $end, correctedOut: true), // supervisor typed the out
                        default => $this->fullDay($punch, $start - ($index * 3) % 7, $end - ($index * 5) % 9),
                    };

                    continue;
                }

                // Today, only when nothing has punched it yet, and only once a shift has started.
                if ($todayTaken || $start > $minuteNow) {
                    continue;
                }

                match (true) {
                    $index % 11 === 4 => null,                                                      // never came in
                    $end <= $minuteNow => $this->fullDay($punch, $start - ($index * 3) % 7, $end - ($index * 5) % 9), // shift over: done for today
                    $index % 11 === 6 && $minuteNow > $start + 90 => $this->fullDay($punch, $start - 3, min($end, $minuteNow - 40)), // gone home early
                    $index % 11 === 2 => $punch(TimePunch::IN, $start + 19),                        // on shift, late
                    default => $punch(TimePunch::IN, $start - ($index * 3) % 7),                    // on shift
                };
            }
        }

        $this->command?->info("Punched {$punched} times for {$teachers->count()} staff, ".$lastMonday->format('M j').' – '.$now->format('M j').'.');
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
