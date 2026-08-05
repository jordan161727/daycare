<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherClassroomAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_only_sees_and_can_sign_in_children_in_assigned_classroom(): void
    {
        $teacher = User::factory()->create(['classroom' => 'Sunflowers']);
        $assignedChild = Child::create($this->childData('LAN-1', 'Sunflowers'));
        $otherChild = Child::create($this->childData('LAN-2', 'Roses'));

        $this->actingAs($teacher)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee($assignedChild->first_name)
            ->assertDontSee($otherChild->first_name);

        $this->actingAs($teacher)
            ->postJson(route('attendance.signin'), ['child_id' => $otherChild->id, 'attendance_date' => today()->toDateString()])
            ->assertNotFound();

        $this->actingAs($teacher)
            ->postJson(route('attendance.signin'), ['child_id' => $assignedChild->id, 'attendance_date' => today()->toDateString()])
            ->assertOk();
    }

    public function test_teacher_can_view_attendance_report_only_for_selected_assigned_classroom(): void
    {
        $teacher = User::factory()->create([
            'classrooms' => ['Sunflowers', 'Roses'],
        ]);

        $sunflowerChild = Child::create([
            'lan' => 'LAN-3',
            'status' => 'Active',
            'first_name' => 'Sunflower',
            'last_name' => 'Child',
            'classroom' => 'Sunflowers',
        ]);
        $roseChild = Child::create([
            'lan' => 'LAN-4',
            'status' => 'Active',
            'first_name' => 'Rose',
            'last_name' => 'Child',
            'classroom' => 'Roses',
        ]);

        Attendance::create([
            'child_id' => $sunflowerChild->id,
            'attendance_date' => today()->toDateString(),
            'signed_in_at' => now(),
        ]);

        Attendance::create([
            'child_id' => $roseChild->id,
            'attendance_date' => today()->toDateString(),
            'signed_in_at' => now(),
        ]);

        $this->actingAs($teacher)
            ->get(route('reports.index', ['date' => today()->toDateString(), 'classroom' => 'Roses']))
            ->assertOk()
            ->assertSee('Rose Child')
            ->assertDontSee('Sunflower Child');
    }

    public function test_admin_can_view_all_children_in_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $childOne = Child::create([
            'lan' => 'LAN-5',
            'status' => 'Active',
            'first_name' => 'AdminOne',
            'last_name' => 'Child',
            'classroom' => 'Sunflowers',
        ]);
        $childTwo = Child::create([
            'lan' => 'LAN-6',
            'status' => 'Active',
            'first_name' => 'AdminTwo',
            'last_name' => 'Child',
            'classroom' => 'Roses',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.index', ['date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('AdminOne Child')
            ->assertSee('AdminTwo Child');
    }

    public function test_admin_can_assign_multiple_classrooms_to_a_teacher_from_selection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('teachers.store'), [
                'name' => 'Ms. Rivera',
                'email' => 'teacher@example.com',
                'classrooms' => ['School Age', 'PreK'],
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect(route('teachers.index'));

        $teacher = User::where('email', 'teacher@example.com')->firstOrFail();

        $this->assertSame(['School Age', 'PreK'], $teacher->assignedClassrooms());
    }

    private function childData(string $lan, string $classroom): array
    {
        return [
            'lan' => $lan,
            'status' => 'Active',
            'first_name' => $lan === 'LAN-1' ? 'Assigned' : 'Other',
            'last_name' => 'Child',
            'classroom' => $classroom,
        ];
    }
}
