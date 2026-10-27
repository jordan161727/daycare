<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
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

    public function for(User $user, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $today = $now->toDateString();
        $timezone = config('app.timezone');

        $roll = Child::visibleTo($user)->where('status', 'Active')->get()
            ->filter(fn (Child $child) => $child->isEnrolledOn($today))
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

        $states = [];
        foreach ($roll as $child) {
            $states[$child->id] = $this->stateOf($child, $rows[$child->id] ?? collect(), isset($scheduled[$child->id]), $planned[$child->id] ?? collect(), $rooms, $now, $timezone);
        }
        $counts = array_count_values($states) + ['in' => 0, 'returning' => 0, 'out' => 0, 'expected' => 0, 'absent' => 0, 'none' => 0];

        $staff = $this->staffOnTheFloor($today, $now, $timezone);

        return [
            'now' => $now,
            'enrolled' => $roll->count(),
            'scheduled' => count($scheduled),
            'in' => $counts['in'],
            'returning' => $counts['returning'],
            'out' => $counts['out'],
            'expected' => $counts['expected'],
            'absent' => $counts['absent'],
            'arrived' => $counts['in'] + $counts['returning'] + $counts['out'],
            'staffOnFloor' => $staff->count(),
            'ratios' => $this->ratios($roll, $states, $staff, $planned, $today, $timezone),
            'states' => $states,
        ];
    }

    /** Who is booked today: the week's ticks, or the roll's day pattern before a week is opened. */
    private function scheduledToday(Collection $roll, string $today): array
    {
        $ticked = ScheduleSlot::whereDate('slot_date', $today)->where('is_scheduled', true)
            ->whereIn('child_id', $roll->pluck('id'))
            ->distinct()->pluck('child_id');

        if ($ticked->isNotEmpty()) {
            return array_fill_keys($ticked->all(), true);
        }

        $weekday = Carbon::parse($today)->dayOfWeekIso;

        return $roll->filter(fn (Child $child) => in_array($weekday, $child->scheduleDays() ?? [], true))
            ->pluck('id')->flip()->map(fn () => true)->all();
    }

    private function stateOf(Child $child, Collection $rows, bool $scheduled, Collection $slots, Collection $rooms, Carbon $now, string $timezone): string
    {
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

            $out[] = [
                'room' => $room,
                'children' => $in,
                'staff' => $here,
                'needed' => $needed,
                'ratio' => $ratio,
                'status' => $status[0],
                'tone' => $status[1],
                'note' => implode(' · ', $notes),
            ];
        }

        return $out;
    }
}
