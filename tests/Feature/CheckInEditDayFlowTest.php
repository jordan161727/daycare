<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\DoorAttendance;
use App\Models\DoorHealthAudit;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The whole of what Check In's Edit mode does to a day already gone, in the
 * order the dialog does it, against the door's own endpoints.
 *
 * This one walks the sequence a director actually performs — record the
 * arrival with its check, put the times right, change the leaving check,
 * and finally take the day off — on the door's record. The point since the
 * split: none of it reaches the register. The door and Director Attendance
 * are two records that do not meet, so the register's rows and its amendment
 * log are checked to be empty after every step.
 */
class CheckInEditDayFlowTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-09-22';

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->child = Child::create([
            'lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active',
            'classroom' => 'PreK', 'dob' => '2022-12-15', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }

    public function test_a_past_day_is_recorded_corrected_and_removed_on_the_doors_own_record(): void
    {
        $base = ['child_id' => $this->child->id, 'attendance_date' => self::DAY, 'session' => 'FULL'];

        // 1. No arrival: the dialog records one, with its check, in one request.
        $made = $this->actingAs($this->admin)
            ->postJson(route('check-in.day'), $base + ['signed_in_time' => '08:05', 'health_code' => 4, 'health_note' => null])
            ->assertOk()
            ->json();

        $this->assertTrue($made['created']);
        $attendance = DoorAttendance::find($made['attendance_id']);
        $this->assertSame('08:05', $attendance->signed_in_at->format('H:i'));
        $this->assertSame(4, $attendance->health_in_code);
        $this->assertRegisterUntouched();

        // 2. The times, as typed: the arrival moved and a departure added.
        $moved = $this->actingAs($this->admin)
            ->postJson(route('check-in.day.retime'), $base + ['signed_in_time' => '07:55', 'signed_out_time' => '16:30'])
            ->assertOk()
            ->json();

        $attendance->refresh();
        $this->assertSame('07:55', $attendance->signed_in_at->format('H:i'));
        $this->assertSame('16:30', $attendance->signed_out_at->format('H:i'));
        $this->assertSame('7:55a', $moved['time']);
        $this->assertSame('4:30p', $moved['out_time']);
        $this->assertRegisterUntouched();

        // 3. The leaving check, through the health endpoint — codes only.
        $this->actingAs($this->admin)
            ->postJson('/check-in/'.$attendance->id.'/health', ['direction' => 'out', 'code' => 0, 'note' => null])
            ->assertOk();

        $attendance->refresh();
        $this->assertSame(0, $attendance->health_out_code);
        $this->assertSame('16:30', $attendance->signed_out_at->format('H:i'), 'a check never moves a time');
        $this->assertSame(2, DoorHealthAudit::where('attendance_id', $attendance->id)->count(), 'the arrival check and the leaving check, on the door\'s own audit');
        $this->assertSame(0, HealthAudit::count(), 'and none on the register\'s');

        // 4. Not attending after all: the arrival comes off, with its audit.
        $this->actingAs($this->admin)
            ->postJson(route('check-in.day.remove'), $base)
            ->assertOk()
            ->assertJson(['removed' => 1]);

        $this->assertSame(0, DoorAttendance::count());
        $this->assertSame(0, DoorHealthAudit::count(), 'the audit went with the row');
        $this->assertRegisterUntouched();
    }

    public function test_a_teacher_may_edit_today_but_not_a_day_already_gone(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK']);
        $base = ['child_id' => $this->child->id, 'session' => 'FULL'];

        $this->actingAs($teacher)->postJson(route('check-in.day'), $base + ['attendance_date' => self::DAY, 'signed_in_time' => '08:05'])->assertForbidden();
        $this->actingAs($teacher)->postJson(route('check-in.day'), $base + ['attendance_date' => now()->toDateString(), 'signed_in_time' => '08:05'])->assertOk();
    }

    /** The register — Director Attendance — has nothing from any of this. */
    private function assertRegisterUntouched(): void
    {
        $this->assertSame(0, Attendance::count(), 'the door writes none of the register\'s rows');
        $this->assertSame(0, AttendanceAmendment::count(), 'nor its amendment log');
    }

    public function test_the_same_day_reads_back_on_check_in_and_not_on_the_register(): void
    {
        $base = ['child_id' => $this->child->id, 'attendance_date' => self::DAY, 'session' => 'FULL'];

        $this->actingAs($this->admin)->postJson(route('check-in.day'), $base + ['signed_in_time' => '08:05', 'health_code' => 0])->assertOk();
        $this->actingAs($this->admin)->postJson(route('check-in.day.retime'), $base + ['signed_out_time' => '16:30'])->assertOk();

        // Check In, on that day, carries the row the way its dialog reads it.
        $checkIn = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => self::DAY]))->assertOk()->getContent();
        $q = chr(92).'u0022';
        $this->assertStringContainsString($q.'in'.$q.':'.$q.'8:05a'.$q, $checkIn);
        $this->assertStringContainsString($q.'out'.$q.':'.$q.'4:30p'.$q, $checkIn);

        // And the register, that week, knows nothing of it: two records that do not meet.
        $register = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::DAY]))->assertOk()->getContent());
        $this->assertStringNotContainsString('"'.$this->child->id.'":{"'.self::DAY.'"', $register, 'no cell on the register for that day');
        $this->assertSame(0, Attendance::count());
    }
}
