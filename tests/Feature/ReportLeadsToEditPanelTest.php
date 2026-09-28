<?php

namespace Tests\Feature;

use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A report row is a way in, not a dead end.
 *
 * The Manual Time Adjustments report lists every punch a supervisor wrote.
 * Each row is a day somebody already had to put right once, and the next
 * question is usually "and what else is wrong with it" — so the row opens
 * that day in the edit panel on the grid, where a time can be moved and a
 * break added, rather than leaving the reader to go and find the dot.
 *
 * The link is for the screen. The exported file carries the report and not
 * the addresses of the pages that edit it.
 */
class ReportLeadsToEditPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('daycare.timesheet.clock.enabled', true);

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));

        $this->admin = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->teacher = User::create([
            'name' => 'Amaan Bin Alam', 'email' => 'amaan@example.com', 'password' => 'password',
            'role' => 'teacher', 'employment' => 'FT', 'classroom' => 'Toddler', 'pay_rate' => 20,
        ]);

        // One supervisor punch, so the report has a row.
        app(TimeClock::class)->punch(
            user: $this->teacher,
            type: TimePunch::OUT,
            at: Carbon::parse('2026-09-22 16:00', config('app.timezone')),
            source: TimePunch::SOURCE_SUPERVISOR,
            by: $this->admin,
            reason: 'Forgot to punch',
        );
    }

    public function test_each_adjustment_row_opens_that_day_in_the_panel(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('staff.reports', ['report' => 'manual-adjustments', 'start' => '2026-09-21', 'end' => '2026-09-25']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('timesheets.open', ['user' => $this->teacher->id, 'date' => '2026-09-22']), $html);
        $this->assertStringContainsString('Open day', $html);
    }

    public function test_the_open_address_lands_on_the_grid_with_the_day_named(): void
    {
        $this->actingAs($this->admin)
            ->get(route('timesheets.open', ['user' => $this->teacher->id, 'date' => '2026-09-22']))
            ->assertRedirect(route('staff.timesheets', [
                'from' => '2026-09-21',
                'to' => '2026-09-27',
                'open' => $this->teacher->id.'|2026-09-22',
            ]));

        // And the grid knows what to do with it on arrival.
        $grid = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString("new URLSearchParams(window.location.search).get('open')", $grid);
        $this->assertStringContainsString('this.open(Number(userId), date);', $grid);
    }

    public function test_the_export_carries_the_report_and_not_the_links(): void
    {
        $csv = $this->actingAs($this->admin)
            ->get(route('staff.reports.export', ['report' => 'manual-adjustments', 'start' => '2026-09-21', 'end' => '2026-09-25', 'format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Recorded by', $csv);
        $this->assertStringContainsString('Forgot to punch', $csv);
        $this->assertStringNotContainsString('/timesheets/open/', $csv);
        $this->assertStringNotContainsString('Edit', explode("\n", trim($csv))[0]);
    }
}
