<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Services\PeopleDirectory;
use App\Services\PersonMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move every adult out of the children table and into people.
 *
 * Run once per environment. The old columns are left exactly as they are — the
 * point of keeping them for a week is that this can be run again after a fix
 * without anything having been lost, and the checks at the end can be read
 * against the source they came from.
 *
 * Re-runnable on purpose. Links are written by (child, person) so a second run
 * updates rather than duplicates, and a person already matched by mobile is
 * reused rather than inserted again.
 *
 * Two sources, in this order:
 *
 *  1. The guardians table, which the door kiosk uses. First because those rows
 *     carry PINs, and a PIN is the one thing here that cannot be reconstructed
 *     from a child's row — going first means the person who ends up holding it
 *     is the one the office blocks then merge into.
 *  2. Each child's mother, father, emergency and pick-up blocks.
 */
class BackfillPeople extends Command
{
    protected $signature = 'people:backfill {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Fill people and child_people from the old child columns and the guardians table';

    public function __construct(private PeopleDirectory $directory)
    {
        parent::__construct();
    }

    public function handle(PersonMatcher $matcher): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry run — nothing will be written.');
        }

        $counts = ['people' => 0, 'links' => 0, 'reused' => 0];

        /*
         * A dry run is undone by throwing, which is the only thing that undoes
         * it: DB::transaction() commits the moment its closure returns, so a
         * rollBack() afterwards has no open transaction to act on and does
         * nothing at all. This command printed "nothing will be written" and
         * then wrote everything.
         *
         * The counts survive the throw because $counts is bound by reference —
         * it is assigned before the exception is raised.
         */
        try {
            DB::transaction(function () use ($matcher, &$counts, $dry) {
                $counts = $this->backfill($matcher);

                if ($dry) {
                    throw new DryRunComplete;
                }
            });
        } catch (DryRunComplete) {
            // Rolled back. Anything else is a real failure and is left to rise.
        }

        $this->table(
            ['People created', 'People reused', 'Links written'],
            [[$counts['people'], $counts['reused'], $counts['links']]]
        );

        return self::SUCCESS;
    }

    private function backfill(PersonMatcher $matcher): array
    {
        $counts = ['people' => 0, 'links' => 0, 'reused' => 0];

        $this->backfillGuardians($matcher, $counts);

        $children = Child::query()->orderBy('id')->get();
        $bar = $this->output->createProgressBar($children->count());

        foreach ($children as $child) {
            // The same reading of the same columns that a scanned enrollment
            // form gets when it is saved — see PeopleDirectory. One copy, so
            // the migration and the import cannot disagree about what a form
            // says.
            $found = $this->directory->absorbContactBlocks($child, 'migration');

            foreach ($counts as $key => $value) {
                $counts[$key] = $value + $found[$key];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $counts;
    }

    /**
     * The kiosk's guardians, brought across with their PINs.
     *
     * can_collect is the same permission as can_pickup — it is the tick the
     * door already enforces — so it arrives as one. No restriction can come
     * across because the old table had nowhere to record one.
     */
    private function backfillGuardians(PersonMatcher $matcher, array &$counts): void
    {
        if (! DB::getSchemaBuilder()->hasTable('guardians')) {
            return;
        }

        foreach (DB::table('guardians')->orderBy('id')->get() as $guardian) {
            $person = $matcher->findExisting(['cell' => $guardian->phone, 'name' => $guardian->name]);

            if ($person) {
                $counts['reused']++;
            } else {
                $person = new Person([
                    'name' => $guardian->name,
                    'cell' => $guardian->phone,
                ]);
                $counts['people']++;
            }

            // The PIN and its lockout state, which only exist here.
            $person->pin_index = $guardian->pin_index;
            $person->pin_hash = $guardian->pin_hash;
            $person->phone_last4 = $guardian->phone_last4;
            $person->failed_attempts = $guardian->failed_attempts;
            $person->locked_until = $guardian->locked_until;
            $person->save();

            $links = DB::table('child_guardian')->where('guardian_id', $guardian->id)->get();

            foreach ($links as $link) {
                if (! Child::whereKey($link->child_id)->exists()) {
                    continue;
                }

                $this->directory->absorbLink(
                    Child::find($link->child_id),
                    $person,
                    [
                        'relationship' => $guardian->relationship,
                        'can_pickup' => (bool) $link->can_collect,
                        'is_guardian' => false,
                        'is_emergency' => false,
                        'priority' => null,
                    ],
                    'migration',
                    $counts
                );
            }
        }
    }

}
