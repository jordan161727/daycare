<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's numbers are the door's roll and the one attendance record.
 *
 * Total Children is who is on today's floor — Active and enrolled as of
 * today — and Present Today is how many of them have an arrival on the
 * record, counted as children rather than rows. The two-tile dashboard of
 * 16–20 Oct 2026, one tile a record, went when the records were rejoined.
 */
class DashboardCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_present_today_counts_children_on_the_door_s_roll(): void
    {
        $here = Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Here', 'status' => 'Active', 'classroom' => 'PreK']);
        $twice = Child::create(['lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Twice', 'status' => 'Active', 'classroom' => 'School Age']);
        Child::create(['lan' => '10003', 'first_name' => 'Cy', 'last_name' => 'Away', 'status' => 'Active', 'classroom' => 'PreK']);
        // On the books, not on today's floor: starts Monday, or left last week.
        Child::create(['lan' => '10004', 'first_name' => 'Di', 'last_name' => 'Soon', 'status' => 'Active', 'classroom' => 'PreK', 'enrolled_on' => '2026-10-19']);
        Child::create(['lan' => '10005', 'first_name' => 'Ed', 'last_name' => 'Gone', 'status' => 'Active', 'classroom' => 'PreK', 'withdrawn_on' => '2026-10-09']);
        Child::create(['lan' => '10006', 'first_name' => 'Fay', 'last_name' => 'Archived', 'status' => 'Archived', 'classroom' => 'PreK']);

        Attendance::create(['child_id' => $here->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 08:12'), 'health_in_code' => 0]);
        // Two sessions, one child in the building.
        Attendance::create(['child_id' => $twice->id, 'attendance_date' => '2026-10-16', 'session' => 'AM', 'signed_in_at' => Carbon::parse('2026-10-16 07:30'), 'signed_out_at' => Carbon::parse('2026-10-16 08:30'), 'health_in_code' => 0]);
        Attendance::create(['child_id' => $twice->id, 'attendance_date' => '2026-10-16', 'session' => 'PM', 'signed_in_at' => Carbon::parse('2026-10-16 15:10'), 'health_in_code' => 0]);

        $dashboard = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $door = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk();

        // The roll is the door's roll, and the count is the door's count.
        $this->assertSame(3, $door->viewData('stats')['enrolled']);
        $this->assertSame($door->viewData('stats')['enrolled'], $dashboard->viewData('totalChildren'));
        $this->assertSame(2, $dashboard->viewData('presentToday'));

        $dashboard->assertSeeInOrder(['Total Children', '3', 'Present Today', '2', 'Absent', '1', 'Rooms', '2'])
            ->assertSee('2 / 3 children present')
            ->assertSee('1 still to arrive');
    }

    public function test_a_morning_with_nobody_in_yet_reads_as_zero(): void
    {
        Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Coming', 'status' => 'Active', 'classroom' => 'PreK']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('totalChildren', 1)
            ->assertViewHas('presentToday', 0)
            ->assertSee('No sign-ins yet today.');
    }
}
