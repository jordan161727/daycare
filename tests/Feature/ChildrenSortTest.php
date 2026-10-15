<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every column on the roll that can be sorted, sorts — and reads the right
 * way up: DOB earliest first, Age youngest first, Schedule earliest drop-off
 * first with no-hours at the foot, Alerts with the flagged children first.
 */
class ChildrenSortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        Child::create(['lan' => '1', 'first_name' => 'Ada', 'last_name' => 'Old', 'status' => 'Active', 'classroom' => 'PreK', 'dob' => '2022-01-10', 'drop_off_time' => '09:00', 'pick_up_time' => '15:00']);
        Child::create(['lan' => '2', 'first_name' => 'Ben', 'last_name' => 'Young', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2026-03-01', 'drop_off_time' => '07:00', 'pick_up_time' => '17:00', 'alerts' => [['type' => 'allergy', 'text' => "Cow's milk"]]]);
        Child::create(['lan' => '3', 'first_name' => 'Cleo', 'last_name' => 'Middle', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-06-15', 'alerts' => [['type' => 'medication', 'text' => 'Inhaler']]]);
    }

    public function test_dob_and_age_sort_the_date_each_way_up(): void
    {
        $this->assertSame(['Old', 'Middle', 'Young'], $this->order(['sort' => 'dob']));
        $this->assertSame(['Young', 'Middle', 'Old'], $this->order(['sort' => 'dob', 'direction' => 'desc']));
        $this->assertSame(['Young', 'Middle', 'Old'], $this->order(['sort' => 'age']));
        $this->assertSame(['Old', 'Middle', 'Young'], $this->order(['sort' => 'age', 'direction' => 'desc']));
    }

    public function test_schedule_sorts_by_drop_off_with_no_hours_at_the_foot(): void
    {
        $this->assertSame(['Young', 'Old', 'Middle'], $this->order(['sort' => 'schedule']));
        $this->assertSame(['Old', 'Young', 'Middle'], $this->order(['sort' => 'schedule', 'direction' => 'desc']), 'no hours stays at the foot either way');
    }

    public function test_alerts_sorts_the_flagged_children_together(): void
    {
        // Flagged first, by the alert; then the rest.
        $this->assertSame(['Young', 'Middle', 'Old'], $this->order(['sort' => 'alerts']));
        $this->assertSame(['Old', 'Middle', 'Young'], $this->order(['sort' => 'alerts', 'direction' => 'desc']));
    }

    public function test_every_sortable_header_is_a_link_and_an_unknown_sort_is_not_found(): void
    {
        $html = $this->actingAs($this->admin)->get(route('children.index'))->assertOk()->getContent();

        foreach (['lan', 'last_name', 'classroom', 'dob', 'age', 'schedule', 'alerts', 'status'] as $column) {
            $this->assertStringContainsString('sort='.$column, $html, "no link sorts by $column");
        }

        $this->actingAs($this->admin)->get(route('children.index', ['sort' => 'shoe_size']))->assertNotFound();
    }

    /** The surnames down the roll, in the order the page draws them. */
    private function order(array $query): array
    {
        $html = $this->actingAs($this->admin)->get(route('children.index', $query))->assertOk()->getContent();
        // Each row names the child more than once (the face, the link); the
        // first names in page order, repeats collapsed, is the row order.
        preg_match_all('/\b(Ada|Ben|Cleo)\b/', substr($html, strpos($html, '<tbody')), $m);
        $surname = ['Ada' => 'Old', 'Ben' => 'Young', 'Cleo' => 'Middle'];

        return array_values(array_map(fn ($first) => $surname[$first], array_unique($m[1])));
    }
}
