<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DoorAttendance;
use App\Models\DoorHealthAudit;
use App\Models\HealthAudit;
use App\Models\ScheduleSlot;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The first half of October 2026, 1st to 17th, on both records.
 *
 * Director Attendance (attendances) and Teacher Attendance (door_attendances)
 * are separate tables that do not sync, so each is written on its own and
 * they are made to disagree a little, as the real ones do: the register's
 * sign-in is the teacher's round number, the door's clock is the minute the
 * child came through, and now and then one record has a day the other has
 * not.
 *
 * Who comes on which day follows the week schedule where a slot is ticked,
 * and a fixed per-child pattern where the week was never built. Weekends and
 * closures are skipped, as are children not yet enrolled or already
 * withdrawn on the day. Days after today are left alone.
 *
 * Re-runnable: rows are matched on child, day and session and updated in
 * place, so a second run reproduces the same fortnight rather than doubling
 * it, and comments already hanging off a door row stay where they are.
 *
 *   php artisan db:seed --class=OctoberAttendanceSeeder
 *   SEED_FROM=2026-10-01 SEED_TO=2026-10-17 php artisan db:seed --class=OctoberAttendanceSeeder
 */
class OctoberAttendanceSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Weekday offsets (0 = Monday) a child without a built week attends. */
    private const PATTERNS = [
        [0, 1, 2, 3, 4],
        [0, 2, 4],
        [1, 3],
        [0, 1, 2, 3],
        [0, 1, 2, 3, 4],
    ];

    /** Codes a sick arrival is given, in turn: fever, respiratory, diarrhea, rash. */
    private const SICK_CODES = [4, 7, 3, 6];

    public function run(): void
    {
        $from = Carbon::parse(env('SEED_FROM', '2026-10-01'))->startOfDay();
        $to = Carbon::parse(env('SEED_TO', '2026-10-17'))->startOfDay()->min(today());

        $children = Child::where('status', 'Active')->orderBy('id')->get();

        if ($children->isEmpty()) {
            $this->command?->warn('No active children on file — nothing to seed.');

            return;
        }

        $closed = ClosureDay::whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])
            ->pluck('closed_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $teachers = User::where('role', 'teacher')->orderBy('id')->get();
        $slots = ScheduleSlot::whereBetween('slot_date', [$from->toDateString(), $to->toDateString()])
            ->where('is_scheduled', true)
            ->get()
            ->groupBy(fn (ScheduleSlot $slot) => $slot->child_id.'|'.$slot->slot_date->toDateString());

        $register = $door = 0;

        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $iso = $day->toDateString();

            if ($day->isWeekend() || in_array($iso, $closed, true)) {
                continue;
            }

            foreach ($children as $index => $child) {
                if (! $child->isEnrolledOn($iso)) {
                    continue;
                }

                foreach ($this->sessionsOn($child, $index, $day, $slots) as $session) {
                    // Roughly one day in eight nobody came: absent on both.
                    if (($child->id + $day->dayOfYear) % 8 === 0) {
                        continue;
                    }

                    $arrival = $this->arrival($child, $day, $session);
                    $leaving = $this->leaving($child, $day, $session);
                    // Today: whoever arrives after now has not arrived, and
                    // nobody has gone home yet.
                    if ($day->isToday()) {
                        if ($arrival->isFuture()) {
                            continue;
                        }
                        $leaving = null;
                    }

                    // The register misses a day the door has, one in eleven,
                    // and the door misses one the register has, one in thirteen.
                    $registerSkips = ($child->id * 3 + $day->day) % 11 === 0;
                    $doorSkips = ($child->id * 5 + $day->day) % 13 === 0;

                    if (! $registerSkips) {
                        $this->onRegister($child, $iso, $session, $arrival, $leaving);
                        $register++;
                    }

                    if (! $doorSkips) {
                        $this->atDoor($child, $iso, $session, $arrival, $leaving, $teachers);
                        $door++;
                    }
                }
            }
        }

        $this->command?->info(sprintf(
            '%s – %s: %d register rows, %d door rows.',
            $from->format('j M'), $to->format('j M Y'), $register, $door,
        ));
    }

    /**
     * The sessions a child attends on a day: the week schedule's where it was
     * built, a fixed pattern where it was not.
     *
     * @return list<string>
     */
    private function sessionsOn(Child $child, int $index, Carbon $day, $slots): array
    {
        $ticked = $slots->get($child->id.'|'.$day->toDateString());

        if ($ticked !== null) {
            return $ticked->pluck('session')->unique()->values()->all();
        }

        $pattern = self::PATTERNS[$index % count(self::PATTERNS)];

        if (! in_array($day->dayOfWeekIso - 1, $pattern, true)) {
            return [];
        }

        $sessions = $child->sessions();

        // Half the School Age children go home after the morning on Fridays.
        if ($day->isFriday() && $child->id % 2 === 0) {
            $sessions = array_values(array_diff($sessions, ['PM']));
        }

        return $sessions;
    }

    /** The minute the child came through the door. */
    private function arrival(Child $child, Carbon $day, string $session): Carbon
    {
        $minutes = ($child->id * 7 + $day->day * 3) % 80;

        return match ($session) {
            'PM' => $day->copy()->setTime(12, 15)->addMinutes($minutes % 50),
            default => $day->copy()->setTime(7, 10)->addMinutes($minutes),
        };
    }

    /** The minute the child went home. */
    private function leaving(Child $child, Carbon $day, string $session): Carbon
    {
        $minutes = ($child->id * 11 + $day->day * 5) % 90;

        return match ($session) {
            'AM' => $day->copy()->setTime(11, 30)->addMinutes($minutes % 30),
            default => $day->copy()->setTime(16, 15)->addMinutes($minutes),
        };
    }

    /**
     * Director Attendance: the sheet the director keeps. Times to the
     * quarter hour, as somebody writing them down from across the room
     * does, and no health checks — the register never asked for them.
     */
    private function onRegister(Child $child, string $iso, string $session, Carbon $arrival, ?Carbon $leaving): void
    {
        Attendance::updateOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $iso, 'session' => $session],
            [
                'signed_in_at' => self::quarterHour($arrival),
                'signed_out_at' => $leaving ? self::quarterHour($leaving) : null,
            ],
        );
    }

    /**
     * Teacher Attendance: the door's clock, to the minute, with the arrival
     * and leaving checks the teacher took and who took them. One child in
     * fourteen arrives with a symptom; one in ten leaves with one; one in
     * seventeen went out and came back during the day.
     */
    private function atDoor(Child $child, string $iso, string $session, Carbon $arrival, ?Carbon $leaving, $teachers): void
    {
        $inCode = ($child->id + (int) substr($iso, -2)) % 14 === 0
            ? self::SICK_CODES[$child->id % count(self::SICK_CODES)]
            : 0;
        $outCode = $leaving === null ? null : ((($child->id * 2 + (int) substr($iso, -2)) % 10 === 0) ? 4 : 0);

        $row = DoorAttendance::updateOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $iso, 'session' => $session],
            [
                'signed_in_at' => $arrival,
                'signed_out_at' => $leaving,
                'health_in_code' => $inCode,
                'health_in_note' => $inCode === 4 ? 'Warm to the touch, 38.1' : null,
                'health_out_code' => $outCode,
                'health_out_note' => $outCode === 4 ? 'Fever came on after nap' : null,
            ],
        );

        $teacher = $teachers->isEmpty() ? null : $teachers[$child->id % $teachers->count()];

        // The audit trail the door writes with every check, remade for the row.
        DoorHealthAudit::where('attendance_id', $row->id)->delete();
        DoorHealthAudit::create([
            'attendance_id' => $row->id,
            'direction' => HealthAudit::IN,
            'old_code' => null,
            'new_code' => $inCode,
            'note' => $row->health_in_note,
            'user_id' => $teacher?->id,
        ])->forceFill(['created_at' => $arrival])->save();

        if ($leaving !== null) {
            DoorHealthAudit::create([
                'attendance_id' => $row->id,
                'direction' => HealthAudit::OUT,
                'old_code' => null,
                'new_code' => $outCode,
                'note' => $row->health_out_note,
                'user_id' => $teacher?->id,
            ])->forceFill(['created_at' => $leaving])->save();
        }

        // Out for an appointment and back: one trip, mid-morning, on a full day.
        $row->returns()->delete();
        if ($session === 'FULL' && $leaving !== null && ($child->id + (int) substr($iso, -2)) % 17 === 0) {
            $row->returns()->create([
                'left_at' => $arrival->copy()->setTime(10, 0),
                'returned_at' => $arrival->copy()->setTime(11, 20),
                'performed_by' => $teacher?->id,
            ]);
        }
    }

    private static function quarterHour(Carbon $time): Carbon
    {
        return $time->copy()->setTime($time->hour, intdiv($time->minute, 15) * 15);
    }
}
