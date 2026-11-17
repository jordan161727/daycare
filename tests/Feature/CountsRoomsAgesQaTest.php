<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\AttendanceWeek;
use App\Services\CentreSettings;
use App\Services\ClassroomAssignment;
use App\Services\DashboardSnapshot;
use App\Services\FloorPlan;
use App\Services\RoomCounts;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA, 16 Nov 2026 — child counts, rooms, capacity and age against room.
 *
 *  1  "enrolled" and "expected today" are one definition on every page
 *     (App\Services\RoomCounts): an Active child whose first day is still
 *     to come is not enrolled anywhere yet, and "scheduled today" does not
 *     move when somebody opens the week for the first time.
 *  2  the floor plan's "Places free" is capacity less the children in.
 *  3  a room with more enrolled or expected than places is warned about.
 *  4  every ⚠ about age against room reads one check
 *     (ClassroomAssignment::fit).
 *
 * The day: Monday 16 Nov 2026, a quarter past two in the morning.
 */
class CountsRoomsAgesQaTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const LAST_MONDAY = '2026-11-09';

    private User $admin;

    private string $tz;

    private int $lan = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo(Carbon::parse(self::MONDAY.' 02:15:00', $this->tz));
    }

    /* ---- 1: one definition of enrolled and expected ---- */

    public function test_scheduled_today_is_the_same_before_and_after_the_week_is_opened(): void
    {
        // Last week: Tia is registered for Mondays; Rex for Tuesdays but
        // dropped in last Monday, so opening this week ticks his Monday too.
        $this->travelTo(Carbon::parse(self::LAST_MONDAY.' 09:00:00', $this->tz));
        $tia = $this->child('Tia', 'Toddler', '2024-06-01', [1]);
        $rex = $this->child('Rex', 'Toddler', '2024-06-01', [2]);
        app(WeekSchedule::class)->open(self::LAST_MONDAY, $this->admin);
        Attendance::create(['child_id' => $rex->id, 'attendance_date' => self::LAST_MONDAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::LAST_MONDAY.' 08:00', $this->tz)]);

        // 2:15 on Monday: nobody has opened this week yet.
        $this->travelTo(Carbon::parse(self::MONDAY.' 02:15:00', $this->tz));
        $this->assertSame(0, ScheduleSlot::where('week_start', self::MONDAY)->count());
        $before = app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true);

        // 2:18: the register is opened, which builds the week.
        $this->travelTo(Carbon::parse(self::MONDAY.' 02:18:00', $this->tz));
        $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertGreaterThan(0, ScheduleSlot::where('week_start', self::MONDAY)->count());
        $after = app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true);

        $this->assertSame(2, $before['scheduled'], 'before the week is opened');
        $this->assertSame(2, $after['scheduled'], 'after the week is opened');
        $this->assertSame([$tia->id => true, $rex->id => true], RoomCounts::bookedOn(collect([$tia, $rex]), self::MONDAY));
    }

    public function test_an_active_child_who_has_not_started_is_not_enrolled_on_any_page(): void
    {
        $this->child('Pia', 'PreK', '2023-03-01', [1, 2, 3, 4, 5]);
        $late = $this->child('Lou', 'PreK', '2023-03-01', [1, 2, 3, 4, 5], ['enrolled_on' => '2026-11-18']);

        $this->assertFalse(RoomCounts::isEnrolled($late, self::MONDAY));
        $this->assertTrue(RoomCounts::isEnrolled($late, '2026-11-18'));

        $dashboard = app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true);
        $this->assertSame(1, $dashboard['enrolled']);
        $this->assertSame(1, $dashboard['scheduled']);

        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertSame(1, $register->viewData('dayCounts')['enrolled']);
        $this->assertSame(1, $register->viewData('dayCounts')['expected']);
        // The row is on the week's sheet, flagged off today's roll for the browser's counts.
        $this->assertStringContainsString('on_roll\u0022:false', $register->getContent());

        $checkIn = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk();
        $this->assertSame(1, $checkIn->viewData('stats')['enrolled']);
        $this->assertSame(1, $checkIn->viewData('stats')['awaited']);

        $week = app(AttendanceWeek::class)->build($this->admin, Carbon::parse(self::MONDAY));
        $this->assertSame(1, $week['sum']['enr']);
        $this->assertSame(1, $week['sum']['exp']);

        $this->assertSame(1, collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'PreK')['enrolled']);
    }

    public function test_an_active_child_past_their_last_day_is_left_off_every_count_and_flagged(): void
    {
        // The record QA found: Active, last day 6 Nov (withdrawal reason
        // set), the nightly sync not run — on the register and the Children
        // list, missing from Check-in and the dashboard.
        $this->child('Pia', 'PreK', '2023-03-01', [1, 2, 3, 4, 5]);
        $left = $this->child('Lef', 'PreK', '2023-03-01', [1, 2, 3, 4, 5], [
            'enrolled_on' => '2026-04-20', 'withdrawn_on' => '2026-11-06', 'withdrawal_reason' => 'Family choice',
            'drop_off_time' => '07:30', 'pick_up_time' => '15:00',
        ]);
        $this->assertSame('Active', $left->fresh()->status);
        $this->assertFalse(RoomCounts::isEnrolled($left, self::MONDAY));

        $dashboard = app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true);
        $this->assertSame([1, 1], [$dashboard['enrolled'], $dashboard['scheduled']]);

        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertSame([1, 1], [$register->viewData('dayCounts')['enrolled'], $register->viewData('dayCounts')['expected']]);

        $checkIn = $this->actingAs($this->admin)->get(route('check-in.index'))->viewData('stats');
        $this->assertSame([1, 1, 1], [$checkIn['enrolled'], $checkIn['expected'], $checkIn['awaited']]);

        $week = app(AttendanceWeek::class)->build($this->admin, Carbon::parse(self::MONDAY));
        $this->assertSame([1, 1], [$week['sum']['enr'], $week['sum']['exp']]);

        $this->assertSame(1, collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'PreK')['enrolled']);
        $this->assertSame(1, RoomCounts::byRoom(self::MONDAY)['PreK']['enrolled']);

        $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Inactive']))->assertOk()
            ->assertSee('Left Nov 6 — status not updated');

        // Same at 2:15 as at 2:18, and as at noon.
        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00', $this->tz));
        $this->assertSame(1, app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true)['scheduled']);
    }

    public function test_check_in_awaits_the_children_the_week_ticks_not_the_registered_days(): void
    {
        $kid = $this->child('Ned', 'Toddler', '2024-06-01', [2]);
        app(WeekSchedule::class)->open(self::MONDAY, $this->admin);
        ScheduleSlot::where('child_id', $kid->id)->whereDate('slot_date', self::MONDAY)->update(['is_scheduled' => true]);

        $stats = $this->actingAs($this->admin)->get(route('check-in.index'))->viewData('stats');
        $this->assertSame(1, $stats['awaited']);
        $this->assertSame(1, app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true)['scheduled']);
    }

    /* ---- 2 and 3: places free and over capacity ---- */

    public function test_an_empty_room_has_all_its_places_free_and_an_over_booked_room_is_warned(): void
    {
        // Infant holds 8; ten booked today, none in yet.
        foreach (range(1, 10) as $i) {
            $this->child('Baby'.$i, 'Infant', '2026-05-01', [1, 2, 3, 4, 5]);
        }

        $infant = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'Infant');
        $this->assertSame(0, $infant['in']);
        $this->assertSame(8, $infant['placesFree'], 'Places free is capacity less the children in');
        $this->assertSame(10, $infant['expected']);
        $this->assertSame('Over capacity: 10 enrolled and 10 expected today for 8 places', $infant['capacityWarning']);

        // Every other room: free = capacity − in, no warning.
        foreach (app(FloorPlan::class)->for($this->admin)['rooms'] as $room) {
            $this->assertSame(max(0, $room['capacity'] - $room['in']), $room['placesFree'], $room['room']);
            if ($room['room'] !== 'Infant') {
                $this->assertNull($room['capacityWarning'], $room['room']);
            }
        }

        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSee('room.placesFree', false)
            ->assertSee('data-room-overbooked', false);

        $ratios = collect(app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true)['ratios'])->keyBy('room');
        $this->assertNotNull($ratios['Infant']['capacityWarning']);
        $this->assertNull($ratios['Toddler']['capacityWarning']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('data-capacity-warning', false);

        $rows = collect(app(CentreSettings::class)->rooms())->keyBy('room');
        $this->assertSame('warn', $rows['Infant']['check']['tone']);
        $this->assertStringContainsString('Over capacity', $rows['Infant']['check']['text']);
        $this->assertSame('OK', $rows['Toddler']['check']['text']);

        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertArrayHasKey('Infant', $register->viewData('roomWarnings'));
        $register->assertSee('data-capacity-warning="Infant"', false);
    }

    public function test_over_capacity_never_blocks_a_sign_in(): void
    {
        $kids = collect(range(1, 9))->map(fn ($i) => $this->child('Bub'.$i, 'Infant', '2026-05-01', [1, 2, 3, 4, 5]));
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00', $this->tz));

        foreach ($kids as $kid) {
            $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $kid->id, 'session' => 'FULL', 'health_code' => 0])->assertSuccessful();
        }

        $this->assertSame(9, Attendance::whereDate('attendance_date', self::MONDAY)->count());
    }

    /* ---- 4: age against room ---- */

    public function test_one_check_decides_whether_an_age_fits_a_room(): void
    {
        // Five months old in Infant, with the room set by hand to Infant —
        // as the save hook records it when a baby is added under six weeks.
        $baby = $this->child('Ivy', 'Infant', '2026-06-10', [1]);
        $baby->forceFill(['classroom_override' => 'Infant', 'classroom_override_from' => null])->save();
        $this->assertTrue($baby->fresh()->classroomOverrideIsStale(), 'the old ⚠ condition');
        $this->assertSame('ok', $baby->fresh()->roomFit()['state']);

        // Five years old, placed in Infant by hand: ⚠.
        $five = $this->child('Max', 'School Age', '2021-07-01', [1]);
        $five->forceFill(['classroom_override' => 'Infant'])->save();
        $this->assertSame('Infant', $five->fresh()->classroom);
        $this->assertSame('mismatch', $five->fresh()->roomFit()['state']);

        // Five years old, stored in PreK with no override (a room the sync has not caught up with): ⚠.
        $prek = $this->child('Zoe', 'School Age', '2021-07-01', [1]);
        Child::whereKey($prek->id)->update(['classroom' => 'PreK']);
        $this->assertSame('mismatch', $prek->fresh()->roomFit()['state']);

        // Five years old: UPK-4 by the client's ranges (School Age is 6–12,
        // decided 16 Nov 2026), and a five-year-old still stored in School
        // Age is flagged — the ~9 the QA pass found.
        $sa = $this->child('Ada', 'School Age', '2021-07-01', [1]);
        $this->assertSame('UPK-4', $sa->fresh()->classroom);
        $this->assertSame('ok', $sa->fresh()->roomFit()['state']);
        Child::whereKey($sa->id)->update(['classroom' => 'School Age']);
        $this->assertSame('mismatch', $sa->fresh()->roomFit()['state']);
        $this->assertSame('6–12 yrs', ClassroomAssignment::ageLabel('School Age'));
        $this->assertSame('6–12 yrs', app(CentreSettings::class)->ages('School Age'));

        // Twelve and three months, kept in School Age by hand: past every band, ⚠.
        $old = $this->child('Kai', 'School Age', '2014-08-01', [1]);
        $old->forceFill(['classroom_override' => 'School Age'])->save();
        $fit = $old->fresh()->roomFit();
        $this->assertSame('mismatch', $fit['state']);
        $this->assertStringContainsString('over the School Age limit', $fit['text']);

        // Moved up one room early by the director: a soft note, not a warning.
        $early = $this->child('Eve', 'Infant', '2025-06-01', [1]);
        $early->forceFill(['classroom_override' => 'Transition'])->save();
        $this->assertSame('placed', $early->fresh()->roomFit()['state']);

        // The register hands the state to the marks and draws them from it.
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringContainsString('room_fit\u0022:\u0022mismatch', $html);
        $this->assertStringContainsString('x-text="roomMark(child)"', $html);
        $this->assertStringNotContainsString("child.override_stale ? '⚠' : '✎'", $html);
    }

    public function test_the_age_labels_are_the_bands(): void
    {
        $this->assertSame(
            ['6 wk–18 mo', '19–24 mo', '24–36 mo', '3–4 yrs', '4–5 yrs', '6–12 yrs'],
            array_map(fn ($room) => ClassroomAssignment::ageLabel($room), ClassroomAssignment::rooms()),
        );
    }

    private function child(string $first, string $room, string $dob, array $days, array $extra = []): Child
    {
        return Child::create($extra + [
            'lan' => (string) $this->lan++,
            'first_name' => $first,
            'last_name' => 'TEST-'.$first,
            'status' => 'Active',
            'classroom' => $room,
            'dob' => $dob,
            'drop_off_time' => '08:00',
            'schedule_days' => $days,
        ]);
    }
}
