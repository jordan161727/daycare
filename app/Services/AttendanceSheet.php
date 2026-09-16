<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The week's schedule laid out for paper.
 *
 * The centre keeps a printed register as well as the screen: a clipboard by
 * the door, one page, the whole week, every room. This turns the same slots
 * the sign-in grid reads into that page — rooms in age order, children by
 * surname, a box per session with the schedule already printed into it — so
 * the paper and the screen can never say different things about who is
 * expected on Tuesday.
 *
 * Only the schedule goes on the paper, never the sign-ins. The sheet is filled
 * in by hand as children arrive; a box printed already black would be a box
 * nobody could fill, and a wrong one nobody could correct.
 *
 * Everything here is shape and arithmetic. The view decides what a box looks
 * like; this decides which boxes there are.
 */
class AttendanceSheet
{
    /** Three columns of rooms fit a landscape Letter page. Two wastes it; four does not fit a name. */
    public const COLUMNS = 3;

    /**
     * Rows a room costs on the page beyond its children: the band, the day
     * header, the scheduled count and the verify row. Used to balance columns.
     */
    private const ROOM_OVERHEAD = 4;

    /**
     * @param  Collection<int, Child>  $children  the children this reader may see
     * @return array{
     *     weekStart: string,
     *     dates: Collection<int, Carbon>,
     *     closed: array<string, string>,
     *     columns: array<int, array{wide: bool, rooms: array}>,
     *     dayTotals: array<string, int>,
     *     unscheduledRooms: array<int, array{room: string, count: int}>,
     * }
     */
    public function build(string $weekStart, Collection $children): array
    {
        $dates = collect(range(0, 4))->map(fn ($offset) => Carbon::parse($weekStart)->addDays($offset));

        $closed = ClosureDay::betweenDates($dates->first(), $dates->last())
            ->get()
            ->keyBy(fn ($day) => $day->closed_on->toDateString())
            ->map(fn ($day) => $day->reason ?: 'Centre closed')
            ->all();

        $schedule = [];
        foreach (ScheduleSlot::where('week_start', $weekStart)->whereIn('child_id', $children->pluck('id'))->get() as $slot) {
            $schedule[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] = (bool) $slot->is_scheduled;
        }

        // A child with no slot at all this week is not enrolled this week —
        // before their start date or after their last day — so they are not a
        // row. A child with slots and no ticks is: they are on the roll and
        // simply not expected, which is exactly what a row of grey boxes says.
        $onTheRoll = $children->filter(fn ($child) => isset($schedule[$child->id]));

        $rooms = [];
        foreach ($this->roomsInOrder($onTheRoll) as $roomName) {
            $members = $onTheRoll
                ->filter(fn ($child) => ($child->classroom ?: 'Unassigned') === $roomName)
                ->sortBy(fn ($child) => mb_strtolower($child->last_name.' '.$child->first_name))
                ->values();

            $rooms[] = $this->room($roomName, $members, $dates, $closed, $schedule);
        }

        // A room with nothing ticked all week is a block of grey boxes the
        // size of a room. It comes off the grid and goes in the footer by
        // name, so the page still accounts for those children without
        // spending a column on them.
        [$scheduled, $unscheduled] = collect($rooms)->partition(fn ($room) => $room['ticks'] > 0);

        $dayTotals = [];
        foreach ($dates as $date) {
            $iso = $date->toDateString();
            $dayTotals[$iso] = isset($closed[$iso])
                ? 0
                : $scheduled->sum(fn ($room) => $room['present'][$iso]);
        }

        return [
            'weekStart' => $weekStart,
            'dates' => $dates,
            'closed' => $closed,
            'columns' => $this->columns($scheduled->values()->all()),
            'dayTotals' => $dayTotals,
            'unscheduledRooms' => $unscheduled->map(fn ($room) => ['room' => $room['name'], 'count' => count($room['rows'])])->values()->all(),
        ];
    }

    /**
     * The same register, a month wide.
     *
     * Not five weekly sheets stapled together: a month of one child is five
     * pages each carrying one row, which is a ream of paper to say very little.
     * Every weekday of the month goes across a single page instead, grouped
     * under the week it belongs to so a date can still be found by counting
     * along a row rather than by reading every column head.
     *
     * Twenty-two columns is what makes this fit at all, and it is why a room
     * signed in by half day gets two rows per child rather than two boxes per
     * cell — forty-four boxes across a page would be too narrow to write in,
     * and a register nobody can write on is not a register.
     *
     * Unlike build(), this one reads the sign-ins too, and that difference is
     * deliberate. The weekly sheet is a clipboard by the door and must arrive
     * blank: a box printed already filled is one nobody could fill and one
     * nobody could correct. The month sheet is not filled in — it is what the
     * month is reconciled from, and a reconciliation that omits what actually
     * happened is a form you would have to sit beside the screen to use.
     *
     * @param  Collection<int, Child>  $children  the children this reader may see
     */
    public function buildMonth(string $anyDayOfMonth, Collection $children): array
    {
        $month = Carbon::parse($anyDayOfMonth)->startOfMonth();
        $lastDay = $month->copy()->endOfMonth();

        // Weekdays only, and only this month's: the centre does not open at the
        // weekend, and a column for a day in another month is a column nobody
        // can sign for here.
        $days = collect();
        for ($cursor = $month->copy(); $cursor->lessThanOrEqualTo($lastDay); $cursor->addDay()) {
            if ($cursor->isWeekday()) {
                $days->push($cursor->copy());
            }
        }

        $closed = ClosureDay::betweenDates($month, $lastDay)
            ->get()
            ->keyBy(fn ($day) => $day->closed_on->toDateString())
            ->map(fn ($day) => $day->reason ?: 'Centre closed')
            ->all();

        // One query for the month rather than one per week.
        $schedule = [];
        foreach (ScheduleSlot::whereBetween('slot_date', [$month->toDateString(), $lastDay->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get() as $slot) {
            $schedule[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] = (bool) $slot->is_scheduled;
        }

        // Who actually came. Date strings, not Carbon instances: attendance_date
        // is a DATE column and a Carbon binds as 'Y-m-d H:i:s', which SQLite
        // sorts after the bare date — the first day of the range would be
        // silently dropped, as it once was on the sheet itself.
        $attended = [];
        foreach (Attendance::whereBetween('attendance_date', [$month->toDateString(), $lastDay->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get() as $record) {
            $attended[$record->child_id][$record->attendance_date->toDateString()][$record->session ?? 'FULL'] = true;
        }

        // A child with no box anywhere in the month was not at the centre this
        // month at all, as against one on the roll with nothing ticked.
        $onTheRoll = $children->filter(fn ($child) => isset($schedule[$child->id]));

        $rooms = [];
        foreach ($this->roomsInOrder($onTheRoll) as $roomName) {
            $members = $onTheRoll
                ->filter(fn ($child) => ($child->classroom ?: 'Unassigned') === $roomName)
                ->sortBy(fn ($child) => mb_strtolower($child->last_name.' '.$child->first_name))
                ->values();

            $rooms[] = $this->monthRoom($roomName, $members, $days, $closed, $schedule, $attended);
        }

        // A room stays on the page if anybody was expected there OR anybody
        // turned up. Judging on the schedule alone dropped a room whose month
        // was nothing but drop-ins — which is the one kind of day a billing
        // reconciliation cannot afford to lose.
        [$scheduled, $unscheduled] = collect($rooms)->partition(
            fn ($room) => $room['ticks'] > 0 || array_sum($room['attended']) > 0
        );

        // The weeks, for the band across the top of the day columns. Keyed by
        // the Monday so a week that starts in the previous month still groups.
        $weeks = $days->groupBy(fn (Carbon $day) => $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString());

        return [
            'month' => $month,
            'monthLabel' => $month->format('F Y'),
            'days' => $days,
            'weeks' => $weeks,
            'closed' => $closed,
            'rooms' => $scheduled->values()->all(),
            'unscheduledRooms' => $unscheduled->map(fn ($room) => ['room' => $room['name'], 'count' => $room['members']])->values()->all(),
            'weekTotals' => $weeks->map(fn ($week) => $scheduled->sum(
                fn ($room) => collect($week)->sum(fn (Carbon $day) => $room['present'][$day->toDateString()])
            ))->all(),
            'monthTotal' => $scheduled->sum(fn ($room) => array_sum($room['present'])),
            // What was expected against what happened — the one comparison the
            // month is reconciled on, so the page carries it rather than
            // leaving it to be counted by hand.
            'monthAttended' => $scheduled->sum(fn ($room) => array_sum($room['attended'])),
        ];
    }

    /**
     * One room's block on the month sheet.
     *
     * A room signed in by half day gets two rows per child — the morning and
     * the afternoon — because the column is already as narrow as a box can be.
     */
    private function monthRoom(string $name, Collection $members, Collection $days, array $closed, array $schedule, array $attended = []): array
    {
        $split = $members->contains(fn ($child) => count($child->sessions()) > 1);

        $rows = [];
        $counts = [];
        $present = [];
        $arrivals = [];
        $ticks = 0;

        foreach ($days as $day) {
            $counts[$day->toDateString()] = 0;
            $present[$day->toDateString()] = 0;
            $arrivals[$day->toDateString()] = 0;
        }

        foreach ($members as $child) {
            foreach ($child->sessions() as $session) {
                $cells = [];

                foreach ($days as $day) {
                    $date = $day->toDateString();

                    if (isset($closed[$date])) {
                        $cells[$date] = 'closed';

                        continue;
                    }

                    if (! $child->isEnrolledOn($date)) {
                        $cells[$date] = 'out';

                        continue;
                    }

                    $on = $schedule[$child->id][$date][$session] ?? false;
                    $came = $attended[$child->id][$date][$session] ?? false;

                    /*
                     * Four answers, not two. Expected and came is the ordinary
                     * day; expected and did not is the one the month is
                     * reconciled over; not expected and came is a drop-in, and
                     * still billable; neither is an ordinary day off.
                     *
                     * A drop-in keeps the tint under its filled box, so the
                     * page never loses the fact that nobody had booked it.
                     */
                    $cells[$date] = match (true) {
                        $on && $came => 'came',
                        $on => 'on',
                        $came => 'dropin',
                        default => 'off',
                    };

                    if ($on) {
                        $ticks++;
                        $counts[$date]++;
                    }

                    if ($came) {
                        $arrivals[$date]++;
                    }
                }

                $rows[] = [
                    'name' => $child->last_name.', '.$child->first_name,
                    // Beside the name because that is the column with room for
                    // it, and because it is a fact about the child rather than
                    // about any one of the five days.
                    'hours' => $child->hoursCompact(),
                    // Only worth printing where a room has two rows a child.
                    'session' => $split ? $session : null,
                    // The name is written once and the second row carries the
                    // half alone, so a pair reads as one child rather than two.
                    'repeat' => $split && $session !== $child->sessions()[0],
                    'cells' => $cells,
                ];
            }
        }

        // A child expected for either half of a split day is one child that day.
        foreach ($days as $day) {
            $date = $day->toDateString();

            $present[$date] = $members->filter(function ($child) use ($schedule, $date) {
                foreach ($child->sessions() as $session) {
                    if ($schedule[$child->id][$date][$session] ?? false) {
                        return true;
                    }
                }

                return false;
            })->count();
        }

        return [
            'name' => $name,
            'split' => $split,
            'members' => $members->count(),
            'rows' => $rows,
            'counts' => $counts,
            'present' => $present,
            'attended' => $arrivals,
            'ticks' => $ticks,
        ];
    }
    /**
     * Rooms in the order the centre says them — youngest first, as on every
     * other screen — with anything the age rule does not name after them.
     */
    private function roomsInOrder(Collection $children): array
    {
        $present = $children->map(fn ($child) => $child->classroom ?: 'Unassigned')->unique()->values();

        $known = collect(ClassroomAssignment::rooms())->filter(fn ($room) => $present->contains($room));
        $extra = $present->reject(fn ($room) => $known->contains($room))->sort();

        return $known->concat($extra)->values()->all();
    }

    /**
     * One room's block: its rows, its count per day, and how many ticks it has
     * in the whole week.
     *
     * School Age is the room that splits the day. Its rows carry a box for the
     * morning and one for the afternoon, and its count row says both — "5/11"
     * is five expected before lunch and eleven after — because a single number
     * would be wrong for whichever half of the day somebody was checking.
     */
    private function room(string $name, Collection $members, Collection $dates, array $closed, array $schedule): array
    {
        $split = $members->contains(fn ($child) => count($child->sessions()) > 1);
        $sessions = $split ? ['AM', 'PM'] : ['FULL'];

        $rows = [];
        $counts = [];
        $present = [];
        $ticks = 0;

        foreach ($dates as $date) {
            $iso = $date->toDateString();
            $counts[$iso] = array_fill_keys($sessions, 0);
            $present[$iso] = 0;
        }

        foreach ($members as $child) {
            $cells = [];

            foreach ($dates as $date) {
                $iso = $date->toDateString();

                if (isset($closed[$iso])) {
                    $cells[$iso] = null;

                    continue;
                }

                $boxes = [];
                $expectedToday = false;

                foreach ($child->sessions() as $session) {
                    $on = $schedule[$child->id][$iso][$session] ?? false;
                    $boxes[$session] = $on;

                    if ($on) {
                        $ticks++;
                        $expectedToday = true;
                        $counts[$iso][$session]++;
                    }
                }

                if ($expectedToday) {
                    $present[$iso]++;
                }

                $cells[$iso] = $boxes;
            }

            $rows[] = [
                'name' => $child->last_name.', '.$child->first_name,
                'hours' => $child->hoursCompact(),
                'cells' => $cells,
            ];
        }

        return [
            'name' => $name,
            'split' => $split,
            'rows' => $rows,
            'counts' => $counts,
            'present' => $present,
            'ticks' => $ticks,
        ];
    }

    /**
     * Rooms dealt into columns, in order, each column as near a third of the
     * page as the room boundaries allow.
     *
     * Rooms are never split across columns — a room is read as one block — so
     * this is the classic problem of cutting an ordered list into k runs with
     * the tallest run as short as possible. Greedy is close enough for seven
     * rooms: take the next room if it brings the column nearer the target
     * than stopping would, and hand everything left to the last column.
     *
     * A column holding a split room is flagged wide: two boxes per day need
     * the name column narrower, and the view reads the flag.
     */
    private function columns(array $rooms): array
    {
        $weight = fn (array $room) => count($room['rows']) + self::ROOM_OVERHEAD;
        $target = collect($rooms)->sum($weight) / self::COLUMNS;

        $columns = [];
        $current = [];
        $height = 0;

        foreach ($rooms as $index => $room) {
            $isLastColumn = count($columns) === self::COLUMNS - 1;
            $fits = $height === 0 || abs($height + $weight($room) - $target) <= abs($height - $target);

            if (! $fits && ! $isLastColumn) {
                $columns[] = $current;
                $current = [];
                $height = 0;
            }

            $current[] = $room;
            $height += $weight($room);
        }

        $columns[] = $current;

        // Always three, even when there are two rooms: the page is laid out
        // against three columns and an empty one keeps the others their width.
        while (count($columns) < self::COLUMNS) {
            $columns[] = [];
        }

        return array_map(fn (array $column) => [
            'wide' => collect($column)->contains(fn ($room) => $room['split']),
            'rooms' => $column,
        ], $columns);
    }
}
