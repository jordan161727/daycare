<?php

namespace App\Services;

use App\Models\Child;
use Illuminate\Http\Request;
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
     * The rooms, youngest first, each with the age range it takes: from
     * `min_months` (inclusive) up to `max_months` (exclusive). Infant alone
     * starts at a number of weeks, `min_weeks`, because six weeks is the
     * licensing floor and is not a whole number of months.
     *
     * The ranges join end to end — each room's max is the next room's min —
     * so every age from 6 weeks to the 12th birthday is in exactly one room.
     * On the day a child turns 19 months they are Transition, not Infant.
     *
     * Everything reads these numbers: the automatic room (automaticFor), the
     * ⚠ on a room the age does not fit (ageFits / fit), and the ages shown
     * beside each room (ageLabel). Second QA brief, Nov 2026: the labels had
     * been the client's own wording — "6 wk–18 mo", "19–24 mo", "3–4 yrs",
     * "4–5 yrs" — which read as a gap between Infant and Transition, a
     * 4y 0m in PreK flagged although "3–4 yrs", and a 5y 9m in UPK-4 not
     * flagged although "4–5 yrs". The rule was these numbers all along; the
     * labels now say them.
     */
    public const BANDS = [
        ['room' => 'Infant', 'min_weeks' => 6, 'max_months' => 19],
        ['room' => 'Transition', 'min_months' => 19, 'max_months' => 24],
        ['room' => 'Toddler', 'min_months' => 24, 'max_months' => 36],
        ['room' => 'PreK', 'min_months' => 36, 'max_months' => 48],
        ['room' => 'UPK-4', 'min_months' => 48, 'max_months' => 72],
        ['room' => 'School Age', 'min_months' => 72, 'max_months' => 144],
    ];

    /**
     * School Age ends the way every other band ends — exclusive at the top, so
     * the last day in the room is the day before the 12th birthday. The same
     * figure as School Age's max_months, in years.
     */
    public const AGES_OUT_AT_YEARS = 12;


    /**
     * The animal each room is known by, youngest first.
     *
     * Children who cannot yet read "Transition" can read a turtle, and a parent
     * asked which room their child is in gets an answer they will remember. The
     * order follows the bands above, so the animals grow up alongside them.
     *
     * Here rather than in the Blade component because two screens need it in
     * two languages — the component renders it, and the attendance sheet hands
     * it to Alpine for the rows it draws in JavaScript. A second copy would
     * drift the first time a room was renamed.
     */
    public const ANIMALS = [
        'Infant' => '🐰',
        'Transition' => '🐢',
        'Toddler' => '🦊',
        'PreK' => '🐻',
        'UPK-4' => '🦉',
        'School Age' => '🐘',
    ];

    /** A room's animal, or a star for one nobody has given an animal to. */
    public static function animal(?string $room): string
    {
        return self::ANIMALS[$room] ?? '⭐';
    }

    /** The whole map, for handing to the browser in one go. */
    public static function animals(): array
    {
        return self::ANIMALS;
    }

    /** Room names in age order, for pickers and validation. */
    public static function rooms(): array
    {
        return array_column(self::BANDS, 'room');
    }

    /**
     * The room named in a page's address, if any: "?infant", "?upk-4",
     * "?upk4", "?school-age", "?schoolage" — a key with no value, spelt
     * however the hand that typed it spells it, matched to a room by its
     * letters alone. "?room=Infant" works too. Anything else is ignored, so
     * a date or a page's own parameters can sit beside it.
     */
    public static function fromQuery(Request $request): ?string
    {
        $wanted = array_filter(array_merge(
            [(string) $request->query('room', '')],
            array_keys($request->query()),
        ));
        $letters = fn (string $text) => preg_replace('/[^a-z0-9]/', '', strtolower($text));

        foreach ($wanted as $candidate) {
            foreach (self::rooms() as $room) {
                if ($letters($candidate) === $letters($room)) {
                    return $room;
                }
            }
        }

        return null;
    }

    /**
     * Any list of rooms, put in the order the centre says them: Infant,
     * Transition, Toddler, PreK, UPK-4, School Age — youngest first, the way
     * the bands run and the way the chips on the register read. A room this
     * class does not know goes after them, alphabetically, rather than being
     * dropped. Alphabetical order put PreK before Toddler and UPK-4 last,
     * which is no order a teacher would recognise.
     *
     * @param  iterable<int, string|null>  $rooms
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function inOrder(iterable $rooms): \Illuminate\Support\Collection
    {
        $rank = array_flip(self::rooms());

        return collect($rooms)
            ->filter(fn ($room) => filled($room))
            ->unique()
            ->sortBy(fn (string $room) => [$rank[$room] ?? count($rank), $room])
            ->values();
    }

    /**
     * The same order as a SQL expression, for sorting a query by room.
     * A CASE rather than FIELD(), so it runs on SQLite as well as MySQL.
     */
    public static function orderSql(string $column = 'classroom'): string
    {
        $cases = collect(self::rooms())
            ->map(fn (string $room, int $rank) => "WHEN '".str_replace("'", "''", $room)."' THEN {$rank}")
            ->implode(' ');

        return "CASE {$column} {$cases} ELSE ".count(self::rooms()).' END';
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

        foreach (self::BANDS as $band) {
            if (self::ageFits($band['room'], $dob, $asOf)) {
                return $band['room'];
            }
        }

        return null;
    }

    /**
     * Whether a date of birth puts a child inside a room's age range on a
     * date: at least the room's minimum, and under its maximum. The one rule
     * for age against room — the automatic room is the room this says yes
     * to, and the ⚠ is drawn where it says no.
     */
    public static function ageFits(?string $room, ?Carbon $dob, ?Carbon $asOf = null): bool
    {
        $band = self::band($room);

        if (! $band || ! $dob) {
            return false;
        }

        $dob = $dob->copy()->startOfDay();
        $asOf = ($asOf ? $asOf->copy() : Carbon::today())->startOfDay();

        return $asOf->gte(self::startOf($band, $dob))
            && $asOf->lt($dob->copy()->addMonthsNoOverflow($band['max_months']));
    }

    /**
     * A room's ages as the rule checks them, worked out from BANDS — "6 wk–
     * under 19 mo", "3–under 4 yrs" — so the Rooms page, the floor plan and
     * every ⚠ say the rule actually applied. "under" because the top is
     * exclusive: a child is out of PreK on their fourth birthday.
     */
    public static function ageLabel(?string $room): ?string
    {
        $band = self::band($room);

        if (! $band) {
            return null;
        }

        $max = $band['max_months'];

        if (isset($band['min_weeks'])) {
            return "{$band['min_weeks']} wk–under {$max} mo";
        }

        $min = $band['min_months'];

        // Whole years from three up — "3–under 4 yrs"; months below, as the
        // centre counts its youngest rooms — "24–under 36 mo".
        return $min >= 36 && $min % 12 === 0 && $max % 12 === 0
            ? intdiv($min, 12).'–under '.intdiv($max, 12).' yrs'
            : "{$min}–under {$max} mo";
    }

    /** A room's entry in BANDS, or null if it is not one of ours. */
    private static function band(?string $room): ?array
    {
        $rank = self::rankOf($room);

        return $rank === null ? null : self::BANDS[$rank];
    }

    /**
     * Whether a child's age fits the room they are in — the one check every
     * ⚠ about age against room is drawn from (QA, 16 Nov 2026: a 5-year-old
     * in Infant with no mark, a 12-year-old past School Age with no mark,
     * and a 5-month-old in Infant flagged although her age fits — her room
     * had been set by hand to the room her age gives, and "the age has
     * caught up with the override" was being drawn as the same ⚠).
     *
     * The answer is ageFits on the room the child is in, and nothing else.
     * A room set by hand does not excuse an age outside it (second QA brief,
     * Nov 2026: a 2y 10m moved up to PreK by hand wore only the ✎, with no
     * word that PreK starts at 3). `by_hand` says whether a director chose
     * the room, so the mark can show both.
     *
     * Returns one of:
     *
     *   ok       the age is inside the room's range, whether or not a
     *            director also set it by hand. No mark.
     *   mismatch the age is outside the room's range — under it, over it, or
     *            outside every room (under 6 weeks, or 12 and over), set by
     *            hand or not. ⚠.
     *   unknown  no date of birth, or no room: nothing to check against.
     *
     * @return array{state: string, text: ?string, automatic: ?string, by_hand: bool}
     */
    public static function fit(Child $child, ?Carbon $asOf = null): array
    {
        $room = $child->classroom;
        $dob = $child->birthDate();
        $byHand = filled($room) && $child->hasClassroomOverrideOn($asOf) && $child->classroom_override === $room;

        if (blank($room) || ! $dob) {
            return ['state' => 'unknown', 'text' => null, 'automatic' => null, 'by_hand' => $byHand];
        }

        $automatic = $child->automaticClassroom($asOf);

        if (self::ageFits($room, $dob, $asOf)) {
            return ['state' => 'ok', 'text' => null, 'automatic' => $automatic, 'by_hand' => $byHand];
        }

        $age = $child->ageInWords($asOf);
        $ages = self::ageLabel($room) ?? 'no age range';
        $set = $byHand ? ' (room set by hand)' : '';

        if ($automatic === null) {
            $asOfDay = ($asOf ? $asOf->copy() : Carbon::today())->startOfDay();
            $tooOld = $asOfDay->gte($dob->copy()->startOfDay()->addYearsNoOverflow(self::AGES_OUT_AT_YEARS));

            return ['state' => 'mismatch', 'automatic' => null, 'by_hand' => $byHand, 'text' => $tooOld
                ? "{$age} — over the School Age limit: {$room} is for {$ages}; no room takes ".self::AGES_OUT_AT_YEARS." and over{$set}."
                : "{$age} — under 6 weeks: {$room} is for {$ages}; no room takes this age yet{$set}."];
        }

        // "5y 1m — Infant is for 6 wk–under 19 mo; by age, UPK-4."
        return ['state' => 'mismatch', 'automatic' => $automatic, 'by_hand' => $byHand, 'text' => "{$age} — {$room} is for {$ages}; by age, {$automatic}{$set}."];
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

        $from = $child->classroom;
        $planned = $child->hasClassroomOverrideOn($asOf);

        $child->classroom = $room;
        // The value is already the computed one; going through the saving hook
        // again would only compute it a second time.
        $child->saveQuietly();

        // The move goes in the enrollment history the same as one made by
        // hand (QA C3/C5): a birthday crossing, or a dated move landing on its
        // day. Nobody is signed in to credit — a page load that triggers the
        // catch-up is not the person who moved the child — so it is unsigned.
        if (filled($from) && filled($room)) {
            $child->statusChanges()->create([
                'event' => 'room',
                'from_status' => $from.' → '.$room,
                'to_status' => $child->status,
                'note' => $planned ? 'Planned move, set by hand' : 'By age',
                'changed_by' => null,
            ]);
        }

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
        return isset($band['min_weeks'])
            ? $dob->copy()->addWeeks($band['min_weeks'])
            : $dob->copy()->addMonthsNoOverflow($band['min_months']);
    }
}
