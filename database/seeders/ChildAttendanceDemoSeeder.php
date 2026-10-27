<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DoorAttendance;
use App\Models\DoorHealthAudit;
use App\Models\DssDayMark;
use App\Models\HealthAudit;
use App\Models\ScheduleSlot;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Every child, every room, every day gone: the attendance the reports read.
 *
 * From the first of last month to yesterday by default (SEED_FROM / SEED_TO
 * take any range; days after yesterday are never touched, so today's live
 * picture stays as the floor-plan demo left it). Director Attendance
 * (attendances) and Teacher Attendance (door_attendances) are separate
 * tables that do not sync, so each is written on its own and they are made
 * to disagree a little, as the real ones do: the register's sign-in is the
 * teacher's round number, the door's clock is the minute the child came
 * through, and now and then one record has a day the other has not.
 *
 * Who comes on which day follows the week schedule where a slot is ticked,
 * else the child's own booked days (the same days the Attendance sheet
 * expects them), else every weekday. Weekends and closures are skipped, as
 * are children not yet enrolled or already withdrawn on the day. About one
 * booked day in eight nobody came: that day is marked Absent on the DSS
 * page, so the sheet's A is a deliberate one; the register's one-in-eleven
 * missed sign-in is the kind the monthly email's hold is there to catch.
 *
 * Re-runnable: rows are matched on child, day and session and updated in
 * place, so a second run reproduces the same days rather than doubling
 * them, and comments already hanging off a door row stay where they are.
 *
 *   php artisan db:seed --class=ChildAttendanceDemoSeeder --force
 *   SEED_FROM=2026-09-01 SEED_TO=2026-09-30 php artisan db:seed --class=ChildAttendanceDemoSeeder --force
 */
class ChildAttendanceDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Codes a sick arrival is given, in turn: fever, respiratory, diarrhea, rash. */
    private const SICK_CODES = [4, 7, 3, 6];

    protected function defaultFrom(): string
    {
        return today()->subMonthNoOverflow()->startOfMonth()->toDateString();
    }

    protected function defaultTo(): string
    {
        return today()->subDay()->toDateString();
    }

    public function run(): void
    {
        $from = Carbon::parse(env('SEED_FROM', $this->defaultFrom()))->startOfDay();
        $to = Carbon::parse(env('SEED_TO', $this->defaultTo()))->startOfDay()->min(today()->subDay());

        // Active children, and anyone withdrawn since the range began — the
        // sheet still lists them for the days before they left.
        $children = Child::where(fn ($q) => $q->where('status', 'Active')->orWhereDate('withdrawn_on', '>=', $from->toDateString()))
            ->orderBy('id')->get();

        if ($children->isEmpty()) {
            $this->command?->warn('No active children on file — nothing to seed.');

            return;
        }

        $closed = ClosureDay::whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])
            ->pluck('closed_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $teachers = User::where('role', 'teacher')->orderBy('id')->get();
        $director = User::where('role', 'admin')->orderBy('id')->first();
        $slots = ScheduleSlot::whereBetween('slot_date', [$from->toDateString(), $to->toDateString()])
            ->where('is_scheduled', true)
            ->get()
            ->groupBy(fn (ScheduleSlot $slot) => $slot->child_id.'|'.$slot->slot_date->toDateString());

        $register = $door = $absent = 0;

        for ($day = $from->copy(); $day <= $to; $day->addDay()) {
            $iso = $day->toDateString();

            if ($day->isWeekend() || in_array($iso, $closed, true)) {
                continue;
            }

            foreach ($children as $child) {
                if (! $child->isEnrolledOn($iso)) {
                    continue;
                }

                $sessions = $this->sessionsOn($child, $day, $slots);

                if ($sessions === []) {
                    DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $iso)->where('mark', DssDayMark::ABSENT)->delete();

                    continue;
                }

                // Roughly one day in eight nobody came: absent on both, and
                // said so on the DSS page.
                if (($child->id + $day->dayOfYear) % 8 === 0) {
                    Attendance::where('child_id', $child->id)->whereDate('attendance_date', $iso)->delete();
                    DoorAttendance::where('child_id', $child->id)->whereDate('attendance_date', $iso)->delete();
                    DssDayMark::updateOrCreate(
                        ['child_id' => $child->id, 'marked_on' => $iso],
                        ['mark' => DssDayMark::ABSENT, 'created_by' => $director?->id],
                    );
                    $absent++;

                    continue;
                }

                DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $iso)->where('mark', DssDayMark::ABSENT)->delete();

                foreach ($sessions as $session) {
                    $arrival = $this->arrival($child, $day, $session);
                    $leaving = $this->leaving($child, $day, $session);

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
            '%s – %s: %d children, %d register rows, %d door rows, %d absent days marked.',
            $from->format('j M'), $to->format('j M Y'), $children->count(), $register, $door, $absent,
        ));
    }

    /**
     * The sessions a child attends on a day: the week schedule's where it
     * was built, else the child's own booked days, else every weekday.
     *
     * @return list<string>
     */
    private function sessionsOn(Child $child, Carbon $day, $slots): array
    {
        $ticked = $slots->get($child->id.'|'.$day->toDateString());

        if ($ticked !== null) {
            return $ticked->pluck('session')->unique()->values()->all();
        }

        $booked = $child->scheduleDays();
        $booked = $booked === null || $booked === [] ? [1, 2, 3, 4, 5] : $booked;

        if (! in_array($day->dayOfWeekIso, $booked, true)) {
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
