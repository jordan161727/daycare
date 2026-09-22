<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the Role column is for.
 *
 * It read the room for a while — the same column that says "Room they lead" on
 * the form, printed under a heading that says Role — so the staff list claimed
 * a teacher's job was "Sunflower Room". The job is its own field now, and these
 * hold the two apart.
 */
class StaffJobRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_staff_list_shows_the_job_and_not_the_room(): void
    {
        User::factory()->create([
            'role' => 'teacher',
            'name' => 'Ada Lovelace',
            'job_role' => 'Lead Teacher',
            'title' => 'Sunflower Room',
            'classroom' => 'Sunflower Room',
        ]);

        $page = $this->actingAs($this->admin)->get(route('teachers.index'));

        $page->assertOk();
        $page->assertSee('Lead Teacher');
    }

    public function test_the_role_is_saved_from_the_teacher_form(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'job_role' => null]);

        $this->actingAs($this->admin)
            ->put(route('teachers.update', $teacher), [
                'name' => $teacher->name,
                'email' => $teacher->email,
                'role' => 'teacher',
                'job_role' => 'Floater',
            ])
            ->assertRedirect();

        $this->assertSame('Floater', $teacher->fresh()->job_role);
    }

    public function test_a_role_nobody_offers_is_refused(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'job_role' => 'Assistant']);

        $this->actingAs($this->admin)
            ->from(route('teachers.edit', $teacher))
            ->put(route('teachers.update', $teacher), [
                'name' => $teacher->name,
                'email' => $teacher->email,
                'role' => 'teacher',
                'job_role' => 'Supreme Commander',
            ])
            ->assertSessionHasErrors('job_role');

        $this->assertSame('Assistant', $teacher->fresh()->job_role);
    }

    /**
     * Sixteen people already on file have no job on them. Without a fallback
     * the column they are read in is a column of blanks until somebody edits
     * every one of them.
     */
    public function test_somebody_with_no_job_set_falls_back_to_their_account_role(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'job_role' => null]);

        $this->assertSame('Teacher', $teacher->jobRole());
    }

    public function test_the_role_filter_narrows_by_job_and_not_by_room(): void
    {
        User::factory()->create([
            'role' => 'teacher', 'name' => 'Grace Hopper',
            'job_role' => 'Assistant', 'title' => 'Bluebell Room',
        ]);
        User::factory()->create([
            'role' => 'teacher', 'name' => 'Katherine Johnson',
            'job_role' => 'Floater', 'title' => 'Bluebell Room',
        ]);

        $page = $this->actingAs($this->admin)->get(route('teachers.index', ['role' => 'Assistant']));

        $page->assertOk();
        $page->assertSee('Grace Hopper');
        $page->assertDontSee('Katherine Johnson');
    }

    public function test_the_form_offers_the_role_separately_from_the_room(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $page = $this->actingAs($this->admin)->get(route('teachers.edit', $teacher));

        $page->assertOk();
        $page->assertSee('name="job_role"', false);
        $page->assertSee('Room they lead');
    }
    /**
     * The dropdown was empty.
     *
     * It was built from the column, and nobody has a job set yet, so the only
     * option was "All roles" while the Role beside it read "Teacher" for
     * everybody. A filter that cannot offer what the list is showing is not a
     * filter.
     */
    public function test_the_role_dropdown_offers_what_the_list_actually_shows(): void
    {
        User::factory()->create(['role' => 'teacher', 'job_role' => null]);
        User::factory()->create(['role' => 'teacher', 'job_role' => 'Floater']);

        $page = $this->actingAs($this->admin)->get(route('teachers.index'));

        $page->assertOk();
        $page->assertSee('<option value="Teacher"', false);
        $page->assertSee('<option value="Floater"', false);
    }

    /** And choosing that option has to return the people it was offered for. */
    public function test_filtering_on_the_fallback_returns_the_people_it_describes(): void
    {
        User::factory()->create(['role' => 'teacher', 'name' => 'Annie Easley', 'job_role' => null]);
        User::factory()->create(['role' => 'teacher', 'name' => 'Dorothy Vaughan', 'job_role' => 'Floater']);

        $page = $this->actingAs($this->admin)->get(route('teachers.index', ['role' => 'Teacher']));

        $page->assertOk();
        $page->assertSee('Annie Easley');
        $page->assertDontSee('Dorothy Vaughan');
    }

    /**
     * The week grid has no role chips any more — they offered the account type
     * the Role column already repeats on every row. The filter behind them
     * still works, and the export reads it, so it is worth holding to.
     */
    public function test_the_week_grid_can_still_be_narrowed_to_one_role(): void
    {
        User::factory()->create(['role' => 'teacher', 'name' => 'Annie Easley', 'job_role' => null]);
        User::factory()->create(['role' => 'teacher', 'name' => 'Dorothy Vaughan', 'job_role' => 'Floater']);

        $narrowed = $this->actingAs($this->admin)->get(route('staff.timesheets', ['role' => 'Floater']));

        $narrowed->assertOk();
        $narrowed->assertSee('Dorothy Vaughan');
        $narrowed->assertDontSee('Annie Easley');
    }
}