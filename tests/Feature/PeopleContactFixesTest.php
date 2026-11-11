<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use App\Services\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA #28 (mobile numbers), #29 (fix a name or mobile from the row), #45 (texts on for guardians). */
class PeopleContactFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->child = Child::create(['lan' => '10001', 'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => now()->subMonths(26)->toDateString()]);
    }

    public function test_a_mobile_that_is_not_a_us_number_is_refused(): void
    {
        foreach (['abc12', '12345', '082 555 0134', '(116) 555-0134'] as $bad) {
            $this->actingAs($this->admin)->postJson(route('people.store'), ['child_id' => $this->child->id, 'name' => 'Kate Cole', 'cell' => $bad])
                ->assertStatus(422)->assertJsonPath('errors.cell.0', 'Enter a 10-digit US mobile number, like (716) 555-0134.');
        }
        $this->assertSame(0, Person::count());
    }

    public function test_a_mobile_is_stored_as_e164_and_shown_the_us_way(): void
    {
        $this->actingAs($this->admin)->postJson(route('people.store'), ['child_id' => $this->child->id, 'name' => 'Kate Cole', 'cell' => '716.555.0134', 'relationship' => 'Mother'])
            ->assertOk()->assertJsonPath('person.cell', '(716) 555-0134');

        $this->assertSame('+17165550134', Person::sole()->cell);

        // The same number typed another way is the same person, not a second one.
        $this->actingAs($this->admin)->postJson(route('people.store'), ['child_id' => $this->child->id, 'name' => 'Kate Cole', 'cell' => '1 (716) 555-0134'])
            ->assertOk()->assertJsonPath('status', 'linked_existing');
        $this->assertSame(1, Person::count());
    }

    public function test_the_row_edit_fixes_the_name_and_mobile(): void
    {
        $person = Person::create(['name' => 'Kat Cole', 'cell' => '+17165550134']);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'is_guardian' => true, 'gets_texts' => true]);

        $html = $this->actingAs($this->admin)->get(route('children.show', $this->child))->getContent();
        $this->assertStringContainsString('name="name" value="Kat Cole"', $html);
        $this->assertStringContainsString('name="cell" value="(716) 555-0134"', $html);

        $this->actingAs($this->admin)->post(route('children.people.flags', [$this->child, $person]), [
            'name' => 'Kate Cole', 'cell' => '(716) 555-0199', 'relationship' => 'Mother', 'is_guardian' => '1', 'gets_texts' => '1',
        ])->assertRedirect(route('children.show', $this->child));

        $person->refresh();
        $this->assertSame('Kate Cole', $person->name);
        $this->assertSame('+17165550199', $person->cell);

        // A bad number is refused, the row reopens with the message, and nothing changes.
        $this->actingAs($this->admin)->from(route('children.show', $this->child))->post(route('children.people.flags', [$this->child, $person]), [
            '_person' => $person->id, 'name' => 'Kate Cole', 'cell' => 'abc12',
        ])->assertSessionHasErrors('cell');
        $this->assertSame('+17165550199', $person->fresh()->cell);
    }

    public function test_a_new_guardian_with_a_mobile_gets_texts_and_others_do_not(): void
    {
        $mum = Person::create(['name' => 'Kate Cole', 'cell' => '+17165550134']);
        $gran = Person::create(['name' => 'Gran Cole', 'cell' => '+17165550135']);
        $dad = Person::create(['name' => 'Dan Cole', 'cell' => null]);
        $optedOut = Person::create(['name' => 'Ola Cole', 'cell' => '+17165550136']);

        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $mum->id, 'is_guardian' => true]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $gran->id, 'is_guardian' => false, 'can_pickup' => true]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $dad->id, 'is_guardian' => true]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $optedOut->id, 'is_guardian' => true, 'gets_texts' => false]);

        $ticks = ChildPerson::with('person')->get()->mapWithKeys(fn ($l) => [$l->person->name => $l->gets_texts]);
        $this->assertSame(['Kate Cole' => true, 'Gran Cole' => false, 'Dan Cole' => false, 'Ola Cole' => false], $ticks->all());
    }

    public function test_messages_says_why_a_room_s_contacts_were_skipped(): void
    {
        $gran = Person::create(['name' => 'Gran Cole', 'cell' => '+17165550135']);
        $dad = Person::create(['name' => 'Dan Cole', 'cell' => null]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $gran->id, 'is_guardian' => false]);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $dad->id, 'is_guardian' => true, 'gets_texts' => true]);

        $toddler = collect(app(Messaging::class)->everyoneRows())->firstWhere('room', 'Toddler');

        $this->assertSame(2, $toddler['skipped']);
        $this->assertStringContainsString('1 Texts not ticked', $toddler['reasons']);
        $this->assertStringContainsString('1 no mobile number', $toddler['reasons']);
    }
}
