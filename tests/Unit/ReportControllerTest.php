<?php

namespace Tests\Unit;

use App\Http\Controllers\ReportController;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_report_shows_only_assigned_classroom_children(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Sunflowers']);

        $assignedChild = Child::create([
            'lan' => 'LAN-1',
            'status' => 'Active',
            'first_name' => 'Assigned',
            'last_name' => 'Child',
            'classroom' => 'Sunflowers',
        ]);

        $otherChild = Child::create([
            'lan' => 'LAN-2',
            'status' => 'Active',
            'first_name' => 'Other',
            'last_name' => 'Child',
            'classroom' => 'Roses',
        ]);

        Attendance::create([
            'child_id' => $assignedChild->id,
            'attendance_date' => today()->toDateString(),
            'signed_in_at' => now(),
        ]);

        Attendance::create([
            'child_id' => $otherChild->id,
            'attendance_date' => today()->toDateString(),
            'signed_in_at' => now(),
        ]);

        $request = Request::create('/reports', 'GET', ['date' => today()->toDateString()]);
        $request->setUserResolver(fn () => $teacher);

        $response = (new ReportController())->index($request);
        $viewData = $response->getData();

        $this->assertCount(1, $viewData['children']);
        $this->assertSame('Sunflowers', $viewData['children']->first()->classroom);
        $this->assertArrayHasKey($assignedChild->id, $viewData['attendanceMap']->toArray());
        $this->assertArrayNotHasKey($otherChild->id, $viewData['attendanceMap']->toArray());
    }

    public function test_admin_report_returns_all_active_children(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $childOne = Child::create([
            'lan' => 'LAN-3',
            'status' => 'Active',
            'first_name' => 'AdminOne',
            'last_name' => 'Child',
            'classroom' => 'Sunflowers',
        ]);

        $childTwo = Child::create([
            'lan' => 'LAN-4',
            'status' => 'Active',
            'first_name' => 'AdminTwo',
            'last_name' => 'Child',
            'classroom' => 'Roses',
        ]);

        $request = Request::create('/reports', 'GET', ['date' => today()->toDateString()]);
        $request->setUserResolver(fn () => $admin);

        $response = (new ReportController())->index($request);
        $viewData = $response->getData();

        $this->assertCount(2, $viewData['children']);
        $classrooms = $viewData['children']->pluck('classroom')->unique()->values()->all();
        sort($classrooms);
        $this->assertSame(['Roses', 'Sunflowers'], $classrooms);
    }
}
