<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\DashboardSnapshot;
use App\Services\FloorPlan;
use App\Services\RoomCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA, 17 Nov 2026 — bugs 1, 2, 9, 10, 12 and 13: one day, one rule.
 *
 * At ~9:00 the Dashboard said "Absent 70 / Expected 4", the register "68
 * expected, 0 absent, 68 still to come" and the Floor plan "Expected 2". The
 * dashboard took the room's opening hour as the due time of a child with no
 * agreed hours and called them absent an hour later. Now:
 *
 *   booked today   the whole day's bookings (RoomCounts "expected")
 *   still to come  booked, not arrived, not marked Absent
 *   late           still to come, over an hour past an AGREED time
 *   absent         marked Absent on the register (or the day is over)
 *
 * Tuesday 17 Nov 2026, 9:00 AM. Infant: Ana (agreed 7:30–5:00, late), Bo (no
 * agreed hours), Cy (a drop-off but no pick-up — not agreed). Toddler: Dee
 * (in at 8:00), Eli (marked Absent).
 */
class OneDayCountsQaTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-17';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::DAY.' 09:00:00', $tz));
        $this->admin = User::factory()->create(['role' => 'admin']);

        // Everyone registered Mon–Fri; the week opened the way the office opens it.
        $make = fn (string $lan, string $first, string $room, array $extra = []) => Child::create($extra + [
            'lan' => $lan, 'first_name' => $first, 'last_name' => 'Test', 'status' => 'Active',
            'classroom' => $room, 'classroom_override' => $room, 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        $make('1', 'Ana', 'Infant', ['drop_off_time' => '07:30', 'pick_up_time' => '17:00']);
        $make('2', 'Bo', 'Infant');
        $make('3', 'Cy', 'Infant', ['drop_off_time' => '07:00']);
        $dee = $make('4', 'Dee', 'Toddler');
        $eli = $make('5', 'Eli', 'Toddler');
        app(\App\Services\WeekSchedule::class)->open('2026-11-16', $this->admin);
        $this->assertSame(5, ScheduleSlot::whereDate('slot_date', self::DAY)->where('is_scheduled', true)->distinct()->count('child_id'), 'the week ticks all five today');

        Attendance::create(['child_id' => $dee->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 08:00', $tz)]);
        DssDayMark::create(['child_id' => $eli->id, 'marked_on' => self::DAY, 'mark' => DssDayMark::ABSENT]);
    }

    public function test_the_dashboard_floor_plan_and_register_give_the_same_numbers(): void
    {
        $dashboard = app(DashboardSnapshot::class)->for($this->admin);
        $this->assertSame(
            ['booked' => 5, 'arrived' => 1, 'still_to_come' => 3, 'late' => 1, 'absent' => 1],
            ['booked' => $dashboard['scheduled'], 'arrived' => $dashboard['arrived'], 'still_to_come' => $dashboard['expected'], 'late' => $dashboard['late'], 'absent' => $dashboard['absent']],
            'no agreed hours is never absent or late; only Ana is late; only the mark is absent',
        );

        $plan = app(FloorPlan::class)->for($this->admin);
        $this->assertSame([3, 1], [$plan['counters']['expected'], $plan['counters']['late']]);
        $infant = collect($plan['rooms'])->firstWhere('room', 'Infant');
        $this->assertSame(['booked today' => 3, 'still to come' => 3, 'late' => 1], ['booked today' => $infant['expected'], 'still to come' => $infant['stillToCome'], 'late' => $infant['late']]);
        $dashInfant = collect($dashboard['ratios'])->firstWhere('room', 'Infant');
        $this->assertSame([$dashInfant['expected'], $dashInfant['stillToCome']], [$infant['expected'], $infant['stillToCome']], 'dashboard room = floor plan room');

        $register = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::DAY]))->assertOk();
        $counts = $register->viewData('dayCounts');
        $this->assertSame(['expected' => 5, 'in' => 1, 'absent' => 1, 'still_to_come' => 3, 'late' => 1], array_intersect_key($counts, array_flip(['expected', 'in', 'absent', 'still_to_come', 'late'])));
        $register->assertSee('booked today')->assertSee('data-late-count', false);

        $byRoom = RoomCounts::byRoom(self::DAY);
        $this->assertSame([5, 3, 1, 1], [
            array_sum(array_column($byRoom, 'expected')), array_sum(array_column($byRoom, 'still_to_come')),
            array_sum(array_column($byRoom, 'late')), array_sum(array_column($byRoom, 'absent')),
        ], 'Rooms & hours reads the same tally');

        // The page words.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Booked today', '5', 'Still to come', '3', '1 over an hour late', 'Absent', '1', 'Marked absent on the register'])
            ->assertDontSee('an hour past their time');
    }

    public function test_a_child_with_no_agreed_hours_is_never_late_even_at_five(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 17:30:00', config('app.timezone')));
        $bo = Child::where('first_name', 'Bo')->first();

        $this->assertFalse(RoomCounts::isLate($bo, [], now()));
        $this->assertSame(0, app(DashboardSnapshot::class)->for($this->admin)['absent'] - 1, 'only Eli, who is marked');
    }

    public function test_capacity_warnings_use_one_sentence(): void
    {
        // Bug 13: Toddler read "12 enrolled for 10 places" without the day's figure.
        $this->assertSame('Over capacity: 12 enrolled and 9 booked today for 10 places', RoomCounts::capacityWarning(10, 12, 9));
        $this->assertSame('Over capacity: 9 enrolled and 11 booked today for 10 places', RoomCounts::capacityWarning(10, 9, 11));
        $this->assertSame('Over capacity: 12 enrolled for 10 places', RoomCounts::capacityWarning(10, 12));
        $this->assertNull(RoomCounts::capacityWarning(10, 10, 10));
    }

    public function test_no_staff_on_the_schedule_is_warned_before_anyone_arrives(): void
    {
        // Bug 9: nobody rostered, children booked — "Rooms below ratio 0" said nothing.
        $dashboard = app(DashboardSnapshot::class)->for($this->admin);
        $infant = collect($dashboard['ratios'])->firstWhere('room', 'Infant');
        $this->assertSame('No staff scheduled today for 3 booked — needs 1 at 1:4', $infant['scheduleWarning']);
        $this->assertSame(2, $dashboard['scheduleShort'], 'Infant and Toddler');
        $this->assertNull(DashboardSnapshot::scheduleWarning(8, 2, 4), '2 of 2 needed');
        $this->assertSame('1 of 2 staff scheduled today for 8 booked at 1:4', DashboardSnapshot::scheduleWarning(8, 1, 4));

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('data-schedule-short', false)
            ->assertSee("2 rooms short on today's staff schedule", false);
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSee("2 short on today's staff schedule")
            ->assertSee('data-room-schedule-warning', false);
    }

    public function test_the_afternoon_is_booked_not_checked_in_and_keeps_the_room(): void
    {
        // Bug 10.
        $this->actingAs($this->admin)->get(route('floor-plan.index', ['view' => 'afternoon', 'room' => 'Toddler']))->assertOk()
            ->assertSee('Booked at 3:00 PM')
            ->assertSee('viewUrl(\'morning\')', false)
            ->assertSee("rooms.find(r => r.room === open)", false)
            ->assertViewHas('open', 'Toddler');
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()->assertSee('data-panel-in-label>Checked in', false);
    }

    public function test_the_dashboard_history_links_go_to_sign_in_records(): void
    {
        // Bug 12: both went to the register.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('attendance.month-sheet').'"', $html);
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('check-in.index'), '/').'"[^>]*data-recent-view-all/', $html);
    }
}
