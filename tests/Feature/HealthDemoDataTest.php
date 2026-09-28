<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * demo:health, the data the month sheet and its export are looked at with.
 *
 * What matters is not the days it invents but that it never touches a day
 * somebody real recorded, that every arrival it writes carries its check and
 * the audit entry a real one would, and that --undo takes back exactly what
 * it wrote and nothing else.
 */
class HealthDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday well into the month, so there are weekdays to fill.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        User::factory()->create(['role' => 'admin']);

        $this->child = Child::create([
            'lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active',
            'classroom' => 'PreK', 'dob' => '2022-12-15', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }

    public function test_it_writes_arrivals_with_their_checks_and_audit(): void
    {
        $this->artisan('demo:health --force')->assertSuccessful();

        $days = Attendance::where('child_id', $this->child->id)->get();

        $this->assertGreaterThan(0, $days->count());

        // Only the month so far, and only weekdays: nothing in the future,
        // nothing on a Saturday.
        foreach ($days as $day) {
            $this->assertLessThanOrEqual('2026-09-23', $day->attendance_date->toDateString());
            $this->assertFalse($day->attendance_date->isWeekend());
            $this->assertNotNull($day->health_in_code, 'every demo arrival carries its check');
        }

        // The trail a real check leaves.
        $this->assertGreaterThanOrEqual($days->count(), HealthAudit::count());
    }

    public function test_it_never_touches_a_day_somebody_real_recorded(): void
    {
        $real = Attendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-09-14', 'session' => 'FULL',
            // To the minute, the way the door records it — no demo signature.
            'signed_in_at' => Carbon::parse('2026-09-14 08:05:00'), 'health_in_code' => 4,
        ]);

        $this->artisan('demo:health --force')->assertSuccessful();

        $this->assertSame(1, Attendance::where('child_id', $this->child->id)->whereDate('attendance_date', '2026-09-14')->count());
        $this->assertSame(4, $real->fresh()->health_in_code);
    }

    public function test_undo_takes_back_exactly_what_it_wrote(): void
    {
        $real = Attendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-09-14', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-14 08:05:00'), 'health_in_code' => 0,
        ]);

        $this->artisan('demo:health --force')->assertSuccessful();
        $this->assertGreaterThan(1, Attendance::count());

        $this->artisan('demo:health --undo')->assertSuccessful();

        // The one real day, and only that; its audit untouched too.
        $this->assertSame([$real->id], Attendance::pluck('id')->all());
        $this->assertSame(0, HealthAudit::where('attendance_id', '!=', $real->id)->count());
    }

    public function test_without_force_a_production_box_writes_nothing_non_interactively(): void
    {
        // A non-interactive shell answers the "are you sure" with its default,
        // which is no. --force is the way a person who has decided says yes.
        //
        // isProduction() reads the application's detected environment, not
        // config('app.env'), so that is what has to be set here.
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('demo:health')->expectsConfirmation('This app is running as production. Write demo attendance anyway?', 'no')->assertSuccessful();

        $this->assertSame(0, Attendance::count());
    }
}
