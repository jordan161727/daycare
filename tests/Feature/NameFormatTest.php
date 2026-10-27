<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How a child's name reads, per reader.
 *
 * The office works from surnames because that is how the paper file is
 * ordered; the room works from first names because that is what a child
 * answers to. Both are reading the same roster and neither is wrong, so it is
 * each person's own setting and nobody changes anybody else's screen.
 */
class NameFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_two_formats_are_the_two_ways_a_name_is_written(): void
    {
        $child = $this->makeChild();

        $this->assertSame('Ada Lovelace', $child->displayName('first_last'));
        $this->assertSame('Lovelace, Ada', $child->displayName('last_first'));
    }

    public function test_a_reader_who_has_never_chosen_gets_first_name_first(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin', 'name_format' => null]));
        $this->assertSame('Ada Lovelace', $child->displayName());

        // A format that is no longer offered falls back rather than printing a
        // name in a shape nothing defines.
        auth()->user()->update(['name_format' => 'initials']);
        $this->assertSame('Ada Lovelace', $child->fresh()->displayName());
    }

    public function test_the_attendance_sheet_and_the_roster_follow_the_setting(): void
    {
        $this->makeChild();
        $admin = User::factory()->create(['role' => 'admin', 'name_format' => 'last_first']);

        foreach (['attendance.classic', 'children.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('Lovelace, Ada');
        }

        $admin->update(['name_format' => 'first_last']);

        foreach (['attendance.classic', 'children.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('Ada Lovelace');
        }
    }

    public function test_the_setting_lives_in_settings_now_not_on_the_sheet(): void
    {
        // Moved from the register's ⋯ menu to Settings → Children → Names (Jordan, 27 Oct 2026).
        $this->makeChild();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('attendance.classic'))->assertOk()
            ->assertDontSee('Show names as')
            ->assertDontSee(route('profile.name-format'), false);

        $this->actingAs($admin)->get(route('settings.children'))->assertOk()
            ->assertSee('data-names-setting', false)
            ->assertSee('data-name-option="first_last"', false)
            ->assertSee('data-name-option="last_first"', false)
            ->assertSee('First name first')
            ->assertSee('Surname first')
            ->assertSee(route('settings.names.update'), false);
    }

    public function test_the_centres_choice_reads_for_everyone_and_only_the_director_sets_it(): void
    {
        $this->makeChild();
        $admin = User::factory()->create(['role' => 'admin', 'name_format' => 'first_last']);
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'classrooms' => ['Toddler'], 'name_format' => 'first_last']);

        $this->actingAs($admin)->put(route('settings.names.update'), ['name_format' => 'last_first'])
            ->assertRedirect(route('settings.children'))
            ->assertSessionHas('status', 'Names now read as Smith, John.');

        // Both read surname first now, whatever their own old choice said.
        $this->actingAs($admin)->get(route('attendance.classic'))->assertOk()->assertSee('Lovelace, Ada');
        $this->actingAs($teacher);
        $this->assertSame('Lovelace, Ada', Child::first()->displayName(), 'the teacher reads it the same way, whatever their own old choice said');

        // A teacher cannot set it; a shape nobody offers is refused.
        $this->actingAs($teacher)->put(route('settings.names.update'), ['name_format' => 'first_last'])->assertForbidden();
        $this->actingAs($admin)->put(route('settings.names.update'), ['name_format' => 'initials'])->assertSessionHasErrors('name_format');
        $this->actingAs($admin)->get(route('attendance.classic'))->assertOk()->assertSee('Lovelace, Ada');

        // Back to first name first.
        $this->actingAs($admin)->put(route('settings.names.update'), ['name_format' => 'first_last'])->assertRedirect();
        $this->actingAs($teacher);
        $this->assertSame('Ada Lovelace', Child::first()->displayName());
    }

    public function test_the_old_per_reader_endpoint_still_answers_where_the_centre_has_not_chosen(): void
    {
        $mine = User::factory()->create(['role' => 'admin']);
        $theirs = User::factory()->create(['role' => 'admin', 'name_format' => 'first_last']);

        $this->actingAs($mine)
            ->from(route('attendance.classic'))
            ->post(route('profile.name-format'), ['name_format' => 'last_first'])
            ->assertRedirect(route('attendance.classic'));

        $this->assertSame('last_first', $mine->fresh()->name_format);
        $this->assertSame('first_last', $theirs->fresh()->name_format);
    }

    public function test_a_format_nobody_offers_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'name_format' => 'last_first']);

        $this->actingAs($user)
            ->post(route('profile.name-format'), ['name_format' => 'SURNAME first'])
            ->assertSessionHasErrors('name_format');

        $this->assertSame('last_first', $user->fresh()->name_format);
    }

    /**
     * The roll is sorted by surname because that is how a roll is found. How a
     * name reads is a different question, and tying the two would reorder the
     * whole sheet on a display setting.
     */
    public function test_the_setting_does_not_reorder_the_roll(): void
    {
        $this->makeChild(['first_name' => 'Zoe', 'last_name' => 'Adams', 'lan' => '2001']);
        $this->makeChild();

        $admin = User::factory()->create(['role' => 'admin', 'name_format' => 'first_last']);

        /*
         * Adams before Lovelace, even though "Zoe" sorts after "Ada".
         *
         * The sort is named rather than left to the default, because the
         * subject here is the name format and nothing else: the roll opens in
         * LAN order now, and a test of how a name reads should not quietly
         * become a test of which column the page sorts by.
         */
        $this->actingAs($admin)
            ->get(route('children.index', ['sort' => 'last_name']))
            ->assertOk()
            ->assertSeeInOrder(['Zoe Adams', 'Ada Lovelace']);
    }

    public function test_the_search_finds_a_child_in_either_order(): void
    {
        $this->makeChild();

        $html = $this->actingAs(User::factory()->create(['role' => 'admin', 'name_format' => 'last_first']))
            ->get(route('attendance.classic'))
            ->assertOk()
            ->getContent();

        // The format is how a name reads, not what the search box will accept:
        // both orders are in the haystack whichever one is on show.
        $this->assertStringContainsString("child.first_name + ' ' + child.last_name + ' ' + child.name", $html);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2023-06-15',
        ]);
    }
}
