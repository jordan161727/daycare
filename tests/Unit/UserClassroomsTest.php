<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserClassroomsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_classrooms_returns_combined_classroom_values(): void
    {
        $user = User::factory()->create([
            'classroom' => 'Sunflowers',
            'classrooms' => ['Sunflowers', 'Roses'],
        ]);

        $this->assertSame(['Sunflowers', 'Roses'], $user->assignedClassrooms());
    }

    public function test_can_access_classroom_when_assigned(): void
    {
        $user = User::factory()->create([
            'classrooms' => ['Sunflowers', 'Roses'],
        ]);

        $this->assertTrue($user->canAccessClassroom('Roses'));
        $this->assertFalse($user->canAccessClassroom('Toddlers'));
    }

    public function test_admin_can_access_any_classroom(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($user->canAccessClassroom('Sunflowers'));
        $this->assertTrue($user->canAccessClassroom('Toddlers'));
    }
}
