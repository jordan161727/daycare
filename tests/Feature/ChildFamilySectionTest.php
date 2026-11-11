<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Family & pick-up section on the child record, as Jordan's brief of
 * 6 Nov 2026 lays it out: the add panel as two cards under one relationship
 * picker, the table in its own scrolling box, three summary tiles below.
 * Layout only — PeopleStepTest covers what the buttons do.
 */
class ChildFamilySectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_add_panel_is_two_cards_under_one_relationship_picker(): void
    {
        $html = $this->page(Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell']));

        $this->assertStringContainsString('<h2>Family &amp; pick-up</h2>', $html);
        $this->assertStringContainsString('One list — tick what each person may do', $html);
        $this->assertStringContainsString('>+ Add person</button>', $html);

        // The child's own name, wherever the brief says "Mia".
        $this->assertStringContainsString('<h3>Add a person to Mia</h3>', $html);
        $this->assertStringContainsString('Relationship to Mia</span>', $html);
        $this->assertStringContainsString('>+ Create and add to Mia</button>', $html);
        $this->assertStringContainsString("Matches from Mia's siblings appear here as you type.", $html);

        // One relationship select, above both ways in, and every box labelled.
        $this->assertSame(1, substr_count($html, 'x-model="relationship"'));
        $this->assertMatchesRegularExpression('/data-add-link>.*?Link someone already on file.*?<span class="fam-label">Search by name or cell number<\/span>/s', $html);
        $this->assertMatchesRegularExpression('/data-add-new>.*?Not on file\? Create a new person.*?<span class="fam-label">Full name<\/span>.*?placeholder="First and last name".*?<span class="fam-label">Mobile<\/span>/s', $html);
    }

    public function test_an_empty_record_says_who_to_add_and_the_tiles_say_what_is_missing(): void
    {
        $html = $this->page(Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell']));

        $this->assertStringContainsString('<b>Nobody is on Mia\'s record yet</b>', $html);
        $this->assertStringContainsString('Add a parent or guardian above, then tick what each person may do.', $html);
        $this->assertStringContainsString('class="fam-table"', $html);

        $this->assertStringContainsString('Nobody cleared yet', $html);
        $this->assertMatchesRegularExpression('/Home address<\/span>\s*<span class="fam-muted">Not added<\/span>/', $html);
        $this->assertMatchesRegularExpression('/Custody<\/span>\s*<span class="fam-muted">No arrangement on file<\/span>/', $html);
    }

    public function test_the_tiles_show_the_address_and_custody_when_there_are_some(): void
    {
        $html = $this->page(Child::create(['lan' => '10001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell',
            'address' => '8 Northbrook Ct', 'city' => 'Lancaster', 'zip' => '14086', 'parents_status' => 'Joint custody']));

        $this->assertStringContainsString('8 Northbrook Ct, Lancaster, 14086', $html);
        $this->assertMatchesRegularExpression('/Custody<\/span>\s*<span>Joint custody<\/span>/', $html);
    }

    private function page(Child $child): string
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.show', $child))->assertOk()->getContent();
    }
}
