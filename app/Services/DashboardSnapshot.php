<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dashboard's numbers, worked out once from the day as it stands.
 *
 * Read-only over what the other screens write — the roll, the week's ticks,
 * today's arrivals and departures, the staff clock and the roster — so the
 * dashboard never has a number of its own that could disagree with them.
 * Phase 4.1 of docs/work.md: the seven cards, the ratios room by room, the
 * progress bar.
 *
 * Every child on today's floor is in exactly one of five places:
 *
 *   in         — clocked in, not out
 *   returning  — clocked out, but with a later session still booked today
 *                (a School Age child at school, back after three)
 *   out        — clocked out for the day
 *   expected   — booked, not yet arrived ("still to come" on screen)
 *   absent     — marked Absent on the register, and not arrived
 *
 * Absent is never read off the clock (QA, 17 Nov 2026: at nine the
 * dashboard said "Absent 70" while the register said 0, because a child
 * with no agreed hours was taken as due when the room opened and called
 * absent an hour later). A child more than an hour past an agreed time is
 * still to come and counted LATE beside it — RoomCounts::isLate, the one
 * rule.
 */
class DashboardSnapshot
{
    public function __construct(private TimeClock $clock, private CentreSettings $centre) {}

    /**
     * @param  bool  $wholeBuilding  every room, whoever is asking — the floor
     *                               plan is the building's picture, so a teacher
     *                               sees the same counts as the director (QA J1).
     *                               It carries counts, never children's names.
     */
    public function for(User $user, ?Carbon $now = null, bool $wholeBuilding = false): array
    {
        // The centre's clock, whatever clock the caller handed in: "today"
        // and every "an hour past due" are New York's, not the server's or
        // the reader's (QA #4, 12 Nov 2026).
        $timezone = config('app.timezone');
        $now = ($now ?? now())->copy()->timezone($timezone);
        $today = $now->toDateString();

        // On the roll today, by the one definition (RoomCounts::isEnrolled).
        $roll = ($wholeBuilding ? Child::query() : Child::visibleTo($user))->where('status', 'Active')->get()
            ->filter(fn (Child $child) => RoomCounts::isEnrolled($child, $today))
            ->values();
        $byId = $roll->keyBy('id');

        $scheduled = $this->scheduledToday($roll, $today);
        $rows = Attendance::whereDate('attendance_date', $today)
            ->whereIn('child_id', $roll->pluck('id'))
            ->whereNotNull('signed_in_at')
            ->get()
            ->groupBy('child_id');
        $planned = ScheduleSlot::whereDate('slot_date', $today)->where('is_scheduled', true)
            ->whereIn('child_id', $roll->pluck('id'))
            ->get()
            ->groupBy('child_id');
        // The word on the day from the register: Absent, or not coming after
        // all. The register and the reports read it ahead of the tick, so the
        // dashboard does too — else a child marked Absent at eight sat on the
        // Expected card until nine.
        $marks = DssDayMark::whereDate('marked_on', $today)->whereIn('child_id', $roll->pluck('id'))
            ->pluck('mark', 'child_id');

        $states = [];
        $late = [];
        foreach ($roll as $child) {
            $mark = $marks[$child->id] ?? null;
            $booked = $scheduled[$child->id] ?? false;
            $states[$child->id] = $this->stateOf($child, $rows[$child->id] ?? collect(), $booked, $mark === DssDayMark::ABSENT);
            $late[$child->id] = $states[$child->id] === 'expected' && RoomCounts::isLate($child, $planned[$child->id] ?? [], $now);
        }
        $counts = array_count_values($states) + ['in' => 0, 'returning' => 0, 'out' => 0, 'expected' => 0, 'absent' => 0, 'none' => 0];

        // Booked, arrived, still to come and absent through the one rule every
        // screen counts by (App\Services\RoomCounts, QA #2): booked today is
        // everyone the day was for, a walk-in included, so arrived + still to
        // come + absent is always booked today.
        $tally = RoomCounts::tally(array_map(fn (int $id) => $this->tallied($states[$id], $late[$id]), array_keys($states)));

        $staff = $this->staffOnTheFloor($today, $now, $timezone);
        $ratios = $this->ratios($roll, $states, $late, $staff, $planned, $today, $timezone);

        return [
            'now' => $now,
            'enrolled' => $roll->count(),
            // Booked today — RoomCounts' "expected" (the key keeps its old name).
            'scheduled' => $tally['expected'],
            'in' => $counts['in'],
            'returning' => $counts['returning'],
            'out' => $counts['out'],
            // Still to come — booked, not arrived, not marked Absent.
            'expected' => $tally['still_to_come'],
            // Of those, more than an hour past an agreed time (RoomCounts::isLate).
            'late' => $tally['late'],
            'absent' => $tally['absent'],
            'arrived' => $tally['in'],
            'staffOnFloor' => $staff->count(),
            'ratios' => $ratios,
            // Rooms whose roster today cannot cover the children booked in
            // them (QA, 17 Nov 2026: no week built, every teacher "Off", 74
            // booked, and "Rooms below ratio: 0" because nobody was in yet).
            'scheduleShort' => collect($ratios)->filter(fn (array $r) => $r['scheduleWarning'] !== null)->count(),
            'states' => $states,
            'late_ids' => array_keys(array_filter($late)),
        ];
    }

    /** One child's state as RoomCounts::tally reads it. */
    private function tallied(string $state, bool $late = false): array
    {
        return RoomCounts::child(
            booked: $state !== 'none',
            arrived: in_array($state, ['in', 'returning', 'out'], true),
            absent: $state === 'absent',
            late: $late,
        );
    }

    /**
     * Ahead of the day: a room with children booked today and too few staff
     * on today's roster to take them at the room's ratio — none at all, or
     * fewer than the bookings would need if they were all in at once. Null
     * when the roster covers it or nobody is booked.
     */
    public static function scheduleWarning(int $booked, int $rostered, int $ratio): ?string
    {
        if ($booked <= 0 || $ratio <= 0) {
            return null;
        }

        $need = (int) ceil($booked / $ratio);

        return match (true) {
            $rostered === 0 => "No staff scheduled today for {$booked} booked — needs {$need} at 1:{$ratio}",
            $rostered < $need => "{$rostered} of {$need} staff scheduled today for {$booked} booked at 1:{$ratio}",
            default => null,
        };
    }

    /**
     * Who is booked today — RoomCounts::bookedOn, the one rule. It used to
     * be the week's ticks if anybody had any today, else everybody's
     * registered days: so opening the register for the first time on a
     * Monday moved "Scheduled today" from 110 to 114 at 2:18 in the
     * morning with nothing else changed (QA, 16 Nov 2026).
     */
    private function scheduledToday(Collection $roll, string $today): array
    {
        return RoomCounts::bookedOn($roll, $today);
    }

    private function stateOf(Child $child, Collection $rows, bool $scheduled, bool $markedAbsent): string
    {
        if ($rows->isEmpty() && $markedAbsent) {
            return 'absent';
        }

        if ($rows->contains(fn (Attendance $row) => $row->signed_out_at === null)) {
            return 'in';
        }

        if ($rows->isNotEmpty()) {
            // Out, but with a session still to come: at school, back later.
            $done = $rows->pluck('session')->map(fn ($s) => $s ?? 'FULL')->all();
            $later = array_diff($child->sessions(), $done);

            return ($scheduled && $later !== []) ? 'returning' : 'out';
        }

        return $scheduled ? 'expected' : 'none';
    }

    /**
     * Staff clocked in right now, with the room each is in: the roster's
     * room for the shift covering this minute, else their home room.
     *
     * @return Collection<int, array{user: User, room: ?string}>
     */
    private function staffOnTheFloor(string $today, Carbon $now, string $timezone): Collection
    {
        $minute = $now->copy()->timezone($timezone)->hour * 60 + $now->copy()->timezone($timezone)->minute;
        $shifts = StaffShift::live()->whereDate('shift_date', $today)->get()->groupBy('user_id');

        $presence = app(StaffPresence::class);

        return User::teachers()->get()
            ->filter(fn (User $user) => $this->clock->state($user, $today) === TimeClock::WORKING)
            ->map(function (User $user) use ($shifts, $minute, $presence) {
                // Where the kiosks say they are, first (HANDOFF §5.1: only
                // working_in_room counts). An open check-in names the room, or
                // the office, which counts nowhere. Somebody on the clock who
                // was never placed — the app's own clock, a typed punch — is
                // read off the roster as before, so a centre without room
                // tablets yet sees the same dashboard it always did.
                $status = $presence->status($user);

                if ($status['placed']) {
                    return ['user' => $user, 'room' => $status['room']];
                }

                $shift = ($shifts[$user->id] ?? collect())->first(fn (StaffShift $shift) => $shift->covers($minute))
                    ?? ($shifts[$user->id] ?? collect())->first();

                return ['user' => $user, 'room' => $shift?->classroom ?: $user->classroom];
            })
            ->values();
    }

    /**
     * Room by room: children in, staff in, what the ratio asks for, and a
     * word on where that leaves the room.
     */
    private function ratios(Collection $roll, array $states, array $late, Collection $staff, Collection $planned, string $today, string $timezone): array
    {
        $rostered = StaffShift::live()->with('user')->whereDate('shift_date', $today)->get()->groupBy('classroom');
        $out = [];

        foreach (ClassroomAssignment::rooms() as $room) {
            $children = $roll->filter(fn (Child $child) => $child->classroom === $room);
            $in = $children->filter(fn (Child $child) => ($states[$child->id] ?? 'none') === 'in')->count();
            $returning = $children->filter(fn (Child $child) => ($states[$child->id] ?? 'none') === 'returning');
            $here = $staff->filter(fn (array $s) => $s['room'] === $room)->count();
            $ratio = $this->centre->ratio($room);
            $needed = ($ratio > 0 && $in > 0) ? (int) ceil($in / $ratio) : 0;

            $status = match (true) {
                $in === 0 && $here === 0 => ['Empty', 'empty'],
                $here < $needed => ['Short '.($needed - $here), 'short'],
                $here > $needed && $needed > 0 => [($here - $needed).' spare', 'spare'],
                default => ['Covered', 'covered'],
            };

            // The note under the numbers: what the ratio asks for, who is
            // rostered here but not in, and who is coming back.
            $notes = [];
            if ($needed > 0) {
                $notes[] = "Needs {$needed} at 1:{$ratio}";
            }
            $missing = ($rostered[$room] ?? collect())
                ->pluck('user')->filter()->unique('id')
                ->reject(fn (User $user) => $staff->contains(fn (array $s) => $s['user']->id === $user->id))
                ->map(fn (User $user) => explode(' ', trim($user->name))[0]);
            if ($status[1] === 'short' && $missing->isNotEmpty()) {
                $notes[] = $missing->implode(', ').' not in';
            }
            if ($returning->isNotEmpty()) {
                $back = $returning->flatMap(fn (Child $child) => ($planned[$child->id] ?? collect())->pluck('planned_time'))->filter()->sort()->first();
                $notes[] = $returning->count().' checked out · returning'.($back ? ' at '.Carbon::parse($today.' '.substr((string) $back, 0, 5), $timezone)->format('g:i') : ' later today');
            }

            // More enrolled or booked than places: warned, never blocked (QA, 16 Nov 2026).
            $day = RoomCounts::tally($children->map(fn (Child $child) => $this->tallied($states[$child->id] ?? 'none', $late[$child->id] ?? false)));
            $expectedHere = $day['expected'];
            $rosteredHere = ($rostered[$room] ?? collect())->pluck('user_id')->unique()->count();

            $out[] = [
                'room' => $room,
                'capacity' => $this->centre->capacity($room),
                'enrolled' => $children->count(),
                // Booked today and still to come, by RoomCounts — the floor plan's figures.
                'expected' => $expectedHere,
                'stillToCome' => $day['still_to_come'],
                'late' => $day['late'],
                'rostered' => $rosteredHere,
                'scheduleWarning' => self::scheduleWarning($expectedHere, $rosteredHere, $ratio),
                'capacityWarning' => RoomCounts::capacityWarning($this->centre->capacity($room), $children->count(), $expectedHere),
                'children' => $in,
                'staff' => $here,
                'needed' => $needed,
                'ratio' => $ratio,
                'status' => $status[0],
                'tone' => $status[1],
                'note' => implode(' · ', $notes),
            ];
        }

        // Places are checked by physical space: UPK-4 and School Age share
        // one room, so they are added together against its places and carry
        // one warning between them — the page shows it once (QA retest, 16
        // Nov 2026). Each class keeps its own ratio above.
        $warnings = RoomCounts::capacityWarnings(collect($out)->keyBy('room')->all(), $this->centre);
        $said = [];
        foreach ($out as $i => $row) {
            $warning = $warnings[$row['room']] ?? null;
            $out[$i]['capacityWarning'] = $warning !== null && ! in_array($warning, $said, true) ? $warning : null;
            $said[] = $warning;
        }

        return $out;
    }
}
