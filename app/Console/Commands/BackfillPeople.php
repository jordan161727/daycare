<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
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

    public function handle(PersonMatcher $matcher): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry run — nothing will be written.');
        }

        $counts = ['people' => 0, 'links' => 0, 'reused' => 0];

        DB::transaction(function () use ($matcher, &$counts) {
            $counts = $this->backfill($matcher);
        });

        if ($dry) {
            // Everything above ran; rolling back by throwing would lose the
            // counts, so the transaction is undone here instead.
            DB::rollBack();
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
            foreach ($matcher->merge($this->candidatesFor($child)) as $candidate) {
                $person = $this->personFor($matcher, $candidate, $child, $counts);

                $this->link($child, $person, $candidate, 'migration', $counts);
            }

            $this->renumberEmergencies($child);
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

                $this->link(
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

    /**
     * One child's blocks as candidate adults, in precedence order.
     *
     * Mother and father first because those blocks are the fullest — ten
     * fields each — so when the same person is named again further down with
     * only a telephone, it is the full record that survives the merge.
     *
     * @return list<array<string, mixed>>
     */
    private function candidatesFor(Child $child): array
    {
        $candidates = [];

        foreach (['mother' => 'Mother', 'father' => 'Father'] as $block => $relationship) {
            if (blank($child->{$block.'_name'})) {
                continue;
            }

            $candidates[] = [
                'block' => $block,
                'name' => $child->{$block.'_name'},
                'address' => $child->{$block.'_address'},
                'home_phone' => $child->{$block.'_home_phone'},
                'work_phone' => $child->{$block.'_work_phone'},
                'cell' => $child->{$block.'_cell'},
                'fax' => $child->{$block.'_fax'},
                'email' => $child->{$block.'_email'},
                'employer' => $child->{$block.'_employer'},
                'title' => $child->{$block.'_title'},
                'ssn' => $child->{$block.'_ssn'},
                'relationship' => $relationship,
                'is_guardian' => true,
                'can_pickup' => true,
            ];
        }

        // The first emergency contact is a name and nothing else on this form —
        // there is one telephone box in that section and it belongs to the
        // second. Nearly always it is a name already above, which is why the
        // merge runs before anything is written.
        if (filled($child->emergency_contact)) {
            $candidates[] = [
                'block' => 'emergency_1',
                'name' => $child->emergency_contact,
                'is_emergency' => true,
                'priority' => 1,
            ];
        }

        if (filled($child->secondary_emergency_contact)) {
            $candidates[] = [
                'block' => 'emergency_2',
                'name' => $child->secondary_emergency_contact,
                'cell' => $child->emergency_telephone,
                'relationship' => $child->emergency_relationship,
                'drivers_license' => $child->emergency_license_number,
                'is_emergency' => true,
                'priority' => 2,
            ];
        }

        foreach ([1, 2, 3] as $slot) {
            if (blank($child->{'pickup_'.$slot.'_name'})) {
                continue;
            }

            $candidates[] = [
                'block' => 'pickup_'.$slot,
                'name' => $child->{'pickup_'.$slot.'_name'},
                'address' => $child->{'pickup_'.$slot.'_address'},
                'cell' => $child->{'pickup_'.$slot.'_telephone'},
                'alternate_phone' => $child->{'pickup_'.$slot.'_alternate'},
                'relationship' => $child->{'pickup_'.$slot.'_relationship'},
                'drivers_license' => $child->{'pickup_'.$slot.'_license_number'},
                'can_pickup' => true,
            ];
        }

        return $candidates;
    }

    /** The person this candidate is, found on file or created. */
    private function personFor(PersonMatcher $matcher, array $candidate, Child $child, array &$counts): Person
    {
        $fields = [];

        foreach (PersonMatcher::FIELDS as $field) {
            $fields[$field] = $candidate[$field] ?? null;
        }

        // "Same as household" is stored as nothing at all and resolved against
        // the child when it is shown, so it cannot go stale when they move.
        if (PersonMatcher::meansSameAsHousehold($fields['address'])) {
            $fields['address'] = null;
        }

        $existing = $matcher->findExisting($fields);

        if ($existing) {
            $counts['reused']++;

            // Fill gaps on the record already on file without overwriting it:
            // this child's form may carry an email the other child's did not.
            foreach ($fields as $field => $value) {
                if (filled($value) && blank($existing->{$field})) {
                    $existing->{$field} = $value;
                }
            }

            $existing->save();

            return $existing;
        }

        $counts['people']++;

        return Person::create($fields);
    }

    /** Write the link, OR-ing onto whatever a previous source already set. */
    private function link(Child $child, Person $person, array $candidate, string $source, array &$counts): void
    {
        $link = ChildPerson::firstOrNew([
            'child_id' => $child->id,
            'person_id' => $person->id,
        ]);

        $link->relationship = $link->relationship ?: ($candidate['relationship'] ?? null);
        $link->is_guardian = $link->is_guardian || ($candidate['is_guardian'] ?? false);
        $link->can_pickup = $link->can_pickup || ($candidate['can_pickup'] ?? false);
        $link->is_emergency = $link->is_emergency || ($candidate['is_emergency'] ?? false);

        $priority = $candidate['priority'] ?? null;

        if ($priority !== null) {
            $link->priority = $link->priority === null ? $priority : min($link->priority, $priority);
        }

        $link->source = $link->exists ? $link->source : $source;

        if (! $link->exists) {
            $counts['links']++;
        }

        $link->save();
    }

    /**
     * Close the gaps in one child's call order.
     *
     * Rule 3: priorities are unique per child and run 1, 2, 3 with nothing
     * missing. A form whose first emergency contact was blank would otherwise
     * leave a list that starts at 2.
     */
    private function renumberEmergencies(Child $child): void
    {
        $links = ChildPerson::where('child_id', $child->id)
            ->where('is_emergency', true)
            ->orderByRaw('priority is null, priority')
            ->orderBy('id')
            ->get();

        foreach ($links->values() as $index => $link) {
            $link->priority = $index + 1;
            $link->save();
        }

        // A tick that came off leaves no call order behind.
        ChildPerson::where('child_id', $child->id)
            ->where('is_emergency', false)
            ->whereNotNull('priority')
            ->update(['priority' => null]);
    }
}
