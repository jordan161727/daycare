<?php

namespace Tests\Feature;

use App\Exports\StaffTimesheetExport;
use App\Http\Controllers\StaffTimesheetController;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\ScheduleEditor;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Staff attendance, one real week — Phase 6 of docs/work.md, end to end.
 *
 * Three people and a director, Monday to Thursday morning: an on-time day,
 * a late one, a forgotten clock-out, a forgotten clock-in, a sick day, a
 * late arrival this morning and somebody due at noon. Then the two fixes
 * through the panel, and the week taken away as a file. What the page
 * says at each step is pinned here, in order.
 */
class StaffAttendanceScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-10-12';   // Mon 12 – Fri 16 Oct

    private const TODAY = '2026-10-15';  // Thursday

    private User $admin;

    private User $rachel;

    private User $omar;

    private User $priya;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::TODAY.' 10:30:00', $this->tz));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);

        $this->rachel = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim', 'job_role' => 'Lead Teacher', 'classroom' => 'Toddler', 'title' => 'Toddler', 'pay_rate' => 24, 'employment' => 'FT']);
        $this->omar = User::factory()->create(['role' => 'teacher', 'name' => 'Omar Haddad', 'job_role' => 'Assistant', 'classroom' => 'PreK', 'title' => 'PreK', 'pay_rate' => 18, 'employment' => 'FT']);
        $this->priya = User::factory()->create(['role' => 'teacher', 'name' => 'Priya Nair', 'job_role' => 'Floater', 'classroom' => 'Infant', 'title' => 'Infant', 'pay_rate' => 20, 'employment' => 'PT']);

        // Rachel: 7:30–3:30 Monday to Thursday.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $date) {
            $this->shift($this->rachel, $date, 7 * 60 + 30, 15 * 60 + 30, 'Toddler');
        }
        // Omar: 8–4 all week.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $date) {
            $this->shift($this->omar, $date, 8 * 60, 16 * 60, 'PreK');
        }
        // Priya: Thursday noon to six only.
        $this->shift($this->priya, '2026-10-15', 12 * 60, 18 * 60, 'Infant');

        // Monday: both on time.
        $this->punch($this->rachel, '2026-10-12 07:28', TimePunch::IN);
        $this->punch($this->rachel, '2026-10-12 15:35', TimePunch::OUT);
        $this->punch($this->omar, '2026-10-12 08:00', TimePunch::IN);
        $this->punch($this->omar, '2026-10-12 16:00', TimePunch::OUT);
        // Tuesday: Rachel 22 minutes late; Omar worked but never touched the clock.
        $this->punch($this->rachel, '2026-10-13 07:52', TimePunch::IN);
        $this->punch($this->rachel, '2026-10-13 15:30', TimePunch::OUT);
        // Wednesday: Rachel forgot to clock out; Omar out sick (entered as time off).
        $this->punch($this->rachel, '2026-10-14 07:30', TimePunch::IN);
        app(ScheduleEditor::class)->timeOff($this->omar, '2026-10-14', 'all', null, 'sick', $this->admin);
        // Thursday, 10:30 now: Rachel in since 7:25; Omar due at 8 and not in; Priya due at noon.
        $this->punch($this->rachel, '2026-10-15 07:25', TimePunch::IN);
    }

    public function test_the_live_board_reads_this_morning(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        // One row each, in the morning's order: here, late, due. The director is not a row.
        $this->assertStringContainsString('data-live-row="'.$this->rachel->id.'" data-live-key="in"', $html);
        $this->assertStringContainsString('data-live-row="'.$this->omar->id.'" data-live-key="late"', $html);
        $this->assertStringContainsString('data-live-row="'.$this->priya->id.'" data-live-key="due"', $html);
        $this->assertStringNotContainsString('data-live-row="'.$this->admin->id.'"', $html);
        $this->assertTrue(strpos($html, 'data-live-row="'.$this->rachel->id.'"') < strpos($html, 'data-live-row="'.$this->omar->id.'"'));
        $this->assertTrue(strpos($html, 'data-live-row="'.$this->omar->id.'"') < strpos($html, 'data-live-row="'.$this->priya->id.'"'));

        // What each line says.
        $this->assertStringContainsString('On shift', $html);
        $this->assertStringContainsString('Not in · due 8:00 AM', $html);
        $this->assertStringContainsString('Due 12:00 PM', $html);
        $this->assertStringContainsString('7:30a – 3:30p', $html);
        $this->assertStringContainsString('3h 05m', $html, 'Rachel, in at 7:25, has worked three hours five minutes by half past ten');

        // The cards: one in of two due by now, one due later, nobody off, three hours so far.
        $this->assertStringContainsString('1 <small>of 2 due by now</small>', $html);
        $this->assertStringContainsString('Due later 1', $html);
        $this->assertStringContainsString('Off 0', $html);
    }

    public function test_the_week_grid_colours_each_day_for_what_it_was(): void
    {
        $cell = fn (User $who, string $date) => app(StaffTimesheetController::class)->cell($who, $date);

        // Rachel: on time, late, missing out, on shift now.
        $this->assertSame(StaffTimesheetController::ON_TIME, $cell($this->rachel, '2026-10-12')['status']);
        $this->assertSame(StaffTimesheetController::LATE, $cell($this->rachel, '2026-10-13')['status']);
        $this->assertSame(StaffTimesheetController::MISSING_OUT, $cell($this->rachel, '2026-10-14')['status']);
        $this->assertSame('7:25a – …', $cell($this->rachel, '2026-10-15')['top']);
        // Omar: on time, forgot to clock in, off sick, not in yet, scheduled tomorrow.
        $this->assertSame(StaffTimesheetController::ON_TIME, $cell($this->omar, '2026-10-12')['status']);
        $this->assertSame(StaffTimesheetController::MISSING_IN, $cell($this->omar, '2026-10-13')['status']);
        $this->assertSame(['Off', 'Sick'], [$cell($this->omar, '2026-10-14')['top'], $cell($this->omar, '2026-10-14')['sub']]);
        $this->assertSame(['Not in', 'Late · due 8:00 AM'], [$cell($this->omar, '2026-10-15')['top'], $cell($this->omar, '2026-10-15')['sub']]);
        $this->assertSame(['8a – 4p', 'Scheduled'], [$cell($this->omar, '2026-10-16')['top'], $cell($this->omar, '2026-10-16')['sub']]);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets', ['view' => 'hist']))->assertOk()->getContent();

        $this->assertStringContainsString('7:28a – 3:35p', $html);
        $this->assertStringContainsString('8h 07m', $html);
        $this->assertStringContainsString('Late 22 min · 7h 38m', $html);
        $this->assertStringContainsString('No clock-out', $html);
        $this->assertStringContainsString('Forgot to clock in', $html);
        $this->assertStringContainsString('Sick', $html);
        $this->assertStringContainsString('Scheduled', $html);
        $this->assertStringContainsString('On shift · 3h 05m', $html);
        $this->assertStringContainsString('data-hours="'.$this->rachel->id.'"', $html);

        // The role filter narrows the grid to the assistants.
        $filtered = $this->actingAs($this->admin)->get(route('staff.timesheets', ['view' => 'hist', 'role' => 'Assistant']))->assertOk()->getContent();
        $this->assertStringContainsString('Omar Haddad', $filtered);
        $this->assertStringNotContainsString('Rachel Kim', $filtered);
    }

    public function test_the_two_broken_days_are_fixed_through_the_panel_and_the_week_adds_up(): void
    {
        // Rachel's Wednesday: the panel knows the shift ended at 3:30 and offers it.
        $panel = $this->actingAs($this->admin)->getJson(route('timesheets.panel', ['user' => $this->rachel, 'date' => '2026-10-14']))->assertOk()->json();
        $this->assertTrue($panel['totals']['open']);
        $this->assertSame('15:30', $panel['shift']['ends_at']);
        $this->assertSame(StaffTimesheetController::MISSING_OUT, $panel['cell']['status']);

        $fixed = $this->actingAs($this->admin)->postJson(route('timesheets.panel', ['user' => $this->rachel, 'date' => '2026-10-14']), [
            'reason' => 'forgot_out',
            'changes' => [['op' => 'add', 'type' => TimePunch::OUT, 'at' => '15:30']],
        ])->assertOk()->json();
        $this->assertSame([StaffTimesheetController::EDITED, '3:30 PM', 23.8], [$fixed['cell']['status'], $fixed['cell']['out'], $fixed['hours']], 'hours is the week so far: 8h07 + 7h38 + 8h');

        // Omar's Tuesday: in and out typed from his shift, one reason for both.
        $fixed = $this->actingAs($this->admin)->postJson(route('timesheets.panel', ['user' => $this->omar, 'date' => '2026-10-13']), [
            'reason' => 'forgot',
            'changes' => [
                ['op' => 'add', 'type' => TimePunch::IN, 'at' => '08:00'],
                ['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00'],
            ],
        ])->assertOk()->json();
        $this->assertSame([StaffTimesheetController::EDITED, '8:00 AM', '4:00 PM', 16.0], [$fixed['cell']['status'], $fixed['cell']['in'], $fixed['cell']['out'], (float) $fixed['hours']]);

        // The supervisor's punches carry the director's name and the reason.
        $added = TimePunch::where('source', TimePunch::SOURCE_SUPERVISOR)->get();
        $this->assertCount(3, $added);
        $this->assertSame([$this->admin->id], $added->pluck('recorded_by')->unique()->all());
        $this->assertSame(['Forgot to clock out', 'Forgot to punch'], $added->pluck('reason')->unique()->values()->all());

        // The week now: nothing left to fix, the corrections listed under the grid.
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets', ['view' => 'hist']))->assertOk()->getContent();
        $this->assertStringNotContainsString('No clock-out', $html);
        $this->assertSame(StaffTimesheetController::EDITED, app(StaffTimesheetController::class)->cell($this->omar, '2026-10-13')['status']);
        $this->assertStringContainsString('Corrected · 8h', $html);
        $this->assertStringContainsString('2 corrected', $html);
        $this->assertStringContainsString('Clocked out missing', $html);
        $this->assertStringContainsString('Rachel Kim', $html);
        $this->assertStringContainsString('Omar Haddad', $html);

        // Hours for the days done: Rachel 8h07 + 7h38 + 8h = 23h45; Omar 8h + 8h = 16h.
        $this->assertStringContainsString('data-hours="'.$this->rachel->id.'">23h 45m', $html);
        $this->assertStringContainsString('data-hours="'.$this->omar->id.'">16h 00m', $html);
    }

    public function test_the_week_leaves_as_a_file_that_says_what_the_grid_says(): void
    {
        $this->actingAs($this->admin)->postJson(route('timesheets.panel', ['user' => $this->rachel, 'date' => '2026-10-14']), [
            'reason' => 'forgot_out', 'changes' => [['op' => 'add', 'type' => TimePunch::OUT, 'at' => '15:30']],
        ])->assertOk();

        Excel::fake();
        $this->actingAs($this->admin)->get(route('staff.timesheets.export', ['from' => self::WEEK, 'to' => '2026-10-16', 'format' => 'csv']))->assertOk();

        $rows = collect();
        Excel::assertDownloaded('timesheet-2026-10-12-to-2026-10-16.csv', function (StaffTimesheetExport $export) use (&$rows) {
            $rows = $export->collection();
            $this->assertSame(['Staff ID', 'Name', 'Role', 'Rate', 'Date', 'Day', 'Time in', 'Time out', 'Status', 'Week total (h)'], $export->headings());

            return true;
        });

        // A row per person per day with a punch: Rachel Mon–Thu, Omar Mon only (Tue has no punch, Wed is off, Thu not in).
        $rachel = $rows->where(1, 'Rachel Kim')->values();
        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'], $rachel->pluck(4)->all());
        $this->assertSame(['On time', 'Late', 'Edited', 'On time'], $rachel->pluck(8)->all());
        $this->assertSame(['7:28 AM', '3:35 PM', 'Mon'], [$rachel[0][6], $rachel[0][7], $rachel[0][5]]);
        $this->assertSame('3:30 PM', $rachel[2][7]);
        $this->assertNull($rachel[3][7], 'still on shift: no time out yet');
        $this->assertSame(['Lead Teacher', 24.0], [$rachel[0][2], (float) $rachel[0][3]]);
        $this->assertSame(23.8, (float) $rachel[0][9], 'the days done, in hours: 8h07 + 7h38 + 8h');

        $omar = $rows->where(1, 'Omar Haddad')->values();
        $this->assertSame(['2026-10-12'], $omar->pluck(4)->all());
        $this->assertSame('On time', $omar[0][8]);
        $this->assertSame(8.0, (float) $omar[0][9]);
        $this->assertCount(0, $rows->where(1, 'Priya Nair'), 'not punched in yet this week');

        // A teacher asking for the file gets the page's own refusal, not everybody's pay.
        $this->actingAs($this->rachel)->get(route('staff.timesheets.export', ['from' => self::WEEK, 'to' => '2026-10-16']))->assertForbidden();
    }

    private function shift(User $who, string $date, int $from, int $to, string $room): void
    {
        StaffShift::create([
            'week_start' => self::WEEK, 'user_id' => $who->id, 'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')),
            'starts_at' => $from, 'ends_at' => $to, 'classroom' => $room, 'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    private function punch(User $who, string $at, string $type): void
    {
        app(TimeClock::class)->punch($who, $type, Carbon::parse($at, $this->tz), 'kiosk');
    }
}
