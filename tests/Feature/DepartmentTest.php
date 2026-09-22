<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The parts of the centre, and what depends on them.
 *
 * The one that matters is removal: closing a department must not take the
 * people in it with it. Everything attached to a staff record — their punches,
 * their hours, their pay history — hangs off that row, and an org change that
 * quietly deletes a year of timesheets is the worst kind of data loss, because
 * nobody notices until payroll.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
    }

    public function test_a_department_can_be_added(): void
    {
        $this->actingAs($this->admin)
            ->post(route('departments.store'), ['name' => 'Kitchen', 'notes' => 'Meals and dishes'])
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseHas('departments', ['name' => 'Kitchen', 'notes' => 'Meals and dishes']);
    }

    public function test_two_departments_cannot_share_a_name(): void
    {
        // The whole point is grouping: two Kitchens split every report down the
        // middle and nothing on screen would say why.
        Department::create(['name' => 'Kitchen']);

        $this->actingAs($this->admin)
            ->post(route('departments.store'), ['name' => 'Kitchen'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::count());
    }

    public function test_a_department_can_be_renamed(): void
    {
        $department = Department::create(['name' => 'Kitchen']);

        $this->actingAs($this->admin)
            ->put(route('departments.update', $department), ['name' => 'Catering'])
            ->assertRedirect(route('departments.index'));

        $this->assertSame('Catering', $department->fresh()->name);
    }

    public function test_renaming_a_department_to_its_own_name_is_not_a_clash(): void
    {
        $department = Department::create(['name' => 'Kitchen']);

        $this->actingAs($this->admin)
            ->put(route('departments.update', $department), ['name' => 'Kitchen', 'notes' => 'Meals'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Meals', $department->fresh()->notes);
    }

    public function test_removing_a_department_keeps_the_people_who_were_in_it(): void
    {
        // Closing the kitchen is an org change, not a reason to lose the cook's
        // record and every hour attached to it.
        $department = Department::create(['name' => 'Kitchen']);

        $cook = User::factory()->create([
            'role' => 'teacher', 'name' => 'Rachel Kim', 'department_id' => $department->id,
        ]);

        app(TimeClock::class)->punch($cook, TimePunch::IN, Carbon::parse('2026-09-21 08:00'));

        $this->actingAs($this->admin)
            ->delete(route('departments.destroy', $department))
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('users', ['id' => $cook->id, 'department_id' => null]);
        $this->assertDatabaseHas('time_punches', ['user_id' => $cook->id]);
    }

    public function test_the_list_says_how_many_are_in_each_one(): void
    {
        // What makes removing one a decision rather than a click.
        $department = Department::create(['name' => 'Kitchen']);

        User::factory()->count(2)->create(['role' => 'teacher', 'department_id' => $department->id]);

        $this->actingAs($this->admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('Kitchen')
            ->assertSee('>2<', false);
    }

    public function test_a_teacher_cannot_reach_the_departments(): void
    {
        // A department is a line on the org chart and the grouping every hours
        // report totals by, so who may create one is the same question as who
        // may set a pay rate.
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get(route('departments.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('departments.store'), ['name' => 'Kitchen'])->assertForbidden();
    }

    public function test_the_staff_form_offers_a_department_only_once_one_exists(): void
    {
        // An empty dropdown is a question with no answer, and a centre that
        // does not run departments should never be shown it.
        $this->actingAs($this->admin)
            ->get(route('teachers.create'))
            ->assertOk()
            ->assertDontSee('name="department_id"', false);

        Department::create(['name' => 'Kitchen']);

        $this->actingAs($this->admin)
            ->get(route('teachers.create'))
            ->assertOk()
            ->assertSee('name="department_id"', false);
    }

    public function test_somebody_can_be_put_in_a_department_from_their_record(): void
    {
        $department = Department::create(['name' => 'Kitchen']);
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim']);

        $this->actingAs($this->admin)->put(route('teachers.update', $teacher), [
            'name' => 'Rachel Kim',
            'email' => $teacher->email,
            'department_id' => $department->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($department->id, $teacher->fresh()->department_id);
    }

    public function test_a_department_that_does_not_exist_is_refused_on_a_staff_record(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim']);

        $this->actingAs($this->admin)->put(route('teachers.update', $teacher), [
            'name' => 'Rachel Kim',
            'email' => $teacher->email,
            'department_id' => 9999,
        ])->assertSessionHasErrors('department_id');
    }
}
