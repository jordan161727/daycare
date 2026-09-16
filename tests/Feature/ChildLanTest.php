<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChildLanTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_lan_starts_the_sequence(): void
    {
        $this->assertSame('10001', Child::nextLan());
    }

    public function test_the_next_lan_is_one_past_the_highest(): void
    {
        $this->makeChild('10004');
        $this->makeChild('10070');
        $this->makeChild('10012');

        $this->assertSame('10071', Child::nextLan());
    }

    public function test_non_numeric_lans_are_ignored(): void
    {
        $this->makeChild('LAN-9999');
        $this->makeChild('10005');
        $this->makeChild('');

        $this->assertSame('10006', Child::nextLan());
    }

    public function test_inactive_children_still_hold_their_number(): void
    {
        $this->makeChild('10200', 'Inactive');

        $this->assertSame('10201', Child::nextLan(), 'a withdrawn child must not have its LAN reused');
    }

    /**
     * A roll that predates the five-digit range still issues five.
     *
     * The sequence is floored at the start of the range rather than counting on
     * from whatever is on file, so the centre cannot end up back in four digits
     * because an old record happens to hold the highest number.
     */
    public function test_a_four_digit_roll_still_issues_five_digits(): void
    {
        $this->makeChild('1072');

        $this->assertSame('10001', Child::nextLan());
    }
    public function test_the_add_child_form_prefills_the_next_lan(): void
    {
        $this->makeChild('10070');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('children.create'))
            ->assertOk()
            ->assertSee('value="10071" class="cs-input" readonly', false)
            ->assertSee('>Auto<', false);
    }

    public function test_the_edit_form_keeps_the_existing_lan(): void
    {
        $child = $this->makeChild('10004');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('value="10004" class="cs-input" readonly', false)
            ->assertDontSee('>Auto<', false)
            ->assertDontSee('From form');
    }

    /**
     * A LAN cannot be duplicated, because it cannot be typed.
     *
     * It used to be a text box holding a suggestion, so two people adding
     * children at the same moment both took the number they were shown and the
     * second was refused the whole finished form. The number is issued on save
     * now: whatever the request carries is discarded.
     */
    public function test_a_posted_lan_is_ignored_and_one_is_issued(): void
    {
        $this->makeChild('10004');

        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('children.store'), [
            'lan' => '10004',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'status' => 'Active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('10005', Child::firstWhere('first_name', 'Grace')->lan);
    }

    /** And editing a record leaves the number it was issued alone. */
    public function test_an_edit_cannot_change_the_lan(): void
    {
        $child = $this->makeChild('10004');

        $this->actingAs(User::factory()->create(['role' => 'admin']))->put(route('children.update', $child), [
            'lan' => '99999',
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => 'Active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('10004', $child->fresh()->lan);
    }
    private function makeChild(string $lan, string $status = 'Active'): Child
    {
        return Child::create([
            'lan' => $lan,
            'status' => $status,
            'first_name' => 'Test',
            'last_name' => 'Child'.Child::count(),
            'classroom' => 'Infant',
        ]);
    }
}
