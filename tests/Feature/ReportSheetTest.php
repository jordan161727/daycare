<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

class ReportSheetTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Regression: ConvertEmptyStringsToNull turns the "All classrooms" option into
     * null, which used to filter on "classroom IS NULL" and empty the whole report.
     */
    public function test_submitting_the_form_with_all_classrooms_still_lists_every_child(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant');
        $this->makeChild('Turing', 'Alan', 'School Age');

        $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27', 'classroom' => '']))
            ->assertOk()
            ->assertSee('Lovelace, Ada')
            ->assertSee('Turing, Alan')
            ->assertDontSee('No active children found');
    }

    public function test_a_blank_date_falls_back_to_the_current_week(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant');

        $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '', 'classroom' => '']))
            ->assertOk()
            ->assertSee('Lovelace, Ada')
            ->assertDontSee('No active children found');
    }

    public function test_selecting_a_classroom_narrows_the_report(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant');
        $this->makeChild('Turing', 'Alan', 'School Age');

        $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27', 'classroom' => 'Infant']))
            ->assertOk()
            ->assertSee('Lovelace, Ada')
            ->assertDontSee('Turing, Alan');
    }

    public function test_sheet_shows_rooms_dates_and_dates_of_birth(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant', '2025-03-28');

        $response = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk();

        $response->assertSee('Infant');
        $response->assertSee('DOB');
        $response->assertSee('July');           // month band
        $response->assertSee('3/28/25');        // date of birth
        $response->assertSee('Infant total');
        $response->assertSee('Center total');

        // Monday to Friday of the selected week.
        foreach (['27', '28', '29', '30', '31'] as $day) {
            $response->assertSee('>'.$day.'</th>', false);
        }
    }

    public function test_only_school_age_gets_am_and_pm_columns(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant');
        $this->makeChild('Turing', 'Alan', 'School Age');

        $html = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->getContent();

        // One "pm" sub-header per weekday, from the School Age block only.
        $this->assertSame(5, substr_count($html, '>pm</th>'));
    }

    public function test_totals_count_a_child_once_even_with_both_sessions(): void
    {
        $infantOne = $this->makeChild('Lovelace', 'Ada', 'Infant');
        $infantTwo = $this->makeChild('Hopper', 'Grace', 'Infant');
        $schoolAge = $this->makeChild('Turing', 'Alan', 'School Age');

        $this->markPresent($infantOne, '2026-07-27', 'FULL');
        $this->markPresent($infantTwo, '2026-07-27', 'FULL');
        $this->markPresent($schoolAge, '2026-07-27', 'AM');
        $this->markPresent($schoolAge, '2026-07-27', 'PM');

        $data = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->original
            ->getData();

        $blocks = $data['blocks']->keyBy('room');

        $this->assertSame(2, $blocks['Infant']['totals']['2026-07-27']);
        $this->assertSame(1, $blocks['School Age']['totals']['2026-07-27'], 'AM + PM is still one child');
        $this->assertSame(1, $blocks['School Age']['sessionTotals']['2026-07-27']['AM']);
        $this->assertSame(1, $blocks['School Age']['sessionTotals']['2026-07-27']['PM']);
        $this->assertSame(3, $data['centerTotals']['2026-07-27']);
        $this->assertSame(0, $data['centerTotals']['2026-07-28']);
    }

    public function test_a_full_day_stamp_covers_both_halves_of_the_day(): void
    {
        $schoolAge = $this->makeChild('Turing', 'Alan', 'School Age');
        $this->markPresent($schoolAge, '2026-07-27', 'FULL');

        $blocks = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->original
            ->getData()['blocks']->keyBy('room');

        $this->assertSame(1, $blocks['School Age']['sessionTotals']['2026-07-27']['AM']);
        $this->assertSame(1, $blocks['School Age']['sessionTotals']['2026-07-27']['PM']);
    }

    public function test_combined_subtotals_appear_when_both_rooms_are_present(): void
    {
        $transition = $this->makeChild('Archie', 'Devante', 'Transition');
        $toddler = $this->makeChild('Alam', 'Amsah', 'Toddler');
        $this->markPresent($transition, '2026-07-27', 'FULL');
        $this->markPresent($toddler, '2026-07-27', 'FULL');

        $data = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk();

        $data->assertSee('Tr / Toddler total');

        $blocks = $data->original->getData()['blocks']->keyBy('room');
        $this->assertSame(2, $blocks['Toddler']['combined']['totals']['2026-07-27']);
        $this->assertNull($blocks['Transition']['combined'], 'the subtotal belongs to the last room of the set');
    }

    public function test_rooms_are_ordered_youngest_first(): void
    {
        foreach (['School Age', 'Infant', 'UPK-4', 'Toddler', 'PreK', 'Transition'] as $index => $room) {
            $this->makeChild('Child'.$index, 'Test', $room);
        }

        $rooms = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->original
            ->getData()['blocks']
            ->pluck('room')
            ->all();

        $this->assertSame(['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'], $rooms);
    }

    public function test_class_report_summary_lists_each_room_and_a_total_row(): void
    {
        $infant = $this->makeChild('Lovelace', 'Ada', 'Infant');
        $schoolAge = $this->makeChild('Turing', 'Alan', 'School Age');
        $this->markPresent($infant, '2026-07-27', 'FULL');
        $this->markPresent($schoolAge, '2026-07-27', 'AM');

        $response = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk();

        $response->assertSee('Class report');
        $response->assertSee('Daily sheet');
        $response->assertSee('>Classroom</th>', false);
        $response->assertSee('>Total</th>', false);

        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $weekday) {
            $response->assertSee($weekday);
        }
    }

    public function test_the_empty_state_only_shows_when_there_are_no_children(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->assertSee('No active children found');
    }

    public function test_inactive_children_are_left_off_the_sheet(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Infant');
        $this->makeChild('Babbage', 'Charles', 'Infant', '2025-01-01', 'Inactive');

        $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => '2026-07-27']))
            ->assertOk()
            ->assertSee('Lovelace, Ada')
            ->assertDontSee('Babbage, Charles');
    }

    private function makeChild(string $last, string $first, string $room, ?string $dob = null, string $status = 'Active'): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => $status,
            'first_name' => $first,
            'last_name' => $last,
            'dob' => $dob ?? $this->dobForRoom($room),
        ]);
    }

    private function markPresent(Child $child, string $date, string $session): void
    {
        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => $date,
            'session' => $session,
            'signed_in_at' => now(),
        ]);
    }
}
