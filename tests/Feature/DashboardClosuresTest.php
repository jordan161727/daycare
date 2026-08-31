<?php

namespace Tests\Feature;

use App\Models\ClosureDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Upcoming closures on the dashboard.
 *
 * A teacher has no way into the holidays page, so this is the only place they
 * are told the centre is shut next Monday before the day itself.
 */
class DashboardClosuresTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));

        $this->teacher = User::factory()->create(['role' => 'teacher']);
    }

    public function test_a_teacher_sees_the_next_closures(): void
    {
        ClosureDay::create(['closed_on' => '2026-09-07', 'reason' => 'Labour Day']);
        ClosureDay::create(['closed_on' => '2026-10-12', 'reason' => 'Thanksgiving Day']);

        $this->actingAs($this->teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Upcoming closures')
            ->assertSee('Labour Day')
            ->assertSee('Thanksgiving Day')
            // The page they cannot open is not offered to them.
            ->assertDontSee('Manage holidays');
    }

    public function test_the_director_gets_a_way_through_to_the_holidays_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        ClosureDay::create(['closed_on' => '2026-09-07', 'reason' => 'Labour Day']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Manage holidays');
    }

    public function test_a_closure_that_has_passed_is_not_shown(): void
    {
        ClosureDay::create(['closed_on' => '2026-08-03', 'reason' => 'Civic Holiday']);
        ClosureDay::create(['closed_on' => '2026-09-07', 'reason' => 'Labour Day']);

        $this->actingAs($this->teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Civic Holiday')
            ->assertSee('Labour Day');
    }

    public function test_a_closure_today_still_counts_as_upcoming(): void
    {
        ClosureDay::create(['closed_on' => '2026-09-01', 'reason' => 'Staff training']);

        $this->actingAs($this->teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Staff training')
            ->assertSee('Today');
    }

    public function test_only_the_next_four_are_shown(): void
    {
        foreach (['2026-09-07', '2026-10-12', '2026-11-11', '2026-12-25', '2027-01-01'] as $date) {
            ClosureDay::create(['closed_on' => $date, 'reason' => 'Closure '.$date]);
        }

        // A seeded statutory calendar runs to dozens of days; the dashboard is
        // for the ones near enough to plan around.
        $this->actingAs($this->teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Closure 2026-12-25')
            ->assertDontSee('Closure 2027-01-01');
    }

    public function test_the_dashboard_reads_normally_with_no_closures_at_all(): void
    {
        $this->actingAs($this->teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('the centre is open every weekday');
    }
}
