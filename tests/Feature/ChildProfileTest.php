<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The child's record read rather than edited: who to ring, who may collect
 * them, what the note says — reachable from their name on the roster, and by
 * the teacher who has them as well as the director.
 */
class ChildProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_name_on_the_roster_opens_the_record(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee(route('children.show', $child), escape: false);
    }

    public function test_the_record_shows_what_is_on_file(): void
    {
        $child = $this->child([
            'drop_off_time' => '07:00',
            'pick_up_time' => '17:30',
            'mother_name' => 'Ada Lovelace Senior',
            'mother_cell' => '555-0100',
            'pickup_1_name' => 'Charles Babbage',
            'pickup_1_relationship' => 'Uncle',
            'important_notes' => 'Peanut allergy — EpiPen in the office.',
        ]);

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('7:00 AM – 5:30 PM')
            ->assertSee('Ada Lovelace Senior')
            ->assertSee('555-0100')
            ->assertSee('Charles Babbage')
            ->assertSee('Peanut allergy — EpiPen in the office.');
    }

    public function test_a_number_on_the_record_can_be_rung(): void
    {
        // The page is read on a phone at the door as often as at a desk.
        $child = $this->child(['mother_cell' => '(555) 010-0100', 'emergency_telephone' => '555-0199']);

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('tel:5550100100', escape: false)
            ->assertSee('tel:5550199', escape: false);
    }

    public function test_a_room_the_director_chose_says_so_on_the_record(): void
    {
        $child = $this->child(['classroom_override' => 'Toddler', 'classroom_override_from' => '2026-08-01']);

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('Set by hand from 8/1/2026');
    }

    public function test_a_social_security_number_is_never_on_the_page(): void
    {
        $child = $this->child(['mother_ssn' => '123-45-6789', 'father_ssn' => '987-65-4321']);

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertDontSee('123-45-6789')
            ->assertDontSee('987-65-4321');
    }

    public function test_a_teacher_may_read_a_child_in_their_own_room(): void
    {
        $child = $this->child();

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']))
            ->get(route('children.show', $child))
            ->assertOk()
            // Reading is not editing, and the form behind that button is the
            // director's.
            ->assertDontSee('Edit record');
    }

    public function test_a_teacher_may_not_read_a_child_in_another_room(): void
    {
        $child = $this->child();

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK']))
            ->get(route('children.show', $child))
            ->assertForbidden();
    }

    public function test_the_record_is_not_public(): void
    {
        $this->get(route('children.show', $this->child()))->assertRedirect(route('login'));
    }

    public function test_the_add_form_is_still_reachable(): void
    {
        // /children/{child} would swallow /children/create without the numeric
        // constraint on it.
        $this->actingAs($this->admin())->get(route('children.create'))->assertOk();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'classroom_override' => 'Infant',
        ]);
    }
}
