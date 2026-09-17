<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeing one status at a time on the roster.
 *
 * The roll lists everybody, which is right — a leaver is still somebody you
 * look up — but "show me who has left" was a scroll down a column of sixty
 * hunting for the one badge that is not green. On a page whose whole job is
 * finding one child, that was the question with no control.
 */
class ChildStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_the_roll_can_be_filtered_to_one_status(): void
    {
        $this->child('Active', 'Stays');
        $this->child('Inactive', 'Left');
        $this->child('Pending', 'Soon');

        // Everybody, as before.
        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Stays')->assertSee('Left')->assertSee('Soon');

        // And one at a time.
        $this->actingAs($this->admin)
            ->get(route('children.index', ['status' => 'Inactive']))
            ->assertOk()
            ->assertSee('Left')
            ->assertDontSee('Stays')
            ->assertDontSee('Soon');
    }

    /** The chips carry the whole roll's counts, not the filtered view's. */
    public function test_the_counts_do_not_move_when_a_chip_is_pressed(): void
    {
        $this->child('Active', 'Stays');
        $this->child('Inactive', 'Left');

        foreach (['', 'Active', 'Inactive'] as $filter) {
            $this->actingAs($this->admin)
                ->get(route('children.index', array_filter(['status' => $filter])))
                ->assertOk()
                ->assertSee('All <span class="ml-0.5 opacity-60">2</span>', false)
                ->assertSee('Active <span class="ml-0.5 opacity-60">1</span>', false)
                ->assertSee('Inactive <span class="ml-0.5 opacity-60">1</span>', false);
        }
    }

    /** A status nobody is in is not offered: a chip reading 0 does nothing. */
    public function test_a_status_with_nobody_in_it_has_no_chip(): void
    {
        $this->child('Active', 'Stays');

        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Active <span class="ml-0.5 opacity-60">1</span>', false)
            ->assertDontSee('Inactive <span', false)
            ->assertDontSee('Pending <span', false);
    }

    /** Sorting keeps the filter, or a column heading silently shows everybody. */
    public function test_sorting_carries_the_filter_with_it(): void
    {
        $this->child('Inactive', 'Left');

        $this->actingAs($this->admin)
            ->get(route('children.index', ['status' => 'Inactive']))
            ->assertOk()
            // Escaped, because the page writes it into an href: the ampersands
            // arrive as &amp; and a raw comparison misses a URL that is there.
            ->assertSee(e(route('children.index', ['sort' => 'classroom', 'direction' => 'asc', 'status' => 'Inactive'])), false);
    }

    /**
     * An invented status is a 404, not an empty list.
     *
     * A page that says "no children" when the truth is "no such status" sends
     * somebody looking for a bug in their data.
     */
    public function test_an_invented_status_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->get(route('children.index', ['status' => 'Departed']))
            ->assertNotFound();
    }

    /** And an empty result says which question came back empty. */
    public function test_an_empty_filter_says_what_was_asked(): void
    {
        $this->child('Active', 'Stays');

        // Reached by URL rather than by a chip, since the chip is not drawn.
        $this->actingAs($this->admin)
            ->get(route('children.index', ['status' => 'Inactive']))
            ->assertOk()
            ->assertSee('Nobody on the roll is inactive right now.');
    }

    private function child(string $status, string $first): Child
    {
        return Child::create([
            'lan' => (string) (10000 + Child::count() + 1),
            'status' => $status,
            'first_name' => $first,
            'last_name' => 'Test',
            'classroom' => 'Toddler',
            'birth_date' => '2023-10-01',
        ]);
    }
}
