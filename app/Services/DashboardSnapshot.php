<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\RoomSchedule;
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
 *   expected   — booked, not yet arrived
 *   absent     — booked, not arrived, and well past when they were due
 *
 * There is no "absent" switch on the register yet (that is Phase 3), so
 * absent is read off the clock: an hour past the hour the child was booked
 * in for — their planned time, else their usual drop-off, else the room's
 * opening — and still no sign of them.
 */
class DashboardSnapshot
{
    /** How long past the booked hour before "expected" becomes "absent". */
    public const ABSENT_AFTER_MINUTES = 60;

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
        $rooms = RoomSchedule::byRoom();
        // The word on the day from the register: Absent, or not coming after
        // all. The register and the reports read it ahead of the tick, so the
        // dashboard does too — else a child marked Absent at eight sat on the
        // Expected card until nine.
        $marks = DssDayMark::whereDate('marked_on', $today)->whereIn('child_id', $roll->pluck('id'))
            ->pluck('mark', 'child_id');

        $states = [];
        foreach ($roll as $child) {
            $mark = $marks[$child->id] ?? null;
            $booked = $scheduled[$child->id] ?? false;
            $states[$child->id] = $this->stateOf($child, $rows[$child->id] ?? collect(), $booked, $mark === DssDayMark::ABSENT, $planned[$child->id] ?? collect(), $rooms, $now, $timezone);
        }
        $counts = array_count_values($states) + ['in' => 0, 'returning' => 0, 'out' => 0, 'expected' => 0, 'absent' => 0, 'none' => 0];

        // Scheduled, arrived, expected and absent through the one rule every
        // screen counts by (App\Services\RoomCounts, QA #2): scheduled is
        // everyone the day was for, a walk-in included, so arrived + expected
        // + absent is always scheduled.
        $tally = RoomCounts::tally(array_map(fn (string $state) => RoomCounts::child(
            booked: $state !== 'none',
            arrived: in_array($state, ['in', 'returning', 'out'], true),
            absent: $state === 'absent',
        ), $states));

        $staff = $this->staffOnTheFloor($today, $now, $timezone);

        return [
            'now' => $now,
            'enrolled' => $roll->count(),
            'scheduled' => $tally['expected'],
            'in' => $counts['in'],
            'returning' => $counts['returning'],
            'out' => $counts['out'],
            'expected' => $tally['still_to_come'],
            'absent' => $tally['absent'],
            'arrived' => $tally['in'],
            'staffOnFloor' => $staff->count(),
            'ratios' => $this->ratios($roll, $states, $staff, $planned, $today, $timezone),
            'states' => $states,
        ];
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

    private function stateOf(Child $child, Collection $rows, bool $scheduled, bool $markedAbsent, Collection $slots, Collection $rooms, Carbon $now, string $timezone): string
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

        if (! $scheduled) {
            return 'none';
        }

        $due = $this->dueAt($child, $slots, $rooms, $now, $timezone);

        return $due !== null && $now->greaterThan($due->copy()->addMinutes(self::ABSENT_AFTER_MINUTES)) ? 'absent' : 'expected';
    }

    /** The hour the child was booked in for today, if anything says. */
    private function dueAt(Child $child, Collection $slots, Collection $rooms, Carbon $now, string $timezone): ?Carbon
    {
        $time = $slots->pluck('planned_time')->filter()->sort()->first()
            ?: $child->drop_off_time
            ?: ($rooms[$child->classroom]->opens_at ?? null);

        if (blank($time)) {
            return null;
        }

        return Carbon::parse($now->toDateString().' '.substr((string) $time, 0, 5), $timezone);
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
        $shifts = StaffShift::whereDate('shift_date', $today)->get()->groupBy('user_id');

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
    private function ratios(Collection $roll, array $states, Collection $staff, Collection $planned, string $today, string $timezone): array
    {
        $rostered = StaffShift::with('user')->whereDate('shift_date', $today)->get()->groupBy('classroom');
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

            // More enrolled or expected than places: warned, never blocked (QA, 16 Nov 2026).
            $expectedHere = RoomCounts::tally($children->map(fn (Child $child) => RoomCounts::child(
                booked: ($states[$child->id] ?? 'none') !== 'none',
                arrived: in_array($states[$child->id] ?? 'none', ['in', 'returning', 'out'], true),
                absent: ($states[$child->id] ?? 'none') === 'absent',
            )))['expected'];

            $out[] = [
                'room' => $room,
                'capacity' => $this->centre->capacity($room),
                'enrolled' => $children->count(),
                'expected' => $expectedHere,
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
