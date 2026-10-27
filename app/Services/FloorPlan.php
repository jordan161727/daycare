<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The building, room by room, right now — Phase 4.2 of docs/work.md.
 *
 * Read-only over the same day DashboardSnapshot reads: who is in which room,
 * how many adults are with them, what the ratio asks for, and what to do
 * about a room that is short. Two views of the day: the morning is live,
 * read off the clock this minute; the afternoon is the day as booked at
 * the hour School Age comes back, so the office can see whether three
 * o'clock is covered before three o'clock.
 */
class FloorPlan
{
    public function __construct(private DashboardSnapshot $snapshot, private TimeClock $clock, private CentreSettings $centre) {}

    public function for(User $user, string $view = 'morning', ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $timezone = config('app.timezone');
        $today = $now->toDateString();
        $afternoon = $view === 'afternoon';

        // The hour the plan is drawn for: this minute, or the hour the
        // afternoon turns — School Age back from school.
        $at = $afternoon
            ? Carbon::parse($today.' '.$this->centre->get('school_age_returns'), $timezone)
            : $now->copy();

        $day = $this->snapshot->for($user, $now);
        $roll = Child::visibleTo($user)->where('status', 'Active')->get()
            ->filter(fn (Child $child) => $child->isEnrolledOn($today))->values();
        $planned = ScheduleSlot::whereDate('slot_date', $today)->where('is_scheduled', true)
            ->whereIn('child_id', $roll->pluck('id'))->get()->groupBy('child_id');

        $staff = $this->staff($today, $at, $timezone, $afternoon);
        $rooms = [];

        foreach (ClassroomAssignment::rooms() as $room) {
            $children = $roll->filter(fn (Child $child) => $child->classroom === $room);
            $state = fn (Child $child) => $day['states'][$child->id] ?? 'none';

            // The afternoon counts the children booked to be here then: the
            // ones in now who are not picked up by then (nothing says they
            // will be), plus the ones away who are booked back.
            $in = $afternoon
                ? $children->filter(fn (Child $c) => in_array($state($c), ['in', 'returning', 'expected'], true))->count()
                : $children->filter(fn (Child $c) => $state($c) === 'in')->count();
            // Booked and not in yet. A child an hour overdue is off the room's
            // seats, as the client's plan has it — they are on the dashboard's
            // Absent card instead.
            $booked = $children->filter(fn (Child $c) => $state($c) === 'expected')->count();
            $returning = $children->filter(fn (Child $c) => $state($c) === 'returning');
            $out = $children->filter(fn (Child $c) => $state($c) === 'out')->count();

            $here = $staff->filter(fn (array $s) => $s['room'] === $room && $s['status'] === 'in')->values();
            $ratio = $this->centre->ratio($room);
            $capacity = $this->centre->capacity($room);
            $needed = ($ratio > 0 && $in > 0) ? (int) ceil($in / $ratio) : 0;
            $away = ! $afternoon && $in === 0 && $returning->isNotEmpty();

            [$status, $tone] = match (true) {
                $away => ['Away', 'away'],
                $in === 0 => ['Empty', 'empty'],
                $here->count() < $needed => ['Short '.($needed - $here->count()), 'short'],
                $here->count() > $needed && $needed > 0 => [($here->count() - $needed).' spare', 'spare'],
                default => ['Covered', 'covered'],
            };

            $returnAt = $returning->flatMap(fn (Child $c) => ($planned[$c->id] ?? collect())->pluck('planned_time'))->filter()->sort()->first();
            $returnLabel = $returnAt
                ? Carbon::parse($today.' '.substr((string) $returnAt, 0, 5), $timezone)->format('g:i A')
                : Carbon::parse($today.' '.$this->centre->get('school_age_returns'), $timezone)->format('g:i A');

            $rooms[$room] = [
                'room' => $room,
                'ages' => $this->centre->ages($room),
                'ratio' => $ratio,
                'capacity' => $capacity,
                'in' => $in,
                'booked' => $booked,
                'out' => $out,
                'returning' => $returning->count(),
                'returnLabel' => $returnLabel,
                'enrolled' => $children->count(),
                'free' => max(0, $capacity - $in - $booked),
                'nyMax' => $this->centre->nyMaxGroup($room),
                'needed' => $needed,
                'staffIn' => $here->count(),
                'status' => $status,
                'tone' => $tone,
                'staff' => $staff->filter(fn (array $s) => $s['room'] === $room)->values()->all(),
                'tip' => null,
            ];
        }

        // Tips: for a short room, an adult to borrow from a room with one
        // to spare; for School Age, what three o'clock will take.
        foreach ($rooms as $room => &$detail) {
            if ($detail['tone'] === 'short') {
                $late = collect($detail['staff'])->first(fn (array $s) => $s['status'] === 'late');
                $donor = collect($rooms)->first(fn (array $r) => $r['tone'] === 'spare' && $r['room'] !== $room);
                // The adult to borrow is the later arrival — the room's opener stays with it.
                $spareStaff = $donor ? collect($donor['staff'])->filter(fn (array $s) => $s['status'] === 'in' && ! $s['moved'])->sortBy('in_at')->last() : null;
                $parts = [];
                $parts[] = "{$detail['in']} ".strtolower(Str::plural($this->childWord($room), $detail['in']))." need {$detail['needed']} staff at 1:{$detail['ratio']}.";
                if ($late) {
                    $parts[] = "{$late['name']} was due {$late['due']} and hasn't clocked in.";
                }
                if ($donor && $spareStaff) {
                    $parts[] = "{$donor['room']} has {$donor['in']} children and {$donor['staffIn']} staff, so {$spareStaff['name']} is free to cover.";
                    // The button is a real move (App\Services\FloorCover): who goes, where, and who they stand in for.
                    $detail['tip'] = [
                        'text' => implode(' ', $parts),
                        'action' => "Move {$spareStaff['name']} to {$room}".($late ? " until {$late['name']} arrives" : ''),
                        'move' => ['user_id' => $spareStaff['id'], 'to' => $room, 'for' => $late['id'] ?? null],
                    ];
                } else {
                    $parts[] = 'Nobody has a spare adult right now — call a substitute.';
                    $detail['tip'] = ['text' => implode(' ', $parts), 'action' => null];
                }
            } elseif ($room === 'School Age' && $detail['returning'] > 0 && ! $afternoon) {
                $back = $detail['returning'] + $detail['booked'];
                $needAt3 = (int) ceil($back / max(1, $detail['ratio']));
                $detail['tip'] = ['text' => "School Age children are at school. {$back} are booked back at {$detail['returnLabel']} — that needs {$needAt3} staff at 1:{$detail['ratio']}.", 'action' => null];
            } elseif ($detail['nyMax'] > 0 && $detail['capacity'] > $detail['nyMax']) {
                // 18 NYCRR 418-1.8: above the group cap the room runs as two groups.
                $detail['tip'] = ['text' => "Capacity on the plan is {$detail['capacity']}, but New York caps a group of this age at {$detail['nyMax']}. Above {$detail['nyMax']} the room needs to run as two groups.", 'action' => null];
            }
        }
        unset($detail);

        return [
            'view' => $afternoon ? 'afternoon' : 'morning',
            'at' => $at,
            'updated' => $now,
            'counters' => [
                'in' => $day['in'],
                'returning' => $day['returning'],
                'out' => $day['out'],
                'expected' => $day['expected'],
                'staff' => $staff->where('status', 'in')->count(),
                'short' => collect($rooms)->where('tone', 'short')->count(),
            ],
            'rooms' => array_values($rooms),
        ];
    }

    /**
     * Every teacher with a place on today's floor, and where they stand:
     * in (clocked in, working), late (due before now, not in), due (shift
     * later), or off (not rostered, not in). The room is the shift's, else
     * the home room; "moved" says the two differ.
     *
     * @return Collection<int, array{id:int,name:string,room:?string,home:?string,moved:bool,status:string,due:?string,in:?string}>
     */
    private function staff(string $today, Carbon $at, string $timezone, bool $projected): Collection
    {
        $minute = $at->copy()->timezone($timezone)->hour * 60 + $at->copy()->timezone($timezone)->minute;
        $shifts = StaffShift::whereDate('shift_date', $today)->get()->groupBy('user_id');

        return User::teachers()->get()->map(function (User $user) use ($shifts, $minute, $today, $timezone, $projected) {
            $own = $shifts[$user->id] ?? collect();
            $shift = $own->first(fn (StaffShift $s) => $s->covers($minute)) ?? $own->sortBy('starts_at')->first();
            $room = $shift?->classroom ?: $user->classroom;
            $punches = $this->clock->day($user->id, $today);
            $working = $punches['state'] === TimeClock::WORKING;
            $firstIn = $this->clock->punches($user->id, $today)->firstWhere('type', \App\Models\TimePunch::IN)?->punched_at;

            $status = match (true) {
                // The afternoon is the roster: whoever is rostered then is counted as there.
                $projected => ($shift !== null && $shift->covers($minute)) ? 'in' : ($shift !== null ? 'due' : 'off'),
                $working => 'in',
                $shift !== null && $shift->starts_at <= $minute => 'late',
                $shift !== null => 'due',
                default => 'off',
            };

            return [
                'id' => $user->id,
                'name' => explode(' ', trim($user->name))[0],
                'room' => $room,
                'home' => $user->classroom,
                'moved' => $shift !== null && $shift->classroom && $user->classroom && $shift->classroom !== $user->classroom,
                'status' => $status,
                'due' => $shift ? Carbon::parse($today, $timezone)->setTime(intdiv($shift->starts_at, 60), $shift->starts_at % 60)->format('g:i') : null,
                'dueFull' => $shift ? Carbon::parse($today, $timezone)->setTime(intdiv($shift->starts_at, 60), $shift->starts_at % 60)->format('g:i A') : null,
                'until' => $shift ? Carbon::parse($today, $timezone)->setTime(intdiv($shift->ends_at, 60), $shift->ends_at % 60)->format('g:i') : null,
                'in' => $firstIn?->timezone($timezone)->format('g:i A'),
                'in_at' => $firstIn?->timestamp,
            ];
        })->filter(fn (array $s) => $s['status'] !== 'off')->values();
    }

    private function childWord(string $room): string
    {
        return match ($room) {
            'Infant' => 'infant',
            'Toddler', 'Transition' => 'toddler',
            default => 'child',
        };
    }
}
