<?php

namespace Tests\Feature;

use App\Models\StaffShift;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\PayPeriod;
use App\Services\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    /** The second half of August 2026: the 16th to the 31st. */
    private const PERIOD = '2026-08-16';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand after the period has ended, so approving is allowed. Approving
        // a period still running is refused, and has its own test.
        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);
    }

    // ---------------- the periods themselves ----------------

    public function test_periods_run_from_the_first_to_the_fifteenth_and_the_sixteenth_to_month_end(): void
    {
        $first = PayPeriod::containing('2026-08-07');
        $this->assertSame('2026-08-01', $first->start->toDateString());
        $this->assertSame('2026-08-15', $first->end->toDateString());

        $second = PayPeriod::containing('2026-08-16');
        $this->assertSame('2026-08-16', $second->start->toDateString());
        $this->assertSame('2026-08-31', $second->end->toDateString());

        // February, and a short second half.
        $feb = PayPeriod::containing('2026-02-20');
        $this->assertSame('2026-02-28', $feb->end->toDateString());
    }

    public function test_stepping_between_periods_crosses_the_year(): void
    {
        $this->assertSame('2026-08-01', PayPeriod::containing(self::PERIOD)->previous()->start->toDateString());
        $this->assertSame('2026-09-01', PayPeriod::containing(self::PERIOD)->next()->start->toDateString());
        $this->assertSame('2027-01-01', PayPeriod::containing('2026-12-16')->next()->start->toDateString());
    }

    public function test_a_period_is_named_without_repeating_the_month(): void
    {
        $this->assertSame('Aug 16 – 31, 2026', PayPeriod::containing(self::PERIOD)->label());
    }

    // ---------------- filling from the roster ----------------

    public function test_the_roster_fills_the_period_as_an_unconfirmed_draft(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->shift($staff, '2026-08-17', 7 * 60, 15 * 60);   // 8 h

        $period = TimesheetPeriod::forDate(self::PERIOD);
        $added = app(Timesheet::class)->seed($period);

        $this->assertSame(1, $added);

        $entry = TimesheetEntry::first();
        $this->assertSame(480, $entry->workedMinutes());
        // The roster's word, not anybody's, until somebody says so.
        $this->assertFalse($entry->isConfirmed());
    }

    /** Two shifts in a day are one paid day with a gap in the middle. */
    public function test_a_split_shift_becomes_one_day_with_a_break(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->shift($staff, '2026-08-17', 9 * 60, 12 * 60);
        $this->shift($staff, '2026-08-17', 13 * 60, 17 * 60);

        app(Timesheet::class)->seed(TimesheetPeriod::forDate(self::PERIOD));

        $entry = TimesheetEntry::first();
        $this->assertSame(9 * 60, $entry->starts_at);
        $this->assertSame(17 * 60, $entry->ends_at);
        $this->assertSame(60, $entry->break_minutes, 'the hour between the two shifts');
        $this->assertSame(7 * 60, $entry->workedMinutes());
    }

    public function test_re_filling_never_overwrites_a_day_somebody_confirmed(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->shift($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);
        app(Timesheet::class)->seed($period);

        // The director says she actually left at 16:00.
        TimesheetEntry::first()->update([
            'ends_at' => 16 * 60,
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);

        $this->assertSame(0, app(Timesheet::class)->seed($period), 'nothing new to bring in');
        $this->assertSame(16 * 60, TimesheetEntry::first()->ends_at, 'the correction survives');
    }

    public function test_the_roster_only_reaches_the_period_it_falls_in(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->shift($staff, '2026-08-14', 7 * 60, 15 * 60);   // first half of August
        $this->shift($staff, '2026-08-17', 7 * 60, 15 * 60);   // second half

        app(Timesheet::class)->seed(TimesheetPeriod::forDate(self::PERIOD));

        $this->assertSame(1, TimesheetEntry::count());
        $this->assertSame('2026-08-17', TimesheetEntry::first()->work_date->toDateString());
    }

    // ---------------- overtime ----------------

    public function test_overtime_starts_after_forty_hours_in_a_week(): void
    {
        $staff = $this->teacher('Maria Santos');

        // Mon 17th to Fri 21st, nine hours a day — 45 in the week.
        foreach (['08-17', '08-18', '08-19', '08-20', '08-21'] as $day) {
            $this->entry($staff, "2026-$day", 7 * 60, 16 * 60);
        }

        $line = $this->summaryFor($staff);

        $this->assertSame(40.0, $line['regular_hours']);
        $this->assertSame(5.0, $line['overtime_hours']);
        $this->assertSame(45.0, $line['paid_hours']);
    }

    /**
     * The case semi-monthly periods create and weekly ones never do: a week cut
     * in half by the 15th. Overtime belongs to the whole week; the hours belong
     * to the period the day falls in.
     */
    public function test_a_week_split_across_the_boundary_counts_overtime_across_the_whole_week(): void
    {
        $staff = $this->teacher('Maria Santos');

        // Mon 10 Aug to Sun 16 Aug is one workweek. The 15th is a period
        // boundary, so the Sunday lands in the second half of the month.
        foreach (['08-10', '08-11', '08-12', '08-13', '08-14'] as $day) {
            $this->entry($staff, "2026-$day", 7 * 60, 15 * 60);          // 8 h × 5 = 40
        }
        $this->entry($staff, '2026-08-16', 8 * 60, 14 * 60);              // Sunday, 6 h

        // The Sunday is entirely overtime — the forty were used up before it —
        // and it is paid in the period it falls in, not the one that earned it.
        $second = $this->summaryFor($staff, self::PERIOD);
        $this->assertSame(0.0, $second['regular_hours']);
        $this->assertSame(6.0, $second['overtime_hours']);

        // The first half keeps its forty regular, and no overtime.
        $first = $this->summaryFor($staff, '2026-08-01');
        $this->assertSame(40.0, $first['regular_hours']);
        $this->assertSame(0.0, $first['overtime_hours']);
    }

    /** Paid leave is not hours worked, so it never tips anybody into overtime. */
    public function test_paid_leave_does_not_create_overtime(): void
    {
        $staff = $this->teacher('Maria Santos');

        $this->entry($staff, '2026-08-17', null, null, leave: 'PTO', leaveMinutes: 480);

        foreach (['08-18', '08-19', '08-20', '08-21'] as $day) {
            $this->entry($staff, "2026-$day", 7 * 60, 17 * 60);           // 10 h × 4 = 40
        }

        $line = $this->summaryFor($staff);

        $this->assertSame(40.0, $line['regular_hours']);
        $this->assertSame(0.0, $line['overtime_hours'], '48 paid hours, but only 40 worked');
        $this->assertSame(8.0, $line['paid_leave_hours']);
        $this->assertSame(48.0, $line['paid_hours']);
    }

    public function test_an_unpaid_absence_is_recorded_but_paid_nothing(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->entry($staff, '2026-08-17', null, null, leave: 'UNPAID', leaveMinutes: 480);

        $line = $this->summaryFor($staff);

        $this->assertSame(0.0, $line['paid_hours']);
        $this->assertSame(8.0, $line['unpaid_leave_hours']);
    }

    public function test_the_estimated_gross_pays_overtime_at_time_and_a_half(): void
    {
        $staff = $this->teacher('Maria Santos', ['pay_rate' => 20]);

        foreach (['08-17', '08-18', '08-19', '08-20', '08-21'] as $day) {
            $this->entry($staff, "2026-$day", 7 * 60, 16 * 60);           // 45 h
        }

        // 40 × 20 = 800, plus 5 × 30 = 150.
        $this->assertSame(950.0, $this->summaryFor($staff)['estimated_gross']);
    }

    public function test_a_missing_pay_rate_reports_no_estimate_rather_than_zero(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $this->assertNull($this->summaryFor($staff)['estimated_gross']);
    }

    // ---------------- correcting the days ----------------

    public function test_saving_a_day_confirms_it(): void
    {
        $staff = $this->teacher('Maria Santos');
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $staff]), [
                'days' => ['2026-08-17' => ['starts_at' => '07:00', 'ends_at' => '15:30', 'break_minutes' => 30]],
            ])
            ->assertSessionHas('success');

        $entry = TimesheetEntry::first();
        $this->assertSame(8 * 60, $entry->workedMinutes());
        $this->assertTrue($entry->isConfirmed());
    }

    public function test_a_day_with_only_one_of_the_two_times_is_refused(): void
    {
        $staff = $this->teacher('Maria Santos');
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $staff]), [
                'days' => ['2026-08-17' => ['starts_at' => '07:00', 'ends_at' => '']],
            ])
            ->assertSessionHas('warning');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_a_day_that_ends_before_it_starts_is_refused(): void
    {
        $staff = $this->teacher('Maria Santos');
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $staff]), [
                'days' => ['2026-08-17' => ['starts_at' => '15:00', 'ends_at' => '07:00']],
            ])
            ->assertSessionHas('warning');

        $this->assertSame(0, TimesheetEntry::count());
    }

    // ---------------- approving ----------------

    public function test_approving_is_refused_while_days_are_still_the_rosters_word(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->shift($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);
        app(Timesheet::class)->seed($period);

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('warning');

        $this->assertFalse($period->fresh()->isApproved());
    }

    public function test_approving_is_refused_before_the_period_has_finished(): void
    {
        $this->travelTo(Carbon::parse('2026-08-20 09:00:00'));

        $staff = $this->teacher('Maria Santos');
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('warning');

        $this->assertFalse($period->fresh()->isApproved());
    }

    public function test_a_confirmed_period_can_be_approved_and_is_then_frozen(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('success');

        $period->refresh();
        $this->assertTrue($period->isApproved());
        $this->assertSame($this->admin->id, $period->approved_by);

        // Frozen: a later correction is refused rather than silently applied.
        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $staff]), [
                'days' => ['2026-08-17' => ['starts_at' => '07:00', 'ends_at' => '19:00']],
            ])
            ->assertSessionHas('warning');

        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
    }

    public function test_an_approved_period_can_be_reopened(): void
    {
        $staff = $this->teacher('Maria Santos');
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);
        $this->actingAs($this->admin)->post(route('timesheets.approve', $period));

        $this->actingAs($this->admin)
            ->post(route('timesheets.reopen', $period))
            ->assertSessionHas('warning');

        $this->assertFalse($period->fresh()->isApproved());
    }

    // ---------------- the file payroll gets ----------------

    public function test_the_export_carries_the_hours_split_the_way_they_are_paid(): void
    {
        $staff = $this->teacher('Maria Santos', ['pay_rate' => 20, 'legal_name' => 'Maria G. Santos', 'aspire_id' => 'A-1']);

        foreach (['08-17', '08-18', '08-19', '08-20', '08-21'] as $day) {
            $this->entry($staff, "2026-$day", 7 * 60, 16 * 60);           // 45 h
        }

        $rows = app(Timesheet::class)->exportRows(TimesheetPeriod::forDate(self::PERIOD));

        $this->assertSame('Employee', $rows[0][0]);
        $this->assertSame('Maria Santos', $rows[1][0]);
        $this->assertSame('Maria G. Santos', $rows[1][1]);
        $this->assertSame('A-1', $rows[1][2]);
        $this->assertSame('40.00', $rows[1][6], 'regular');
        $this->assertSame('5.00', $rows[1][7], 'overtime');
        $this->assertSame('45.00', $rows[1][10], 'total paid');
        $this->assertSame('950.00', $rows[1][12], 'estimated gross');
    }

    public function test_somebody_with_no_hours_is_left_out_of_the_export(): void
    {
        $this->teacher('Maria Santos');
        $this->teacher('Grace Lee');

        $rows = app(Timesheet::class)->exportRows(TimesheetPeriod::forDate(self::PERIOD));

        $this->assertCount(1, $rows, 'the header and nothing else');
    }

    public function test_the_csv_downloads(): void
    {
        $staff = $this->teacher('Maria Santos', ['pay_rate' => 20]);
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->get(route('timesheets.export', $period))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ---------------- who may see it ----------------

    public function test_the_page_shows_the_period_and_its_totals(): void
    {
        $staff = $this->teacher('Maria Santos', ['pay_rate' => 20]);
        $this->entry($staff, '2026-08-17', 7 * 60, 15 * 60);

        $this->actingAs($this->admin)
            ->get(route('timesheets.index', ['date' => self::PERIOD]))
            ->assertOk()
            ->assertSee('Aug 16 – 31, 2026')
            ->assertSee('Maria Santos');
    }

    public function test_a_teacher_cannot_reach_payroll_preparation(): void
    {
        $teacher = User::create([
            'name' => 'Grace Lee', 'email' => 'grace@example.com',
            'password' => 'password', 'role' => 'teacher', 'classroom' => 'Toddler',
        ]);

        $this->actingAs($teacher)
            ->get(route('timesheets.index'))
            ->assertForbidden();
    }

    // ---------------- helpers ----------------

    private function teacher(string $name, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => 'FT',
            'classroom' => 'Toddler',
        ], $extra));
    }

    private function shift(User $user, string $date, int $starts, int $ends): StaffShift
    {
        return StaffShift::create([
            'week_start' => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'user_id' => $user->id,
            'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'classroom' => 'Toddler',
        ]);
    }

    /** A day somebody has confirmed — the normal state once a period is worked. */
    private function entry(User $user, string $date, ?int $starts, ?int $ends, ?string $leave = null, int $leaveMinutes = 0): TimesheetEntry
    {
        return TimesheetEntry::create([
            'timesheet_period_id' => TimesheetPeriod::forDate($date)->id,
            'user_id' => $user->id,
            'work_date' => $date,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'break_minutes' => 0,
            'leave_code' => $leave,
            'leave_minutes' => $leaveMinutes,
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);
    }

    private function summaryFor(User $user, string $period = self::PERIOD): array
    {
        return app(Timesheet::class)
            ->summary(TimesheetPeriod::forDate($period))
            ->firstWhere('user.id', $user->id);
    }
}
