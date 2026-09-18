<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Support\Str;

/**
 * Deciding when two descriptions of an adult are the same adult.
 *
 * The same question is asked in three places and has to be answered the same
 * way in all of them, or the centre ends up with two Kaylynns: when an old
 * child row is read (she is the mother block and the first emergency contact),
 * when a scanned form is read (the same, plus whatever the handwriting gave),
 * and when somebody types a name into the search box on the People step.
 *
 * Two rules, and deliberately no more:
 *
 *  1. The same mobile number is the same person. A number is chosen by the
 *     person, not written down about them, so two blocks carrying it are two
 *     mentions of one adult.
 *  2. The same name is the same person only when there is no number to
 *     contradict it. "Amy Crumb" on the emergency line and "Amy Crumb" on the
 *     pick-up line are one grandmother; two Brad Adkinses with different
 *     mobiles are a father and a cousin, and merging them would put a stranger
 *     on a pick-up list.
 *
 * Anything less certain is left for a person to decide on the review screen.
 */
class PersonMatcher
{
    /** The fields a candidate can carry, in the order they are unioned. */
    public const FIELDS = [
        'name', 'address', 'home_phone', 'work_phone', 'cell', 'alternate_phone',
        'fax', 'email', 'employer', 'title', 'ssn', 'drivers_license',
    ];

    /**
     * Fold several mentions of adults into one entry per adult.
     *
     * Input order is precedence: the first mention to give a field is the one
     * that keeps it, so the mother block beats the pick-up slot that names her
     * again with only a telephone. Flags are OR-ed rather than overwritten —
     * being named twice can only ever add permissions — and the call order is
     * the earliest any mention claimed.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function merge(array $candidates): array
    {
        $groups = [];

        foreach ($candidates as $candidate) {
            $index = $this->groupFor($candidate, $groups);

            if ($index === null) {
                $groups[] = $this->start($candidate);

                continue;
            }

            $groups[$index] = $this->absorb($groups[$index], $candidate);
        }

        return array_values($groups);
    }

    /**
     * Which group this mention belongs to, or null for a new one.
     *
     * @param  list<array<string, mixed>>  $groups
     */
    private function groupFor(array $candidate, array $groups): ?int
    {
        $cell = Person::digits($candidate['cell'] ?? null);
        $name = Person::normalizeName($candidate['name'] ?? null);

        foreach ($groups as $index => $group) {
            $groupCell = Person::digits($group['cell'] ?? null);

            // Rule 1: the same number is the same person, whatever the name
            // was written as.
            if ($cell !== '' && $cell === $groupCell) {
                return $index;
            }

            // Rule 2: the same name, but only while no number disagrees.
            if ($name !== '' && $name === Person::normalizeName($group['name'] ?? null)
                && ($cell === '' || $groupCell === '')) {
                return $index;
            }
        }

        return null;
    }

    /** A group of one, with the flags the block it came from implies. */
    private function start(array $candidate): array
    {
        $group = [];

        foreach (self::FIELDS as $field) {
            $group[$field] = $this->value($candidate[$field] ?? null);
        }

        $group['relationship'] = $this->value($candidate['relationship'] ?? null);
        $group['is_guardian'] = (bool) ($candidate['is_guardian'] ?? false);
        $group['can_pickup'] = (bool) ($candidate['can_pickup'] ?? false);
        $group['is_emergency'] = (bool) ($candidate['is_emergency'] ?? false);
        $group['priority'] = $candidate['priority'] ?? null;
        $group['blocks'] = [$candidate['block'] ?? 'manual'];

        return $group;
    }

    /** A second mention of somebody already in the list. */
    private function absorb(array $group, array $candidate): array
    {
        foreach (self::FIELDS as $field) {
            // First non-empty wins: a later mention fills gaps, it does not
            // overwrite what an earlier and usually fuller block said.
            if (blank($group[$field]) && filled($this->value($candidate[$field] ?? null))) {
                $group[$field] = $this->value($candidate[$field]);
            }
        }

        if (blank($group['relationship']) && filled($candidate['relationship'] ?? null)) {
            $group['relationship'] = $this->value($candidate['relationship']);
        }

        $group['is_guardian'] = $group['is_guardian'] || ($candidate['is_guardian'] ?? false);
        $group['can_pickup'] = $group['can_pickup'] || ($candidate['can_pickup'] ?? false);
        $group['is_emergency'] = $group['is_emergency'] || ($candidate['is_emergency'] ?? false);

        // Called earliest wins: being named first on one line and third on
        // another means they are rung first.
        $priority = $candidate['priority'] ?? null;

        if ($priority !== null) {
            $group['priority'] = $group['priority'] === null ? $priority : min($group['priority'], $priority);
        }

        $group['blocks'][] = $candidate['block'] ?? 'manual';

        return $group;
    }

    /**
     * Somebody already on file who is this person, or null.
     *
     * The mobile first, because it is the rule that does not guess. Falling
     * back to the name only when there is no number on either side, for the
     * same reason merge() does.
     */
    public function findExisting(array $person): ?Person
    {
        $cell = Person::digits($person['cell'] ?? null);

        if ($cell !== '') {
            $match = Person::all()->first(fn (Person $existing) => Person::digits($existing->cell) === $cell);

            if ($match) {
                return $match;
            }

            // A number that matches nobody is a new person. Deliberately not
            // falling through to the name: this person has a mobile and it is
            // not on file, so a same-named record is somebody else.
            return null;
        }

        $name = Person::normalizeName($person['name'] ?? null);

        if ($name === '') {
            return null;
        }

        return Person::all()->first(fn (Person $existing) => Person::normalizeName($existing->name) === $name
            && Person::digits($existing->cell) === '');
    }

    /**
     * Trim, and read the several ways a form says "the same as the child's".
     *
     * An address written out that happens to equal the household is still
     * stored as written; only the words that mean "look at the child's row"
     * become null. Resolving the two is the caller's job — see
     * BackfillPeople, which has the household in hand.
     */
    private function value(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        return $value;
    }

    /** Whether an address field is one of the ways a form says "as above". */
    public static function meansSameAsHousehold(?string $address): bool
    {
        $value = Str::lower(trim((string) $address));

        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^(same|same as (above|household|child\'?s?( household)?)|as above|ditto|")$/', $value);
    }
}
