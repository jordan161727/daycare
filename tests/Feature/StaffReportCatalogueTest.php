<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffReportController;
use App\Models\Department;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rest of the reports on the dropdown.
 *
 * Each one is a different question about the same punches, so what these check
 * is that each answers its own: a counter counts, an absence report names
 * people who were not in, an activity log shows the punch that was voided
 * rather than quietly dropping it.
 */
class StaffReportCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rachel;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so a default period has days behind and ahead in it.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $this->admin = User::factory()->create([
            'role' => 'admin', 'name' => 'Administrator', 'employment' => 'FT',
        ]);

        $this->rachel = $this->staff('Rachel Kim');
    }

    public function test_every_report_on_the_dropdown_renders(): void
    {
        // The registry is what the dropdown is built from, so a report listed
        // there and not implemented is a broken option somebody will pick.
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-21 16:30', TimePunch::OUT);

        foreach (array_keys(StaffReportController::REPORTS) as $key) {
            $this->actingAs($this->admin)
                ->get(route('staff.reports', ['report' => $key, 'start' => '2026-09-21', 'end' => '2026-09-25', 'pay' => 1]))
                ->assertOk();

            $this->actingAs($this->admin)
                ->get(route('staff.reports.export', ['report' => $key, 'start' => '2026-09-21', 'end' => '2026-09-25', 'format' => 'csv']))
                ->assertOk();
        }
    }

    public function test_the_counter_counts_who_was_in_and_who_was_not(): void
    {
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-21 16:00', TimePunch::OUT);

        $html = $this->report('attendance-counter', ['start' => '2026-09-21', 'end' => '2026-09-21']);

        // One of the two staff worked, so one worked and one was absent.
        $this->assertStringContainsString('08:00', $html);
        $this->assertStringContainsString('2026-09-21', $html);
        $this->assertStringContainsString('Showing 1 row', $html);
    }

    public function test_attendance_only_leaves_out_the_days_nobody_punched(): void
    {
        // The point of that report is the clock face. A row of dashes per
        // person per weekend is noise in a report about times.
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-21 16:00', TimePunch::OUT);

        $html = $this->report('attendance-only', ['start' => '2026-09-21', 'end' => '2026-09-25']);

        $this->assertStringContainsString('8:00 AM', $html);
        $this->assertStringContainsString('4:00 PM', $html);
        $this->assertStringContainsString('Showing 1 row', $html);
    }

    public function test_the_absence_report_names_the_people_who_were_not_in(): void
    {
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-21 16:00', TimePunch::OUT);

        $html = $this->report('daily-absence', ['start' => '2026-09-21', 'end' => '2026-09-21']);

        // Rachel worked that day, so it is the administrator who is absent.
        $this->assertStringContainsString('Administrator', $this->body($html));
        $this->assertStringNotContainsString('Rachel Kim', $this->body($html));
        $this->assertStringContainsString('Showing 1 row', $html);
    }

    public function test_current_status_reads_today_and_takes_no_dates(): void
    {
        $this->punch($this->rachel, '2026-09-23 07:45', TimePunch::IN);

        $html = $this->report('current-status');

        $this->assertStringContainsString('Clocked in', $html);
        $this->assertStringContainsString('Not in', $html);
        // A report about right now has no period to set, so the form does not
        // offer one — an input that changes nothing is one somebody will set.
        $this->assertStringNotContainsString('id="start"', $html);
    }

    public function test_the_summary_averages_over_days_worked_not_days_in_the_range(): void
    {
        // An average over days nobody worked is not an average of anything.
        $this->punch($this->rachel, '2026-09-21 09:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-21 13:00', TimePunch::OUT);
        $this->punch($this->rachel, '2026-09-22 09:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-22 15:00', TimePunch::OUT);

        $html = $this->report('employee-summary', ['start' => '2026-09-21', 'end' => '2026-09-25']);

        // Ten hours over two days worked, not over five days in the range.
        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString('05:00', $html);
    }

    public function test_the_weekday_summary_adds_the_same_weekday_across_weeks(): void
    {
        // Two Mondays a fortnight apart, which is the whole point of reading
        // the range by weekday rather than by date.
        $this->punch($this->rachel, '2026-09-07 09:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-07 12:00', TimePunch::OUT);
        $this->punch($this->rachel, '2026-09-14 09:00', TimePunch::IN);
        $this->punch($this->rachel, '2026-09-14 13:00', TimePunch::OUT);

        $html = $this->report('weekday-summary', ['start' => '2026-09-07', 'end' => '2026-09-20']);

        // Three hours and four, both landing on Monday.
        $this->assertStringContainsString('07:00', $html);
    }

    public function test_the_role_summary_groups_by_job_rather_than_by_person(): void
    {
        $html = $this->report('role-summary', ['start' => '2026-09-21', 'end' => '2026-09-25']);

        $this->assertStringContainsString('Admin', $html);
        $this->assertStringContainsString('Teacher', $html);
        // A row per role, not per person.
        $this->assertStringContainsString('Showing 2 rows', $html);
        $this->assertStringNotContainsString('Rachel Kim', $this->body($html));
    }

    public function test_the_roster_reports_carry_no_rate_until_pay_is_asked_for(): void
    {
        $plain = $this->report('employee-list');
        $this->assertStringNotContainsString('>Rate</th>', $plain);

        $withPay = $this->report('employee-list', ['pay' => 1]);
        $this->assertStringContainsString('>Rate</th>', $withPay);
    }

    public function test_a_report_with_no_rates_in_it_does_not_offer_the_pay_box(): void
    {
        // A tickbox that does nothing is one somebody ticks and then mistrusts
        // the report for.
        $html = $this->report('attendance-counter', ['start' => '2026-09-21', 'end' => '2026-09-25']);

        $this->assertStringNotContainsString('name="pay"', $html);
    }

    public function test_the_activity_log_shows_a_voided_punch_rather_than_dropping_it(): void
    {
        // A record that hides its own corrections is not an audit trail.
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);

        $punch = TimePunch::where('user_id', $this->rachel->id)->firstOrFail();
        app(TimeClock::class)->void($punch, $this->admin, 'Punched on the wrong card');

        $html = $this->report('employee-activity', ['start' => '2026-09-21', 'end' => '2026-09-21']);

        $this->assertStringContainsString('Clocked in', $html);
        $this->assertStringContainsString('Voided', $html);
    }

    public function test_manual_adjustments_show_only_what_a_supervisor_wrote(): void
    {
        $this->punch($this->rachel, '2026-09-21 08:00', TimePunch::IN);

        app(TimeClock::class)->punch(
            $this->rachel,
            TimePunch::OUT,
            Carbon::parse('2026-09-21 16:00'),
            TimePunch::SOURCE_SUPERVISOR,
            $this->admin,
            'Forgot to clock out',
        );

        $html = $this->report('manual-adjustments', ['start' => '2026-09-21', 'end' => '2026-09-21']);

        $this->assertStringContainsString('Forgot to clock out', $html);
        $this->assertStringContainsString('Supervisor', $html);
        // The employee's own punch is not an adjustment.
        $this->assertStringContainsString('Showing 1 row', $html);
    }

    public function test_a_range_given_backwards_is_swapped_rather_than_refused(): void
    {
        $html = $this->report('attendance-counter', ['start' => '2026-09-25', 'end' => '2026-09-21']);

        $this->assertStringContainsString('Sep 21, 2026 – Sep 25, 2026', $html);
    }

    public function test_a_range_longer_than_the_cap_is_cut_and_said_so(): void
    {
        $html = $this->report('attendance-counter', ['start' => '2026-01-01', 'end' => '2026-12-31']);

        $this->assertStringContainsString('longer than '.StaffReportController::MAX_DAYS.' days', $html);
    }

    public function test_the_department_summary_totals_by_department_not_by_job(): void
    {
        // The grouping the role report could not give: a kitchen holds several
        // jobs, and a cook and a dishwasher are one department and two roles.
        $kitchen = Department::create(['name' => 'Kitchen']);

        $this->rachel->update(['department_id' => $kitchen->id, 'job_role' => 'Cook']);

        $dishwasher = $this->staff('Devon Brooks');
        $dishwasher->update(['department_id' => $kitchen->id, 'job_role' => 'Assistant']);

        $html = $this->report('department-summary', ['start' => '2026-09-21', 'end' => '2026-09-25']);

        $this->assertStringContainsString('Kitchen', $html);
        // The administrator is in no department, and is named as such rather
        // than dropped off a report that is supposed to cover everybody.
        $this->assertStringContainsString('Unassigned', $html);
        $this->assertStringContainsString('Showing 2 rows', $html);
    }

    public function test_the_department_filter_narrows_every_report(): void
    {
        $kitchen = Department::create(['name' => 'Kitchen']);
        $this->rachel->update(['department_id' => $kitchen->id]);

        $body = $this->body($this->report('employee-list', ['department' => (string) $kitchen->id]));

        $this->assertStringContainsString('Rachel Kim', $body);
        $this->assertStringNotContainsString('Administrator', $body);
    }

    public function test_unassigned_is_a_choice_on_the_department_filter(): void
    {
        // The question somebody asks the week after setting departments up.
        $kitchen = Department::create(['name' => 'Kitchen']);
        $this->rachel->update(['department_id' => $kitchen->id]);

        $body = $this->body($this->report('employee-list', ['department' => 'none']));

        $this->assertStringContainsString('Administrator', $body);
        $this->assertStringNotContainsString('Rachel Kim', $body);
    }

    public function test_the_department_filter_is_hidden_until_a_department_exists(): void
    {
        $this->assertStringNotContainsString('name="department"', $this->report('employee-list'));

        Department::create(['name' => 'Kitchen']);

        $this->assertStringContainsString('name="department"', $this->report('employee-list'));
    }

    /** The page, for one report and whatever filters it takes. */
    private function report(string $key, array $filters = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => $key] + $filters))
            ->assertOk()
            ->getContent();
    }

    /**
     * The table's rows, without the page around them.
     *
     * The signed-in director's own name sits in the sidebar of every screen, so
     * "is this person in the report" asked of the whole page answers yes for
     * them whatever the report says.
     */
    private function body(string $html): string
    {
        $from = (int) strpos($html, '<tbody');

        return substr($html, $from, (int) strpos($html, '</tbody>') - $from);
    }

    private function staff(string $name): User
    {
        return User::factory()->create([
            'role' => 'teacher', 'name' => $name, 'title' => 'Lead Teacher',
            'employment' => 'FT', 'pay_rate' => 24,
        ]);
    }

    private function punch(User $staff, string $at, string $type): void
    {
        app(TimeClock::class)->punch($staff, $type, Carbon::parse($at));
    }
}
