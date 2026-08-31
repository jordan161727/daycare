<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

class AttendanceSheetTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

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

        // Both sign-in layouts loop the same children and can both sort.
        $this->assertSame(2, substr_count($html, '(child, index) in filteredChildren"'));
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

        // One handler per weekday per layout; the session comes from the child's own
        // list at runtime rather than being hardcoded per room.
        $this->assertSame(10, substr_count($html, "signIn(child.id, '"));
        $this->assertSame(2, substr_count($html, "signIn(child.id, '".$date."', session)"));
        $this->assertStringContainsString('x-for="session in child.sessions"', $html);
    }

    public function test_the_toolbar_stacks_on_narrow_screens(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // The toolbar wraps onto more lines rather than overflowing.
        $this->assertStringContainsString('flex flex-wrap items-center gap-x-3 gap-y-2', $html);
        // Room pills scroll sideways rather than stacking rows.
        $this->assertStringContainsString('overflow-x-auto px-1 pb-0.5 sm:mx-0 sm:flex-wrap', $html);
        // The search box is dropped below sm: on a phone the sheet is scrolled
        // rather than searched, and the box would take the whole line.
        $this->assertStringContainsString('relative hidden sm:block', $html);
        // Jumping to a far-off week is behind the overflow menu, so the line
        // holds only what is used on every visit.
        $this->assertStringContainsString('aria-label="More"', $html);
    }

    public function test_each_child_carries_the_sessions_their_room_uses(): void
    {
        $this->makeChild('Turing', 'Alan', 'School Age');
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // School Age splits into halves, everyone else is one full-day stamp.
        // Blade's @js() ships the roster as JSON.parse('...') with " for quotes.
        $q = chr(92)."u0022";
        $this->assertStringContainsString("School Age{$q},{$q}sessions{$q}:[{$q}AM{$q},{$q}PM{$q}]", $html);
        $this->assertStringContainsString("Toddler{$q},{$q}sessions{$q}:[{$q}FULL{$q}]", $html);
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

    public function test_the_sheet_does_not_offer_a_day_it_would_refuse(): void
    {
        $this->makeChild('Turing', 'Alan', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // The box for any day but today is disabled, so the click is never
        // taken and then explained away in a dialog.
        $this->assertStringContainsString("! canSignIn('", $html);
        $this->assertStringContainsString('canSignIn(date) { return date === this.today; }', $html);
    }

    public function test_another_day_is_still_refused_by_the_server(): void
    {
        // The sheet no longer offers the click, but the rule is the record's,
        // not the page's — a hand-made post is refused just the same.
        $child = $this->makeChild('Turing', 'Alan', 'Toddler');

        $response = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => today()->subDay()->toDateString(),
                'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date');

        // Still names both days: it reaches a person if anything ever does show it.
        $message = $response->json('errors.attendance_date.0');
        $this->assertStringContainsString(today()->format('l, M j'), $message);
        $this->assertStringContainsString(today()->subDay()->format('l, M j'), $message);

        $this->assertSame(0, Attendance::count());
    }

    private function makeChild(string $last, string $first, string $room): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'dob' => $this->dobForRoom($room),
        ]);
    }
}
