<?php

namespace App\Services;

use App\Models\Child;
use Illuminate\Support\Carbon;

/**
 * Which room a child belongs in.
 *
 * The room follows the date of birth, so nobody is in the wrong room because a
 * field was typed wrong, and a child crosses into the next room on their
 * birthday without anyone touching the record.
 *
 * The director can override it — a child ready for Transition at 17½ months
 * goes there now — and the override wins until it is cleared. See Child for the
 * override itself; this class only knows the rule it departs from.
 */
class ClassroomAssignment
{
    /**
     * The rooms, youngest first, with the age each one starts at.
     *
     * Every band is closed at the top: the child is in the last room whose
     * starting age they have reached, which puts the boundary age in the higher
     * band. On the day they turn 18 months they are Transition, not Infant.
     */
    public const BANDS = [
        ['room' => 'Infant', 'weeks' => 6],
        ['room' => 'Transition', 'months' => 18],
        ['room' => 'Toddler', 'months' => 24],
        ['room' => 'PreK', 'months' => 36],
        ['room' => 'UPK-4', 'months' => 48],
        ['room' => 'School Age', 'months' => 60],
    ];

    /**
     * School Age ends the way every other band ends — exclusive at the top, so
     * the last day in the room is the day before the 12th birthday.
     */
    public const AGES_OUT_AT_YEARS = 12;

    /** Room names in age order, for pickers and validation. */
    public static function rooms(): array
    {
        return array_column(self::BANDS, 'room');
    }

    /**
     * The room a date of birth puts a child in on a given date, or null when no
     * band covers them — under 6 weeks, or aged out at 12.
     *
     * Null is deliberate. A child with no band reads as unassigned rather than
     * being rounded into the nearest room, because that case is nearly always a
     * date of birth that was typed wrong, and a visible blank gets fixed.
     */
    public static function automaticFor(?Carbon $dob, ?Carbon $asOf = null): ?string
    {
        if (! $dob) {
            return null;
        }

        $dob = $dob->copy()->startOfDay();
        $asOf = ($asOf ? $asOf->copy() : Carbon::today())->startOfDay();

        if ($asOf->lt(self::startOf(self::BANDS[0], $dob)) || $asOf->gte($dob->copy()->addYearsNoOverflow(self::AGES_OUT_AT_YEARS))) {
            return null;
        }

        $room = null;

        foreach (self::BANDS as $band) {
            if ($asOf->lt(self::startOf($band, $dob))) {
                break;
            }

            $room = $band['room'];
        }

        return $room;
    }

    /** Where a room sits in the age order, or null if it is not one of ours. */
    public static function rankOf(?string $room): ?int
    {
        $rank = array_search($room, self::rooms(), true);

        return $rank === false ? null : $rank;
    }

    /**
     * Bring every active child's stored room back in line with the rule.
     *
     * The room is worked out from an age, so it goes stale on a birthday with
     * nobody touching the record. Reports, teacher visibility and the room
     * filters all read the stored column straight from SQL, so the column has
     * to be the answer rather than something recomputed at the point of use.
     *
     * Only rows that actually move are written.
     */
    public static function syncAll(?Carbon $asOf = null): int
    {
        $changed = 0;

        foreach (Child::where('status', 'Active')->get() as $child) {
            $changed += (int) self::sync($child, $asOf);
        }

        return $changed;
    }

    /** Same for one child. True when the room moved. */
    public static function sync(Child $child, ?Carbon $asOf = null): bool
    {
        $room = $child->classroomOn($asOf);

        if ($room === $child->classroom) {
            return false;
        }

        $child->classroom = $room;
        // The value is already the computed one; going through the saving hook
        // again would only compute it a second time.
        $child->saveQuietly();

        return true;
    }

    /**
     * The first day of a band, measured from the date of birth.
     *
     * Without NoOverflow, a child born on the 31st reaches 18 months on March
     * 3rd — Carbon rolls February 31st forward — and sits in Infant three days
     * longer than a child born a day earlier. A February 29th birth turns two on
     * March 1st for the same reason. Month-end births land on the month end.
     */
    private static function startOf(array $band, Carbon $dob): Carbon
    {
        return isset($band['weeks'])
            ? $dob->copy()->addWeeks($band['weeks'])
            : $dob->copy()->addMonthsNoOverflow($band['months']);
    }
}
