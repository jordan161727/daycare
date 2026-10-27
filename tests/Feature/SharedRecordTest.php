<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Teacher Attendance and Director Attendance are one record.
 *
 * Split into two tables on 16 Oct 2026, rejoined on 20 Oct: a clock-in at
 * the door is the arrival on the register, a sign-in on the register is on
 * the door, and the comments sit on that one row. These tests are the fence
 * the other way round from the one that stood for four days.
 */
class SharedRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-20 08:00:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->child = Child::create(['lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-06-01']);
        app(\App\Services\WeekSchedule::class)->open('2026-10-19');
    }

    public function test_a_clock_in_at_the_door_is_the_arrival_on_the_register(): void
    {
        $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $this->child->id, 'session' => 'FULL', 'health_code' => 3])->assertOk();

        // One row, on the one table, with its audit beside it.
        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, HealthAudit::count());
        $this->assertSame(Attendance::sole()->id, DoorAttendance::sole()->id, 'the door model reads the same row');

        // Both screens show it.
        $door = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent());
        $this->assertStringContainsString('"in":"8:00a","in_code":3', $door);
        $register = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('attendance.classic', ['date' => '2026-10-20']))->assertOk()->getContent());
        $this->assertStringContainsString('"'.$this->child->id.'":{"2026-10-20":{"FULL":"8:00a"', $register);
    }

    public function test_a_sign_in_on_the_register_is_on_the_door(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), ['child_id' => $this->child->id, 'attendance_date' => '2026-10-20', 'health_code' => 2])->assertOk();

        $this->assertSame(1, Attendance::count());
        $door = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent());
        $this->assertStringContainsString('"in":"8:00a","in_code":2', $door);
    }

    public function test_a_clock_out_at_the_door_closes_the_registers_row(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), ['child_id' => $this->child->id, 'attendance_date' => '2026-10-20'])->assertOk();
        $row = Attendance::sole();

        $this->travelTo(now()->addHours(8));
        $this->actingAs($this->admin)->postJson(route('check-in.out', $row->id), ['health_code' => 0])->assertOk();

        $this->assertSame('16:00', $row->fresh()->signed_out_at->timezone(config('app.timezone'))->format('H:i'));
        $this->assertSame(1, Attendance::count());
    }

    public function test_comments_sit_on_the_one_row(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), ['child_id' => $this->child->id, 'attendance_date' => '2026-10-20'])->assertOk();
        $row = Attendance::sole();

        $this->actingAs($this->admin)->postJson(route('attendances.comments.store', $row->id), ['tag' => 'health', 'body' => 'Small rash on left cheek.'])->assertCreated();

        $this->assertSame($row->id, AttendanceComment::sole()->attendance_id);
        $this->assertSame('Small rash on left cheek.', $row->fresh()->comments()->sole()->body);
    }

    public function test_the_month_export_is_one_run_of_room_sheets(): void
    {
        $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $this->child->id, 'session' => 'FULL', 'health_code' => 0])->assertOk();

        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 10, 'year' => 2026]))->assertOk();
        $path = storage_path('app/test-shared.xlsx');
        file_put_contents($path, $response->streamedContent());
        $titles = array_map(fn ($sheet) => $sheet->getTitle(), \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getAllSheets());
        @unlink($path);

        $this->assertSame(['Infant', 'Comments'], $titles);
        $this->actingAs($this->admin)->get(route('attendance.month-sheet', ['month' => 10, 'year' => 2026]))->assertOk()->assertDontSee('data-source-toggle', false);
    }
}
