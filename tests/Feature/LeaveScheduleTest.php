<?php

namespace Tests\Feature;

use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Approved leave, as the roster sees it.
 *
 * Two directions matter and they are different problems. Leave granted before
 * the week is built has to bound the solve. Leave granted afterwards has to
 * reach a roster that is already published — and say that it did.
 */
class LeaveScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEEK.' 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);
    }

    // ---------------- building a week around leave ----------------

    public function test_nobody_is_rostered_on_a_day_they_were_granted_off(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');

        app(StaffSchedule::class)->generate(self::WEEK);

        $days = StaffShift::where('user_id', $maria->id)->pluck('day');

        $this->assertNotContains('WED', $days);
        $this->assertContains('TUE', $days);
    }

    public function test_a_week_with_leave_in_it_lowers_the_target_rather_than_squeezing_the_hours_in(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');

        app(StaffSchedule::class)->generate(self::WEEK);

        $minutes = StaffShift::where('user_id', $maria->id)->get()
            ->sum(fn (StaffShift $shift) => $shift->minutes());

        // Four days of a 40h week, spread evenly: eight hours a day, not ten.
        $this->assertSame(4 * 8 * 60, $minutes);
    }

    public function test_the_week_says_who_is_away_and_why_the_hours_are_lower(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'SICK', '2026-07-29', '2026-07-30');

        $week = app(StaffSchedule::class)->generate(self::WEEK);

        $this->assertTrue(
            collect($week->warnings)->contains(fn ($line) => str_contains($line, 'Maria Santos is on approved leave WED, THU')),
            'The week should say who is away: '.json_encode($week->warnings),
        );
    }

    public function test_somebody_on_leave_is_not_pulled_in_to_cover_another_room_either(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-07-27', '2026-07-31');

        app(StaffSchedule::class)->generate(self::WEEK);

        $this->assertSame(0, StaffShift::where('user_id', $maria->id)->count());
    }

    public function test_leave_over_a_closed_day_costs_nothing_and_is_not_reported_as_a_gap(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-08-01', '2026-08-02');   // a weekend

        $week = app(StaffSchedule::class)->generate(self::WEEK);

        $this->assertFalse(
            collect($week->warnings)->contains(fn ($line) => str_contains($line, 'on approved leave')),
        );
        $this->assertSame(5, StaffShift::where('user_id', $maria->id)->count());
    }

    // ---------------- leave granted after the week was published ----------------

    public function test_approving_leave_takes_the_shifts_off_a_published_week(): void
    {
        $maria = $this->teacher('Maria Santos');

        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);
        $this->assertSame(5, StaffShift::where('user_id', $maria->id)->count());

        $leave = $this->pendingLeave($maria, 'VACATION', '2026-07-29', '2026-07-30');
        $this->grant($maria, 'VACATION', 40);

        $this->actingAs($this->admin)->post(route('leave.approve', $leave))->assertSessionHas('success');

        $days = StaffShift::where('user_id', $maria->id)->pluck('day');

        $this->assertCount(3, $days);
        $this->assertNotContains('WED', $days);
        $this->assertNotContains('THU', $days);
    }

    public function test_the_published_week_records_that_shifts_were_pulled_and_asks_to_be_rebuilt(): void
    {
        $maria = $this->teacher('Maria Santos');

        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);

        $leave = $this->pendingLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');
        $this->grant($maria, 'VACATION', 40);

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));

        $warnings = StaffScheduleWeek::firstWhere('week_start', self::WEEK)->warnings;

        $this->assertTrue(
            collect($warnings)->contains(fn ($line) => str_contains($line, 'regenerate the week to re-cover those rooms')),
            'The published week should ask to be rebuilt: '.json_encode($warnings),
        );
    }

    public function test_a_colleagues_shifts_are_left_alone(): void
    {
        $maria = $this->teacher('Maria Santos');
        $grace = $this->teacher('Grace Ilagan');

        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);

        $leave = $this->pendingLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');
        $this->grant($maria, 'VACATION', 40);

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));

        $this->assertSame(5, StaffShift::where('user_id', $grace->id)->count());
    }

    // ---------------- what the screens show ----------------

    public function test_the_teachers_own_week_shows_the_day_as_booked_off(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');

        app(StaffSchedule::class)->generate(self::WEEK);

        $this->actingAs($maria)->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('Wednesday')
            ->assertSee('Vacation')
            ->assertSee('8h approved');
    }

    public function test_a_week_that_is_nothing_but_leave_still_shows_the_leave(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'SICK', '2026-07-27', '2026-07-31');

        // No roster at all for the week — the leave is the only thing on it.
        $this->actingAs($maria)->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('Sick leave')
            ->assertDontSee('not scheduled for any shift this week');
    }

    public function test_the_full_roster_marks_the_absence_rather_than_leaving_a_blank(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->approvedLeave($maria, 'VACATION', '2026-07-29', '2026-07-29');

        app(StaffSchedule::class)->generate(self::WEEK);

        $this->actingAs($this->admin)->get(route('staff-schedule.index'))
            ->assertOk()
            ->assertSee('VACATION')
            ->assertSee('approved leave');
    }

    // ---------------- helpers ----------------

    private function teacher(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => 'FT',
            'classroom' => 'Toddler',
        ]);
    }

    private function pendingLeave(User $user, string $type, string $from, string $to): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'starts_on' => $from,
            'ends_on' => $to,
            'hours_per_day' => 8,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }

    private function approvedLeave(User $user, string $type, string $from, string $to): LeaveRequest
    {
        $leave = $this->pendingLeave($user, $type, $from, $to);

        $leave->forceFill([
            'status' => LeaveRequest::STATUS_APPROVED,
            'paid_hours' => $leave->hours(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ])->save();

        return $leave;
    }

    private function grant(User $user, string $type, float $hours): void
    {
        LeaveLedgerEntry::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'hours' => $hours,
            'effective_on' => self::WEEK,
            'source' => LeaveLedgerEntry::SOURCE_ADJUSTMENT,
            'note' => 'Opening balance',
        ]);
    }
}
