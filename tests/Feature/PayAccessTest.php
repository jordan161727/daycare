<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Pay access is a permission (users.can_view_pay, gate 'view-pay'), not one
 * named person (QA, 16 Nov 2026).
 */
class PayAccessTest extends TestCase
{
    use RefreshDatabase;

    private function details(User $staff, array $extra = []): array
    {
        return array_merge([
            'name' => $staff->name, 'email' => $staff->email,
        ], $extra);
    }

    public function test_the_migration_backfills_exactly_who_the_old_rule_allowed(): void
    {
        $director = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $office = User::factory()->create(['role' => 'admin', 'job_role' => 'Office']);
        $untitled = User::factory()->create(['role' => 'admin', 'job_role' => null]);
        $teacher = User::factory()->create(['role' => 'teacher', 'job_role' => 'Director']);
        DB::table('users')->update(['can_view_pay' => false]);

        (require database_path('migrations/2026_11_16_000030_add_can_view_pay_to_users_table.php'))->up();

        $this->assertTrue($director->fresh()->can_view_pay);
        $this->assertFalse($office->fresh()->can_view_pay);
        $this->assertFalse($untitled->fresh()->can_view_pay);
        $this->assertFalse($teacher->fresh()->can_view_pay);
    }

    public function test_the_gate_follows_the_flag_not_the_job_title(): void
    {
        $director = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $office = User::factory()->create(['role' => 'admin', 'job_role' => 'Office', 'can_view_pay' => true]);
        $teacher = User::factory()->create(['role' => 'teacher', 'can_view_pay' => true]);

        $this->assertFalse(Gate::forUser($director->fresh())->allows('view-pay'));
        $this->assertTrue(Gate::forUser($office->fresh())->allows('view-pay'));
        // A teacher never sees pay, flag or no flag.
        $this->assertFalse(Gate::forUser($teacher->fresh())->allows('view-pay'));
    }

    public function test_the_staff_page_says_who_has_pay_access_instead_of_naming_one_person(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'job_role' => 'Director', 'name' => 'Ray Kader', 'can_view_pay' => true]);
        $office = User::factory()->create(['role' => 'admin', 'job_role' => 'Office', 'name' => 'Olive Office']);
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Lana Teach', 'pay_rate' => 21.5]);

        $html = $this->actingAs($office)->get(route('staff.show', $teacher))->assertOk()->getContent();
        $this->assertStringContainsString('Only people with pay access can view pay.', $html);
        $this->assertStringContainsString('Pay access: Ray Kader', $html);
        $this->assertStringNotContainsString('21.50', $html);
        // The office cannot grant it, so there is no switch.
        $this->assertStringNotContainsString('data-pay-access-toggle', $this->actingAs($office)->get(route('staff.show', $owner))->getContent());

        $html = $this->actingAs($owner)->get(route('staff.show', $teacher))->assertOk()->getContent();
        $this->assertStringContainsString('name="pay_rate"', $html);
    }

    public function test_the_owner_grants_and_removes_pay_access_on_the_staff_profile(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'job_role' => 'Director', 'can_view_pay' => true]);
        $office = User::factory()->create(['role' => 'admin', 'job_role' => 'Office']);

        $this->assertStringContainsString('data-pay-access-toggle', $this->actingAs($owner)->get(route('staff.show', $office))->getContent());

        $this->actingAs($owner)->put(route('staff.update', $office), $this->details($office, ['can_view_pay' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($office->fresh()->can_view_pay);
        $this->assertTrue(Gate::forUser($office->fresh())->allows('view-pay'));

        $this->actingAs($owner)->put(route('staff.update', $office), $this->details($office, ['can_view_pay' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($office->fresh()->can_view_pay);
    }

    public function test_someone_who_is_not_the_owner_cannot_grant_it(): void
    {
        $office = User::factory()->create(['role' => 'admin', 'job_role' => 'Office', 'can_view_pay' => true]);
        $other = User::factory()->create(['role' => 'admin', 'job_role' => 'Office']);

        $this->actingAs($office)->put(route('staff.update', $other), $this->details($other, ['can_view_pay' => '1']));
        $this->assertFalse($other->fresh()->can_view_pay);
    }

    public function test_a_teacher_cannot_be_given_pay_access(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'job_role' => 'Director', 'can_view_pay' => true]);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($owner)->put(route('staff.update', $teacher), $this->details($teacher, ['can_view_pay' => '1']));
        $this->assertFalse((bool) $teacher->fresh()->can_view_pay);
    }
}
