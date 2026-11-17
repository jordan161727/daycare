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
        $centre = app(CentreSettings::class);
        $open = $centre->minutes($centre->get('centre_opens'));
        $close = $centre->minutes($centre->get('centre_closes'));
        $midday = (int) config('daycare.midday');
        $step = max(5, (int) config('daycare.coverage_step'));

        $dates = StaffScheduleWeek::datesOf($weekStart);
        $closed = array_flip(ClosureDay::inWeek($weekStart));

        // Everybody who could be on the roll this week; who is actually
        // booked each day is RoomCounts::bookedOn — the one rule the
        // dashboard and the register count by. It reads the week's ticks
        // where the week has been built and, for a week nobody has opened
        // yet, the ticks building it would write (last week's shape, else
        // the child's standing days), with closures, enrolment dates and
        // "not coming" applied (QA, 17 Nov 2026: next week read "0 children
        // at peak" in every room because only built weeks were read).
        $children = \App\Models\Child::query()->whereIn('status', ['Active', 'Inactive'])->get();

        $slots = ScheduleSlot::query()
            ->where('week_start', $weekStart)
            ->where('is_scheduled', true)
            ->get(['id', 'child_id', 'slot_date', 'session'])
            ->groupBy(fn ($slot) => $slot->slot_date->toDateString());

        $demand = [];

        foreach ($dates as $day => $date) {
            $demand[$day] = [];
            $ymd = $date->toDateString();

            // A closure day needs nobody, and reporting a shortfall on a day
            // the centre is shut is noise that trains people to ignore the
            // warnings that matter.
            if (isset($closed[$ymd])) {
                continue;
            }

            $booked = RoomCounts::bookedOn($children, $ymd);
            $sessions = ($slots[$ymd] ?? collect())->groupBy('child_id')->map(fn ($s) => $s->pluck('session')->map(fn ($x) => $x ?? 'FULL')->all());

            /** @var array<string, list<array{0:int,1:int}>> $spans room => [from, to] per child */
            $spans = [];

            foreach ($children as $child) {
                if (! ($booked[$child->id] ?? false) || blank($child->classroom)) {
                    continue;
                }

                // The day's sessions: the week's ticks, else the room's usual
                // shape (School Age books mornings and afternoons).
                $theirs = $sessions[$child->id] ?? $child->sessions();
                $windows = in_array('FULL', $theirs, true) || (in_array('AM', $theirs, true) && in_array('PM', $theirs, true) && ! in_array($child->classroom, \App\Models\Child::SESSION_ROOMS, true))
                    ? [[$open, $close]]
                    : array_values(array_filter([
                        in_array('AM', $theirs, true) ? [$open, $midday] : null,
                        in_array('PM', $theirs, true) ? [$midday, $close] : null,
                    ]));

                // Inside the hours agreed for them, where there are any; a
                // child with no agreed hours is counted the whole time open.
                $from = $child->hoursAgreed() ? $centre->minutes(substr((string) $child->drop_off_time, 0, 5)) : $open;
                $to = $child->hoursAgreed() ? $centre->minutes(substr((string) $child->pick_up_time, 0, 5)) : $close;

                foreach ($windows as [$a, $b]) {
                    $a = max($a, $from, $open);
                    $b = min($b, $to, $close);
                    if ($b > $a) {
                        $spans[$child->classroom][] = [$a, $b];
                    }
                }
            }

            foreach ($spans as $room => $list) {
                $steps = [];
                for ($minute = $open; $minute < $close; $minute += $step) {
                    $count = count(array_filter($list, fn ($s) => $s[0] <= $minute && $s[1] > $minute));
                    $last = $steps ? $steps[count($steps) - 1] : null;
                    if ($last && $last['children'] === $count && $last['to'] === $minute) {
                        $steps[count($steps) - 1]['to'] = min($close, $minute + $step);
                    } else {
                        $steps[] = ['from' => $minute, 'to' => min($close, $minute + $step), 'children' => $count];
                    }
                }
                $demand[$day][$room] = $steps;
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

        // The ratio on Settings > Rooms & hours (config until it is saved).
        $ratio = app(CentreSettings::class)->ratio($room) ?: null;

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
