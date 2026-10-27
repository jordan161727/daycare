<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\StaffShift;
use App\Models\StaffScheduleWeek;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Today, arranged as the floor-plan mock-up shows it, for looking at the
 * dashboard and the floor plan with something in them.
 *
 * Every time is set relative to now, so the picture is the same whenever it
 * is run: the morning's arrivals a few hours ago, Sultana's shift started an
 * hour ago with no clock-in, School Age back in four and a half hours, a
 * few children due in half an hour and a few two hours overdue.
 *
 *   Infant      6 in of 8  · Evana, Farhana in                 → Covered
 *   Transition  3 in       · Zameera in                        → Covered
 *   Toddler    10 in       · Mary Jo in, Sultana late          → Short 1
 *   PreK        7 in       · Denise, LaQuora in                → 1 spare
 *   UPK-4      17 in       · Frenci, Beverly, Kalia in         → Covered
 *   School Age  6 away     · Amanda due 2:30, back at 3:00     → Away
 *
 * It uses the children already on the roll, room by room, so the exact
 * counts follow the roll. Today's rows for those children, and today's
 * punches and shifts for the demo staff, are rewritten each run — this is
 * demo data on a demo day, not a record.
 *
 *     php artisan db:seed --class=FloorPlanDemoSeeder --force
 */
class FloorPlanDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Who is in which room, and how they stand, as the mock has it. */
    private const STAFF = [
        // name, room, in (hours ago, null = not in), shift start (hours from now), shift end (hours from now)
        ['Evana Rahman', 'Infant', 3.0, -3.5, 4.5],
        ['Farhana Begum', 'Infant', 2.5, -3.0, 5.0],
        ['Zameera Khan', 'Transition', 3.0, -3.5, 4.5],
        ['Mary Jo Carter', 'Toddler', 3.0, -3.5, 4.5],
        ['Sultana Ahmed', 'Toddler', null, -1.0, 7.0],
        ['Denise Hall', 'PreK', 2.75, -3.0, 5.0],
        ['LaQuora Smith', 'PreK', 2.5, -2.5, 5.5],
        ['Frenci Lopez', 'UPK-4', 3.0, -3.5, 4.5],
        ['Beverly Ng', 'UPK-4', 2.75, -3.0, 5.0],
        ['Kalia Brooks', 'UPK-4', 2.0, -2.0, 6.0],
        ['Amanda Cole', 'School Age', null, 4.0, 7.5],
    ];

    /** How many of each room's children are in; the rest are expected or absent. */
    private const IN = ['Infant' => 6, 'Transition' => 3, 'Toddler' => 10, 'PreK' => 7, 'UPK-4' => 17];

    public function run(): void
    {
        $tz = config('app.timezone');
        $now = now()->timezone($tz);
        $today = $now->toDateString();
        $weekStart = ScheduleWeek::startOf($today);
        $returnAt = $now->copy()->addMinutes(270)->format('H:i');   // "back at 3:00" on the mock's clock

        $this->command?->info("Arranging {$now->format('l, M j g:i A')} as the floor-plan mock-up…");

        $roll = Child::where('status', 'Active')->get()->filter(fn (Child $c) => $c->isEnrolledOn($today));
        if ($roll->isEmpty()) {
            $this->command?->error('No active children on the roll. Run DemoScenarioSeeder or import children first.');

            return;
        }

        // A clean day for these children: today's rows go, today's ticks are rewritten.
        Attendance::whereDate('attendance_date', $today)->whereIn('child_id', $roll->pluck('id'))->delete();
        ScheduleSlot::whereDate('slot_date', $today)->whereIn('child_id', $roll->pluck('id'))->delete();
        $weekStart = Carbon::parse($weekStart)->toDateString();

        $tick = fn (Child $c, string $session, ?string $planned = null) => ScheduleSlot::create([
            'week_start' => $weekStart, 'child_id' => $c->id, 'slot_date' => $today, 'session' => $session, 'is_scheduled' => true, 'planned_time' => $planned,
        ]);
        $in = $expected = $absent = $away = 0;

        foreach (ClassroomAssignment::rooms() as $room) {
            $children = $roll->filter(fn (Child $c) => $c->classroom === $room)->values();

            if ($room === 'School Age') {
                // At school: the morning done, the afternoon booked for later.
                foreach ($children->take(6) as $i => $c) {
                    $tick($c, 'AM'); $tick($c, 'PM', $returnAt);
                    Attendance::create(['child_id' => $c->id, 'attendance_date' => $today, 'session' => 'AM',
                        'signed_in_at' => $now->copy()->subMinutes(200 + $i * 3), 'signed_out_at' => $now->copy()->subMinutes(130), 'health_in_code' => 0, 'health_out_code' => 0]);
                    $away++;
                }

                continue;
            }

            $target = self::IN[$room] ?? 0;
            foreach ($children as $i => $c) {
                if ($i < $target) {
                    $tick($c, 'FULL');
                    Attendance::create(['child_id' => $c->id, 'attendance_date' => $today, 'session' => 'FULL',
                        'signed_in_at' => $now->copy()->subMinutes(180 - $i * 7), 'health_in_code' => [0, 0, 0, 0, 3, 0, 2, 0][$i % 8]]);
                    $in++;
                } elseif ($i - $target < 2) {
                    // Due in half an hour: expected.
                    $tick($c, 'FULL', $now->copy()->addMinutes(30)->format('H:i'));
                    $expected++;
                } else {
                    // Booked two hours ago and no sign of them: absent.
                    $tick($c, 'FULL', $now->copy()->subMinutes(120)->format('H:i'));
                    $absent++;
                }
            }
        }

        // Staff: the demo adults, each with today's shift and, mostly, a clock-in.
        $staffWeek = StaffScheduleWeek::startOf($today);
        $minute = fn (float $hoursFromNow) => max(0, min(24 * 60 - 1, (int) round(($now->hour * 60 + $now->minute) + $hoursFromNow * 60)));
        foreach (self::STAFF as [$name, $room, $inHoursAgo, $start, $end]) {
            $user = User::firstOrCreate(['email' => Str::slug($name, '.').'@daycare.test'], [
                'name' => $name, 'password' => Hash::make('password'), 'role' => 'teacher', 'classroom' => $room, 'employment' => 'FT', 'title' => $room,
            ]);
            $user->forceFill(['classroom' => $room])->save();

            StaffShift::whereDate('shift_date', $today)->where('user_id', $user->id)->delete();
            StaffShift::create(['week_start' => $staffWeek, 'user_id' => $user->id, 'shift_date' => $today, 'day' => $now->dayOfWeekIso,
                'starts_at' => $minute($start), 'ends_at' => $minute($end), 'classroom' => $room, 'role' => 'teacher']);

            TimePunch::where('work_date', $today)->where('user_id', $user->id)->delete();
            if ($inHoursAgo !== null) {
                TimePunch::create(['user_id' => $user->id, 'work_date' => $today, 'punched_at' => $now->copy()->subMinutes((int) ($inHoursAgo * 60)), 'type' => TimePunch::IN, 'source' => 'kiosk']);
            }
        }

        $this->command?->info("Children: {$in} in, {$away} at school (back at ".Carbon::parse("$today $returnAt", $tz)->format('g:i A')."), {$expected} expected, {$absent} absent.");
        $this->command?->info('Staff: 9 clocked in, Sultana late in Toddler, Amanda due later for School Age.');
        $this->command?->line('Open /floor-plan and /dashboard.');
    }
}
