<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The filters on the staff list, which apply themselves.
 *
 * There was a Search button and a Reset beside it. Neither earned its place:
 * a change to a filter is the whole of what somebody meant by it, so Search
 * only delayed what they had already asked for, and Reset is what the "All"
 * option in each control already is.
 */
class StaffFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_filters_apply_themselves(): void
    {
        $page = $this->actingAs($this->admin)->get(route('teachers.index'));

        $page->assertOk();
        $page->assertSee('$event.target.form.submit()', false);
    }

    public function test_there_is_no_search_or_reset_button_left_to_press(): void
    {
        $html = $this->actingAs($this->admin)->get(route('teachers.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Search</button>', $html);
        $this->assertStringNotContainsString('>Reset</a>', $html);
    }

    /**
     * Somebody who asked for a hundred rows and then picks a room should still
     * have a hundred rows. Losing it would be a second surprise on top of the
     * page reloading by itself.
     */
    public function test_a_chosen_page_size_survives_a_filter_change(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('teachers.index', ['per_page' => 100]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<input type="hidden" name="per_page" value="100">', $html);
    }

    public function test_the_filters_still_narrow_the_list(): void
    {
        User::factory()->create(['role' => 'teacher', 'name' => 'Grace Hopper', 'classroom' => 'Bluebell Room']);
        User::factory()->create(['role' => 'teacher', 'name' => 'Mary Jackson', 'classroom' => 'Sunflower Room']);

        $page = $this->actingAs($this->admin)->get(route('teachers.index', ['classroom' => 'Bluebell Room']));

        $page->assertOk();
        $page->assertSee('Grace Hopper');
        $page->assertDontSee('Mary Jackson');
    }

    /** The "All" options are the reset, so they have to actually clear. */
    public function test_choosing_all_clears_the_filter(): void
    {
        User::factory()->create(['role' => 'teacher', 'name' => 'Grace Hopper', 'classroom' => 'Bluebell Room']);
        User::factory()->create(['role' => 'teacher', 'name' => 'Mary Jackson', 'classroom' => 'Sunflower Room']);

        $page = $this->actingAs($this->admin)->get(route('teachers.index', ['classroom' => '', 'role' => '', 'status' => '']));

        $page->assertOk();
        $page->assertSee('Grace Hopper');
        $page->assertSee('Mary Jackson');
    }

    public function test_add_staff_is_the_apps_primary_blue(): void
    {
        $html = $this->actingAs($this->admin)->get(route('teachers.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*teachers\/create[^>]*bg-indigo-600[^>]*>\+ Add staff<\/a>/',
            $html
        );
    }
}
