<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The adults on the roll, and what each is for each child.
 *
 * Everything that reads or writes a person or a link goes through here: the
 * People step, the drawer, the child page and — when it arrives — the import.
 * The rules that make the lists trustworthy are rules about data, not about
 * screens, so they are written once, here, rather than in each of the four.
 */
class PeopleDirectory
{
    public function __construct(private PersonMatcher $matcher) {}

    /**
     * Adults matching what somebody typed, with the children they belong to.
     *
     * Name or telephone, because those are the two things a person searching
     * has: the office rings up quoting a number as often as a name. A search
     * is always offered before "create a new person", which is what stops the
     * second Kaylynn being typed in.
     *
     * @return Collection<int, Person>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return new Collection;
        }

        $digits = Person::digits($query);

        return Person::query()
            ->with(['links.child:id,first_name,last_name,lan'])
            ->where(function ($builder) use ($query, $digits) {
                $builder->where('name', 'like', '%'.$query.'%');

                // A number is searched by its digits, so "(585) 820" finds a
                // record stored as "585-820-5029".
                if (strlen($digits) >= 3) {
                    foreach (['cell', 'home_phone', 'work_phone', 'alternate_phone'] as $column) {
                        $builder->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?",
                            ['%'.$digits.'%']
                        );
                    }
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** Everybody on one child's record, guardians first, then pick-up, then the rest. */
    public function forChild(Child $child): Collection
    {
        // links_count comes back on the person so the row can say how many
        // other children they belong to — which is what tells unlinking them
        // from this one apart from taking them off the roll entirely.
        return ChildPerson::with(['person' => fn ($query) => $query->withCount('links')])
            ->where('child_id', $child->id)
            ->get()
            ->sortBy([
                fn (ChildPerson $link) => $link->is_guardian ? 0 : 1,
                fn (ChildPerson $link) => $link->can_pickup ? 0 : 1,
                fn (ChildPerson $link) => $link->priority ?? 99,
                fn (ChildPerson $link) => $link->person?->name ?? '',
            ])
            ->values();
    }

    /**
     * Create a person, refusing one who is already on file.
     *
     * Returning the match rather than a duplicate: somebody who typed a whole
     * record out has not made a mistake worth an error message, they have
     * found the person they were about to create twice.
     *
     * @return array{person: Person, existed: bool}
     */
    public function create(array $fields): array
    {
        $existing = $this->matcher->findExisting($fields);

        if ($existing) {
            return ['person' => $existing, 'existed' => true];
        }

        return ['person' => Person::create($this->clean($fields)), 'existed' => false];
    }

    /** Change a person once, for every child they are linked to. */
    public function update(Person $person, array $fields): Person
    {
        $person->fill($this->clean($fields))->save();

        return $person;
    }

    /**
     * Remove a person entirely, which is only allowed once nothing points at
     * them. Rule 6: the alternative to deleting a linked person is unlinking
     * them from the child in hand, which leaves the other children alone.
     */
    public function delete(Person $person): bool
    {
        if ($person->links()->exists()) {
            return false;
        }

        $person->delete();

        return true;
    }

    /**
     * Write what this person is for this child.
     *
     * One row per pair, so this is an insert or an update and never a second
     * row. The call order is renumbered afterwards, because a list that runs
     * 1, 3, 4 is one somebody has to read twice to ring in the right order.
     */
    public function link(Child $child, Person $person, array $flags, string $source = 'manual'): ChildPerson
    {
        return DB::transaction(function () use ($child, $person, $flags, $source) {
            $link = ChildPerson::firstOrNew([
                'child_id' => $child->id,
                'person_id' => $person->id,
            ]);

            $link->relationship = $flags['relationship'] ?? $link->relationship;
            $link->is_guardian = (bool) ($flags['is_guardian'] ?? false);
            $link->can_pickup = (bool) ($flags['can_pickup'] ?? false);
            $link->is_emergency = (bool) ($flags['is_emergency'] ?? false);

            // Rule 3: a call order only means anything on an emergency contact,
            // and is cleared the moment that tick comes off.
            $link->priority = $link->is_emergency ? ($flags['priority'] ?? null) : null;

            // Blank is no restriction at all, not an empty one — the pick-up
            // list tests for null.
            $restriction = trim((string) ($flags['restriction'] ?? ''));
            $link->restriction = $restriction === '' ? null : $restriction;

            if (! $link->exists) {
                $link->source = $source;
            }

            $link->save();

            $this->renumber($child);

            return $link->fresh();
        });
    }

    /** Take a person off one child, leaving the person and their other children. */
    public function unlink(Child $child, Person $person): void
    {
        DB::transaction(function () use ($child, $person) {
            ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->delete();

            $this->renumber($child);
        });
    }

    /**
     * Who may collect this child.
     *
     * The tick and the absence of a restriction, together. Asked as a query on
     * every read rather than kept as a list, so the answer at the door is the
     * answer the office typed a minute ago.
     */
    public function pickupList(Child $child): Collection
    {
        return ChildPerson::with('person')
            ->where('child_id', $child->id)
            ->allowedToCollect()
            ->get()
            ->sortBy(fn (ChildPerson $link) => $link->person?->name ?? '')
            ->values();
    }

    /** Who to ring, in the order they are rung. */
    public function emergencyList(Child $child): Collection
    {
        return ChildPerson::with('person')
            ->where('child_id', $child->id)
            ->emergencyOrder()
            ->get()
            ->values();
    }

    /**
     * Close the gaps in one child's call order.
     *
     * Runs after every write rather than being maintained by hand: the order
     * is derived from the ticks, and deriving it is the only way it cannot
     * drift from them.
     */
    public function renumber(Child $child): void
    {
        $links = ChildPerson::where('child_id', $child->id)
            ->where('is_emergency', true)
            ->orderByRaw('priority is null, priority')
            ->orderBy('id')
            ->get();

        foreach ($links->values() as $index => $link) {
            if ($link->priority !== $index + 1) {
                $link->forceFill(['priority' => $index + 1])->save();
            }
        }

        ChildPerson::where('child_id', $child->id)
            ->where('is_emergency', false)
            ->whereNotNull('priority')
            ->update(['priority' => null]);
    }

    /**
     * Whether this child has anybody legally responsible for them.
     *
     * Rule 4: a child with nobody is a record somebody has not finished, which
     * is worth saying on screen — and not worth refusing a save over, because
     * the half-finished record is how a real afternoon goes.
     */
    public function lacksGuardian(Child $child): bool
    {
        return ! ChildPerson::where('child_id', $child->id)->where('is_guardian', true)->exists();
    }

    /** Trim, and read "same as household" as nothing at all. */
    private function clean(array $fields): array
    {
        $clean = [];

        foreach (PersonMatcher::FIELDS as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            $value = trim((string) ($fields[$field] ?? ''));
            $clean[$field] = $value === '' ? null : $value;
        }

        if (array_key_exists('address', $clean) && PersonMatcher::meansSameAsHousehold($clean['address'])) {
            $clean['address'] = null;
        }

        return $clean;
    }
}
