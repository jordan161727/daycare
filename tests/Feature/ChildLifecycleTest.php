<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A child's place on the roll from agreed to gone — Phase 2.2 of
 * docs/work.md: start, cancel, withdraw, re-enroll, archive, restore, and
 * the line each one leaves in the enrollment history.
 */
class ChildLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-22 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_a_pending_child_is_started_from_a_first_day(): void
    {
        $child = $this->child(['status' => 'Pending']);

        $this->actingAs($this->admin)->post(route('children.start', $child), ['first_day' => '2026-10-19'])
            ->assertRedirect(route('children.show', $child));

        $child->refresh();
        $this->assertSame('Active', $child->status);
        $this->assertSame('2026-10-19', $child->enrolled_on->toDateString());
        $this->assertSame('started', $child->statusChanges()->first()->event);
        $this->assertStringContainsString('First day Oct 19, 2026', $child->statusChanges()->first()->note);
    }

    /** A first day still to come starts on its own that morning. */
    public function test_a_first_day_ahead_waits_for_the_nightly_command(): void
    {
        $child = $this->child(['status' => 'Pending']);

        $this->actingAs($this->admin)->post(route('children.start', $child), ['first_day' => '2026-11-02'])->assertRedirect();

        $this->assertSame('Pending', $child->fresh()->status, 'not yet');
        $this->assertSame('2026-11-02', $child->fresh()->enrolled_on->toDateString());

        $this->artisan('children:sync-enrollments')->assertSuccessful();
        $this->assertSame('Pending', $child->fresh()->status, 'still not Monday');

        $this->travelTo(Carbon::parse('2026-11-02 00:10:00'));
        $this->artisan('children:sync-enrollments')->expectsOutputToContain('1 started')->assertSuccessful();
        $this->assertSame('Active', $child->fresh()->status);
    }

    public function test_a_pending_enrollment_can_be_cancelled(): void
    {
        $child = $this->child(['status' => 'Pending']);

        $this->actingAs($this->admin)->post(route('children.cancel', $child), ['reason' => 'Family changed plans', 'note' => 'Mom took a job closer to home'])
            ->assertRedirect();

        $child->refresh();
        $this->assertSame('Inactive', $child->status, 'Withdrawn on screen');
        $this->assertSame('Family changed plans', $child->withdrawal_reason);
        $this->assertSame('cancelled', $child->statusChanges()->first()->event);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->assertSee('data-banner="withdrawn"', false)->assertSee('Family changed plans');
    }

    public function test_withdrawing_from_a_past_last_day_takes_them_off_the_roll_now(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.withdraw', $child), [
            'last_day' => '2026-10-16', 'reason' => 'Moved out of the area', 'could_return' => 'Yes', 'note' => 'Moved to Cheektowaga',
        ])->assertRedirect();

        $child->refresh();
        $this->assertSame('Inactive', $child->status);
        $this->assertSame('2026-10-16', $child->withdrawn_on->toDateString());
        $this->assertSame('Yes', $child->could_return);

        $line = $child->statusChanges()->where('event', 'withdrawn')->first();
        $this->assertSame('Withdrawn · Moved out of the area', $line->summary());
        $this->assertStringContainsString('Moved to Cheektowaga', $line->note);

        // Off the roll: not Active, and attendance after the last day refused by the register's rule.
        $this->assertFalse($child->isEnrolledOn('2026-10-19'));
        $this->assertTrue($child->isEnrolledOn('2026-10-16'), 'the last day still counts');
    }

    /** A last day still to come keeps them on check-in until it has passed. */
    public function test_withdrawing_from_a_future_last_day_waits_for_the_last_day(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.withdraw', $child), ['last_day' => '2026-10-23', 'reason' => 'Changed provider'])->assertRedirect();

        $this->assertSame('Active', $child->fresh()->status, 'still here until Friday is over');
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->assertSee('data-banner="leaving"', false);

        $this->travelTo(Carbon::parse('2026-10-24 00:10:00'));
        $this->artisan('children:sync-enrollments')->expectsOutputToContain('1 withdrawn')->assertSuccessful();
        $this->assertSame('Inactive', $child->fresh()->status);
    }

    public function test_a_withdrawn_child_is_re_enrolled_with_the_same_lan(): void
    {
        $child = $this->child(['status' => 'Inactive', 'withdrawn_on' => '2026-08-28', 'withdrawal_reason' => 'Family choice']);

        $this->actingAs($this->admin)->post(route('children.reenroll', $child))->assertRedirect();

        $child->refresh();
        $this->assertSame('Active', $child->status);
        $this->assertSame('10001', $child->lan);
        $this->assertNull($child->withdrawn_on);
        $this->assertNull($child->withdrawal_reason);
        $this->assertSame('reenrolled', $child->statusChanges()->first()->event);
    }

    public function test_archive_and_restore_go_through_withdrawn(): void
    {
        $child = $this->child(['status' => 'Inactive', 'withdrawn_on' => '2026-08-28']);

        $this->actingAs($this->admin)->post(route('children.archive', $child))->assertRedirect();
        $this->assertSame('Archived', $child->fresh()->status);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->assertSee('data-banner="archived"', false);

        $this->actingAs($this->admin)->post(route('children.unarchive', $child))->assertRedirect();
        $this->assertSame('Inactive', $child->fresh()->status, 'Archived → Withdrawn');
    }

    public function test_the_wrong_step_for_the_status_is_refused(): void
    {
        $active = $this->child();

        $this->actingAs($this->admin)->post(route('children.start', $active), ['first_day' => '2026-10-19'])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('children.cancel', $active), ['reason' => 'Other'])->assertStatus(422);
        $this->actingAs($this->admin)->post(route('children.reenroll', $active))->assertStatus(422);
        $this->actingAs($this->admin)->post(route('children.withdraw', $active), ['last_day' => '2026-10-16', 'reason' => 'Not a reason'])->assertSessionHasErrors('reason');
    }

    public function test_the_lifecycle_is_the_directors(): void
    {
        $child = $this->child();
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);

        $this->actingAs($teacher)->post(route('children.withdraw', $child), ['last_day' => '2026-10-16', 'reason' => 'Other'])->assertForbidden();
        $this->actingAs($teacher)->post(route('children.reenroll', $child))->assertForbidden();
        $this->assertSame('Active', $child->fresh()->status);

        // The teacher reads the status but is offered no way to change it.
        $this->actingAs($teacher)->get(route('children.show', $child))->assertOk()->assertDontSee('Withdraw…');
    }

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '10001',
            'status' => 'Active',
            'first_name' => 'Ella',
            'last_name' => 'Foster',
            'classroom' => 'Toddler',
            'classroom_override' => 'Toddler',
            'birth_date' => '2024-04-05',
        ]);
    }
}
