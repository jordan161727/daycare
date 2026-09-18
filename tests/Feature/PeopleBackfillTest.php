<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Moving the adults out of the children table.
 *
 * The cases here are the ones the old shape could not express and the ones the
 * move is most likely to get wrong: a mother named twice on one form, a
 * grandmother named on two children, a father who must not be merged with a
 * stranger of the same name.
 *
 * Built from fixtures rather than from whatever happens to be in the database:
 * the development copy has contact details on exactly one child, so running
 * the script against it proves almost nothing.
 */
class PeopleBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mother_named_twice_on_one_form_becomes_one_person(): void
    {
        // Kaylynn is the mother block and the first emergency contact — the
        // commonest shape on the paper form, and the one that used to produce
        // two of her.
        $child = $this->makeChild([
            'mother_name' => 'Kaylynn Adkins',
            'mother_cell' => '585-820-5029',
            'mother_email' => 'kaylynn@example.com',
            'emergency_contact' => 'Kaylynn Adkins',
        ]);

        $this->backfill();

        $this->assertSame(1, Person::count());

        $person = Person::first();
        $this->assertSame('kaylynn@example.com', $person->email);

        // Named in two blocks, so she carries what both of them meant.
        $link = ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->firstOrFail();
        $this->assertTrue($link->is_guardian, 'the mother block makes her a guardian');
        $this->assertTrue($link->can_pickup);
        $this->assertTrue($link->is_emergency, 'the emergency line makes her a contact');
        $this->assertSame(1, $link->priority);
        $this->assertSame('Mother', $link->relationship);
    }

    public function test_the_emergency_telephone_belongs_to_the_second_contact(): void
    {
        // There is one telephone box in that section of the form and it sits
        // under the secondary name. Attaching it to the first would put a
        // grandmother's number on the mother's record.
        $child = $this->makeChild([
            'emergency_contact' => 'Kaylynn Adkins',
            'secondary_emergency_contact' => 'Amy Crumb',
            'emergency_telephone' => '585-352-8844',
            'emergency_relationship' => 'Grandmother',
        ]);

        $this->backfill();

        $amy = Person::where('name', 'Amy Crumb')->firstOrFail();
        $this->assertSame('585-352-8844', $amy->cell);

        $kaylynn = Person::where('name', 'Kaylynn Adkins')->firstOrFail();
        $this->assertNull($kaylynn->cell);

        $this->assertSame(1, $this->linkFor($child, $kaylynn)->priority);
        $this->assertSame(2, $this->linkFor($child, $amy)->priority);
    }

    public function test_the_same_grandmother_on_two_blocks_merges_on_her_mobile(): void
    {
        // Amy is emergency #2 and pick-up 1 with the same number. One person,
        // both ticks.
        $child = $this->makeChild([
            'secondary_emergency_contact' => 'Amy Crumb',
            'emergency_telephone' => '585-352-8844',
            'emergency_relationship' => 'Grandmother',
            'pickup_1_name' => 'Amy Crumb',
            'pickup_1_telephone' => '585-352-8844',
            'pickup_1_relationship' => 'Grandmother',
            'pickup_1_address' => '13 Holly Circle, Rochester, NY 14559',
        ]);

        $this->backfill();

        $this->assertSame(1, Person::where('name', 'Amy Crumb')->count());

        $amy = Person::where('name', 'Amy Crumb')->firstOrFail();
        $this->assertSame('13 Holly Circle, Rochester, NY 14559', $amy->address);

        $link = $this->linkFor($child, $amy);
        $this->assertTrue($link->is_emergency);
        $this->assertTrue($link->can_pickup);
        $this->assertFalse($link->is_guardian, 'a pick-up adult is not thereby a legal guardian');
    }

    public function test_one_mother_of_two_children_is_one_record_with_two_links(): void
    {
        $maeve = $this->makeChild(['mother_name' => 'Kaylynn Adkins', 'mother_cell' => '585-820-5029']);
        $sibling = $this->makeChild(['mother_name' => 'Kaylynn Adkins', 'mother_cell' => '(585) 820 5029']);

        $this->backfill();

        // Written two ways on two forms; the digits are what is compared.
        $this->assertSame(1, Person::count());
        $this->assertSame(2, ChildPerson::count());

        $person = Person::first();
        $this->assertEqualsCanonicalizing(
            [$maeve->id, $sibling->id],
            $person->children()->pluck('children.id')->all()
        );
    }

    public function test_two_people_of_the_same_name_with_different_mobiles_stay_apart(): void
    {
        // The rule that stops a merge being dangerous: a matching name is only
        // trusted when there is no number to contradict it.
        $this->makeChild(['father_name' => 'Brad Adkins', 'father_cell' => '716-510-5162']);
        $this->makeChild(['father_name' => 'Brad Adkins', 'father_cell' => '716-555-0000']);

        $this->backfill();

        $this->assertSame(2, Person::where('name', 'Brad Adkins')->count());
    }

    public function test_same_as_household_is_stored_as_nothing(): void
    {
        $child = $this->makeChild([
            'mother_name' => 'Kaylynn Adkins',
            'mother_cell' => '585-820-5029',
            'mother_address' => 'Same as household',
        ]);

        $this->backfill();

        // Rule 5: it is resolved against the child when shown, never copied —
        // a copy is what goes stale when the family moves.
        $this->assertNull(Person::first()->address);
        $this->assertSame('8 Northbrook Ct', $child->fresh()->address);
    }

    public function test_the_call_order_is_renumbered_without_gaps(): void
    {
        // No first emergency contact on the form; the second must not be left
        // as number two of a list that has no number one.
        $child = $this->makeChild([
            'secondary_emergency_contact' => 'Amy Crumb',
            'emergency_telephone' => '585-352-8844',
        ]);

        $this->backfill();

        $this->assertSame(1, $this->linkFor($child, Person::firstOrFail())->priority);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        // The promise the flag makes, and the one worth a test of its own:
        // somebody runs this against production to see what it would do.
        $this->makeChild([
            'mother_name' => 'Kaylynn Adkins',
            'mother_cell' => '585-820-5029',
            'pickup_1_name' => 'Amy Crumb',
            'pickup_1_telephone' => '585-352-8844',
        ]);

        $this->artisan('people:backfill --dry-run')->assertSuccessful();

        $this->assertSame(0, Person::count(), 'a dry run wrote people to the database');
        $this->assertSame(0, ChildPerson::count(), 'a dry run wrote links to the database');

        // And it still reports what it would have done.
        $this->backfill();
        $this->assertSame(2, Person::count());
    }

    public function test_the_script_can_be_run_twice_without_duplicating(): void
    {
        $this->makeChild([
            'mother_name' => 'Kaylynn Adkins',
            'mother_cell' => '585-820-5029',
            'emergency_contact' => 'Kaylynn Adkins',
            'pickup_1_name' => 'Amy Crumb',
            'pickup_1_telephone' => '585-352-8844',
        ]);

        $this->backfill();

        $people = Person::count();
        $links = ChildPerson::count();

        $this->backfill();

        $this->assertSame($people, Person::count());
        $this->assertSame($links, ChildPerson::count());
    }

    public function test_the_kiosk_guardians_come_across_with_their_pins(): void
    {
        $child = $this->makeChild([]);

        $guardianId = DB::table('guardians')->insertGetId([
            'name' => 'Wendy Adkins',
            'relationship' => 'Grandmother',
            'phone' => '716-812-6160',
            'pin_index' => 'idx-wendy',
            'pin_hash' => 'hash-wendy',
            'phone_last4' => '6160',
            'failed_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('child_guardian')->insert([
            'child_id' => $child->id,
            'guardian_id' => $guardianId,
            'can_collect' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->backfill();

        $wendy = Person::where('name', 'Wendy Adkins')->firstOrFail();

        // The PIN is the one thing that cannot be rebuilt from a child's row.
        $this->assertSame('hash-wendy', $wendy->pin_hash);
        $this->assertTrue($wendy->hasPin());

        // can_collect at the door is can_pickup on the record.
        $this->assertTrue($this->linkFor($child, $wendy)->can_pickup);
    }

    public function test_a_guardian_and_a_parent_block_with_one_mobile_become_one_person(): void
    {
        // The case the fold exists for: the door knows her by PIN, the office
        // knows her as the mother, and they are the same woman.
        $child = $this->makeChild(['mother_name' => 'Kaylynn Adkins', 'mother_cell' => '585-820-5029']);

        $guardianId = DB::table('guardians')->insertGetId([
            'name' => 'Kaylynn Adkins',
            'relationship' => 'Mother',
            'phone' => '5858205029',
            'pin_index' => 'idx-kay',
            'pin_hash' => 'hash-kay',
            'phone_last4' => '5029',
            'failed_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('child_guardian')->insert([
            'child_id' => $child->id,
            'guardian_id' => $guardianId,
            'can_collect' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->backfill();

        $this->assertSame(1, Person::count(), 'the door and the office had the same woman twice');

        $person = Person::firstOrFail();
        $this->assertSame('hash-kay', $person->pin_hash);

        $link = $this->linkFor($child, $person);
        $this->assertTrue($link->is_guardian);
        $this->assertTrue($link->can_pickup);
    }

    public function test_the_checks_pass_on_migrated_data(): void
    {
        $this->makeChild([
            'mother_name' => 'Kaylynn Adkins',
            'mother_cell' => '585-820-5029',
            'father_name' => 'Brad Adkins',
            'father_cell' => '716-510-5162',
            'emergency_contact' => 'Kaylynn Adkins',
            'secondary_emergency_contact' => 'Amy Crumb',
            'emergency_telephone' => '585-352-8844',
            'pickup_1_name' => 'Amy Crumb',
            'pickup_1_telephone' => '585-352-8844',
            'pickup_2_name' => 'Wendy Adkins',
            'pickup_2_telephone' => '716-812-6160',
        ]);

        $this->backfill();

        $this->artisan('people:check')->assertSuccessful();
    }

    public function test_a_restriction_beats_the_pick_up_tick(): void
    {
        // Rule 2. Nothing in the old columns can produce one, so it is set by
        // hand here — this is the guarantee the child page depends on.
        $child = $this->makeChild(['mother_name' => 'Kaylynn Adkins', 'mother_cell' => '585-820-5029']);

        $this->backfill();

        $person = Person::firstOrFail();
        $this->assertTrue($child->pickupPeople()->where('people.id', $person->id)->exists());

        $this->linkFor($child, $person)->update(['restriction' => 'Court order — must not collect']);

        $this->assertFalse(
            $child->fresh()->pickupPeople()->where('people.id', $person->id)->exists(),
            'a court order has to beat a tick somebody left on'
        );
    }

    private function backfill(): void
    {
        $this->artisan('people:backfill')->assertSuccessful();
    }

    private function linkFor(Child $child, Person $person): ChildPerson
    {
        return ChildPerson::where('child_id', $child->id)->where('person_id', $person->id)->firstOrFail();
    }

    private function makeChild(array $attributes): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (10000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'dob' => '2022-12-15',
            'address' => '8 Northbrook Ct',
            'city' => 'Lancaster',
            'zip' => '14086',
            'telephone' => '716-510-5162',
        ], $attributes));
    }
}
