<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The hour on an arrival, and who may move it.
 *
 * The commonest correction there is: the child was here, the tap came late.
 * Until now the only way to make it was to take the arrival off and put it
 * back, which wrote two amendments to say one thing. Now the row keeps its
 * identity and the time on it moves — and for a day already gone, the move is
 * written down with who made it.
 */
class AttendanceRetimeTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-16';

    private const MONDAY = '2026-09-14';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 09:20:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    public function test_a_typed_time_is_the_time_recorded_on_a_day_gone_by(): void
    {
        $ada = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_time' => '08:15',
        ])->assertOk()->assertJson(['success' => true, 'time' => '8:15a']);

        $row = Attendance::where('child_id', $ada->id)->sole();
        $this->assertSame(self::MONDAY.' 08:15:00', $row->signed_in_at->format('Y-m-d H:i:s'));

        // Added by hand, and the register says so.
        $amendment = AttendanceAmendment::where('child_id', $ada->id)->sole();
        $this->assertSame(AttendanceAmendment::ADDED, $amendment->action);
    }

    /**
     * Today too. The tap at the door stamps the moment; a typed time is
     * somebody saying the moment was wrong, and they are the one who knows.
     */
    public function test_a_typed_time_is_taken_as_given_today_as_well(): void
    {
        $ada = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $ada->id,
            'attendance_date' => self::TODAY,
            'session' => 'FULL',
            'signed_in_time' => '08:45',
        ])->assertOk()->assertJson(['time' => '8:45a']);

        $this->assertSame('08:45', Attendance::where('child_id', $ada->id)->sole()->signed_in_at->format('H:i'));
    }

    /** Without a typed time nothing changes: the door still stamps now. */
    public function test_an_untyped_sign_in_today_still_stamps_the_moment(): void
    {
        $ada = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $ada->id,
            'attendance_date' => self::TODAY,
            'session' => 'FULL',
        ])->assertOk()->assertJson(['time' => '9:20a']);
    }

    public function test_an_arrival_can_be_retimed_and_the_move_is_written_down(): void
    {
        $ada = $this->makeChild();
        $row = $this->arrive($ada, self::MONDAY, '09:04');

        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_time' => '08:31',
        ])->assertOk()->assertJson(['success' => true, 'time' => '8:31a']);

        // Same row, new hour.
        $row->refresh();
        $this->assertSame(1, Attendance::count());
        $this->assertSame(self::MONDAY.' 08:31:00', $row->signed_in_at->format('Y-m-d H:i:s'));

        // One amendment saying what it now is, and by whom.
        $amendment = AttendanceAmendment::where('child_id', $ada->id)->sole();
        $this->assertSame(AttendanceAmendment::RETIMED, $amendment->action);
        $this->assertSame('08:31', $amendment->signed_in_at->format('H:i'));
        $this->assertSame($this->admin->id, $amendment->performed_by);
    }

    /**
     * A retime today moves the row and writes nothing: today's arrivals are
     * ordinary sign-ins, and a log that fills with routine mornings is one
     * nobody reads. The same rule record() applies to adding one.
     */
    public function test_retiming_today_moves_the_row_without_an_amendment(): void
    {
        $ada = $this->makeChild();
        $this->arrive($ada, self::TODAY, '09:20');

        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => self::TODAY,
            'session' => 'FULL',
            'signed_in_time' => '08:45',
        ])->assertOk()->assertJson(['time' => '8:45a', 'amendment' => null]);

        $this->assertSame('08:45', Attendance::where('child_id', $ada->id)->sole()->signed_in_at->format('H:i'));
        $this->assertSame(0, AttendanceAmendment::count());
    }

    public function test_a_day_that_has_not_happened_has_no_arrival_to_move(): void
    {
        $ada = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => '2026-09-18',
            'session' => 'FULL',
            'signed_in_time' => '08:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['attendance_date']);
    }

    public function test_a_cell_with_no_arrival_on_it_cannot_be_retimed(): void
    {
        $ada = $this->makeChild();

        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_time' => '08:00',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Ada Lovelace has no arrival recorded on Monday, Sep 14 to move.']);

        $this->assertSame(0, AttendanceAmendment::count());
    }

    /** The same door as taking an arrival off: a teacher's own rooms only. */
    public function test_a_teacher_cannot_move_an_arrival_in_another_room(): void
    {
        $ada = $this->makeChild();
        $this->arrive($ada, self::MONDAY, '09:04');

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant', 'must_change_password' => false]);

        $this->actingAs($teacher)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_time' => '08:00',
        ])->assertNotFound();

        $this->assertSame('09:04', Attendance::where('child_id', $ada->id)->sole()->signed_in_at->format('H:i'));
    }

    public function test_a_teacher_can_move_an_arrival_in_their_own_room(): void
    {
        $ada = $this->makeChild();
        $this->arrive($ada, self::MONDAY, '09:04');

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);

        $this->actingAs($teacher)->postJson(route('attendance.signin.retime'), [
            'child_id' => $ada->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_time' => '08:00',
        ])->assertOk();

        $this->assertSame('08:00', Attendance::where('child_id', $ada->id)->sole()->signed_in_at->format('H:i'));
    }

    private function makeChild(): Child
    {
        return Child::create([
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
        ]);
    }

    private function arrive(Child $child, string $date, string $time): Attendance
    {
        return Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => $date,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' '.$time),
        ]);
    }
}
