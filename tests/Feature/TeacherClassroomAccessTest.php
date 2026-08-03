<?php

namespace Tests\Feature;

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
