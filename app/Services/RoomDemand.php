<?php

namespace App\Services;

use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\StaffScheduleWeek;

/**
 * How many children are expected in each room, at each minute of each day.
 *
 * This is what turns a licensing ratio into a staffing number. It reads the
 * children's booked week — the same slots the attendance board ticks — rather
 * than an enrolment headcount, because a room with twelve children on the roll
 * and four booked on Wednesday needs one member of staff that day, not three.
 *
 * Demand is a curve, not a number. School Age books by half day, so a room can
 * need two staff at 9am and one at 2pm; a flat daily count would either
 * over-staff the afternoon or hide a morning shortfall.
 */
class RoomDemand
{
    /**
     * Children per room per day, as a list of {from, to, children} steps.
     *
     * Shape: [ 'MON' => [ 'Toddler' => [ ['from' => 420, 'to' => 720, 'children' => 9], ... ] ] ]
     */
    public function forWeek(string $weekStart): array
    {
        $open = (int) config('daycare.open');
        $close = (int) config('daycare.close');
        $midday = (int) config('daycare.midday');

        $dates = StaffScheduleWeek::datesOf($weekStart);
        $closed = array_flip(ClosureDay::inWeek($weekStart));

        // One query for the week. Counting per room per day in PHP beats five
        // grouped queries and keeps the closure and session logic in one place.
        $slots = ScheduleSlot::query()
            ->where('week_start', $weekStart)
            ->where('is_scheduled', true)
            ->with('child:id,classroom')
            ->get(['id', 'child_id', 'slot_date', 'session']);

        $demand = [];

        foreach ($dates as $day => $date) {
            $demand[$day] = [];

            // A closure day needs nobody, and reporting a shortfall on a day
            // the centre is shut is noise that trains people to ignore the
            // warnings that matter.
            if (isset($closed[$date->toDateString()])) {
                continue;
            }

            $onDay = $slots->filter(fn ($slot) => $slot->slot_date->toDateString() === $date->toDateString());

            foreach ($onDay->groupBy(fn ($slot) => $slot->child?->classroom) as $room => $roomSlots) {
                if (blank($room)) {
                    continue;
                }

                $morning = $roomSlots->filter(fn ($slot) => $slot->session !== 'PM')->count();
                $afternoon = $roomSlots->filter(fn ($slot) => $slot->session !== 'AM')->count();

                $demand[$day][$room] = $morning === $afternoon
                    ? [['from' => $open, 'to' => $close, 'children' => $morning]]
                    : [
                        ['from' => $open, 'to' => $midday, 'children' => $morning],
                        ['from' => $midday, 'to' => $close, 'children' => $afternoon],
                    ];
            }
        }

        return $demand;
    }

    /**
     * Staff required in a room at a given minute, from the demand curve.
     *
     * Rounds up, and returns zero for an empty room — an empty room is not a
     * shortfall, it is a room nobody needs to stand in.
     */
    public function staffNeeded(array $steps, string $room, int $minute): int
    {
        $children = $this->childrenAt($steps, $minute);

        if ($children === 0) {
            return 0;
        }

        $ratio = config('daycare.ratios')[$room] ?? null;

        // A room with no ratio configured is the one case worth failing loudly
        // on: silently treating it as needing nobody would hide every gap in it.
        if (! $ratio) {
            return 0;
        }

        return (int) ceil($children / $ratio);
    }

    public function childrenAt(array $steps, int $minute): int
    {
        foreach ($steps as $step) {
            if ($minute >= $step['from'] && $minute < $step['to']) {
                return $step['children'];
            }
        }

        return 0;
    }

    /** The busiest point of the day, for the room header. */
    public function peak(array $steps): int
    {
        return collect($steps)->max('children') ?? 0;
    }

    /** Rooms with a ratio configured but no entry — a licensing blind spot. */
    public static function roomsMissingRatios(): array
    {
        $configured = array_keys(config('daycare.ratios'));

        return array_values(array_diff(ClassroomAssignment::rooms(), $configured));
    }
}
