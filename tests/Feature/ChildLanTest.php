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
        $this->assertSame('1001', Child::nextLan());
    }

    public function test_the_next_lan_is_one_past_the_highest(): void
    {
        $this->makeChild('1004');
        $this->makeChild('1070');
        $this->makeChild('1012');

        $this->assertSame('1071', Child::nextLan());
    }

    public function test_non_numeric_lans_are_ignored(): void
    {
        $this->makeChild('LAN-9999');
        $this->makeChild('1005');
        $this->makeChild('');

        $this->assertSame('1006', Child::nextLan());
    }

    public function test_inactive_children_still_hold_their_number(): void
    {
        $this->makeChild('1200', 'Inactive');

        $this->assertSame('1201', Child::nextLan(), 'a withdrawn child must not have its LAN reused');
    }

    public function test_the_add_child_form_prefills_the_next_lan(): void
    {
        $this->makeChild('1070');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('children.create'))
            ->assertOk()
            ->assertSee('name="lan" value="1071"', false)
            ->assertSee('>Auto<', false);
    }

    public function test_the_edit_form_keeps_the_existing_lan(): void
    {
        $child = $this->makeChild('1004');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('name="lan" value="1004"', false)
            ->assertDontSee('>Auto<', false)
            ->assertDontSee('From form');
    }

    public function test_a_duplicate_lan_is_rejected(): void
    {
        $this->makeChild('1004');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('children.store'), [
            'lan' => '1004',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Infant',
            'status' => 'Active',
        ])->assertSessionHasErrors('lan');
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
