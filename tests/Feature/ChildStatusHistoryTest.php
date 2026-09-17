<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a child went on the roll, when they came off it, and who said so.
 *
 * The record already carried enrolled_on and withdrawn_on, but those are two
 * boxes on a form: they say what was intended, they are only right if somebody
 * filled them in, and they hold one answer each. A child who leaves in June and
 * returns in September has one withdrawal date and no way to say they came
 * back. This is the other half — not what was planned but what happened, every
 * time it happened.
 */
class ChildStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /** Going on the roll is the first thing that happened to them. */
    public function test_adding_a_child_records_the_first_line(): void
    {
        $child = $this->child();

        $this->assertCount(1, $child->statusChanges);
        $this->assertNull($child->statusChanges->first()->from_status);
        $this->assertSame('Active', $child->statusChanges->first()->to_status);
        $this->assertSame('Added as Active', $child->statusChanges->first()->summary());
    }

    /** And every change after it, with who made it. */
    public function test_a_status_change_is_recorded_with_who_made_it(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.update', $child), [
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => 'Inactive',
            'birth_date' => '2023-10-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $latest = $child->statusChanges()->first();

        $this->assertSame('Active', $latest->from_status);
        $this->assertSame('Inactive', $latest->to_status);
        $this->assertSame($this->admin->id, $latest->changed_by);
        $this->assertSame('Active → Inactive', $latest->summary());
    }

    /**
     * A child who leaves and comes back is two lines, not a contradiction.
     *
     * This is the case the two date fields cannot hold: withdrawn_on has room
     * for one answer, and overwriting it loses the first departure.
     */
    public function test_leaving_and_returning_are_both_kept(): void
    {
        $child = $this->child();

        $child->update(['status' => 'Inactive']);
        $child->update(['status' => 'Active']);

        $this->assertSame(
            ['Added as Active', 'Active → Inactive', 'Inactive → Active'],
            $child->statusChanges()->orderBy('id')->get()->map->summary()->all()
        );
    }

    /** Saving without touching the status writes nothing. */
    public function test_an_unrelated_edit_adds_no_line(): void
    {
        $child = $this->child();

        $child->update(['first_name' => 'Grace']);

        $this->assertCount(1, $child->statusChanges()->get());
    }

    /**
     * It is the model that records it, not the form.
     *
     * A status can change by more than one road — the edit screen, the
     * importer, a command run from the shell — and a history that only covers
     * the road somebody remembered to instrument is worse than none, because it
     * reads as complete.
     */
    public function test_a_change_made_outside_the_form_is_recorded_too(): void
    {
        $child = $this->child();

        // No request, no signed-in user: a seeder or an artisan command.
        $child->update(['status' => 'Pending']);

        $latest = $child->statusChanges()->first();

        $this->assertSame('Active → Pending', $latest->summary());
        $this->assertNull($latest->changed_by, 'nobody was signed in, and that is worth recording as such');
    }

    /** When they became what they are now. */
    public function test_the_record_can_say_how_long_they_have_been_this(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05 09:00:00'));
        $child = $this->child();

        $this->travelTo(Carbon::parse('2026-06-05 09:00:00'));
        $child->update(['status' => 'Inactive']);

        $this->assertSame('2026-06-05', $child->statusSince()->toDateString());
    }

    /** The history shows on the record once there is something to tell. */
    public function test_the_record_shows_the_history_once_it_has_one(): void
    {
        $child = $this->child();

        // Nothing but "added": the badge at the top already says as much.
        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertDontSee('On the roll');

        $this->actingAs($this->admin)->put(route('children.update', $child), [
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => 'Inactive',
            'birth_date' => '2023-10-01',
        ]);

        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('On the roll')
            ->assertSee('Active → Inactive')
            ->assertSee('by '.$this->admin->name);
    }

    /** Append-only: deleting the child takes the history, nothing else does. */
    public function test_the_history_goes_with_the_child_and_not_before(): void
    {
        $child = $this->child();
        $child->update(['status' => 'Inactive']);

        $this->assertSame(2, ChildStatusChange::where('child_id', $child->id)->count());

        $child->delete();

        $this->assertSame(0, ChildStatusChange::where('child_id', $child->id)->count());
    }

    /**
     * A child who was on the roll before any of this existed still gets a
     * history the moment something happens to them.
     *
     * They have no "Added as Active" line — nothing was recording when their
     * record was made — so their first real change leaves exactly one row. The
     * panel was shown on "more than one row", which meant the whole existing
     * roll, the only children this question is ever asked about, showed
     * nothing.
     */
    public function test_a_child_from_before_the_history_still_shows_their_first_change(): void
    {
        $child = $this->child();

        // As if their record predates the table: no line for being added.
        ChildStatusChange::where('child_id', $child->id)->delete();

        $this->actingAs($this->admin)->put(route('children.update', $child), [
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => 'Inactive',
            'birth_date' => '2023-10-01',
        ])->assertRedirect();

        $this->assertCount(1, $child->statusChanges()->get(), 'exactly the case that was hidden');

        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('On the roll')
            ->assertSee('Active → Inactive');
    }

    /** And the record says when they became what they are, above the planned dates. */
    public function test_the_record_says_inactive_since_when(): void
    {
        $this->travelTo(Carbon::parse('2026-01-05 09:00:00'));
        $child = $this->child();

        $this->travelTo(Carbon::parse('2026-06-05 09:00:00'));
        $child->update(['status' => 'Inactive']);

        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertSee('Inactive since')
            ->assertSee('6/5/2026');
    }

    /** An active child is not told how long they have been ordinary. */
    public function test_an_active_child_gets_no_since_line(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->get(route('children.show', $child))
            ->assertOk()
            ->assertDontSee('Active since');
    }

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2023-10-01',
        ]);
    }
}
