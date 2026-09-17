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

    /**
     * A child's page is addressed by their LAN, not by their row id.
     *
     * The id is on no paper the centre keeps and is different in every copy of
     * this database, so a link carrying it can be neither read out nor checked
     * against a file. /children/10063 can be both.
     */
    public function test_a_child_is_addressed_by_their_lan(): void
    {
        $child = $this->child();

        $this->assertSame($child->lan, (string) $child->getRouteKey());
        $this->assertStringEndsWith('/children/'.$child->lan, route('children.show', $child));

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('Ada Lovelace');
    }

    /** And the row id is no longer a way in. */
    public function test_the_row_id_no_longer_opens_the_record(): void
    {
        $child = $this->child();

        // Guards the test itself: with a five-digit LAN these cannot collide,
        // and if they ever did this would be checking nothing.
        $this->assertNotSame((string) $child->id, $child->lan);

        $this->actingAs($this->admin())
            ->get('/children/'.$child->id)
            ->assertNotFound();
    }

    /**
     * Back goes where the reader came from, not to one named page.
     *
     * The record is opened from the roster, from the attendance sheet, and from
     * a search. The link said "Roster", so anyone arriving from the sheet
     * pressed it and landed somewhere they had not been, having lost the week
     * they had open.
     */
    public function test_back_returns_to_the_page_the_record_was_opened_from(): void
    {
        $child = $this->child();
        $sheet = route('attendance.index', ['date' => '2026-09-14']);

        $this->actingAs($this->admin())
            ->get(route('children.show', $child), ['referer' => $sheet])
            ->assertOk()
            ->assertSee('← Back')
            ->assertSee($sheet, escape: false);
    }

    /** With nowhere to go back to, the roster is the place a record belongs to. */
    public function test_back_falls_to_the_roster_when_there_is_no_previous_page(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee(route('children.index'), escape: false);
    }

    /**
     * And never back to the record itself.
     *
     * Saving an edit lands here from the edit form, so honouring the referer
     * would make Back return to the form just left — and a reader who reloads
     * the record would get a Back that reloads the page it is on.
     */
    public function test_back_does_not_point_at_the_record_or_its_own_form(): void
    {
        $child = $this->child();

        foreach ([route('children.show', $child), route('children.edit', $child)] as $own) {
            $html = $this->actingAs($this->admin())
                ->get(route('children.show', $child), ['referer' => $own])
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('href="'.route('children.index').'" class="rounded-xl bg-white/10', $html);
        }
    }

    /** A Back button is not a way off this site. */
    public function test_back_refuses_an_outside_referer(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin())
            ->get(route('children.show', $child), ['referer' => 'https://example.com/somewhere'])
            ->assertOk()
            ->assertDontSee('https://example.com/somewhere', escape: false);
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
            // And may keep it up to date: a teacher is the one told a new
            // mobile number at the door. What decides rooms, enrolment and
            // billing is still the director's � see ChildRecordEditingTest.
            ->assertSee('Edit record');
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
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'classroom_override' => 'Infant',
        ]);
    }
}
