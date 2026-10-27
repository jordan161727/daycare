<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's numbers are the day as the other screens wrote it.
 *
 * Phase 4.1 of docs/work.md: the seven cards, the ratios room by room and
 * the progress bar, all read from the roll, the week's ticks, today's
 * arrivals, the staff clock and the roster — and every child on today's
 * floor in exactly one of in, returning, out, expected, absent.
 */
class DashboardCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 Oct 2026, 10:30 in the centre's own clock.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_every_child_on_the_floor_lands_in_one_card_and_the_bar_adds_them_up(): void
    {
        $tz = config('app.timezone');
        $day = '2026-10-15';
        $make = fn (string $lan, string $first, string $last, string $room, array $extra = []) => Child::create(['lan' => $lan, 'first_name' => $first, 'last_name' => $last, 'status' => 'Active', 'classroom' => $room] + $extra);
        $tick = fn (Child $child, string $session = 'FULL', ?string $planned = null) => ScheduleSlot::create(['week_start' => '2026-10-12', 'child_id' => $child->id, 'slot_date' => $day, 'session' => $session, 'is_scheduled' => true, 'planned_time' => $planned]);

        // In the building.
        $in = $make('1', 'Ada', 'In', 'Infant'); $tick($in);
        Attendance::create(['child_id' => $in->id, 'attendance_date' => $day, 'session' => 'FULL', 'signed_in_at' => Carbon::parse("$day 07:46", $tz)]);
        // Picked up for the day.
        $out = $make('2', 'Ben', 'Out', 'Infant'); $tick($out);
        Attendance::create(['child_id' => $out->id, 'attendance_date' => $day, 'session' => 'FULL', 'signed_in_at' => Carbon::parse("$day 07:50", $tz), 'signed_out_at' => Carbon::parse("$day 09:40", $tz)]);
        // At school, back later: School Age, AM done, PM booked for 3:00.
        $returning = $make('3', 'Cy', 'Returning', 'School Age', ['dob' => '2019-03-02']); $tick($returning, 'AM'); $tick($returning, 'PM', '15:00');
        Attendance::create(['child_id' => $returning->id, 'attendance_date' => $day, 'session' => 'AM', 'signed_in_at' => Carbon::parse("$day 07:30", $tz), 'signed_out_at' => Carbon::parse("$day 08:30", $tz)]);
        // Expected: booked for 11:00, not here yet, and it is only 10:30.
        $expected = $make('4', 'Di', 'Expected', 'Toddler'); $tick($expected, 'FULL', '11:00');
        // Absent: booked for 8:00 and nothing by 10:30 — an hour past.
        $absent = $make('5', 'Ed', 'Absent', 'Toddler', ['drop_off_time' => '08:00']); $tick($absent);
        // Not booked today: on the roll, in no card.
        $make('6', 'Fay', 'Off', 'PreK');
        // Not on today's floor at all.
        $make('7', 'Gus', 'Later', 'PreK', ['enrolled_on' => '2026-10-19']);

        // Staff: one clocked in and rostered to Infant, one clocked in with
        // no shift (home room Toddler), one rostered to Toddler but not in.
        $teachers = [];
        foreach ([['Sultana', 'Toddler', false], ['Noor', 'Infant', true], ['Priya', 'Toddler', true]] as [$name, $room, $clockedIn]) {
            $teacher = User::factory()->create(['role' => 'teacher', 'name' => $name.' Teacher', 'classroom' => $room]);
            $teachers[$name] = $teacher;
            if ($clockedIn) {
                TimePunch::create(['user_id' => $teacher->id, 'work_date' => $day, 'punched_at' => Carbon::parse("$day 07:00", $tz), 'type' => TimePunch::IN, 'source' => 'kiosk', 'method' => 'pin']);
            }
        }
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $teachers['Noor']->id, 'shift_date' => $day, 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'Infant', 'role' => 'opener']);
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $teachers['Sultana']->id, 'shift_date' => $day, 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'Toddler', 'role' => 'opener']);

        $snapshot = app(DashboardSnapshot::class)->for($this->admin);

        $this->assertSame(6, $snapshot['enrolled'], 'Gus starts Monday');
        $this->assertSame(5, $snapshot['scheduled']);
        $this->assertSame(['in' => 1, 'returning' => 1, 'out' => 1, 'expected' => 1, 'absent' => 1], array_intersect_key($snapshot, array_flip(['in', 'returning', 'out', 'expected', 'absent'])));
        $this->assertSame(3, $snapshot['arrived'], 'in + returning + out');
        $this->assertSame(2, $snapshot['staffOnFloor']);

        // Ratios: Infant has 1 child and Noor — covered; Toddler has Priya
        // and nobody in — covered with nothing to cover; School Age is empty
        // but says who is coming back and when.
        $rooms = collect($snapshot['ratios'])->keyBy('room');
        $this->assertSame(['Covered', 1, 1, 'Needs 1 at 1:4'], [$rooms['Infant']['status'], $rooms['Infant']['children'], $rooms['Infant']['staff'], $rooms['Infant']['note']]);
        $this->assertSame('1 checked out · returning at 3:00', $rooms['School Age']['note']);
        $this->assertSame('Empty', $rooms['School Age']['status']);
        $this->assertSame('Empty', $rooms['PreK']['status']);

        // And the page says it, card by card.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Scheduled today', '5', 'of 6 enrolled', 'Checked in', '1', 'Checked out · returning', '1', 'Checked out', '1', 'Expected', '1', 'Absent', '1', 'Staff on the floor', '2'])
            ->assertSee('Ratios right now')
            ->assertSee('Arrived: 3 of 5 scheduled')
            ->assertSee('1 checked in, 1 checked out · returning')
            ->assertSee('1 expected · 1 absent')
            ->assertSee('60%')
            ->assertSee('Thursday, Oct 15, 10:30 AM');
    }

    public function test_a_room_short_of_staff_is_flagged_and_names_who_is_not_in(): void
    {
        $tz = config('app.timezone');
        $day = '2026-10-15';
        for ($i = 1; $i <= 10; $i++) {
            $child = Child::create(['lan' => "1$i", 'first_name' => "Kid$i", 'last_name' => 'Toddler', 'status' => 'Active', 'classroom' => 'Toddler']);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => $day, 'session' => 'FULL', 'signed_in_at' => Carbon::parse("$day 07:40", $tz)->addMinutes($i)]);
        }
        $in = User::factory()->create(['role' => 'teacher', 'name' => 'Priya Raman', 'classroom' => 'Toddler']);
        TimePunch::create(['user_id' => $in->id, 'work_date' => $day, 'punched_at' => Carbon::parse("$day 07:00", $tz), 'type' => TimePunch::IN, 'source' => 'kiosk', 'method' => 'pin']);
        $away = User::factory()->create(['role' => 'teacher', 'name' => 'Sultana Begum', 'classroom' => 'Toddler']);
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $away->id, 'shift_date' => $day, 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'Toddler', 'role' => 'opener']);

        $toddler = collect(app(DashboardSnapshot::class)->for($this->admin)['ratios'])->firstWhere('room', 'Toddler');

        // Ten toddlers at 1:6 need two adults; one is in; the roster says who is missing.
        $this->assertSame(['Short 1', 'short', 10, 1, 2], [$toddler['status'], $toddler['tone'], $toddler['children'], $toddler['staff'], $toddler['needed']]);
        $this->assertSame('Needs 2 at 1:6 · Sultana not in', $toddler['note']);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('data-ratio-tone="short"', false)
            ->assertSee('Short 1')
            ->assertSee('Sultana not in');
    }

    public function test_a_morning_with_nobody_in_yet_reads_as_zero(): void
    {
        Child::create(['lan' => '10001', 'first_name' => 'Ada', 'last_name' => 'Coming', 'status' => 'Active', 'classroom' => 'PreK']);

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('of 1 enrolled')
            ->assertSee('0%')
            ->assertSee('No sign-ins yet today.');
    }
}
