<?php

namespace Tests\Feature;

use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\StaffShift;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Approved leave on its way to payroll.
 *
 * The day has to arrive under a code payroll recognises, at the right length,
 * and the day the balance could not stretch to has to arrive as unpaid rather
 * than as silence.
 */
class LeaveTimesheetTest extends TestCase
{
    use RefreshDatabase;

    /** The first half of August 2026: the 1st to the 15th. */
    private const PERIOD = '2026-08-01';

    private User $admin;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-07-27 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->maria = User::create([
            'name' => 'Maria Santos', 'email' => 'maria@example.com',
            'password' => 'password', 'role' => 'teacher',
            'employment' => 'FT', 'classroom' => 'Toddler',
        ]);
    }

    public function test_approving_puts_the_days_on_the_timesheet_under_the_payroll_code(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-04');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave))->assertSessionHas('success');

        $entries = TimesheetEntry::where('user_id', $this->maria->id)->orderBy('work_date')->get();

        $this->assertCount(2, $entries);
        $this->assertSame('PTO', $entries[0]->leave_code);
        $this->assertSame(480, $entries[0]->leave_minutes);
        $this->assertSame(480, $entries[0]->paidLeaveMinutes());
        $this->assertSame(0, $entries[0]->workedMinutes());

        // Approved by a person, so it is not a day still waiting to be
        // confirmed on the way to payroll.
        $this->assertFalse($entries[0]->needsConfirming());
        $this->assertSame($this->admin->id, $entries[0]->confirmed_by);
    }

    public function test_a_sick_day_arrives_as_sick_rather_than_as_pto(): void
    {
        $this->grant($this->maria, 'SICK', 16);
        $leave = $this->pendingLeave('SICK', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));

        $this->assertSame('SICK', TimesheetEntry::first()->leave_code);
    }

    public function test_the_days_the_balance_could_not_cover_arrive_as_unpaid(): void
    {
        $this->grant($this->maria, 'VACATION', 8);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-05');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave), ['allow_unpaid' => 1]);

        $codes = TimesheetEntry::where('user_id', $this->maria->id)
            ->orderBy('work_date')->pluck('leave_code')->all();

        $this->assertSame(['PTO', 'UNPAID', 'UNPAID'], $codes);

        // And payroll is told the difference: eight paid hours, sixteen not.
        $summary = app(Timesheet::class)
            ->summary(TimesheetPeriod::forDate(self::PERIOD))
            ->firstWhere('user.id', $this->maria->id);

        $this->assertSame(8.0, $summary['paid_leave_hours']);
        $this->assertSame(16.0, $summary['unpaid_leave_hours']);
    }

    public function test_half_days_are_paid_at_half_a_day(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-03', 4);

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));

        $this->assertSame(240, TimesheetEntry::first()->leave_minutes);
        $this->assertSame(4.0, $leave->fresh()->paid_hours);
    }

    public function test_seeding_a_period_brings_approved_leave_in_alongside_the_roster(): void
    {
        $this->approvedLeave('VACATION', '2026-08-04', '2026-08-04');
        $this->shift('2026-08-03', 7 * 60, 15 * 60);

        // The leave was approved before this period had a timesheet at all.
        TimesheetEntry::query()->delete();

        $period = TimesheetPeriod::forDate(self::PERIOD);
        $added = app(Timesheet::class)->seed($period);

        $this->assertSame(2, $added);

        $entries = TimesheetEntry::orderBy('work_date')->get();

        $this->assertSame(480, $entries[0]->workedMinutes());   // Monday, rostered
        $this->assertSame('PTO', $entries[1]->leave_code);      // Tuesday, on leave
    }

    public function test_a_day_somebody_has_already_spoken_for_is_not_written_over(): void
    {
        // She punched in and worked the day; the leave is approved afterwards.
        TimesheetEntry::create([
            'timesheet_period_id' => TimesheetPeriod::forDate('2026-08-03')->id,
            'user_id' => $this->maria->id,
            'work_date' => '2026-08-03',
            'starts_at' => 7 * 60,
            'ends_at' => 15 * 60,
            'source' => TimesheetEntry::SOURCE_CLOCK,
        ]);

        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave))
            ->assertSessionHas('warning');

        $entry = TimesheetEntry::first();

        $this->assertSame(480, $entry->workedMinutes());
        $this->assertNull($entry->leave_code);
    }

    public function test_an_approved_pay_period_is_never_touched(): void
    {
        $period = TimesheetPeriod::forDate('2026-08-03');
        $period->forceFill([
            'status' => TimesheetPeriod::STATUS_APPROVED,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ])->save();

        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave))
            ->assertSessionHas('warning');

        // The leave still stands and still costs the balance — it simply
        // cannot reach a period payroll has already been paid on.
        $this->assertTrue($leave->fresh()->isApproved());
        $this->assertSame(32.0, $this->balance($this->maria, 'VACATION'));
        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_revoking_takes_the_days_back_off_the_timesheet(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->pendingLeave('VACATION', '2026-08-03', '2026-08-04');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));
        $this->assertSame(2, TimesheetEntry::count());

        $this->actingAs($this->admin)->post(route('leave.revoke', $leave))->assertSessionHas('success');

        $this->assertSame(0, TimesheetEntry::count());
        $this->assertSame(40.0, $this->balance($this->maria, 'VACATION'));
    }

    // ---------------- helpers ----------------

    private function pendingLeave(string $type, string $from, string $to, float $hoursPerDay = 8): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $this->maria->id,
            'leave_type' => $type,
            'starts_on' => $from,
            'ends_on' => $to,
            'hours_per_day' => $hoursPerDay,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }

    private function approvedLeave(string $type, string $from, string $to): LeaveRequest
    {
        $this->grant($this->maria, $type, 40);
        $leave = $this->pendingLeave($type, $from, $to);

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));

        return $leave->fresh();
    }

    private function shift(string $date, int $starts, int $ends): StaffShift
    {
        return StaffShift::create([
            'week_start' => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'user_id' => $this->maria->id,
            'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'classroom' => 'Toddler',
        ]);
    }

    private function grant(User $user, string $type, float $hours): void
    {
        LeaveLedgerEntry::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'hours' => $hours,
            'effective_on' => '2026-07-27',
            'source' => LeaveLedgerEntry::SOURCE_ADJUSTMENT,
            'note' => 'Opening balance',
        ]);
    }

    private function balance(User $user, string $type): float
    {
        return round((float) LeaveLedgerEntry::where('user_id', $user->id)
            ->where('leave_type', $type)->sum('hours'), 2);
    }
}
