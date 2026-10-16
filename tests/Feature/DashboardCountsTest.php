<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's head counts.
 *
 * Director Attendance (the register) and Teacher Attendance (the door) are
 * two records, so the dashboard carries two "present" numbers, each read from
 * its own record and each on the same roll the door's strip counts. Somebody
 * reading the door and then the dashboard, and seeing 89 and 90, stops
 * trusting both.
 */
class DashboardCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-16 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_each_record_is_counted_from_its_own_table_on_the_door_s_roll(): void
    {
        $here = Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Here', 'status' => 'Active', 'classroom' => 'PreK']);
        $twice = Child::create(['lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Twice', 'status' => 'Active', 'classroom' => 'School Age']);
        Child::create(['lan' => '10003', 'first_name' => 'Cy', 'last_name' => 'Away', 'status' => 'Active', 'classroom' => 'PreK']);
        // On the books, not on today's floor: starts Monday, or left last week.
        Child::create(['lan' => '10004', 'first_name' => 'Di', 'last_name' => 'Soon', 'status' => 'Active', 'classroom' => 'PreK', 'enrolled_on' => '2026-10-19']);
        Child::create(['lan' => '10005', 'first_name' => 'Ed', 'last_name' => 'Gone', 'status' => 'Active', 'classroom' => 'PreK', 'withdrawn_on' => '2026-10-09']);
        Child::create(['lan' => '10006', 'first_name' => 'Fay', 'last_name' => 'Archived', 'status' => 'Archived', 'classroom' => 'PreK']);

        DoorAttendance::create(['child_id' => $here->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 08:12'), 'health_in_code' => 0]);
        // Two sessions, one child in the building.
        DoorAttendance::create(['child_id' => $twice->id, 'attendance_date' => '2026-10-16', 'session' => 'AM', 'signed_in_at' => Carbon::parse('2026-10-16 07:30'), 'signed_out_at' => Carbon::parse('2026-10-16 08:30'), 'health_in_code' => 0]);
        DoorAttendance::create(['child_id' => $twice->id, 'attendance_date' => '2026-10-16', 'session' => 'PM', 'signed_in_at' => Carbon::parse('2026-10-16 15:10'), 'health_in_code' => 0]);

        $dashboard = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $door = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk();

        // The roll is the door's roll.
        $this->assertSame(3, $door->viewData('stats')['enrolled']);
        $this->assertSame($door->viewData('stats')['enrolled'], $dashboard->viewData('totalChildren'));

        // Teacher Attendance is what the door's strip says is in.
        $this->assertSame(2, $door->viewData('stats')['in']);
        $this->assertSame($door->viewData('stats')['in'], $dashboard->viewData('doorPresent'));

        // Director Attendance is the register's own record: one child marked
        // there, a different one, and the two numbers do not meet.
        Attendance::create(['child_id' => $here->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 08:00'), 'health_in_code' => 0]);
        $dashboard = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $dashboard->viewData('registerPresent'));
        $this->assertSame(2, $dashboard->viewData('doorPresent'));

        $dashboard->assertSeeInOrder(['Total Children', '3', 'Present · Director', 'Present · Teacher', '2', 'Rooms', '2'])
            ->assertSee('Director Attendance')
            ->assertSee('Teacher Attendance')
            ->assertSee('2 / 3 children present')
            ->assertSee('1 absent');
    }

    public function test_a_morning_with_nobody_in_yet_reads_as_zero_on_both_records(): void
    {
        Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Coming', 'status' => 'Active', 'classroom' => 'PreK']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('totalChildren', 1)
            ->assertViewHas('registerPresent', 0)
            ->assertViewHas('doorPresent', 0)
            ->assertSee('No sign-ins yet today.');
    }
}
