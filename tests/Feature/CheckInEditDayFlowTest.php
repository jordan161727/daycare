<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The whole of what Check In's Edit mode does to a day already gone, in the
 * order the dialog does it, against the register's own endpoints.
 *
 * Each endpoint has its own tests. This one walks the sequence a director
 * actually performs — record the arrival with its check, put the times
 * right, change the leaving check, and finally take the day off — and reads
 * the register's amendment log after each step. The point is the trail: a
 * day edited from the door has to read on the register exactly as if it had
 * been edited there, because it was.
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

    public function test_a_past_day_is_recorded_corrected_and_removed_through_the_register(): void
    {
        $base = ['child_id' => $this->child->id, 'attendance_date' => self::DAY, 'session' => 'FULL'];

        // 1. No arrival: the dialog records one, with its check, in one request.
        $made = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), $base + ['signed_in_time' => '08:05', 'health_code' => 4, 'health_note' => null])
            ->assertOk()
            ->json();

        $this->assertTrue($made['created']);
        $attendance = Attendance::find($made['attendance_id']);
        $this->assertSame('08:05', $attendance->signed_in_at->format('H:i'));
        $this->assertSame(4, $attendance->health_in_code);
        $this->assertSame([AttendanceAmendment::ADDED], AttendanceAmendment::pluck('action')->all());

        // 2. The times, as typed: the arrival moved and a departure added.
        $moved = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), $base + ['signed_in_time' => '07:55', 'signed_out_time' => '16:30'])
            ->assertOk()
            ->json();

        $attendance->refresh();
        $this->assertSame('07:55', $attendance->signed_in_at->format('H:i'));
        $this->assertSame('16:30', $attendance->signed_out_at->format('H:i'));
        $this->assertSame('7:55a', $moved['time']);
        $this->assertSame('4:30p', $moved['out_time']);
        $this->assertSame([AttendanceAmendment::ADDED, AttendanceAmendment::RETIMED], AttendanceAmendment::pluck('action')->all());

        // 3. The leaving check, through the health endpoint — codes only.
        $this->actingAs($this->admin)
            ->postJson('/attendance/'.$attendance->id.'/health', ['direction' => 'out', 'code' => 0, 'note' => null])
            ->assertOk();

        $attendance->refresh();
        $this->assertSame(0, $attendance->health_out_code);
        $this->assertSame('16:30', $attendance->signed_out_at->format('H:i'), 'a check never moves a time');
        $this->assertSame(2, HealthAudit::where('attendance_id', $attendance->id)->count(), 'the arrival check and the leaving check');

        // 4. Not attending after all: the arrival comes off, and the log says so.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.remove'), $base)
            ->assertOk()
            ->assertJson(['removed' => 1]);

        $this->assertSame(0, Attendance::count());
        $this->assertSame(
            [AttendanceAmendment::ADDED, AttendanceAmendment::RETIMED, AttendanceAmendment::REMOVED],
            AttendanceAmendment::pluck('action')->all(),
            'the register remembers the whole afternoon, in order'
        );
    }

    public function test_the_same_day_reads_back_on_check_in_and_on_the_register(): void
    {
        $base = ['child_id' => $this->child->id, 'attendance_date' => self::DAY, 'session' => 'FULL'];

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), $base + ['signed_in_time' => '08:05', 'health_code' => 0])->assertOk();
        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), $base + ['signed_out_time' => '16:30'])->assertOk();

        // Check In, on that day, carries the row the way its dialog reads it.
        $checkIn = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => self::DAY]))->assertOk()->getContent();
        $q = chr(92).'u0022';
        $this->assertStringContainsString($q.'in'.$q.':'.$q.'8:05a'.$q, $checkIn);
        $this->assertStringContainsString($q.'out'.$q.':'.$q.'4:30p'.$q, $checkIn);

        // And the register, that week, shows the same arrival.
        $register = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::DAY]))->assertOk()->getContent();
        $this->assertStringContainsString('8:05a', $register);
    }
}
