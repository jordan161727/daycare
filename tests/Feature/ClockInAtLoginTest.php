<?php

namespace Tests\Feature;

use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Signing in is clocking in, and the guided run of punches after it.
 */
class ClockInAtLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-20';

    protected function setUp(): void
    {
        parent::setUp();

        // The clock is off by default this version; these are its own tests.
        config()->set('daycare.timesheet.clock.enabled', true);

        $this->travelTo(Carbon::parse(self::TODAY.' 07:02:00'));
    }

    public function test_a_teacher_signing_in_is_clocked_in_at_that_moment(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('clock.index'))
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '7:02 am'));

        $punch = TimePunch::where('user_id', $teacher->id)->sole();

        $this->assertSame(TimePunch::IN, $punch->type);
        $this->assertSame('7:02 am', $punch->time());
        $this->assertSame(self::TODAY, $punch->work_date->toDateString());
    }

    public function test_a_brand_new_teacher_still_sees_they_were_clocked_in(): void
    {
        // The very first sign-in is bounced to the password change, an extra
        // hop the login never knew about — the arrival time has to survive it.
        $teacher = $this->teacher();
        $teacher->forceFill(['must_change_password' => true])->save();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('clock.index'));

        $this->followingRedirects()
            ->get(route('clock.index'))
            ->assertSee('Choose your password')
            ->assertSee('Clocked in at 7:02 am', escape: false);
    }

    public function test_signing_in_again_the_same_day_does_not_punch_a_second_time(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password']);
        $this->post(route('logout'));

        // Back from an errand at eleven. The morning's IN still stands, and a
        // second one would read as a shift that never ended.
        $this->travelTo(Carbon::parse(self::TODAY.' 11:00:00'));

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, TimePunch::where('user_id', $teacher->id)->count());
    }

    public function test_an_admin_signing_in_is_not_clocked_in(): void
    {
        $admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, TimePunch::where('user_id', $admin->id)->count());
    }

    public function test_the_clock_is_not_punched_when_the_feature_is_off(): void
    {
        config()->set('daycare.timesheet.clock.enabled', false);

        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, TimePunch::where('user_id', $teacher->id)->count());
    }

    public function test_lunch_break_and_clocking_out_each_confirm_what_was_recorded(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password']);

        $this->travelTo(Carbon::parse(self::TODAY.' 12:00:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::LUNCH_START])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'Left for lunch at 12:00 pm')
                && str_contains($said, 'unpaid'));

        $this->travelTo(Carbon::parse(self::TODAY.' 12:30:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::LUNCH_END])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '30 minutes, unpaid'));

        $this->travelTo(Carbon::parse(self::TODAY.' 15:00:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::BREAK_START])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, 'End break'));

        $this->travelTo(Carbon::parse(self::TODAY.' 15:15:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::BREAK_END])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '15 minutes, paid'));

        // 7:02 to 16:02 is nine hours, less the half-hour lunch. The break is
        // inside the cap, so it stays paid.
        $this->travelTo(Carbon::parse(self::TODAY.' 16:02:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::OUT])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '8.50 h worked today')
                && str_contains($said, '30 minutes unpaid'));
    }

    public function test_a_break_past_the_cap_says_how_much_of_it_was_unpaid(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password']);

        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::BREAK_START]);

        $this->travelTo(Carbon::parse(self::TODAY.' 10:35:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::BREAK_END])
            ->assertSessionHas('success', fn (string $said) => str_contains($said, '35 minutes — 20 paid, 15 unpaid'));
    }

    public function test_a_punch_out_of_order_is_refused_and_says_where_they_actually_stand(): void
    {
        $teacher = $this->teacher();

        $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password']);

        $this->travelTo(Carbon::parse(self::TODAY.' 12:00:00'));
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::LUNCH_START]);

        // A page left open since before lunch still offers "Start lunch".
        $this->actingAs($teacher)->post(route('clock.punch'), ['type' => TimePunch::LUNCH_START])
            ->assertSessionHas('warning', fn (string $said) => str_contains($said, 'already at lunch'));

        $this->assertSame(2, TimePunch::where('user_id', $teacher->id)->count());
    }

    private function teacher(): User
    {
        return User::create([
            'name' => 'Maria Santos', 'email' => 'maria@example.com',
            'password' => 'password', 'role' => 'teacher',
            'employment' => 'FT', 'classroom' => 'Toddler',
        ]);
    }
}
