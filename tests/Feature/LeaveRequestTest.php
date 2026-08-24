<?php

namespace Tests\Feature;

use App\Models\ClosureDay;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asking for time off, and what a director can do about it.
 */
class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    /** Monday 27 July 2026. Every date below is relative to standing here. */
    private const TODAY = '2026-07-27';

    private User $admin;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->maria = $this->teacher('Maria Santos');
    }

    // ---------------- asking ----------------

    public function test_a_teacher_asks_for_time_off_and_it_lands_as_pending(): void
    {
        $this->actingAs($this->maria)->post(route('leave.store'), [
            'leave_type' => 'VACATION',
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-08-05',
            'hours_per_day' => 8,
            'reason' => 'Family visiting',
        ])->assertRedirect(route('leave.index'));

        $leave = LeaveRequest::first();

        $this->assertSame('Maria Santos', $leave->user->name);
        $this->assertSame(LeaveRequest::STATUS_PENDING, $leave->status);
        $this->assertSame(3, $leave->days());
        $this->assertSame(24.0, $leave->hours());
    }

    public function test_weekends_and_closure_days_are_not_charged_for(): void
    {
        ClosureDay::create(['closed_on' => '2026-08-05', 'reason' => 'Staff training']);

        // Mon 3rd to Mon 10th: six working days, less the closed Wednesday.
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-10');

        $this->assertSame(5, $leave->days());
        $this->assertNotContains('2026-08-08', $leave->workingDates());   // Saturday
        $this->assertNotContains('2026-08-05', $leave->workingDates());   // closed
    }

    public function test_vacation_cannot_be_asked_for_after_the_fact(): void
    {
        $this->actingAs($this->maria)->post(route('leave.store'), [
            'leave_type' => 'VACATION',
            'starts_on' => '2026-07-20',
            'ends_on' => '2026-07-21',
        ])->assertSessionHas('warning');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_a_sick_day_can_be_filed_the_morning_after(): void
    {
        $this->actingAs($this->maria)->post(route('leave.store'), [
            'leave_type' => 'SICK',
            'starts_on' => '2026-07-24',
            'ends_on' => '2026-07-24',
        ])->assertSessionHas('success');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_a_range_of_nothing_but_weekends_is_refused(): void
    {
        $this->actingAs($this->maria)->post(route('leave.store'), [
            'leave_type' => 'VACATION',
            'starts_on' => '2026-08-01',   // Saturday
            'ends_on' => '2026-08-02',     // Sunday
        ])->assertSessionHas('warning');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_two_requests_cannot_cover_the_same_day(): void
    {
        $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-05');

        $this->actingAs($this->maria)->post(route('leave.store'), [
            'leave_type' => 'SICK',
            'starts_on' => '2026-08-05',
            'ends_on' => '2026-08-06',
        ])->assertSessionHas('warning');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_a_pending_request_can_be_withdrawn_but_somebody_elses_cannot(): void
    {
        $mine = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-03');
        $theirs = $this->request($this->teacher('Grace Ilagan'), 'VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->maria)->delete(route('leave.destroy', $mine))->assertSessionHas('success');
        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $mine->fresh()->status);

        $this->actingAs($this->maria)->delete(route('leave.destroy', $theirs))->assertForbidden();
        $this->assertSame(LeaveRequest::STATUS_PENDING, $theirs->fresh()->status);
    }

    // ---------------- deciding ----------------

    public function test_approving_takes_the_hours_off_the_balance(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-04');

        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave))
            ->assertSessionHas('success');

        $leave->refresh();

        $this->assertSame(LeaveRequest::STATUS_APPROVED, $leave->status);
        $this->assertSame(16.0, $leave->paid_hours);
        $this->assertSame(0.0, $leave->unpaid_hours);
        $this->assertSame(24.0, $this->balance($this->maria, 'VACATION'));
    }

    public function test_a_request_the_balance_cannot_cover_is_held_until_the_shortfall_is_accepted(): void
    {
        $this->grant($this->maria, 'VACATION', 8);
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-05');

        // Three days asked for, one day on the card.
        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave))
            ->assertSessionHas('warning');

        $this->assertTrue($leave->fresh()->isPending());
        $this->assertSame(8.0, $this->balance($this->maria, 'VACATION'));

        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave), ['allow_unpaid' => 1])
            ->assertSessionHas('success');

        $leave->refresh();

        $this->assertSame(8.0, $leave->paid_hours);
        $this->assertSame(16.0, $leave->unpaid_hours);
        $this->assertSame(0.0, $this->balance($this->maria, 'VACATION'));
    }

    public function test_nobody_approves_their_own_leave(): void
    {
        $this->grant($this->admin, 'VACATION', 40);
        $leave = $this->request($this->admin, 'VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)
            ->post(route('leave.approve', $leave))
            ->assertSessionHas('warning');

        $this->assertTrue($leave->fresh()->isPending());
    }

    public function test_denying_keeps_the_request_and_the_reason(): void
    {
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)
            ->post(route('leave.deny', $leave), ['decision_note' => 'Two others are already off that week'])
            ->assertSessionHas('success');

        $leave->refresh();

        $this->assertSame(LeaveRequest::STATUS_DENIED, $leave->status);
        $this->assertSame('Two others are already off that week', $leave->decision_note);
        $this->assertSame($this->admin->id, $leave->reviewed_by);

        // The record survives for the teacher to read, which is the point of
        // not deleting it.
        $this->actingAs($this->maria)->get(route('leave.index'))
            ->assertOk()
            ->assertSee('Two others are already off that week');
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));
        $this->actingAs($this->admin)->post(route('leave.deny', $leave))->assertSessionHas('warning');

        $this->assertTrue($leave->fresh()->isApproved());
        $this->assertSame(1, LeaveLedgerEntry::where('source', LeaveLedgerEntry::SOURCE_TAKEN)->count());
    }

    public function test_revoking_an_approval_puts_the_hours_back(): void
    {
        $this->grant($this->maria, 'VACATION', 40);
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-04');

        $this->actingAs($this->admin)->post(route('leave.approve', $leave));
        $this->assertSame(24.0, $this->balance($this->maria, 'VACATION'));

        $this->actingAs($this->admin)->post(route('leave.revoke', $leave))->assertSessionHas('success');

        $this->assertSame(LeaveRequest::STATUS_CANCELLED, $leave->fresh()->status);
        $this->assertSame(40.0, $this->balance($this->maria, 'VACATION'));
    }

    // ---------------- who may do what ----------------

    public function test_a_teacher_cannot_reach_the_queue_or_decide_anything(): void
    {
        $leave = $this->request($this->maria, 'VACATION', '2026-08-03', '2026-08-03');

        $this->actingAs($this->maria)->get(route('leave.requests'))->assertForbidden();
        $this->actingAs($this->maria)->get(route('leave.balances'))->assertForbidden();
        $this->actingAs($this->maria)->post(route('leave.approve', $leave))->assertForbidden();

        $this->assertTrue($leave->fresh()->isPending());
    }

    public function test_signed_out_visitors_are_sent_to_the_login(): void
    {
        $this->get(route('leave.index'))->assertRedirect(route('login'));
    }

    public function test_the_queue_shows_what_is_asked_for_against_what_is_held(): void
    {
        $this->grant($this->maria, 'SICK', 6);
        $this->request($this->maria, 'SICK', '2026-08-03', '2026-08-04');

        $this->actingAs($this->admin)->get(route('leave.requests'))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('16h')            // asked for
            ->assertSee('6.00')           // on the card
            ->assertSee('10h short');
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
        ]);
    }

    private function request(User $user, string $type, string $from, string $to, float $hoursPerDay = 8): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'starts_on' => $from,
            'ends_on' => $to,
            'hours_per_day' => $hoursPerDay,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }

    private function grant(User $user, string $type, float $hours): LeaveLedgerEntry
    {
        return LeaveLedgerEntry::create([
            'user_id' => $user->id,
            'leave_type' => $type,
            'hours' => $hours,
            'effective_on' => self::TODAY,
            'source' => LeaveLedgerEntry::SOURCE_ADJUSTMENT,
            'note' => 'Opening balance',
        ]);
    }

    private function balance(User $user, string $type): float
    {
        return round((float) LeaveLedgerEntry::where('user_id', $user->id)
            ->where('leave_type', $type)
            ->sum('hours'), 2);
    }
}
