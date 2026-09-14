<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        $this->assertStringContainsString('x-for="child in filteredChildren"', $html);
    }

    public function test_the_header_shows_counts_as_inline_chips(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Turing', 'Alan', 'School Age');

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk();

        $response->assertSee('enrolled');
        $response->assertSee('in');
        $response->assertSee('not in');
        $response->assertDontSee('Present today');       // the old stat card
        $response->assertDontSee('DAYCARE MANAGEMENT');  // the old eyebrow
    }

    /**
     * The first column names the child the way the office does.
     *
     * It used to count rows, which answers nothing: the number changed when the
     * sort flipped or a room was filtered, so it was never the same child twice.
     */
    public function test_the_first_column_is_the_lan_not_a_row_number(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>LAN</th>', $html);
        $this->assertStringContainsString("x-text=\"child.lan || '—'\"", $html);
        // The rows are handed to Alpine as JSON, so the LAN has to be in them
        // as well as named in the markup that draws the column.
        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $rows));
        $this->assertSame(
            '1001',
            json_decode(json_decode('"'.$rows[1].'"'), associative: true)[0]['lan'],
            'the LAN never reached the browser'
        );

        // Nothing left counting rows, in either layout.
        $this->assertStringNotContainsString('index + 1', $html);

        // And a number on the sheet can be typed into the box beside it.
        $this->assertStringContainsString("child.lan ?? ''", $html);
        $this->assertStringContainsString('Search name or LAN', $html);
    }

    /**
     * The room has a column of its own, third, rather than trailing the name.
     *
     * Beside the name it read as part of it. In a column the rooms stack, and a
     * child sitting in one nobody else on screen is in stands out — which is
     * the thing the sheet is grouped and staffed by.
     */
    public function test_the_classroom_is_the_third_column(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSeeInOrder(['>LAN</th>', '>Student<', '>Classroom</th>', '>DOB</th>', '>Age</th>'], escape: false);
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
        $this->assertSame(2, substr_count($html, 'child in filteredChildren"'));
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
        $this->assertSame(10, substr_count($html, "tapCell(child.id, '"));
        $this->assertSame(2, substr_count($html, "tapCell(child.id, '".$date."', session)"));
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

    /**
     * The sheet never offers a tap it would have to explain away afterwards.
     *
     * Today always; a day already gone only once Edit is on, and only for
     * somebody who may correct one; tomorrow never.
     */
    public function test_the_sheet_only_offers_the_days_it_can_take(): void
    {
        $this->makeChild('Turing', 'Alan', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // canTap: signing in today, or in Edit setting the plan for any day.
        $this->assertStringContainsString("! canTap('", $html);
        $this->assertStringContainsString('if (date === this.today) return true;', $html);
        $this->assertStringContainsString('return this.editing && this.canAmend && date < this.today;', $html);

        // Off by default: the sheet a teacher stands in front of all day offers
        // today and nothing else, because the commonest mistake on a
        // five-column grid is the column next to the one you meant.
        $this->assertStringContainsString('editing: false,', $html);
    }

    /**
     * The rule is the record's, not the page's.
     *
     * A hand-made post gets the same answer the sheet would have given, and the
     * two things that stay refused are refused here rather than in the markup.
     */
    public function test_the_server_draws_the_boundary_the_sheet_only_shows(): void
    {
        // Mid-week, so "yesterday" and "tomorrow" are both inside it.
        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
        $child = $this->makeChild('Turing', 'Alan', 'Toddler');

        // Yesterday, inside this week: a correction, and allowed.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Tomorrow: an arrival that has not happened is a guess, not a record.
        $message = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-17',
                'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date')
            ->json('errors.attendance_date.0');

        // Named dates: the sheet shows a whole week at once, and "today" alone
        // does not say which column was meant.
        $this->assertStringContainsString('Thursday, Sep 17', $message);
        $this->assertStringContainsString('has not happened yet', $message);

        // Last week is open too: a missing day is usually noticed when the
        // month is being reconciled, which is weeks after the fact. The frozen
        // week still locks its *plan* — this is its record.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-11',
                'session' => 'FULL',
            ])
            ->assertOk();

        $this->assertSame(2, Attendance::count());
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
