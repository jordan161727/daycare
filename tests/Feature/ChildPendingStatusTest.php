<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pending: a place agreed and not yet started.
 *
 * The roll was a yes or a no — Active or Inactive — and a child whose
 * paperwork was in but whose first day was a fortnight off had to be one of
 * them. Filed as Active they were counted as somebody the rooms were staffed
 * for and looked for at sign-in; filed as Inactive they read as having left.
 */
class ChildPendingStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_a_child_can_be_saved_as_pending(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), [
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'status' => 'Pending',
                'birth_date' => '2023-10-01',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Pending', $child->fresh()->status);
    }

    /** The form offers it, from the same list the rules accept. */
    public function test_the_form_offers_every_status_the_rules_accept(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('children.create'))
            ->assertOk()
            ->getContent();

        foreach (Child::STATUSES as $status) {
            $this->assertStringContainsString('value="'.$status.'"', $html);
        }
    }

    public function test_an_invented_status_is_still_refused(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), [
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'status' => 'Maybe',
                'birth_date' => '2023-10-01',
            ])
            ->assertSessionHasErrors('status');
    }

    /**
     * Amber on the roll: a place held rather than a place taken.
     *
     * Not the green of somebody in the building, and not the grey of somebody
     * who has left — which is what it read as while the badge was a yes or no.
     */
    public function test_pending_reads_as_its_own_state_on_the_roll(): void
    {
        $this->child(['status' => 'Pending']);

        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('bg-amber-100', false);
    }

    /** It is not counted as active, which is the reason it exists. */
    public function test_a_pending_child_is_not_counted_as_active(): void
    {
        $this->child(['status' => 'Pending']);
        $this->child(['lan' => '10002', 'first_name' => 'Grace', 'status' => 'Active']);

        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('>1</b> active', false)
            ->assertSee('>1</b> pending', false);
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
