<?php

namespace Tests\Feature;

use App\Models\StaffRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One role label for one person (QA, 16 Nov 2026): "Director" on /staff was
 * "Lead" on /staff-timesheets, and the employment type list mixed a position
 * (Lead) with contract types (FT, PT).
 */
class StaffRoleLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_is_no_longer_an_employment_type(): void
    {
        $this->assertNotContains('LEAD', StaffRule::EMPLOYMENT);
        $this->assertContains('Lead Teacher', User::JOB_ROLES);
    }

    public function test_an_old_lead_row_reads_as_ft_and_lead_teacher_without_being_rewritten(): void
    {
        $maria = User::factory()->create(['role' => 'teacher', 'employment' => 'LEAD', 'job_role' => null]);

        $this->assertSame('FT', $maria->employmentLabel());
        $this->assertSame('Lead Teacher', $maria->jobRole());
        $this->assertSame('LEAD', $maria->fresh()->employment);
        // The job filter agrees with the label.
        $this->assertTrue(User::jobRoleIs('Lead Teacher')->whereKey($maria->id)->exists());
        $this->assertFalse(User::jobRoleIs('Teacher')->whereKey($maria->id)->exists());

        // A plain teacher with no job title is still a Teacher.
        $plain = User::factory()->create(['role' => 'teacher', 'employment' => null, 'job_role' => null]);
        $this->assertTrue(User::jobRoleIs('Teacher')->whereKey($plain->id)->exists());

        // A director whose type was LEAD stays Director.
        $director = User::factory()->create(['role' => 'admin', 'employment' => 'LEAD', 'job_role' => 'Director']);
        $this->assertSame('Director', $director->jobRole());
        $this->assertSame('FT', $director->employmentLabel());
    }

    public function test_the_staff_form_no_longer_offers_lead_and_preselects_what_an_old_row_reads_as(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $maria = User::factory()->create(['role' => 'teacher', 'employment' => 'LEAD', 'job_role' => null]);

        $html = $this->actingAs($admin)->get(route('staff.show', $maria))->assertOk()->getContent();

        $this->assertStringNotContainsString('<option value="LEAD"', $html);
        $this->assertMatchesRegularExpression('/<option value="FT" selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="Lead Teacher" selected/', $html);

        // Posting LEAD is refused now.
        $this->actingAs($admin)->put(route('staff.update', $maria), ['name' => $maria->name, 'email' => $maria->email, 'employment' => 'LEAD'])
            ->assertSessionHasErrors('employment');
    }

    /**
     * QA, 17 Nov 2026: the header said "Teacher · FT · Infant" while the Role
     * select said "Not set" — the header fell back to the account role, the
     * form reads job_role. Both read job_role now.
     */
    public function test_the_profile_header_and_the_role_select_read_the_same_field(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $infant = User::factory()->create(['role' => 'teacher', 'job_role' => null, 'employment' => 'FT', 'classroom' => 'Infant', 'name' => 'Infant Teacher']);

        $html = $this->actingAs($admin)->get(route('staff.show', $infant))->assertOk()->getContent();
        $this->assertStringContainsString('Role not set · FT · Infant', $html);
        $this->assertStringNotContainsString('>Teacher · FT', $html);
        // …and the Role select has nothing chosen, so it says "Not set" too.
        $this->assertMatchesRegularExpression('/name="job_role">\s*<option value="">Not set<\/option>/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="job_role">(?:(?!<\/select>).)*selected/s', $html);

        $infant->update(['job_role' => 'Assistant']);
        $html = $this->actingAs($admin)->get(route('staff.show', $infant))->assertOk()->getContent();
        $this->assertStringContainsString('Assistant · FT · Infant', $html);
        $this->assertMatchesRegularExpression('/<option value="Assistant" selected/', $html);
    }

    public function test_staff_attendance_leads_with_the_same_job_role_as_the_staff_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $lana = User::factory()->create(['role' => 'teacher', 'job_role' => 'Floater', 'employment' => 'LEAD', 'name' => 'Lana Ortiz']);

        $staffPage = $this->actingAs($admin)->get(route('staff.show', $lana))->assertOk()->getContent();
        $this->assertStringContainsString('Floater · FT', $staffPage);

        $attendance = $this->actingAs($admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringContainsString('Floater · FT', $attendance);
        $this->assertStringNotContainsString('>Lead<', $attendance);
    }
}
