<?php

namespace Tests\Feature;

use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The reports screen: a report is chosen, a start date is given, a grid comes
 * back.
 *
 * What it has to get right is the arithmetic and who may see it. A day's cell
 * is hours somebody is paid for, and the pay column is a rate — so the page
 * belongs to the director, and a teacher reaching it would be reading the
 * centre's payroll.
 */
class StaffDailySummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so a default period has days behind and ahead in it.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
    }

    public function test_a_days_worked_hours_are_printed_in_their_own_column(): void
    {
        $staff = $this->staff('Rachel Kim');

        $this->punch($staff, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($staff, '2026-09-21 16:30', TimePunch::OUT);

        $html = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rachel Kim', $html);
        $this->assertStringContainsString($staff->staffId(), $html);

        // Eight and a half hours, as the clock reads them rather than as a
        // decimal — this grid is compared against a punch card, not multiplied.
        $this->assertStringContainsString('08:30', $html);
    }

    public function test_one_week_draws_seven_columns_and_two_weeks_fourteen(): void
    {
        $this->staff('Rachel Kim');

        $week = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sun 09/27', $week);
        $this->assertStringNotContainsString('Mon 09/28', $week);

        $fortnight = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-2w', 'start' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Mon 09/28', $fortnight);
        $this->assertStringContainsString('Sun 10/04', $fortnight);
    }

    public function test_the_period_runs_forward_from_whatever_day_is_given(): void
    {
        // A centre whose fortnight starts on a Saturday gets a fortnight
        // starting on a Saturday, not one snapped to a Monday.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-2w', 'start' => '2026-08-29']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sat 08/29', $html);
        $this->assertStringContainsString('Fri 09/11', $html);
    }

    public function test_the_total_is_the_sum_of_the_days(): void
    {
        $staff = $this->staff('Patrick Ortiz');

        $this->punch($staff, '2026-09-21 09:00', TimePunch::IN);
        $this->punch($staff, '2026-09-21 12:00', TimePunch::OUT);
        $this->punch($staff, '2026-09-22 09:00', TimePunch::IN);
        $this->punch($staff, '2026-09-22 13:15', TimePunch::OUT);

        $html = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        // Three hours and four and a quarter.
        $this->assertStringContainsString('07:15', $html);
    }

    public function test_pay_is_only_printed_when_it_is_asked_for(): void
    {
        // A rate on screen by default is payroll left open on a desk. It is a
        // column somebody turns on when they are working on pay.
        $staff = $this->staff('Rachel Kim');

        $this->punch($staff, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($staff, '2026-09-21 16:00', TimePunch::OUT);

        $plain = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>Pay</th>', $plain);

        $withPay = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21', 'pay' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Pay</th>', $withPay);
        // Eight hours at twenty-four. Written as a number rather than a
        // formatted one, because these same rows go into the spreadsheet and a
        // pay column somebody cannot total is not worth exporting.
        $this->assertMatchesRegularExpression('/>\s*192\s*</', $withPay);
    }

    public function test_the_role_filter_narrows_the_rows(): void
    {
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'daily-summary-1w', 'start' => '2026-09-21', 'role' => 'Admin']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Administrator', $html);
        $this->assertStringNotContainsString('Rachel Kim', $html);
    }

    public function test_a_report_nobody_recognises_falls_back_rather_than_failing(): void
    {
        // The usual cause is a bookmarked link from before a rename, and a
        // grid is a better answer to that than an error page.
        $this->staff('Rachel Kim');

        $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'whatever-this-was']))
            ->assertOk()
            ->assertSee('Employee Daily Summary');
    }

    public function test_an_invented_start_date_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->get(route('staff.reports', ['start' => 'whenever']))
            ->assertSessionHasErrors('start');
    }

    public function test_a_teacher_cannot_reach_the_reports(): void
    {
        $teacher = $this->staff('Maya Lindqvist');

        $this->actingAs($teacher)->get(route('staff.reports'))->assertForbidden();
        $this->actingAs($teacher)->get(route('staff.reports.export'))->assertForbidden();
    }

    public function test_the_grid_comes_out_as_a_file(): void
    {
        $staff = $this->staff('Rachel Kim');

        $this->punch($staff, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($staff, '2026-09-21 16:30', TimePunch::OUT);

        $this->actingAs($this->admin)
            ->get(route('staff.reports.export', [
                'report' => 'daily-summary-1w',
                'start' => '2026-09-21',
                'format' => 'csv',
            ]))
            ->assertOk()
            ->assertDownload('daily-summary-1w-2026-09-21-to-2026-09-27.csv');
    }

    private function staff(string $name): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => $name, 'title' => 'Lead Teacher', 'pay_rate' => 24]);
    }

    private function punch(User $staff, string $at, string $type): void
    {
        app(TimeClock::class)->punch($staff, $type, Carbon::parse($at));
    }
}
