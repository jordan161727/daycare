<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\DashboardSnapshot;
use App\Services\FloorPlan;
use App\Services\Reports;
use App\Services\RoomCounts;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA, 12 Nov 2026 — the numbers people rely on.
 *
 * #2  expected, in, absent and still to come add up, by one rule
 *     (App\Services\RoomCounts), on the register, the week grid, the
 *     dashboard and the floor plan.
 * #3  nobody is still to come on a day gone by or in a locked week.
 * #4  the dashboard is on the centre's clock, not the browser's.
 * #8  the floor plan's cards follow the Morning / Afternoon view.
 * #9, #10  dark mode on the floor plan and the Reports month stepper.
 * #12 the attendance sheet shows today's arrivals as present.
 * #16, #17 the register keeps its room across weeks; the chips are buttons
 *     with a name and aria-pressed.
 *
 * The day: Thursday 12 Nov 2026, ten in the morning, in Toddler —
 *   Ava    booked, signed in at 8:00, still here
 *   Ben    booked for 9:30, not here yet          → still to come
 *   Cal    booked, marked Absent                  → absent
 *   Dee    not booked today, walked in at 8:30    → in, and so expected
 *   Eli    not booked, not here                   → not counted
 */
class RoomCountsQaTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-12';

    private const MONDAY = '2026-11-09';

    private User $admin;

    private string $tz;

    /** @var array<string, Child> */
    private array $kids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00', $this->tz));
        $this->admin = User::factory()->create(['role' => 'admin']);

        foreach ([
            ['Ava', [1, 2, 3, 4, 5], '08:00'],
            ['Ben', [1, 2, 3, 4, 5], '09:30'],
            ['Cal', [1, 2, 3, 4, 5], '08:00'],
            ['Dee', [1], '08:00'],
            ['Eli', [1], '08:00'],
        ] as $i => [$name, $days, $drop]) {
            $this->kids[$name] = Child::create([
                'lan' => (string) (3000 + $i), 'first_name' => $name, 'last_name' => 'Test', 'status' => 'Active',
                'classroom' => 'Toddler', 'classroom_override' => 'Toddler', 'dob' => '2024-04-05',
                'drop_off_time' => $drop, 'schedule_days' => $days,
            ]);
        }

        app(WeekSchedule::class)->open(self::MONDAY, $this->admin);

        $this->signIn('Ava', '08:00');
        $this->signIn('Dee', '08:30');
        DssDayMark::create(['child_id' => $this->kids['Cal']->id, 'marked_on' => self::DAY, 'mark' => DssDayMark::ABSENT, 'created_by' => $this->admin->id]);
    }

    public function test_the_rule_itself(): void
    {
        $counts = RoomCounts::tally([
            RoomCounts::child(booked: true, arrived: true),
            RoomCounts::child(booked: true, arrived: false),
            RoomCounts::child(booked: true, arrived: false, absent: true),
            RoomCounts::child(booked: false, arrived: true),
            RoomCounts::child(booked: false, arrived: false),
        ]);

        $this->assertSame(['enrolled' => 5, 'expected' => 4, 'in' => 2, 'absent' => 1, 'still_to_come' => 1, 'not_in' => 3], $counts);
        $this->assertSame($counts['expected'], $counts['in'] + $counts['absent'] + $counts['still_to_come']);

        // Once the day is over the one still to come is an absence.
        $over = RoomCounts::tally([RoomCounts::child(true, true), RoomCounts::child(true, false)], dayIsOver: true);
        $this->assertSame([1, 1, 0], [$over['in'], $over['absent'], $over['still_to_come']]);

        $this->assertFalse(RoomCounts::dayIsOver(self::DAY));
        $this->assertTrue(RoomCounts::dayIsOver('2026-11-11'));
        $this->assertTrue(RoomCounts::dayIsOver(self::DAY, weekLocked: true));
    }

    /** #2 — the register's sentence, as the server draws it and the getters keep it. */
    public function test_the_register_header_adds_up(): void
    {
        $response = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::DAY]))->assertOk();

        $this->assertSame(['enrolled' => 5, 'expected' => 4, 'in' => 2, 'absent' => 1, 'still_to_come' => 1, 'not_in' => 3], $response->viewData('dayCounts'));
        $this->assertFalse($response->viewData('dayIsOver'));

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/x-text="expectedCount">4<\/b> expected/', $html);
        $this->assertMatchesRegularExpression('/x-text="awaitedCount">1<\/b> still to come/', $html);
        $this->assertStringContainsString("if (! booked && ! arrived && ! marked) continue;", $html);
    }

    /** #2 — the week grid's sentence, by the same rule. */
    public function test_the_week_grid_adds_up(): void
    {
        $sum = $this->actingAs($this->admin)->get(route('attendance.week'))->assertOk()->viewData('w')['sum'];

        $this->assertSame(['enr' => 5, 'exp' => 4, 'inN' => 2, 'abs' => 1, 'come' => 1, 'notIn' => 3], $sum);
    }

    /** #2 — the dashboard and the floor plan say the same. */
    public function test_the_dashboard_and_the_floor_plan_agree(): void
    {
        $day = app(DashboardSnapshot::class)->for($this->admin);

        $this->assertSame([4, 2, 1, 1], [$day['scheduled'], $day['arrived'], $day['expected'], $day['absent']]);
        $this->assertSame($day['scheduled'], $day['arrived'] + $day['expected'] + $day['absent']);

        $plan = app(FloorPlan::class)->for($this->admin);
        $toddler = collect($plan['rooms'])->firstWhere('room', 'Toddler');
        $this->assertSame([2, 1], [$toddler['in'], $toddler['booked']]);
        $this->assertSame([2, 1], [$plan['counters']['in'], $plan['counters']['expected']]);
    }

    /** #3 — a week that has ended has nobody still to come. */
    public function test_a_day_gone_by_has_nobody_still_to_come(): void
    {
        // Ava, Ben and Cal are booked Wednesdays; only Ava came.
        foreach (['Ava', 'Ben', 'Cal'] as $name) {
            ScheduleSlot::create(['week_start' => '2026-11-02', 'child_id' => $this->kids[$name]->id, 'slot_date' => '2026-11-04', 'session' => 'FULL', 'is_scheduled' => true]);
        }
        Attendance::create(['child_id' => $this->kids['Ava']->id, 'attendance_date' => '2026-11-04', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-11-04 08:00', $this->tz), 'signed_out_at' => Carbon::parse('2026-11-04 16:00', $this->tz)]);

        $response = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-11-04']))->assertOk();

        $this->assertTrue($response->viewData('dayIsOver'));
        $counts = $response->viewData('dayCounts');
        $this->assertSame([3, 1, 2, 0], [$counts['expected'], $counts['in'], $counts['absent'], $counts['still_to_come']]);
        $this->assertStringContainsString('dayIsOver: true', $response->getContent());
    }

    /** #4 — the centre's clock, whatever clock the server or the browser is on. */
    public function test_the_dashboard_reads_the_centres_clock(): void
    {
        // 02:30 UTC on the 13th is 9:30 PM on the 12th in New York.
        $utc = Carbon::parse('2026-11-13 02:30:00', 'UTC');
        $day = app(DashboardSnapshot::class)->for($this->admin, $utc);

        $this->assertSame(self::DAY, $day['now']->toDateString());
        $this->assertSame($this->tz, $day['now']->tzName);
        $this->assertSame(2, $day['arrived'], 'the 12th’s arrivals, not the 13th’s');

        $this->travelTo($utc);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Thursday, Nov 12, 9:30 PM')
            ->assertSee('data-time-zone="America/New_York"', false)
            ->assertSee("timeZone: zone", false)
            ->assertDontSee("toLocaleTimeString([]", false);
    }

    /** #8 — the afternoon's cards are the afternoon's. */
    public function test_the_floor_plan_cards_follow_the_view(): void
    {
        $morning = app(FloorPlan::class)->for($this->admin);
        $afternoon = app(FloorPlan::class)->for($this->admin, 'afternoon');

        // At three, Ben is counted in with Ava and Dee — once, not on two seats.
        $this->assertSame(2, $morning['counters']['in']);
        $this->assertSame(3, $afternoon['counters']['in']);
        $this->assertSame($afternoon['counters']['in'], collect($afternoon['rooms'])->sum('in'));
        $toddler = collect($afternoon['rooms'])->firstWhere('room', 'Toddler');
        $this->assertSame(0, $toddler['booked']);

        $this->actingAs($this->admin)->get(route('floor-plan.index', ['view' => 'afternoon']))->assertOk()
            ->assertSee('data-counters-view="afternoon"', false)
            ->assertSeeInOrder(['In at 3:00 PM', '3', 'Back from school', 'Checked out', 'Still to come', 'Staff on the schedule', 'Rooms below ratio']);
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSeeInOrder(['Checked in', '2', 'Checked out · returning', 'Checked out', 'Expected', '1', 'Staff on the floor']);
    }

    /** #9 — the floor plan carries dark-mode rules for its own light styles. */
    public function test_the_floor_plan_has_dark_mode(): void
    {
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSee('.dark .fp { color: #e2e8f0; }', false)
            ->assertSee('.dark .fp .room {', false)
            ->assertSee('<span class="toggle" data-view-toggle>', false)
            ->assertDontSee('<div style="background: #f7f9fd;', false);
    }

    /** #10 — the Reports month stepper takes its colours from classes with a dark variant. */
    public function test_the_reports_month_stepper_has_dark_mode(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'child']))->assertOk()
            ->assertSee('<span class="mstep">', false)
            ->assertSee('.dark .ch .mstep-label {', false)
            ->assertDontSee('border-radius: 999px; background: #fff; overflow: hidden;">', false);
    }

    /** #12 — a child signed in this morning is present on today's column. */
    public function test_the_attendance_sheet_counts_today(): void
    {
        $sheet = app(Reports::class)->upkSheet(2026, 11, 'Toddler');
        $col = collect($sheet['cols'])->search(fn ($c) => $c['date'] === self::DAY);

        $cell = fn (string $name) => collect($sheet['rows'])->firstWhere('child_id', $this->kids[$name]->id)['cells'][$col]['v'];
        $this->assertSame('✓', $cell('Ava'));
        $this->assertSame('✓', $cell('Dee'));
        $this->assertSame('', $cell('Ben'), 'not here yet is not an absence');
        $this->assertSame('2', $sheet['grand'][$col]['v']);

        // The month report: in, and "here" rather than a dash for the time out.
        $month = app(Reports::class)->childMonth($this->admin, 2026, 11, 'Toddler');
        $day = collect($month['days'])->search(fn ($d) => $d['n'] === 12);
        $ava = collect($month['rows'])->firstWhere('name', 'Test, Ava');
        $this->assertSame(['pr', '8:00a', 'here'], [$ava['cells'][$day]['cls'], $ava['cells'][$day]['a'], $ava['cells'][$day]['b']]);
    }

    /** #16 and #17 — another week keeps the room; the chips are named, pressed buttons. */
    public function test_the_register_keeps_its_room_and_names_its_chips(): void
    {
        $html = $this->actingAs($this->admin)->get(route('attendance.index').'?date='.self::DAY.'&toddler')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('attendance.index').'?date=2026-11-02&amp;toddler"', $html);
        $this->assertStringContainsString('href="'.route('attendance.index').'?date=2026-11-16&amp;toddler"', $html);
        $this->assertStringContainsString(':href="attendanceWeekUrl(\'2026-11-02\', room)"', $html);
        $this->assertStringContainsString('window.location = attendanceWeekUrl(monday, this.room ?? \'\');', $html);

        $this->assertStringContainsString('<button type="button" @click="pickRoom(\'\')" :aria-pressed="room === \'\' ? \'true\' : \'false\'"', $html);
        $this->assertStringContainsString('<button type="button" @click="pickRoom(\'Toddler\')" :aria-pressed="room === \'Toddler\' ? \'true\' : \'false\'"', $html);
        $this->assertStringContainsString(':aria-label="\'Toddler\' + \', \' + roomIn(', $html);
    }

    private function signIn(string $name, string $at): void
    {
        Attendance::create(['child_id' => $this->kids[$name]->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' '.$at, $this->tz)]);
    }
}
