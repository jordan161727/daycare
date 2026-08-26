<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffShift;
use App\Models\StaffScheduleWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which teachers are on the floor with each child, and when.
 *
 * RoomDemand reads this relationship the other way — children's booked days
 * become the staffing a room needs. This reads it back: given the roster that
 * was generated from that demand, who is actually standing in the room while
 * one particular child is there.
 *
 * Two facts decide it and neither is guessed at. The days come from the child's
 * ticked slots, so a child booked Monday and Wednesday is never told about
 * Friday's teacher. The hours come from the child's contracted drop-off and
 * pick-up, so a teacher who leaves before the child does is not listed as
 * covering them. A child with no hours agreed is read against the whole
 * operating day, which is the widest honest answer rather than none at all.
 */
class RoomCover
{
    /**
     * Per child: the teachers covering them this week, and the day-by-day
     * breakdown behind that list.
     *
     * Shape: [ childId => ['names' => ['Grace H.'], 'detail' => 'Mon …', 'partial' => bool] ]
     *
     * Who, not when. What time a class runs is set on the room and read from
     * there — a roster is shift patterns and handovers, and an hour of it is
     * not an hour of the room's day.
     *
     * @param  Collection<int, Child>  $children
     * @return array<int, array{names: list<string>, detail: string, partial: bool}>
     */
    public function forWeek(string $weekStart, Collection $children): array
    {
        $shifts = StaffShift::with('user:id,name')
            ->where('week_start', $weekStart)
            ->orderBy('starts_at')
            ->get();

        // No roster generated for the week is not a gap to report — there is
        // simply nothing yet to say who is on. The rows stay as they were.
        if ($shifts->isEmpty()) {
            return [];
        }

        $dates = StaffScheduleWeek::datesOf($weekStart);
        $dayOf = collect($dates)->mapWithKeys(fn (Carbon $date, string $day) => [$date->toDateString() => $day])->all();

        $booked = $this->bookedDates($weekStart);
        $byRoomAndDay = $shifts->groupBy(['classroom', 'day']);

        $cover = [];

        foreach ($children as $child) {
            [$from, $until] = $this->hoursOf($child);

            $names = [];
            $lines = [];
            $missed = false;

            foreach ($booked[$child->id] ?? [] as $date) {
                $day = $dayOf[$date] ?? null;

                if ($day === null) {
                    continue;
                }

                $onDay = ($byRoomAndDay[$child->classroom] ?? collect())[$day] ?? collect();

                // Half-open on both sides: a teacher who finishes exactly as the
                // child arrives shares no minute with them.
                $covering = $onDay->filter(
                    fn (StaffShift $shift) => $shift->starts_at < $until && $from < $shift->ends_at
                );

                if ($covering->isEmpty()) {
                    $missed = true;

                    continue;
                }

                foreach ($covering as $shift) {
                    $name = $this->shortName($shift->user?->name);

                    if ($name !== null && ! in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }

                $lines[] = ucfirst(strtolower($day)).' '.$covering
                    ->map(fn (StaffShift $shift) => $this->shortName($shift->user?->name).' '.$shift->label())
                    ->join(', ');
            }

            if ($names === []) {
                continue;
            }

            $cover[$child->id] = [
                'names' => $names,
                'detail' => implode(' · ', $lines),
                // A day the child is booked with nobody rostered to their room
                // in their hours. Worth marking on the row rather than leaving
                // the reader to notice a name missing from a list.
                'partial' => $missed,
            ];
        }

        return $cover;
    }

    /**
     * The dates each child is ticked for this week.
     *
     * @return array<int, list<string>>
     */
    private function bookedDates(string $weekStart): array
    {
        $booked = [];

        ScheduleSlot::where('week_start', $weekStart)
            ->where('is_scheduled', true)
            ->get(['child_id', 'slot_date'])
            ->each(function (ScheduleSlot $slot) use (&$booked) {
                $date = $slot->slot_date->toDateString();

                // School Age books twice a day, so the same date arrives twice.
                if (! in_array($date, $booked[$slot->child_id] ?? [], true)) {
                    $booked[$slot->child_id][] = $date;
                }
            });

        return $booked;
    }

    /**
     * The child's day as minutes past midnight, falling back to the centre's
     * own hours when nobody has agreed theirs.
     *
     * @return array{int, int}
     */
    private function hoursOf(Child $child): array
    {
        $open = (int) config('daycare.open');
        $close = (int) config('daycare.close');

        return [
            $this->minutes($child->drop_off_time) ?? $open,
            $this->minutes($child->pick_up_time) ?? $close,
        ];
    }

    private function minutes(?string $time): ?int
    {
        if (blank($time)) {
            return null;
        }

        $at = Carbon::parse($time);

        return $at->hour * 60 + $at->minute;
    }

    /**
     * "Grace Hopper" as "Grace H." — the roster row has one line for this and
     * a full name pushes the room and the hours off the end of it.
     */
    private function shortName(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        return count($parts) < 2
            ? $parts[0]
            : $parts[0].' '.strtoupper(substr(end($parts), 0, 1)).'.';
    }
}
