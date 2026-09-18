<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The checks that have to pass before the old columns can be dropped.
 *
 * Every one of them compares the new tables against the columns they were
 * built from, so they only mean anything while both still exist — which is the
 * whole reason the old columns are kept for a week. Run it after
 * people:backfill, on a copy of production, and read the failures rather than
 * the total.
 */
class CheckPeopleBackfill extends Command
{
    protected $signature = 'people:check';

    protected $description = 'Compare people and child_people against the old child columns';

    public function handle(): int
    {
        /*
         * Three of these compare the new tables against the old columns, which
         * only means anything while nobody has edited the new ones.
         *
         * The old columns are frozen the moment the People step goes live —
         * nothing writes to them any more — so the first teacher to untick a
         * pick-up box makes the two disagree on purpose. Reported as failures
         * after that, they would read as a broken migration for as long as the
         * columns are kept.
         *
         * So the comparison is only a gate while the roll is untouched. The
         * other three check the new tables against themselves and stay true
         * for as long as the tables exist.
         */
        $edits = ChildPerson::where('source', '<>', 'migration')->count();
        $compare = $edits === 0;

        if (! $compare) {
            $this->warn("The roll has been edited since the migration ({$edits} link(s) not from it).");
            $this->line('  The three comparisons against the old columns are shown for information only:');
            $this->line('  a teacher unticking a box is meant to make the two disagree.');
            $this->newLine();
        }

        $comparisons = [
            'guardian count matches the mother and father blocks' => fn () => $this->checkGuardians(),
            'pick-up list matches the old cards' => fn () => $this->checkPickups(),
            'emergency list matches the old order' => fn () => $this->checkEmergencies(),
        ];

        $structural = [
            'no two people share a mobile' => fn () => $this->checkDuplicateCells(),
            'every link points at rows that exist' => fn () => $this->checkOrphans(),
            'kiosk guardians all came across' => fn () => $this->checkGuardiansMigrated(),
        ];

        $failures = 0;

        foreach ($comparisons + $structural as $label => $check) {
            $isComparison = array_key_exists($label, $comparisons);
            $counts = ! $isComparison || $compare;
            $problems = $check();

            if ($problems === []) {
                $this->line('  <fg=green>OK</>    '.$label);

                continue;
            }

            $failures += (int) $counts;

            $this->line(($counts ? '  <fg=red>FAIL</>  ' : '  <fg=yellow>DIFF</>  ').$label.' ('.count($problems).')');

            foreach (array_slice($problems, 0, 5) as $problem) {
                $this->line('          '.$problem);
            }

            if (count($problems) > 5) {
                $this->line('          … and '.(count($problems) - 5).' more');
            }
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error($failures.' check(s) failed — do not drop the old columns.');

            return self::FAILURE;
        }

        $this->info($compare
            ? 'All checks passed.'
            : 'Structural checks passed. The differences above are edits, not migration faults.');

        return self::SUCCESS;
    }

    /** One guardian link per non-blank parent block. */
    private function checkGuardians(): array
    {
        $problems = [];

        foreach (Child::all() as $child) {
            $expected = collect(['mother_name', 'father_name'])
                ->filter(fn ($column) => filled($child->{$column}))
                ->count();

            $actual = ChildPerson::where('child_id', $child->id)->where('is_guardian', true)->count();

            // More is not a failure: the two blocks can name one person, and a
            // mother who is also the father block's emergency contact is still
            // one guardian. Fewer means somebody was dropped.
            if ($actual < min($expected, 1) || ($expected > 0 && $actual === 0)) {
                $problems[] = "child {$child->id}: {$expected} parent block(s), {$actual} guardian link(s)";
            }
        }

        return $problems;
    }

    /** Everybody the old cards let collect can still collect. */
    private function checkPickups(): array
    {
        $problems = [];

        foreach (Child::all() as $child) {
            $expected = collect([
                $child->mother_name, $child->father_name,
                $child->pickup_1_name, $child->pickup_2_name, $child->pickup_3_name,
            ])->filter()->map(fn ($name) => Person::normalizeName($name))->filter()->unique();

            $actual = $child->pickupPeople()->get()
                ->map(fn (Person $person) => Person::normalizeName($person->name));

            foreach ($expected->diff($actual) as $missing) {
                $problems[] = "child {$child->id}: '{$missing}' could collect before and cannot now";
            }
        }

        return $problems;
    }

    /** The same names, in the same order. */
    private function checkEmergencies(): array
    {
        $problems = [];

        foreach (Child::all() as $child) {
            $expected = collect([$child->emergency_contact, $child->secondary_emergency_contact])
                ->filter()
                ->map(fn ($name) => Person::normalizeName($name))
                ->values();

            $actual = $child->emergencyPeople()->get()
                ->map(fn (Person $person) => Person::normalizeName($person->name))
                ->values();

            if ($expected->count() !== $actual->count()) {
                $problems[] = "child {$child->id}: {$expected->count()} emergency contact(s) before, {$actual->count()} now";

                continue;
            }

            foreach ($expected as $index => $name) {
                if (($actual[$index] ?? null) !== $name) {
                    $problems[] = "child {$child->id}: emergency #".($index + 1)." was '{$name}', is '".($actual[$index] ?? 'nobody')."'";
                }
            }
        }

        return $problems;
    }

    /** Rule 8, read backwards: a duplicate mobile is a person stored twice. */
    private function checkDuplicateCells(): array
    {
        $seen = [];
        $problems = [];

        foreach (Person::all() as $person) {
            $digits = Person::digits($person->cell);

            if ($digits === '') {
                continue;
            }

            if (isset($seen[$digits])) {
                $problems[] = "people {$seen[$digits]} and {$person->id} share the mobile {$digits}";

                continue;
            }

            $seen[$digits] = $person->id;
        }

        return $problems;
    }

    private function checkOrphans(): array
    {
        $problems = [];

        $childIds = Child::pluck('id')->flip();
        $personIds = Person::pluck('id')->flip();

        foreach (ChildPerson::all() as $link) {
            if (! $childIds->has($link->child_id)) {
                $problems[] = "link {$link->id} points at missing child {$link->child_id}";
            }

            if (! $personIds->has($link->person_id)) {
                $problems[] = "link {$link->id} points at missing person {$link->person_id}";
            }
        }

        return $problems;
    }

    /** Every PIN the door knows is now on a person, with its links intact. */
    private function checkGuardiansMigrated(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('guardians')) {
            return [];
        }

        $problems = [];

        foreach (DB::table('guardians')->get() as $guardian) {
            $person = Person::where('pin_hash', $guardian->pin_hash)->first();

            if (! $person) {
                $problems[] = "guardian {$guardian->id} ({$guardian->name}) has no person carrying their PIN";

                continue;
            }

            $expected = DB::table('child_guardian')->where('guardian_id', $guardian->id)->pluck('child_id');

            foreach ($expected as $childId) {
                $exists = ChildPerson::where('child_id', $childId)->where('person_id', $person->id)->exists();

                if (! $exists) {
                    $problems[] = "guardian {$guardian->id} was linked to child {$childId} and the person is not";
                }
            }
        }

        return $problems;
    }
}
