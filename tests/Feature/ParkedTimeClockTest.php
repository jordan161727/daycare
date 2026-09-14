<?php

namespace Tests\Feature;

use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The time clock, parked until the next version.
 *
 * It is built and tested — TimeClockTest and ClockInAtLoginTest both turn it
 * on and exercise it — but the centre is not punching one yet, so it ships
 * off. What matters here is the shape of the app with it off: a teacher signs
 * in to the dashboard rather than to a screen they cannot navigate back to,
 * nothing is punched on their behalf, and the dashboard says where the clock
 * went rather than leaving them to conclude something broke.
 */
class ParkedTimeClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 07:02:00'));
    }

    public function test_the_clock_ships_off_this_version(): void
    {
        $this->assertFalse(config('daycare.timesheet.clock.enabled'));
    }

    public function test_a_teacher_signs_in_to_the_dashboard_and_is_not_punched_in(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, TimePunch::where('user_id', $teacher->id)->count());
    }

    /** Off means off: the screen itself is not reachable by typing at it. */
    public function test_the_clock_screen_is_not_there_to_be_found(): void
    {
        $this->actingAs($this->teacher())->get(route('clock.index'))->assertNotFound();
    }

    /**
     * Nothing on the dashboard mentions it either.
     *
     * There was a card here saying the clock was coming, with the time ticking
     * beside it. It is commented out rather than gone — the next version
     * uncomments it — but while the feature is parked the dashboard is silent
     * about it for everybody.
     */
    public function test_the_dashboard_does_not_mention_the_parked_clock(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        foreach ([$this->teacher(), $admin] as $user) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk()
                ->assertDontSee('Next version')
                ->assertDontSee('Time Clock');
        }
    }

    private function teacher(): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'must_change_password' => false,
        ]);
    }
}
