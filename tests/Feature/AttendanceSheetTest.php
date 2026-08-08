<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_children_are_listed_in_a_table(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk();

        $response->assertSee('<table', false);
        $response->assertSee('<thead', false);
        $response->assertSee('<tbody', false);
        $response->assertSee('Student');
    }

    public function test_the_student_header_toggles_the_sort_direction(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('@click="toggleSort"', false)
            ->assertSee("sortDirection === 'asc' ? 'ascending' : 'descending'", false);
    }

    public function test_pagination_and_the_sort_and_show_dropdowns_are_gone(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        foreach (['pagedChildren', 'pageNumbers', 'prevPage', 'nextPage', 'pageSize', '>Previous<', '>Next<'] as $removed) {
            $this->assertStringNotContainsString($removed, $html);
        }

        // Every matching child renders, not a page of them.
        $this->assertStringContainsString('x-for="(child, index) in filteredChildren"', $html);
    }

    public function test_the_header_shows_counts_as_inline_chips(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Turing', 'Alan', 'School Age');

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk();

        $response->assertSee('enrolled');
        $response->assertSee('present');
        $response->assertSee('not signed in');
        $response->assertDontSee('Present today');       // the old stat card
        $response->assertDontSee('DAYCARE MANAGEMENT');  // the old eyebrow
    }

    public function test_the_week_grid_is_replaced_by_cards_on_small_screens(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // The wide grid is hidden below md; the card list is hidden from md up.
        $this->assertStringContainsString('hidden overflow-x-auto md:block', $html);
        $this->assertStringContainsString('md:hidden', $html);

        // Both layouts loop the same children and can both sort.
        $this->assertSame(2, substr_count($html, 'in filteredChildren"'));
        $this->assertSame(2, substr_count($html, '@click="toggleSort"'));
    }

    public function test_both_layouts_render_the_same_sign_in_controls(): void
    {
        $this->makeChild('Turing', 'Alan', 'School Age');
        $date = today()->startOfWeek()->toDateString();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // Both the half-day and full-day templates ship for every weekday (Alpine picks
        // one at runtime): 3 handlers x 5 days x 2 layouts.
        $this->assertSame(30, substr_count($html, "signIn(child.id, '"));
        $this->assertStringContainsString("signIn(child.id, '".$date."', 'AM')", $html);
        $this->assertStringContainsString("signIn(child.id, '".$date."', 'PM')", $html);
        $this->assertStringContainsString("signIn(child.id, '".$date."', 'FULL')", $html);
    }

    public function test_the_toolbar_stacks_on_narrow_screens(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // Date form takes its own full-width line before sm.
        $this->assertStringContainsString('flex w-full items-center gap-1.5 sm:ml-auto sm:w-auto', $html);
        // Room pills scroll sideways rather than stacking rows.
        $this->assertStringContainsString('overflow-x-auto px-1 pb-0.5 sm:mx-0 sm:flex-wrap', $html);
        // Search is full width on a phone.
        $this->assertStringContainsString('relative w-full shrink-0 sm:w-56', $html);
    }

    public function test_school_age_children_get_am_and_pm_buttons(): void
    {
        $this->makeChild('Turing', 'Alan', 'School Age');

        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee("child.classroom === 'School Age'", false)
            ->assertSee("'AM')", false)
            ->assertSee("'PM')", false)
            ->assertSee("'FULL')", false);
    }

    /** A cleared date box arrives as null, which used to fail "required" validation. */
    public function test_a_blank_date_falls_back_to_today(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '']))
            ->assertOk()
            ->assertSee('name="date" value="'.today()->toDateString().'"', false);
    }

    public function test_an_unparsable_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => 'not-a-date']))
            ->assertSessionHasErrors('date');
    }

    public function test_signing_in_records_the_session(): void
    {
        $child = $this->makeChild('Turing', 'Alan', 'School Age');

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => today()->toDateString(),
                'session' => 'AM',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'session' => 'AM']);

        $this->assertDatabaseHas('attendances', [
            'child_id' => $child->id,
            'session' => 'AM',
        ]);
    }

    public function test_am_and_pm_are_separate_stamps_but_signing_in_twice_is_idempotent(): void
    {
        $child = $this->makeChild('Turing', 'Alan', 'School Age');
        $payload = ['child_id' => $child->id, 'attendance_date' => today()->toDateString()];

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), $payload + ['session' => 'AM'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), $payload + ['session' => 'PM'])->assertOk();
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), $payload + ['session' => 'AM'])->assertOk();

        $this->assertSame(2, Attendance::where('child_id', $child->id)->count());
    }

    private function makeChild(string $last, string $first, string $room): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => $room,
            'dob' => '2024-01-15',
        ]);
    }
}
