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
        $timezone = config('app.timezone');
        $now = ($now ?? now())->copy()->timezone($timezone);
        $today = $now->toDateString();
        $afternoon = $view === 'afternoon';

        // The hour the plan is drawn for: this minute, or the hour the
        // afternoon turns — School Age back from school.
        $at = $afternoon
            ? Carbon::parse($today.' '.$this->centre->get('school_age_returns'), $timezone)
            : $now->copy();

        // The whole building for everyone (QA J1): the plan shows counts, not
        // names, and a teacher's own rooms alone made the others read empty
        // while their staff stood in them.
        $day = $this->snapshot->for($user, $now, wholeBuilding: true);
        $roll = Child::query()->where('status', 'Active')->get()
            ->filter(fn (Child $child) => RoomCounts::isEnrolled($child, $today))->values();
        $planned = ScheduleSlot::whereDate('slot_date', $today)->where('is_scheduled', true)
            ->whereIn('child_id', $roll->pluck('id'))->get()->groupBy('child_id');

        $staff = $this->staff($today, $at, $timezone, $afternoon);
        $rooms = [];
        // The dashboard's own room figures — booked today, still to come,
        // late, the roster's cover — so the two screens cannot disagree
        // (QA, 17 Nov 2026: Infant "9 expected" on one, "Expected 1" on the other).
        $dayRooms = collect($day['ratios'])->keyBy('room');

        foreach (ClassroomAssignment::rooms() as $room) {
            $children = $roll->filter(fn (Child $child) => $child->classroom === $room);
            $state = fn (Child $child) => $day['states'][$child->id] ?? 'none';
            $dayRoom = $dayRooms[$room] ?? [];

            // The afternoon counts the children booked to be here then: the
            // ones in now who are not picked up by then (nothing says they
            // will be), plus the ones away who are booked back.
            $in = $afternoon
                ? $children->filter(fn (Child $c) => in_array($state($c), ['in', 'returning', 'expected'], true))->count()
                : $children->filter(fn (Child $c) => $state($c) === 'in')->count();
            // Booked and not in yet — RoomCounts' "still to come", the same
            // figure the dashboard and the register show (QA #2, 12 Nov 2026).
            // A child an hour overdue is off the room's seats, as the client's
            // plan has it — they are on the dashboard's Absent card instead.
            // The afternoon already counts them in, so it seats them once:
            // counting them again here put them on two seats and took two
            // places off "free".
            $booked = $afternoon ? 0 : (int) ($dayRoom['stillToCome'] ?? 0);
            $expectedHere = (int) ($dayRoom['expected'] ?? 0);
            $returning = $children->filter(fn (Child $c) => $state($c) === 'returning');
            $out =$children->filter(fn (Child $c) => $state($c) === 'out')->count();

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
                // The seats on the map not spoken for: capacity less in and
                // booked-not-in-yet (the client's plan, FloorPlanQaSpecTest C4).
                'free' => max(0, $capacity - $in - $booked),
                // Places free in the room right now: capacity less the
                // children in it, never the bookings (QA, 16 Nov 2026: an
                // empty Infant room read "Places free 0 of 8" because eleven
                // were booked). The panel's "Places free" line reads this.
                'placesFree' => max(0, $capacity - $in),
                // Booked today (the whole day), by the one rule (RoomCounts).
                'expected' => $expectedHere,
                // Still to come now, and how many of them are late — the
                // morning's figures, whichever view is drawn.
                'stillToCome' => (int) ($dayRoom['stillToCome'] ?? 0),
                'late' => (int) ($dayRoom['late'] ?? 0),
                // Ahead of the day: staff on today's roster for this room,
                // and the warning when that cannot cover the bookings.
                'rostered' => (int) ($dayRoom['rostered'] ?? 0),
                'scheduleWarning' => $dayRoom['scheduleWarning'] ?? null,
                // More enrolled, or more expected, than the room has places:
                // warned, never blocked (QA, 16 Nov 2026).
                'capacityWarning' => RoomCounts::capacityWarning($capacity, $children->count(), $expectedHere),
                // More children in than the room is licensed for (QA B4): flagged, not blocked at the door.
                'overCapacity' => $capacity > 0 && $in > $capacity,
                'nyMax' => $this->centre->nyMaxGroup($room),
                'needed' => $needed,
                'staffIn' => $here->count(),
                'status' => $status,
                'tone' => $tone,
                'staff' => $staff->filter(fn (array $s) => $s['room'] === $room)->values()->all(),
                'tip' => null,
            ];
        }

        // Places by physical space (QA retest, 16 Nov 2026): rooms that share
        // one — UPK-4 and School Age — are checked together against the
        // space's places, so both panels carry the same combined warning and
        // the same places free. Ratios and staffing stay per class.
        $capacityWarnings = RoomCounts::capacityWarnings($rooms, $this->centre);
        foreach ($rooms as $room => $detail) {
            $space = $this->centre->space($room);
            if (count($space) < 2) {
                continue;
            }
            $places = $this->centre->spaceCapacity($room);
            $inSpace = array_sum(array_map(fn (string $r) => (int) ($rooms[$r]['in'] ?? 0), $space));
            $rooms[$room]['capacityWarning'] = $capacityWarnings[$room];
            $rooms[$room]['placesFree'] = max(0, $places - $inSpace);
            $rooms[$room]['overCapacity'] = $places > 0 && $inSpace > $places;
            $rooms[$room]['sharedWith'] = array_values(array_diff($space, [$room]));
            // One capacity line for the space, on its first room's card; the
            // other cards point to it (QA retest: each read "cap 20 · ⚠ Over-booked").
            $rooms[$room]['space'] = [
                'label' => $this->centre->spaceLabel($room),
                'in' => $inSpace,
                'capacity' => $places,
                'first' => $space[0] === $room,
            ];
        }

        // Who covers whom: each spare adult is offered to one short room only
        // (QA F4 — one spare teacher used to be offered to every short room),
        // the biggest shortfall first, and a room gives no more than it has
        // spare. The adult to borrow is the later arrival — the room's opener
        // stays with it.
        $spareLeft = collect($rooms)->filter(fn (array $r) => $r['tone'] === 'spare')
            ->map(fn (array $r) => $r['staffIn'] - $r['needed'])->all();
        $taken = [];
        $offers = [];
        $order = array_keys($rooms);
        $short = collect($rooms)->filter(fn (array $r) => $r['tone'] === 'short')
            ->sortBy([fn ($a, $b) => ($b['needed'] - $b['staffIn']) <=> ($a['needed'] - $a['staffIn']),
                fn ($a, $b) => array_search($a['room'], $order, true) <=> array_search($b['room'], $order, true)]);
        foreach ($short as $detail) {
            $room = $detail['room'];
            foreach ($spareLeft as $from => $left) {
                if ($left < 1 || $from === $room) {
                    continue;
                }
                $pick = collect($rooms[$from]['staff'])
                    ->filter(fn (array $s) => $s['status'] === 'in' && ! $s['moved'] && ! in_array($s['id'], $taken, true))
                    ->sortBy('in_at')->last();
                if ($pick) {
                    $offers[$room] = [$rooms[$from], $pick];
                    $taken[] = $pick['id'];
                    $spareLeft[$from]--;
                    break;
                }
            }
        }

        // Tips: for a short room, an adult to borrow from a room with one
        // to spare; for School Age, what three o'clock will take.
        foreach ($rooms as $room => &$detail) {
            if ($detail['tone'] === 'short') {
                $late = collect($detail['staff'])->first(fn (array $s) => $s['status'] === 'late');
                [$donor, $spareStaff] = $offers[$room] ?? [null, null];
                $parts = [];
                $parts[] = "{$detail['in']} ".strtolower(Str::plural($this->childWord($room), $detail['in']))." need {$detail['needed']} staff at 1:{$detail['ratio']}.";
                if ($late) {
                    $parts[] = "{$late['first']} was due {$late['due']} and hasn't clocked in.";
                }
                if ($donor && $spareStaff) {
                    $parts[] = "{$donor['room']} has {$donor['in']} children and {$donor['staffIn']} staff, so {$spareStaff['first']} is free to cover.";
                    // The button is a real move (App\Services\FloorCover): who goes, where, and who they stand in for.
                    $detail['tip'] = [
                        'text' => implode(' ', $parts),
                        'action' => "Move {$spareStaff['first']} to {$room}".($late ? " until {$late['first']} arrives" : ''),
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

            if ($detail['overCapacity']) {
                $over = "Over capacity: {$detail['in']} in a room licensed for {$detail['capacity']}.";
                $detail['tip'] = $detail['tip']
                    ? ['text' => $over.' '.$detail['tip']['text']] + $detail['tip']
                    : ['text' => $over, 'action' => null];
            }
        }
        unset($detail);

        return [
            'view' => $afternoon ? 'afternoon' : 'morning',
            'at' => $at,
            'updated' => $now,
            // The cards follow the view the tiles are drawn for (QA #8, 12 Nov
            // 2026): they used to be read off this minute whichever tab was
            // pressed, so the afternoon's tiles sat under the morning's cards.
            // "In" is the rooms added up — live in the morning, as booked at
            // three in the afternoon — and the rest say who that includes.
            'counters' => [
                'in' => collect($rooms)->sum('in'),
                'returning' => $day['returning'],
                'out' => $day['out'],
                'expected' => $day['expected'],
                'late' => $day['late'],
                // Rooms whose roster cannot cover today's bookings (QA, 17 Nov 2026).
                'scheduleShort' => $day['scheduleShort'],
                // Clocked in and in a room (QA A3): someone clocked in with no
                // room is not on the floor, so the card is the rooms added up.
                'staff' => collect($rooms)->sum('staffIn'),
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
                // The name as the staff record has it (QA E1, 16 Nov 2026):
                // "Mary Jo" is not "Mary". The tips still say first names.
                'name' => trim(preg_replace('/\s+/', ' ', $user->name)),
                'first' => explode(' ', trim($user->name))[0],
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
