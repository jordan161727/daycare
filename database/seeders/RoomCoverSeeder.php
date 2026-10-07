<?php

namespace Database\Seeders;

use App\Models\Child;
use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\RoomDemand;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Enough staff, with the right rules, for the generated week to cover every
 * room from opening to closing.
 *
 * The first generated week came back with forty-nine things to look at, every
 * one of them the same thing said in a different place: a room with children
 * in it from 7:00 AM to 6:00 PM and one teacher owed forty hours, who cannot
 * be in it for fifty-five. Eleven hours a day at ratio is two shifts a day in
 * every slot — somebody who opens and somebody who closes — so this seeder
 * reads what the roll needs room by room and tops the staff up to that.
 *
 * It is a top-up, not a reset: people already on staff are counted, the room
 * teachers from the base seed are made the openers of their rooms, and only
 * the gap is filled with new people. Running it twice adds nobody.
 */
class RoomCoverSeeder extends Seeder
{
    use WithoutModelEvents;

    private const OPENER = [7 * 60, 15 * 60];    // 7:00 AM – 3:00 PM
    private const CLOSER = [10 * 60, 18 * 60];   // 10:00 AM – 6:00 PM

    /** Plausible names, taken in order; the room goes in the email so they stay unique. */
    private const NAMES = [
        'Alana Fig', 'Beth Jones', 'Jessica Parker', 'Kelly Smith', 'Noor Haddad', 'Priya Raman',
        'Tomas Alvarez', 'Leah Goldberg', 'Marcus Bell', 'Chloe Dubois', 'Yuki Tanaka', 'Fatima Osei',
        'Daniel Kim', 'Rosa Martinez', 'Ingrid Larsen', 'Samuel Okafor', 'Mei Chen', 'Lucas Ferreira',
        'Amara Diallo', 'Hana Kowalski', 'Omar Siddiqui', 'Elena Petrova', 'Jonah Reed', 'Tara Singh',
        'Nadia Hussain', 'Felix Moreau', 'Zoe Campbell', 'Ravi Patel', 'Isla Murray', 'Kofi Mensah',
    ];

    public function run(): void
    {
        $weekStart = StaffScheduleWeek::startOf(now()->toDateString());
        $demand = app(RoomDemand::class)->forWeek($weekStart);
        $ratios = config('daycare.ratios');
        $names = self::NAMES;

        // Who is on the roll in each room, for a week nobody has opened yet:
        // the booked slots say what the week needs, the roll says what a week
        // will need, and before the first week is opened only the roll exists.
        $onRoll = Child::where('status', 'Active')->get()->countBy(fn (Child $child) => $child->classroom ?: '');

        foreach (ClassroomAssignment::rooms() as $room) {
            $booked = collect($demand)->map(fn ($rooms) => collect($rooms[$room] ?? [])->max('children') ?? 0)->max() ?? 0;
            $peak = max((int) $booked, (int) ($onRoll[$room] ?? 0));
            $need = isset($ratios[$room]) ? (int) ceil($peak / $ratios[$room]) : 0;

            if ($need === 0) {
                continue;
            }

            // The room's own teacher from the base seed opens it.
            foreach (User::teachers()->where('title', $room)->whereDoesntHave('staffRules', fn ($q) => $q->where('rule_type', 'FIXED_SHIFT'))->get() as $teacher) {
                $this->fix($teacher, self::OPENER, opens: true);
            }

            $openers = $this->countWith($room, self::OPENER);
            $closers = $this->countWith($room, self::CLOSER);

            for ($i = $openers; $i < $need; $i++) {
                $this->fix($this->person(array_shift($names), $room), self::OPENER, opens: $i === 0);
            }
            for ($i = $closers; $i < $need; $i++) {
                $this->fix($this->person(array_shift($names), $room), self::CLOSER, opens: false);
            }
        }
    }

    /** How many on staff in this room already hold exactly this shift. */
    private function countWith(string $room, array $shift): int
    {
        return User::teachers()->where('title', $room)
            ->whereHas('staffRules', fn ($q) => $q->where('rule_type', 'FIXED_SHIFT')->where('time_1', $shift[0])->where('time_2', $shift[1]))
            ->count();
    }

    private function person(?string $name, string $room): User
    {
        $name ??= 'Staff '.Str::random(4);

        return User::updateOrCreate(
            ['email' => Str::slug($name).'.'.Str::slug($room).'@daycare.test'],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => 'teacher',
                'employment' => 'FT',
                'title' => $room,
                'classroom' => $room,
                'classrooms' => [$room],
            ]
        );
    }

    /** Pin somebody to one shift every day, in their room, owed a full week. */
    private function fix(User $teacher, array $shift, bool $opens): void
    {
        StaffRule::updateOrCreate(
            ['user_id' => $teacher->id, 'rule_type' => 'FIXED_SHIFT', 'day' => 'ALL'],
            ['priority' => 'HARD', 'time_1' => $shift[0], 'time_2' => $shift[1], 'source_note' => 'Set hours so the room is covered '.StaffRule::formatTime($shift[0]).' – '.StaffRule::formatTime($shift[1]).'.']
        );
        StaffRule::firstOrCreate(
            ['user_id' => $teacher->id, 'rule_type' => 'REQUIRED_HOURS'],
            ['priority' => 'HARD', 'number' => 40, 'value_text' => 'WEEKLY']
        );
        StaffRule::firstOrCreate(
            ['user_id' => $teacher->id, 'rule_type' => 'ROOM_PREFERENCE'],
            ['priority' => 'SOFT', 'value_text' => $teacher->title]
        );
        if ($opens) {
            StaffRule::firstOrCreate(
                ['user_id' => $teacher->id, 'rule_type' => 'CAN_OPEN'],
                ['priority' => 'HARD', 'source_note' => 'Keyholder.']
            );
        }
    }
}
