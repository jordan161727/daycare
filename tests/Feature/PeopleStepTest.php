<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The People step and the drawer behind it.
 *
 * What is checked here is the promises the two screens make: that a person
 * shared between two children is one record, that a tick is per child, that a
 * court order beats the tick, and that the ten fields can only be typed once.
 */
class PeopleStepTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_edit_form_offers_a_people_step_instead_of_parents_and_pickup(): void
    {
        $child = $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Linked people', $html);
        $this->assertStringContainsString('Add a person to', $html);

        // The two steps it replaces are gone from the stepper, and so are the
        // sixty columns they drew.
        $this->assertStringNotContainsString('Emergency &amp; pickup', $html);
        $this->assertStringNotContainsString('name="mother_name"', $html);
        $this->assertStringNotContainsString('name="pickup_1_name"', $html);
    }

    public function test_taking_somebody_away_is_asked_in_the_app_not_by_the_browser(): void
    {
        $child = $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->getContent();

        // window.confirm puts "127.0.0.1:8000 says" above the question and can
        // only manage one line — so unlinking and deleting read identically,
        // when one is reversible and the other is not.
        $this->assertStringNotContainsString('confirm(`', $html);
        $this->assertStringNotContainsString('if (! confirm(', $html);

        // The dialog that replaces it, and the two things the one-liner could
        // not say: what the person is for this child, and whether the record
        // survives.
        $this->assertStringContainsString('role="alertdialog"', $html);
        $this->assertStringContainsString('What they are for', $html);
        $this->assertStringContainsString('marksFor(row)', $html);
        $this->assertStringContainsString("tone: 'rose'", $html);
        $this->assertStringContainsString('This cannot be undone.', $html);
    }

    public function test_saving_the_child_no_longer_touches_the_old_contact_columns(): void
    {
        // They are still on the table and still hold what the migration put
        // there. A save from a form that no longer posts them must leave them
        // alone rather than blanking them.
        $child = $this->makeChild(['mother_name' => 'Kaylynn Adkins', 'pickup_1_name' => 'Amy Crumb']);

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), [
                'first_name' => 'Maeve',
                'last_name' => 'Adkins',
                'status' => 'Active',
            ])
            ->assertRedirect();

        $child->refresh();
        $this->assertSame('Kaylynn Adkins', $child->mother_name);
        $this->assertSame('Amy Crumb', $child->pickup_1_name);
    }

    public function test_a_person_is_created_and_linked_in_one_press(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->postJson(route('people.store'), [
                'child_id' => $child->id,
                'name' => 'Kaylynn Adkins',
                'cell' => '585-820-5029',
                'email' => 'kaylynn@example.com',
                'relationship' => 'Mother',
                'is_guardian' => true,
                'can_pickup' => true,
            ])
            ->assertOk()
            ->assertJson(['status' => 'created']);

        $person = Person::firstOrFail();
        $this->assertSame('Kaylynn Adkins', $person->name);

        $link = ChildPerson::firstOrFail();
        $this->assertTrue($link->is_guardian);
        $this->assertTrue($link->can_pickup);
        $this->assertSame('Mother', $link->relationship);
    }

    public function test_creating_somebody_already_on_file_links_them_rather_than_duplicating(): void
    {
        $first = $this->makeChild();
        $second = $this->makeChild(['first_name' => 'Sibling']);

        $this->actingAs($this->admin)->postJson(route('people.store'), [
            'child_id' => $first->id, 'name' => 'Kaylynn Adkins', 'cell' => '585-820-5029',
        ])->assertOk();

        // The same woman typed out again on her second child's record. Not an
        // error worth refusing — she has been found, not duplicated.
        $this->actingAs($this->admin)->postJson(route('people.store'), [
            'child_id' => $second->id, 'name' => 'Kaylynn Adkins', 'cell' => '(585) 820 5029',
        ])->assertOk()->assertJson(['status' => 'linked_existing']);

        $this->assertSame(1, Person::count());
        $this->assertSame(2, ChildPerson::count());
    }

    public function test_the_search_finds_people_by_name_and_by_number(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('people.store'), [
            'child_id' => $child->id, 'name' => 'Kaylynn Adkins', 'cell' => '585-820-5029', 'relationship' => 'Mother',
        ])->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('people.search', ['q' => 'kay']))
            ->assertOk()
            ->assertJsonPath('people.0.name', 'Kaylynn Adkins');

        // The office rings up quoting a number, and it is written down a
        // different way every time.
        $this->actingAs($this->admin)
            ->getJson(route('people.search', ['q' => '(585) 820']))
            ->assertOk()
            ->assertJsonPath('people.0.name', 'Kaylynn Adkins');
    }

    public function test_the_search_says_which_families_a_person_already_belongs_to(): void
    {
        // The whole reason the search is offered before "create new": it is how
        // somebody tells this Amy Crumb from another one.
        $child = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('people.store'), [
            'child_id' => $child->id, 'name' => 'Kaylynn Adkins', 'cell' => '585-820-5029', 'relationship' => 'Mother',
        ])->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('people.search', ['q' => 'kay', 'child_id' => $child->id]))
            ->assertOk()
            ->assertJsonPath('people.0.families.0', 'Mother of Maeve Adkins')
            ->assertJsonPath('people.0.already_linked', true);
    }

    public function test_the_ticks_are_per_child(): void
    {
        $maeve = $this->makeChild();
        $sibling = $this->makeChild(['first_name' => 'Sibling']);

        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        $this->actingAs($this->admin)->postJson(route('people.link', $person), [
            'child_id' => $maeve->id, 'relationship' => 'Mother', 'is_guardian' => true, 'can_pickup' => true,
        ])->assertOk();

        $this->actingAs($this->admin)->postJson(route('people.link', $person), [
            'child_id' => $sibling->id, 'relationship' => 'Mother', 'is_guardian' => false, 'can_pickup' => false,
        ])->assertOk();

        $this->assertTrue(ChildPerson::where('child_id', $maeve->id)->firstOrFail()->is_guardian);
        $this->assertFalse(ChildPerson::where('child_id', $sibling->id)->firstOrFail()->is_guardian);
    }

    public function test_a_restriction_takes_somebody_off_the_pick_up_list(): void
    {
        $child = $this->makeChild();
        $person = Person::create(['name' => 'Jordan Adkins', 'cell' => '585-000-0000']);

        $this->actingAs($this->admin)->postJson(route('people.link', $person), [
            'child_id' => $child->id, 'can_pickup' => true,
        ])->assertOk();

        $this->assertTrue($child->pickupPeople()->where('people.id', $person->id)->exists());

        // The tick stays on, which is the case worth testing: the court order
        // is what decides, not somebody remembering to untick a box.
        $this->actingAs($this->admin)->postJson(route('people.link', $person), [
            'child_id' => $child->id, 'can_pickup' => true, 'restriction' => 'Court order — must not collect',
        ])->assertOk();

        $this->assertTrue(ChildPerson::firstOrFail()->can_pickup);
        $this->assertFalse($child->pickupPeople()->where('people.id', $person->id)->exists());
    }

    public function test_the_call_order_is_renumbered_without_gaps(): void
    {
        $child = $this->makeChild();

        foreach ([['Amy Crumb', '585-352-8844'], ['Wendy Adkins', '716-812-6160'], ['Brad Adkins', '716-510-5162']] as [$name, $cell]) {
            $person = Person::create(['name' => $name, 'cell' => $cell]);

            $this->actingAs($this->admin)->postJson(route('people.link', $person), [
                'child_id' => $child->id, 'is_emergency' => true,
            ])->assertOk();
        }

        $this->assertSame([1, 2, 3], ChildPerson::where('child_id', $child->id)->orderBy('priority')->pluck('priority')->all());

        // Taking the first off cannot leave a list that starts at two.
        $first = ChildPerson::where('priority', 1)->firstOrFail();

        $this->actingAs($this->admin)->postJson(route('people.link', Person::find($first->person_id)), [
            'child_id' => $child->id, 'is_emergency' => false,
        ])->assertOk();

        $this->assertSame([1, 2], ChildPerson::where('child_id', $child->id)->where('is_emergency', true)->orderBy('priority')->pluck('priority')->all());
        $this->assertNull($first->fresh()->priority, 'a tick that came off leaves no call order behind');
    }

    public function test_unlinking_leaves_the_person_and_their_other_children(): void
    {
        $maeve = $this->makeChild();
        $sibling = $this->makeChild(['first_name' => 'Sibling']);
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        foreach ([$maeve, $sibling] as $child) {
            $this->actingAs($this->admin)->postJson(route('people.link', $person), ['child_id' => $child->id])->assertOk();
        }

        $this->actingAs($this->admin)
            ->postJson(route('people.unlink', $person), ['child_id' => $maeve->id])
            ->assertOk();

        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertSame(1, ChildPerson::where('person_id', $person->id)->count());
        $this->assertTrue(ChildPerson::where('child_id', $sibling->id)->exists());
    }

    public function test_the_drawer_shows_every_child_the_person_belongs_to(): void
    {
        $maeve = $this->makeChild();
        $sibling = $this->makeChild(['first_name' => 'Sibling']);
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        foreach ([$maeve, $sibling] as $child) {
            $this->actingAs($this->admin)->postJson(route('people.link', $person), [
                'child_id' => $child->id, 'relationship' => 'Mother',
            ])->assertOk();
        }

        $this->actingAs($this->admin)
            ->getJson(route('people.show', $person))
            ->assertOk()
            ->assertJsonCount(2, 'children')
            ->assertJsonPath('person.name', 'Kaylynn Adkins');
    }

    public function test_the_drawer_never_hands_out_a_whole_ssn(): void
    {
        $child = $this->makeChild();
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029', 'ssn' => '123-45-4864']);

        $this->actingAs($this->admin)->postJson(route('people.link', $person), ['child_id' => $child->id])->assertOk();

        $response = $this->actingAs($this->admin)->getJson(route('people.show', $person))->assertOk();

        $response->assertJsonPath('person.ssn_last4', '4864');
        $response->assertJsonMissingPath('person.ssn');
        $this->assertStringNotContainsString('123-45', $response->getContent());
    }

    public function test_editing_a_person_changes_them_for_every_linked_child(): void
    {
        $maeve = $this->makeChild();
        $sibling = $this->makeChild(['first_name' => 'Sibling']);
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        foreach ([$maeve, $sibling] as $child) {
            $this->actingAs($this->admin)->postJson(route('people.link', $person), ['child_id' => $child->id])->assertOk();
        }

        $this->actingAs($this->admin)
            ->postJson(route('people.update', $person), ['name' => 'Kaylynn Adkins', 'cell' => '585-999-1234'])
            ->assertOk();

        // One record, so both children see it — which is the whole point.
        $this->assertSame('585-999-1234', $maeve->fresh()->people()->first()->cell);
        $this->assertSame('585-999-1234', $sibling->fresh()->people()->first()->cell);
    }

    public function test_a_linked_person_cannot_be_deleted(): void
    {
        $child = $this->makeChild();
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        $this->actingAs($this->admin)->postJson(route('people.link', $person), ['child_id' => $child->id])->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('people.destroy', $person))
            ->assertStatus(422)
            ->assertJson(['status' => 'linked']);

        $this->assertDatabaseHas('people', ['id' => $person->id]);

        // Unlinked everywhere, it may go.
        $this->actingAs($this->admin)->postJson(route('people.unlink', $person), ['child_id' => $child->id])->assertOk();
        $this->actingAs($this->admin)->postJson(route('people.destroy', $person))->assertOk();

        $this->assertDatabaseMissing('people', ['id' => $person->id]);
    }

    public function test_a_teacher_cannot_touch_a_child_outside_their_rooms(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
        $child = $this->makeChild(['classroom' => 'PreK']);
        $person = Person::create(['name' => 'Kaylynn Adkins', 'cell' => '585-820-5029']);

        $this->actingAs($teacher)
            ->postJson(route('people.link', $person), ['child_id' => $child->id])
            ->assertForbidden();

        $this->assertSame(0, ChildPerson::count());
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (10000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'dob' => '2022-12-15',
            'classroom' => 'Toddler',
            'address' => '8 Northbrook Ct',
            'city' => 'Lancaster',
            'zip' => '14086',
        ], $attributes));
    }
}
