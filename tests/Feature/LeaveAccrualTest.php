<?php

namespace Tests\Feature;

use App\Models\LeaveLedgerEntry;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\LeaveLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Earning leave, and the ways a balance moves without anybody taking a day off.
 */
class LeaveAccrualTest extends TestCase
{
    use RefreshDatabase;

    /** The second half of August 2026: the 16th to the 31st. */
    private const PERIOD = '2026-08-16';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // After the period has ended, so it can be approved — and accrual only
        // ever follows an approval.
        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);
    }

    public function test_hourly_staff_earn_against_the_hours_they_actually_worked(): void
    {
        $maria = $this->teacher('Maria Santos');

        // Sixty hours worked across the period.
        $this->worked($maria, '2026-08-17', 8);
        $this->worked($maria, '2026-08-18', 8);
        $this->worked($maria, '2026-08-19', 8);
        $this->worked($maria, '2026-08-20', 8);
        $this->worked($maria, '2026-08-21', 8);
        $this->worked($maria, '2026-08-24', 8);
        $this->worked($maria, '2026-08-25', 8);
        $this->worked($maria, '2026-08-26', 4);

        $this->accrue();

        // One hour of sick per thirty worked, one of vacation per forty.
        $this->assertSame(2.0, $this->balance($maria, 'SICK'));
        $this->assertSame(1.5, $this->balance($maria, 'VACATION'));
    }

    public function test_paid_leave_does_not_itself_earn_more_leave(): void
    {
        $maria = $this->teacher('Maria Santos');

        $this->worked($maria, '2026-08-17', 8);
        $this->leaveDay($maria, '2026-08-18', 'PTO', 8);

        $this->accrue();

        // Eight hours worked, not sixteen: 8/30 rounds to 0.27.
        $this->assertSame(0.27, $this->balance($maria, 'SICK'));
    }

    public function test_salaried_staff_earn_a_flat_rate_whatever_the_clock_says(): void
    {
        $lead = $this->teacher('Grace Ilagan', ['employment' => 'FT_SALARY']);
        $this->worked($lead, '2026-08-17', 3);

        $this->accrue();

        $this->assertSame(3.33, $this->balance($lead, 'VACATION'));
        $this->assertSame(1.34, $this->balance($lead, 'SICK'));
    }

    public function test_running_the_same_period_twice_earns_nothing_the_second_time(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        $this->accrue();
        $first = $this->balance($maria, 'SICK');

        app(LeaveLedger::class)->accrue(TimesheetPeriod::forDate(self::PERIOD), $this->admin);

        $this->assertSame($first, $this->balance($maria, 'SICK'));
        $this->assertSame(1, LeaveLedgerEntry::where('source', LeaveLedgerEntry::SOURCE_ACCRUAL)
            ->where('leave_type', 'SICK')->count());
    }

    public function test_a_draft_period_earns_nothing_because_its_hours_can_still_change(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        $lines = app(LeaveLedger::class)->accrue(TimesheetPeriod::forDate(self::PERIOD), $this->admin);

        $this->assertSame(0.0, $this->balance($maria, 'SICK'));
        $this->assertStringContainsString('has not been approved yet', $lines[0]);
    }

    public function test_accrual_stops_at_the_cap_and_says_so(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        // Half an hour under the sick cap, so only half an hour can be earned.
        $this->grant($maria, 'SICK', config('daycare.leave.cap')['SICK'] - 0.1);

        $lines = $this->accrue();

        $this->assertSame((float) config('daycare.leave.cap')['SICK'], $this->balance($maria, 'SICK'));
        $this->assertTrue(
            collect($lines)->contains(fn ($line) => str_contains($line, 'capped')),
            'The run should name the capped balance: '.json_encode($lines),
        );
    }

    public function test_approving_a_pay_period_is_what_earns_the_leave(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->assertSame(0.0, $this->balance($maria, 'SICK'));

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('success');

        $this->assertSame(0.27, $this->balance($maria, 'SICK'));
    }

    public function test_approving_prints_what_everybody_earned(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        // The run's own lines come back on the redirect and are printed above
        // the grid: a balance that moves with no explanation is a number staff
        // have to take on faith.
        $this->actingAs($this->admin)
            ->from(route('timesheets.index', ['date' => self::PERIOD]))
            ->followingRedirects()
            ->post(route('timesheets.approve', TimesheetPeriod::forDate(self::PERIOD)))
            ->assertOk()
            ->assertSee('Leave earned')
            ->assertSee('Maria Santos: 0.27h SICK earned.')
            ->assertSee('See every balance');
    }

    // ---------------- from the command line ----------------

    public function test_the_command_accrues_a_named_period(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);
        $this->approvePeriod();

        $this->artisan('leave:accrue', ['date' => '2026-08-20'])
            ->expectsOutputToContain('Aug 16 – 31, 2026')
            ->expectsOutputToContain('Maria Santos: 0.27h SICK earned.')
            ->assertExitCode(0);

        $this->assertSame(0.27, $this->balance($maria, 'SICK'));
    }

    public function test_the_command_defaults_to_the_period_that_has_just_ended(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);
        $this->approvePeriod();

        // Standing on 2 September, the period just gone is 16–31 August — not
        // the one we are in the middle of, which nobody has worked out yet.
        $this->artisan('leave:accrue')
            ->expectsOutputToContain('Aug 16 – 31, 2026')
            ->assertExitCode(0);

        $this->assertSame(0.27, $this->balance($maria, 'SICK'));
    }

    public function test_the_command_says_so_when_the_period_has_no_timesheet(): void
    {
        $this->artisan('leave:accrue', ['date' => '2026-03-04'])
            ->expectsOutputToContain('No timesheet exists for Mar 1 – 15, 2026')
            ->assertExitCode(0);
    }

    public function test_the_command_refuses_a_period_that_is_still_a_draft(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->worked($maria, '2026-08-17', 8);

        $this->artisan('leave:accrue', ['date' => '2026-08-20'])
            ->expectsOutputToContain('has not been approved yet')
            ->assertExitCode(0);

        $this->assertSame(0.0, $this->balance($maria, 'SICK'));
    }

    // ---------------- the director's own corrections ----------------

    public function test_a_director_can_open_a_balance_by_hand_but_must_say_why(): void
    {
        $maria = $this->teacher('Maria Santos');

        $this->actingAs($this->admin)->post(route('leave.adjust', $maria), [
            'leave_type' => 'VACATION',
            'hours' => 24,
            'note' => 'Carried over from the old paper card',
        ])->assertSessionHas('success');

        $this->assertSame(24.0, $this->balance($maria, 'VACATION'));

        $this->actingAs($this->admin)->post(route('leave.adjust', $maria), [
            'leave_type' => 'VACATION',
            'hours' => 8,
        ])->assertSessionHasErrors('note');

        $this->assertSame(24.0, $this->balance($maria, 'VACATION'));
    }

    public function test_an_adjustment_can_take_hours_away_again(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->grant($maria, 'SICK', 10);

        $this->actingAs($this->admin)->post(route('leave.adjust', $maria), [
            'leave_type' => 'SICK',
            'hours' => -4,
            'note' => 'Correcting a double entry',
        ])->assertSessionHas('success');

        $this->assertSame(6.0, $this->balance($maria, 'SICK'));
    }

    public function test_the_balances_page_shows_every_staff_member(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->grant($maria, 'VACATION', 12.5);

        $this->actingAs($this->admin)->get(route('leave.balances'))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('12.50');
    }

    public function test_a_teacher_sees_their_own_balance_and_where_it_came_from(): void
    {
        $maria = $this->teacher('Maria Santos');
        $this->grant($maria, 'SICK', 6);

        $this->actingAs($maria)->get(route('leave.index'))
            ->assertOk()
            ->assertSee('6.00')
            ->assertSee('Opening balance')
            ->assertSee('Adjusted by the director');
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

    /** A confirmed day of work, which is the only kind that earns anything. */
    private function worked(User $user, string $date, float $hours): TimesheetEntry
    {
        return TimesheetEntry::create([
            'timesheet_period_id' => TimesheetPeriod::forDate($date)->id,
            'user_id' => $user->id,
            'work_date' => $date,
            'starts_at' => 8 * 60,
            'ends_at' => (int) (8 * 60 + $hours * 60),
            'break_minutes' => 0,
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);
    }

    private function leaveDay(User $user, string $date, string $code, float $hours): TimesheetEntry
    {
        return TimesheetEntry::create([
            'timesheet_period_id' => TimesheetPeriod::forDate($date)->id,
            'user_id' => $user->id,
            'work_date' => $date,
            'leave_code' => $code,
            'leave_minutes' => (int) ($hours * 60),
            'source' => TimesheetEntry::SOURCE_MANUAL,
        ]);
    }

    /** Sign the period off, the way the director's approve button does. */
    private function approvePeriod(string $date = self::PERIOD): TimesheetPeriod
    {
        $period = TimesheetPeriod::forDate($date);

        $period->forceFill([
            'status' => TimesheetPeriod::STATUS_APPROVED,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ])->save();

        return $period;
    }

    /** Approve the period, then run the accrual over it. */
    private function accrue(): array
    {
        return app(LeaveLedger::class)->accrue($this->approvePeriod(), $this->admin);
    }

    private function grant(User $user, string $type, float $hours): void
    {
        LeaveLedgerEntry::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'hours' => $hours,
            'effective_on' => self::PERIOD,
            'source' => LeaveLedgerEntry::SOURCE_ADJUSTMENT,
            'note' => 'Opening balance',
        ]);
    }

    private function balance(User $user, string $type): float
    {
        return app(LeaveLedger::class)->balance($user->id, $type);
    }
}
