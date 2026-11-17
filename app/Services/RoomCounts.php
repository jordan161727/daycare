<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Expected, in, absent and still to come — the numbers a room is run by,
 * worked out in one place so every screen that shows them adds up the same
 * way (QA #2, 12 Nov 2026: School Age read "20 expected, 11 in, 11 still to
 * come", because "in" counted children nobody had booked and "still to come"
 * did not).
 *
 * Each child is three facts about one day:
 *
 *   booked   ticked for the day (any session) and not marked "not coming"
 *   arrived  an arrival on the register that day — in now, or been and gone
 *   absent   marked Absent on the register — never read off the clock
 *            (see LATE below: an hour past an agreed time is late, not absent)
 *
 * and the numbers are:
 *
 *   expected       booked, arrived or absent. A child who walked in unbooked
 *                  was expected after all, so every arrival is inside it.
 *   in             arrived. Never more than expected.
 *   absent         expected, not arrived, and absent — or not arrived at all
 *                  once the day is over (a day gone by, or a locked week:
 *                  QA #3, nothing is "still to come" in a week that has ended).
 *   still_to_come  expected − in − absent. Never negative, and 0 once the
 *                  day is over.
 *   not_in         enrolled − in: the roll-wide figure the register has
 *                  always shown, unbooked children included.
 *
 * The register works the same rule in the browser (it moves with every
 * tap); its getters say so and follow this one line for line.
 */
final class RoomCounts
{
    /*
     * Who is counted at all — one definition for every screen (QA, 16 Nov
     * 2026: Dashboard 117 enrolled, the register 118, Check-in 117 with a
     * PreK child missing; "scheduled today" 110 at 2:15 and 114 at 2:18).
     *
     *   ENROLLED on a date   on the roll that day: status Active, and the
     *                        date inside their enrolment — on or after the
     *                        first day (enrolled_on, when set), on or before
     *                        the last day (withdrawn_on, when set), and not
     *                        in a gap between a last day and a re-enrolment.
     *                        A child withdrawn since still counts on the days
     *                        before they left. Pending (not started yet),
     *                        Withdrawn with no last day, and Archived never
     *                        count. An Active child whose first day is still
     *                        to come is NOT enrolled yet — that was the PreK
     *                        child the register counted and Check-in did not.
     *                        See isEnrolled().
     *
     *   BOOKED on a date     enrolled, the centre open (no closure that
     *                        day), not marked "not coming", and scheduled:
     *                        a tick in the week's boxes, any session — or,
     *                        for a child whose week has not been built yet,
     *                        the tick building it would write (last week's
     *                        shape, else their registered days; see
     *                        WeekSchedule::wouldTick). Never the clock: the
     *                        same at 2:15 as at 2:18, before the week is
     *                        opened as after. See bookedOn().
     *
     *   EXPECTED             booked, arrived or marked Absent (tally below).
     *                        A child marked Absent is inside expected and
     *                        counted absent, so "still to come" is expected
     *                        less in and absent — the number of booked
     *                        children not marked absent and not here yet.
     */

    /** Whether a child is on the roll on a date — ENROLLED above. */
    public static function isEnrolled(\App\Models\Child $child, string $date): bool
    {
        if (! $child->isEnrolledOn($date)) {
            return false;
        }

        return $child->status === 'Active'
            || ($child->status === 'Inactive' && $child->withdrawn_on !== null);
    }

    /**
     * BOOKED above, for each child on one date.
     *
     * @param  iterable<\App\Models\Child>  $children
     * @return array<int, bool> child id => booked
     */
    public static function bookedOn(iterable $children, string $date): array
    {
        $children = collect($children);
        $ids = $children->pluck('id');
        $closed = \App\Models\ClosureDay::whereDate('closed_on', $date)->exists();
        $ticks = \App\Models\ScheduleSlot::whereDate('slot_date', $date)->whereIn('child_id', $ids)->get()
            ->groupBy('child_id')
            ->map(fn ($slots) => $slots->contains(fn ($slot) => (bool) $slot->is_scheduled));
        $notComing = \App\Models\DssDayMark::whereDate('marked_on', $date)->whereIn('child_id', $ids)
            ->where('mark', \App\Models\DssDayMark::NONE)->pluck('child_id')->flip();
        $unbuilt = $children->reject(fn ($child) => $ticks->has($child->id));
        $would = $unbuilt->isEmpty() || $closed ? [] : app(WeekSchedule::class)->wouldTick($unbuilt, $date);

        $out = [];
        foreach ($children as $child) {
            $out[$child->id] = ! $closed
                && self::isEnrolled($child, $date)
                && ! $notComing->has($child->id)
                && ($ticks[$child->id] ?? ($would[$child->id] ?? false));
        }

        return $out;
    }

    /**
     * The warning for a room with more children than places, or null.
     *
     * More enrolled, or more expected today, than the room's capacity
     * (QA, 16 Nov 2026: Infant holds 8, with 14 enrolled and 11 expected,
     * and nothing said so). A warning only: an over-capacity room is
     * flagged, never blocked — a child at the door is still signed in.
     */
    public static function capacityWarning(int $capacity, int $enrolled, ?int $expected = null): ?string
    {
        if ($capacity <= 0) {
            return null;
        }

        if ($enrolled <= $capacity && ($expected === null || $expected <= $capacity)) {
            return null;
        }

        // One sentence for every room (QA, 17 Nov 2026: Toddler read "12
        // enrolled for 10 places" while the others carried the day's figure
        // too): both numbers whenever the day's is known, whichever is over.
        // "Booked today" is the whole day's bookings — never "still to come".
        return 'Over capacity: '.$enrolled.' enrolled'
            .($expected !== null ? ' and '.$expected.' booked today' : '')
            .' for '.$capacity.' places';
    }

    /*
     * LATE — the one lateness rule (QA, 17 Nov 2026). The dashboard used to
     * call a booked child "absent" an hour past their due time, and took the
     * room's opening hour as the due time of a child with no agreed hours —
     * so at nine it read "Absent 70" while the register said 0 absent.
     *
     * Now nobody is absent off the clock. A child is LATE when they are still
     * to come and more than LATE_AFTER_MINUTES past a time somebody agreed:
     * the hour planned for that day on the register, else the child's agreed
     * drop-off (Child::hoursAgreed). A child with no agreed hours is never
     * late — they are still to come until the day ends. Late is a part of
     * "still to come", shown beside it; it never moves a child to absent.
     * Absent is the register's mark, or a day that has ended.
     */
    public const LATE_AFTER_MINUTES = 60;

    /**
     * The agreed time a booked child is due on a date, or null when nothing
     * agreed says (no planned hour that day and no agreed hours).
     *
     * @param  iterable<\App\Models\ScheduleSlot>  $slots  the child's ticked slots that day
     */
    public static function dueAt(\App\Models\Child $child, iterable $slots, string $date): ?Carbon
    {
        $time = collect($slots)->pluck('planned_time')->filter()->sort()->first()
            ?: ($child->hoursAgreed() ? $child->drop_off_time : null);

        return blank($time) ? null : Carbon::parse($date.' '.substr((string) $time, 0, 5), config('app.timezone'));
    }

    /** LATE above: due by an agreed time, and more than an hour past it at $now. */
    public static function isLate(\App\Models\Child $child, iterable $slots, Carbon $now): bool
    {
        $now = $now->copy()->timezone(config('app.timezone'));
        $due = self::dueAt($child, $slots, $now->toDateString());

        return $due !== null && $now->greaterThan($due->copy()->addMinutes(self::LATE_AFTER_MINUTES));
    }

    /**
     * capacityWarning for every room, checked by physical space: rooms that
     * share one (CentreSettings::sharesWith) are added together against the
     * space's places, and each of them carries the one combined warning,
     * named for the space — "UPK-4 + School Age (shared room): Over
     * capacity: 74 enrolled … for 20 places". Ratios stay per class; this is
     * places only (QA retest, 16 Nov 2026).
     *
     * @param  array<string, array{enrolled: int, expected?: int|null}>  $counts  room => figures
     * @return array<string, ?string> room => warning
     */
    public static function capacityWarnings(array $counts, CentreSettings $centre): array
    {
        $out = [];
        foreach (array_keys($counts) as $room) {
            $space = $centre->space($room);
            $sum = fn (string $key) => array_sum(array_map(fn (string $r) => (int) ($counts[$r][$key] ?? 0), $space));
            $warning = self::capacityWarning($centre->spaceCapacity($room), $sum('enrolled'), $sum('expected'));
            $out[$room] = $warning !== null && count($space) > 1 ? $centre->spaceLabel($room).': '.$warning : $warning;
        }

        return $out;
    }

    /**
     * Every room's tally for a date, by the rules above: the whole building,
     * room by the stored room. For the screens that list rooms with their
     * capacity and want the day's enrolled and expected beside it.
     *
     * @return array<string, array{enrolled: int, expected: int, in: int, absent: int, still_to_come: int, not_in: int}>
     */
    public static function byRoom(string $date): array
    {
        $roll = \App\Models\Child::query()->whereIn('status', ['Active', 'Inactive'])->get()
            ->filter(fn ($child) => self::isEnrolled($child, $date))->values();
        $booked = self::bookedOn($roll, $date);
        $arrived = \App\Models\Attendance::whereDate('attendance_date', $date)->whereIn('child_id', $roll->pluck('id'))
            ->whereNotNull('signed_in_at')->distinct()->pluck('child_id')->flip();
        $absent = \App\Models\DssDayMark::whereDate('marked_on', $date)->whereIn('child_id', $roll->pluck('id'))
            ->where('mark', \App\Models\DssDayMark::ABSENT)->pluck('child_id')->flip();
        $dayIsOver = self::dayIsOver($date);
        // Late is only ever today's: a day to come has nobody late yet.
        $now = Carbon::now(config('app.timezone'));
        $slots = $date === $now->toDateString()
            ? \App\Models\ScheduleSlot::whereDate('slot_date', $date)->where('is_scheduled', true)
                ->whereIn('child_id', $roll->pluck('id'))->get()->groupBy('child_id')
            : collect();

        $out = [];
        foreach (ClassroomAssignment::rooms() as $room) {
            $out[$room] = self::tally($roll->where('classroom', $room)->map(fn ($child) => self::child(
                booked: $booked[$child->id] ?? false,
                arrived: $arrived->has($child->id),
                absent: $absent->has($child->id),
                late: $date === $now->toDateString() && self::isLate($child, $slots[$child->id] ?? [], $now),
            )), $dayIsOver);
        }

        return $out;
    }

    /** One child's day, as tally() reads it. */
    public static function child(bool $booked, bool $arrived, bool $absent = false, bool $late = false): array
    {
        return ['booked' => $booked, 'arrived' => $arrived, 'absent' => $absent, 'late' => $late];
    }

    /**
     * On screen, "expected" reads "booked today" and "still_to_come" reads
     * "still to come"; "late" is the part of still to come that is over an
     * hour past an agreed time (LATE above).
     *
     * @param  iterable<array{booked: bool, arrived: bool, absent?: bool, late?: bool}>  $children
     * @return array{enrolled: int, expected: int, in: int, absent: int, still_to_come: int, late: int, not_in: int}
     */
    public static function tally(iterable $children, bool $dayIsOver = false): array
    {
        $enrolled = $expected = $in = $absent = $late = 0;

        foreach ($children as $child) {
            $enrolled++;
            $arrived = (bool) $child['arrived'];
            $marked = (bool) ($child['absent'] ?? false);

            if (! $arrived && ! $marked && ! $child['booked']) {
                continue;
            }

            $expected++;

            if ($arrived) {
                $in++;
            } elseif ($marked || $dayIsOver) {
                $absent++;
            } elseif ($child['late'] ?? false) {
                $late++;
            }
        }

        return [
            'enrolled' => $enrolled,
            'expected' => $expected,
            'in' => $in,
            'absent' => $absent,
            'still_to_come' => max(0, $expected - $in - $absent),
            'late' => $late,
            'not_in' => $enrolled - $in,
        ];
    }

    /**
     * Whether a day can still have anybody to come: not once it has gone by
     * in the centre's own time zone, and not in a week that has been locked.
     */
    public static function dayIsOver(string $date, bool $weekLocked = false): bool
    {
        return $weekLocked || $date < Carbon::now(config('app.timezone'))->toDateString();
    }
}
