<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Director Attendance's tap (Jordan, 28 Oct 2026): "when I click the time
 * it changes to a dot; when I click it again it changes to Absent; if I
 * click it once more it should return to the previous time."
 *
 * The RULES:
 *  - time → dot → Absent → the same time back, in and out both.
 *  - The times are remembered on the day's mark, not on the screen.
 *  - A day with no times goes dot → Absent → booked.
 *  - Every step on a day gone by leaves an amendment.
 *  - The page is the register, with no Live switch and no ⋯ menu.
 */
class DirectorAttendanceCycleTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-12';

    private User $admin;

    private Child $ava;

    private Child $ruby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));
        $this->admin = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->ava = Child::create(['lan' => '10009', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler', 'dob' => '2024-04-05', 'drop_off_time' => '07:45', 'pick_up_time' => '17:30', 'schedule_days' => [1, 2, 3, 4, 5]]);
        $this->ruby = Child::create(['lan' => '10023', 'first_name' => 'Ruby', 'last_name' => 'Kim', 'status' => 'Active', 'classroom' => 'School Age', 'classroom_override' => 'School Age', 'dob' => '2018-10-05', 'drop_off_time' => '07:15', 'pick_up_time' => '18:00', 'schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->open(self::MONDAY, $this->admin);
    }

    public function test_a_time_goes_to_a_dot_then_absent_then_back_to_the_same_time(): void
    {
        $this->sign($this->ava, '2026-10-13', 'FULL', '07:50', '17:20');

        // Tap 1: the dot. The times are off the register and on the mark.
        $day = $this->tap($this->ava, '2026-10-13');
        $this->assertSame('none', $day['mark']);
        $this->assertSame(['in' => null, 'out' => null, 'scheduled' => false], $day['sessions']['FULL']);
        $this->assertSame(0, Attendance::where('child_id', $this->ava->id)->count());
        $this->assertCount(1, DssDayMark::first()->kept);

        // Tap 2: Absent, still remembering.
        $day = $this->tap($this->ava, '2026-10-13');
        $this->assertSame('absent', $day['mark']);
        $this->assertNotEmpty(DssDayMark::first()->kept);

        // Tap 3: the same time back, in and out.
        $day = $this->tap($this->ava, '2026-10-13');
        $this->assertNull($day['mark']);
        $this->assertSame(['in' => '7:50a', 'out' => '5:20p', 'scheduled' => true], $day['sessions']['FULL']);
        $this->assertSame(0, DssDayMark::count());

        // A day gone by: the removal and the putting back are both on record.
        $this->assertSame(
            [AttendanceAmendment::REMOVED, AttendanceAmendment::ADDED],
            AttendanceAmendment::orderBy('id')->pluck('action')->all(),
        );

        // And round again: the cycle has no end.
        $this->assertSame('none', $this->tap($this->ava, '2026-10-13')['mark']);
    }

    public function test_today_cycles_the_same_way(): void
    {
        $this->sign($this->ava, '2026-10-15', 'FULL', '07:48', null);

        $this->assertSame('none', $this->tap($this->ava, '2026-10-15')['mark']);
        $this->assertSame('absent', $this->tap($this->ava, '2026-10-15')['mark']);
        $this->assertSame('7:48a', $this->tap($this->ava, '2026-10-15')['sessions']['FULL']['in']);
    }

    public function test_a_school_age_day_steps_both_sessions_together(): void
    {
        $this->sign($this->ruby, '2026-10-14', 'AM', '07:17', '08:30');
        $this->sign($this->ruby, '2026-10-14', 'PM', '15:04', '17:59');

        $this->assertSame([null, null], array_column($this->tap($this->ruby, '2026-10-14', 'PM')['sessions'], 'in'));
        $this->tap($this->ruby, '2026-10-14', 'AM');
        $back = $this->tap($this->ruby, '2026-10-14', 'AM')['sessions'];

        $this->assertSame(['7:17a', '8:30a'], [$back['AM']['in'], $back['AM']['out']]);
        $this->assertSame(['3:04p', '5:59p'], [$back['PM']['in'], $back['PM']['out']]);
    }

    public function test_a_day_with_no_times_goes_dot_absent_booked(): void
    {
        ScheduleSlot::where('child_id', $this->ava->id)->whereDate('slot_date', '2026-10-12')->update(['is_scheduled' => false]);

        $this->assertSame('absent', $this->tap($this->ava, '2026-10-12')['mark']);
        $day = $this->tap($this->ava, '2026-10-12');
        $this->assertNull($day['mark']);
        $this->assertTrue($day['sessions']['FULL']['scheduled']);
        $this->assertNull($day['sessions']['FULL']['in']);
    }

    public function test_a_day_to_come_is_not_stepped_and_a_parent_cannot_step(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.step'), ['child_id' => $this->ava->id, 'date' => '2026-10-16', 'session' => 'FULL'])
            ->assertStatus(422);

        $parent = User::create(['name' => 'P', 'email' => 'p@example.com', 'password' => 'password', 'role' => 'parent']);
        $this->actingAs($parent)->postJson(route('attendance.step'), ['child_id' => $this->ava->id, 'date' => '2026-10-13', 'session' => 'FULL'])
            ->assertForbidden();
    }

    public function test_director_attendance_is_the_register_with_no_live_switch_and_no_more_menu(): void
    {
        DssDayMark::create(['child_id' => $this->ava->id, 'marked_on' => '2026-10-14', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->admin->id]);

        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="attendanceApp()"', $html);
        $this->assertStringNotContainsString('data-week-grid', $html);
        $this->assertStringNotContainsString('att-switch', $html);
        $this->assertStringNotContainsString('aria-label="More"', $html);
        $this->assertStringContainsString('editing: true,', $html);
        $this->assertStringContainsString("mode: 'sheet',", $html);
        // The register knows the mark, so it can draw Absent.
        $this->assertSame(
            [$this->ava->id => ['2026-10-14' => 'absent']],
            $this->actingAs($this->admin)->get(route('attendance.index'))->viewData('markMap'),
        );
        $this->assertStringContainsString('marks: ', $html);
        $this->assertStringContainsString(route('attendance.step'), $html);

        // The week grid is still there, at its own address.
        $this->actingAs($this->admin)->get(route('attendance.week'))->assertOk()->assertSee('data-week-grid', false);
    }

    public function test_the_pencil_moves_the_arrival_and_the_departure(): void
    {
        // Jordan, 28 Oct 2026: "I can edit the in and out".
        $this->sign($this->ava, '2026-10-13', 'FULL', '07:29', '17:28');

        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->getContent();
        $this->assertStringContainsString('x-model="draftOut"', $html);
        $this->assertStringContainsString("this.draftOut = present ? (this.outTime(childId, date, session) || '') : '';", $html);
        $this->assertStringContainsString('...(outChanged ? { signed_out_time: outValue } : {}),', $html);

        // The departure alone, as the second box sends it.
        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $this->ava->id, 'attendance_date' => '2026-10-13', 'session' => 'FULL', 'signed_out_time' => '17:45',
        ])->assertOk()->assertJson(['time' => '7:29a', 'out_time' => '5:45p']);

        // Both together.
        $this->actingAs($this->admin)->postJson(route('attendance.signin.retime'), [
            'child_id' => $this->ava->id, 'attendance_date' => '2026-10-13', 'session' => 'FULL', 'signed_in_time' => '07:40', 'signed_out_time' => '17:50',
        ])->assertOk()->assertJson(['time' => '7:40a', 'out_time' => '5:50p']);
    }

    private function tap(Child $child, string $date, string $session = 'FULL'): array
    {
        return $this->actingAs($this->admin)
            ->postJson(route('attendance.step'), ['child_id' => $child->id, 'date' => $date, 'session' => $session])
            ->assertOk()
            ->json();
    }

    private function sign(Child $child, string $date, string $session, string $in, ?string $out): void
    {
        Attendance::create([
            'child_id' => $child->id, 'attendance_date' => $date, 'session' => $session,
            'signed_in_at' => Carbon::parse($date.' '.$in, config('app.timezone')),
            'signed_out_at' => $out ? Carbon::parse($date.' '.$out, config('app.timezone')) : null,
        ]);
    }
}
