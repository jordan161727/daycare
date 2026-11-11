<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA #34: parents' SSNs are encrypted at rest, never sent to a page in full,
 * directors and the office only, and every look or change is in the log.
 */
class SsnProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Dana Director']);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
        $this->child = Child::create(['lan' => '10001', 'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => now()->subMonths(26)->toDateString(), 'mother_ssn' => '123-45-6789']);
    }

    public function test_stored_encrypted_and_never_serialized(): void
    {
        $raw = DB::table('children')->where('id', $this->child->id)->value('mother_ssn');
        $this->assertStringNotContainsString('6789', $raw);
        $this->assertSame('123-45-6789', $this->child->fresh()->mother_ssn);

        $this->assertArrayNotHasKey('mother_ssn', $this->child->fresh()->toArray());
        $this->assertArrayNotHasKey('ssn', Person::create(['name' => 'Kate Cole', 'cell' => '5855550134', 'ssn' => '987-65-4321'])->toArray());
    }

    public function test_setting_one_is_logged_without_the_number(): void
    {
        $line = DB::table('activity_log')->where('subject_type', 'Child')->where('subject_id', $this->child->id)->sole();
        $this->assertSame('updated', $line->event);
        $this->assertStringContainsString("mother's set on the record of Mia Cole", $line->text);
        $this->assertStringNotContainsString('6789', $line->text);
    }

    public function test_the_person_drawer_shows_an_admin_the_last_four_and_logs_it(): void
    {
        $person = $this->linkedPerson();

        $this->actingAs($this->admin)->getJson(route('people.show', $person))
            ->assertOk()->assertJsonPath('person.ssn_last4', '4321');

        $this->assertTrue(DB::table('activity_log')->where('event', 'viewed')->where('actor_name', 'Dana Director')->where('subject_id', $person->id)->exists());
    }

    public function test_a_teacher_gets_no_ssn_and_cannot_set_one(): void
    {
        $person = $this->linkedPerson();

        $this->actingAs($this->teacher)->getJson(route('people.show', $person))
            ->assertOk()->assertJsonPath('person.ssn_last4', null)->assertJsonPath('person.can_see_ssn', false);

        $this->actingAs($this->teacher)->postJson(route('people.update', $person), ['name' => 'Kate Cole', 'cell' => '5855550134', 'ssn' => '111-22-3333'])
            ->assertStatus(422)->assertJsonValidationErrors('ssn');
        $this->assertSame('987-65-4321', $person->fresh()->ssn);

        $this->assertFalse(DB::table('activity_log')->where('event', 'viewed')->exists());
    }

    public function test_the_edit_form_never_holds_the_number_and_an_empty_box_keeps_it(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.edit', $this->child))->assertOk()->getContent();
        $this->assertStringNotContainsString('123-45-6789', $html);
        $this->assertStringNotContainsString('123456789', $html);

        $this->actingAs($this->admin)->put(route('children.update', $this->child), [
            'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'mother_ssn' => '',
        ]);
        $this->assertSame('123-45-6789', $this->child->fresh()->mother_ssn);

        // A teacher's save cannot touch it either.
        $this->actingAs($this->teacher)->put(route('children.update', $this->child), [
            'first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Active', 'mother_ssn' => '999-99-9999',
        ]);
        $this->assertSame('123-45-6789', $this->child->fresh()->mother_ssn);
    }

    public function test_a_teacher_sees_no_ssn_box(): void
    {
        $person = $this->linkedPerson();
        $html = $this->actingAs($this->teacher)->get(route('children.show', $this->child))->assertOk()->getContent();

        $this->assertStringNotContainsString('x-model="drawer.ssn"', $html);
        $this->assertStringNotContainsString('4321', $html);
    }

    private function linkedPerson(): Person
    {
        $person = Person::create(['name' => 'Kate Cole', 'cell' => '5855550134', 'ssn' => '987-65-4321']);
        ChildPerson::create(['child_id' => $this->child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'is_guardian' => true]);

        return $person;
    }
}
