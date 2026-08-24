<?php

namespace Tests\Feature;

use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The teacher's own week, and only their own.
 */
class MyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEEK.' 09:00:00'));
    }

    public function test_a_teacher_sees_their_own_shifts_and_not_a_colleagues(): void
    {
        $maria = $this->teacher('Maria Santos', 'Toddler');
        $grace = $this->teacher('Grace Ilagan', 'UPK-4');

        $this->shift($maria, 'MON', 7 * 60, 15 * 60 + 30, 'Toddler');
        $this->shift($grace, 'MON', 8 * 60, 16 * 60, 'UPK-4');

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('My Schedule')
            ->assertSee('Maria Santos')
            ->assertSee('7:00 AM – 3:30 PM', escape: false)
            ->assertSee('Toddler')
            // The colleague on the same day is not on this page at all.
            ->assertDontSee('Grace Ilagan')
            ->assertDontSee('8:00 AM – 4:00 PM', escape: false);
    }

    public function test_the_week_total_is_their_own_hours(): void
    {
        $maria = $this->teacher('Maria Santos', 'Toddler');
        $grace = $this->teacher('Grace Ilagan', 'UPK-4');

        // 8 hours Monday, 4 Tuesday. Grace's day must not land in the total.
        $this->shift($maria, 'MON', 8 * 60, 16 * 60, 'Toddler');
        $this->shift($maria, 'TUE', 8 * 60, 12 * 60, 'Toddler');
        $this->shift($grace, 'MON', 8 * 60, 16 * 60, 'UPK-4');

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('12')
            ->assertSee('scheduled this week');
    }

    public function test_a_day_with_no_shift_says_so_rather_than_going_missing(): void
    {
        $maria = $this->teacher('Maria Santos', 'Toddler');
        $this->shift($maria, 'MON', 8 * 60, 16 * 60, 'Toddler');

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('Tuesday')
            ->assertSee('Not scheduled');
    }

    public function test_another_week_can_be_looked_at(): void
    {
        $maria = $this->teacher('Maria Santos', 'Toddler');

        $next = Carbon::parse(self::WEEK)->addWeek()->toDateString();
        $this->shift($maria, 'WED', 9 * 60, 17 * 60, 'Toddler', $next);

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('not scheduled for any shift this week');

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine', ['week' => $next]))
            ->assertOk()
            ->assertSee('9:00 AM – 5:00 PM', escape: false);
    }

    public function test_a_cover_shift_says_why_they_are_in_a_room_that_is_not_theirs(): void
    {
        $maria = $this->teacher('Maria Santos', 'Toddler');
        $this->shift($maria, 'MON', 13 * 60, 15 * 60, 'UPK-4', role: StaffShift::ROLE_PATCH);

        $this->actingAs($maria)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('keep the room in ratio');
    }

    public function test_signed_out_visitors_are_sent_to_the_login(): void
    {
        $this->get(route('staff-schedule.mine'))->assertRedirect(route('login'));
    }

    private function teacher(string $name, ?string $room = null): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => 'FT',
            'classroom' => $room,
        ]);
    }

    private function shift(User $user, string $day, int $from, int $to, string $room, ?string $week = null, string $role = StaffShift::ROLE_STAFF): StaffShift
    {
        $week ??= self::WEEK;
        $offset = array_search($day, config('daycare.days'), true);

        return StaffShift::create([
            'week_start' => $week,
            'user_id' => $user->id,
            'shift_date' => Carbon::parse($week)->addDays($offset)->toDateString(),
            'day' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
            'classroom' => $room,
            'role' => $role,
        ]);
    }
}
