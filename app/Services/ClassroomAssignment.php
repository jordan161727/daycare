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
     * The rooms, youngest first, with the age each one starts at.
     *
     * Every band is closed at the top: the child is in the last room whose
     * starting age they have reached, which puts the boundary age in the higher
     * band. On the day they turn 19 months they are Transition, not Infant.
     *
     * The client's ranges (QA, 16 Nov 2026): Infant 6 wk–18 mo, Transition
     * 19–24 mo, Toddler 24–36 mo, PreK 3–4 yrs, UPK-4 4–5 yrs, School Age
     * 6–12 yrs. "18 mo" is the eighteenth month, so Transition starts at 19;
     * a five-year-old is UPK-4 until their sixth birthday.
     */
    public const BANDS = [
        ['room' => 'Infant', 'weeks' => 6],
        ['room' => 'Transition', 'months' => 19],
        ['room' => 'Toddler', 'months' => 24],
        ['room' => 'PreK', 'months' => 36],
        ['room' => 'UPK-4', 'months' => 48],
        ['room' => 'School Age', 'months' => 72],
    ];

    /**
     * School Age ends the way every other band ends — exclusive at the top, so
     * the last day in the room is the day before the 12th birthday.
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

    /**
     * A room's ages as the bands assign them — "6 wk–18 mo", "5–12 yrs" —
     * for the ⚠ tooltips, so the reason given is the rule actually checked.
     * The bands follow the client's ranges since 16 Nov 2026, so the label
     * is the client's own wording (config daycare.room_ages) when there is
     * one — "6 wk–18 mo", "4–5 yrs" — and worked out from the bands if not.
     */
    public static function ageLabel(?string $room): ?string
    {
        $rank = self::rankOf($room);

        if ($rank === null) {
            return null;
        }

        if (filled($label = config("daycare.room_ages.$room"))) {
            return $label;
        }

        $say = function (array $band): array {
            if (isset($band['weeks'])) {
                return [$band['weeks'], 'wk'];
            }

            return $band['months'] >= 24 && $band['months'] % 12 === 0
                ? [intdiv($band['months'], 12), 'yrs']
                : [$band['months'], 'mo'];
        };

        [$from, $fromUnit] = $say(self::BANDS[$rank]);
        [$to, $toUnit] = isset(self::BANDS[$rank + 1])
            ? $say(self::BANDS[$rank + 1])
            : [self::AGES_OUT_AT_YEARS, 'yrs'];

        // "2–3 yrs" when both ends are in years; "6 wk–18 mo" when they differ.
        if ($fromUnit === 'mo' && $toUnit === 'yrs') {
            [$to, $toUnit] = [$to * 12, 'mo'];
        }

        return $fromUnit === $toUnit ? "{$from}–{$to} {$toUnit}" : "{$from} {$fromUnit}–{$to} {$toUnit}";
    }

    /**
     * Whether a child's age fits the room they are in — the one check every
     * ⚠ about age against room is drawn from (QA, 16 Nov 2026: a 5-year-old
     * in Infant with no mark, a 12-year-old past School Age with no mark,
     * and a 5-month-old in Infant flagged although her age fits — her room
     * had been set by hand to the room her age gives, and "the age has
     * caught up with the override" was being drawn as the same ⚠).
     *
     * Returns one of:
     *
     *   ok       the room is the room the age bands give, whether or not a
     *            director also set it by hand. No mark.
     *   placed   the director set a different room on purpose, and it is
     *            one band ahead of the age (moved up early — what
     *            overrides are for). A soft note (✎), not a warning.
     *   mismatch the age does not fit the room: no age band covers the
     *            child at all (under 6 weeks, or 12 and over — no room is
     *            licensed for them, override or not); or the room is behind
     *            the age (held back, or an override the age has passed); or
     *            more than one band ahead; or a room nobody set by hand
     *            that the age bands disagree with. ⚠.
     *   unknown  no date of birth, or no room: nothing to check against.
     *
     * @return array{state: string, text: ?string, automatic: ?string}
     */
    public static function fit(Child $child, ?Carbon $asOf = null): array
    {
        $room = $child->classroom;
        $dob = $child->birthDate();

        if (blank($room) || ! $dob) {
            return ['state' => 'unknown', 'text' => null, 'automatic' => null];
        }

        $automatic = $child->automaticClassroom($asOf);
        $age = $child->ageInWords($asOf);

        if ($automatic === null) {
            $asOfDay = ($asOf ? $asOf->copy() : Carbon::today())->startOfDay();
            $tooOld = $asOfDay->gte($dob->copy()->startOfDay()->addYearsNoOverflow(self::AGES_OUT_AT_YEARS));

            return ['state' => 'mismatch', 'automatic' => null, 'text' => $tooOld
                ? "{$age} — over the School Age limit: {$room} is for ".self::ageLabel($room).'; no room takes '.self::AGES_OUT_AT_YEARS.' and over.'
                : "{$age} — under 6 weeks: {$room} is for ".self::ageLabel($room).'; no room takes this age yet.'];
        }

        if ($automatic === $room) {
            return ['state' => 'ok', 'text' => null, 'automatic' => $automatic];
        }

        $gap = (self::rankOf($room) ?? -99) - (self::rankOf($automatic) ?? 0);
        $byHand = $child->hasClassroomOverrideOn($asOf) && $child->classroom_override === $room;
        $ages = self::ageLabel($room);

        if ($byHand && $gap === 1) {
            return ['state' => 'placed', 'automatic' => $automatic, 'text' => "{$age} — moved up early to {$room} by hand; {$automatic} by age."];
        }

        // "5y 1m — Infant is for 6 wk–18 mo; by age, School Age."
        return ['state' => 'mismatch', 'automatic' => $automatic, 'text' => "{$age} — {$room} is for {$ages}; by age, {$automatic}".($byHand ? ' (room set by hand).' : '.')];
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
        return isset($band['weeks'])
            ? $dob->copy()->addWeeks($band['weeks'])
            : $dob->copy()->addMonthsNoOverflow($band['months']);
    }
}
