<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home address gets a state, and Parents status becomes a pick-list
 * (Jordan, 6 Nov 2026) — on Add, on Edit, and on the record.
 */
class ChildStateAndParentsStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_add_has_a_state_list_and_a_parents_status_list(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select id="state" name="state" class="cs-input"><option value="" selected>Select a state<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="NY"\s*>New York<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="DC"\s*>District of Columbia<\/option>/', $html);
        // The fifty states and DC.
        $this->assertSame(51, preg_match_all('/<option value="[A-Z]{2}"\s*>/', $this->between($html, 'name="state"', '</select>')));

        $statuses = $this->between($html, 'name="parents_status"', '</select>');
        $this->assertStringContainsString('<option value="" selected>Not recorded</option>', $statuses);
        foreach (Child::PARENTS_STATUSES as $status) {
            $this->assertMatchesRegularExpression('/<option value="'.$status.'"\s*>'.$status.'<\/option>/', $statuses);
        }

        // City, State, Zip in that order.
        $this->assertMatchesRegularExpression('/name="city".*?name="state".*?name="zip"/s', $html);
    }

    public function test_add_saves_both_and_refuses_a_state_not_on_the_list(): void
    {
        $this->actingAs($this->admin)->post(route('children.store'), [
            'first_name' => 'Sam', 'last_name' => 'Stateside', 'status' => 'Active',
            'city' => 'Albany', 'state' => 'NY', 'zip' => '12207', 'parents_status' => 'Separated',
        ])->assertSessionHasNoErrors();

        $child = Child::where('last_name', 'Stateside')->firstOrFail();
        $this->assertSame('NY', $child->state);
        $this->assertSame('Separated', $child->parents_status);

        $this->actingAs($this->admin)->from(route('children.create'))->post(route('children.store'), [
            'first_name' => 'Zed', 'last_name' => 'Nowhere', 'status' => 'Active', 'state' => 'XX',
        ])->assertSessionHasErrors('state');

        // The refusal opens Child details, where the State box is.
        $html = $this->actingAs($this->admin)->get(route('children.create'))->getContent();
        $this->assertMatchesRegularExpression('/x-data="\{\s*index: 2,/', $html);
    }

    public function test_edit_shows_both_lists_and_keeps_an_older_parents_status(): void
    {
        $child = Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Ola', 'last_name' => 'Older',
            'state' => 'NJ', 'parents_status' => 'Married, living apart']);

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="NJ" selected>New Jersey</option>', $html);
        // Typed before the list existed: still there, still chosen, so saving
        // the record does not quietly blank it.
        $this->assertStringContainsString('<option value="Married, living apart" selected>Married, living apart</option>', $this->between($html, 'name="parents_status"', '</select>'));
    }

    public function test_the_record_shows_the_state_with_the_zip(): void
    {
        $child = Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Ada', 'last_name' => 'Address',
            'address' => '8 Northbrook Ct', 'city' => 'Lancaster', 'state' => 'NY', 'zip' => '14086']);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()
            ->assertSee('8 Northbrook Ct, Lancaster, NY 14086');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "{$from} is not on the page");

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }
}
