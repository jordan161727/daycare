<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Director Attendance — Phase 3: the week as a grid, tapped and typed.
 *
 * What these pin: the cell states come from the ticks, the absent mark and
 * the register rows; a tap moves one state to the next and the schedule
 * with it; a time typed here lands on the same row Teacher Attendance reads,
 * with an amendment; and the classic register is still there.
 */
class DirectorAttendanceWeekTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-12';

    private User $admin;

    private Child $ava;

    private Child $ruby;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday Oct 15, mid-morning.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));

        $this->admin = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->ava = Child::create(['lan' => '10009', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler', 'dob' => '2024-04-05', 'drop_off_time' => '07:45', 'pick_up_time' => '17:30', 'schedule_days' => [1, 2, 3, 4, 5]]);
        $this->ruby = Child::create(['lan' => '10023', 'first_name' => 'Ruby', 'last_name' => 'Kim', 'status' => 'Active', 'classroom' => 'School Age', 'classroom_override' => 'School Age', 'dob' => '2018-10-05', 'drop_off_time' => '07:15', 'pick_up_time' => '18:00', 'schedule_days' => [1, 2, 3, 4, 5]]);

        // The week is open, with the ticks the records give.
        app(WeekSchedule::class)->open(self::MONDAY, $this->admin);
    }

    public function test_the_week_reads_the_ticks_the_times_and_the_marks(): void
    {
        $this->sign($this->ava, '2026-10-13', '07:50', '17:20');
        $this->sign($this->ava, '2026-10-15', '07:48', null);
        DssDayMark::create(['child_id' => $this->ava->id, 'marked_on' => '2026-10-14', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->admin->id]);
        ClosureDay::create(['closed_on' => '2026-10-16', 'reason' => 'Staff training']);

        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-week-grid', $html);
        $this->assertStringContainsString('Oct 12 – 16', $html);
        $this->assertStringContainsString('Linked to Teacher Attendance', $html);

        $w = $this->actingAs($this->admin)->get(route('attendance.index'))->viewData('w');
        $ava = collect($w['rows'])->firstWhere('id', $this->ava->id);

        $this->assertSame('7:45a – 5:30p', $ava['hours']);
        $parts = collect($ava['cells'])->keyBy('date')->map(fn ($c) => $c['parts'][0]);

        $this->assertSame(['s-in', '7:50a–5:20p'], [$parts['2026-10-13']['cls'], $parts['2026-10-13']['text']]);
        $this->assertSame(['s-abs', 'Absent'], [$parts['2026-10-14']['cls'], $parts['2026-10-14']['text']]);
        $this->assertSame(['s-in', 'In 7:48a'], [$parts['2026-10-15']['cls'], $parts['2026-10-15']['text']]);
        $this->assertSame('s-closed', $parts['2026-10-16']['cls']);
        $this->assertFalse($parts['2026-10-16']['tappable']);
        // Monday: expected, nothing recorded.
        $this->assertSame('s-exp', $parts['2026-10-12']['cls']);

        // School Age has a morning and an afternoon cell.
        $ruby = collect($w['rows'])->firstWhere('id', $this->ruby->id);
        $this->assertSame(['AM', 'PM'], array_column($ruby['cells'][0]['parts'], 'session'));
        $this->assertSame('7:15a – 8:30a · 3:00p – 6:00p', $ruby['hours']);

        // Today's numbers: two enrolled, two expected, Ava in, Ruby still to come.
        $this->assertSame(['enr' => 2, 'exp' => 2, 'inN' => 1, 'come' => 1, 'notIn' => 1], $w['sum']);
    }

    public function test_a_tap_moves_the_day_through_its_states_and_the_tick_with_it(): void
    {
        $post = fn (string $state) => $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), ['child_id' => $this->ava->id, 'date' => '2026-10-16', 'session' => 'FULL', 'state' => $state]);
        $slot = fn () => ScheduleSlot::where('child_id', $this->ava->id)->whereDate('slot_date', '2026-10-16')->first();

        $this->assertTrue((bool) $slot()->is_scheduled);

        // coming → not scheduled: the tick comes off.
        $json = $post('none')->assertOk()->json();
        $this->assertFalse((bool) $slot()->is_scheduled);
        $this->assertSame('s-none', collect($json['rows'])->firstWhere('id', $this->ava->id)['cells'][4]['parts'][0]['cls']);

        // → absent: the mark goes on.
        $json = $post('abs')->assertOk()->json();
        $this->assertSame(DssDayMark::ABSENT, DssDayMark::where('child_id', $this->ava->id)->sole()->mark);
        $this->assertSame('s-abs', collect($json['rows'])->firstWhere('id', $this->ava->id)['cells'][4]['parts'][0]['cls']);

        // → coming: the mark goes, the tick comes back, and Friday still has no times.
        $json = $post('pres')->assertOk()->json();
        $this->assertSame(0, DssDayMark::count());
        $this->assertTrue((bool) $slot()->is_scheduled);
        $this->assertSame('s-exp', collect($json['rows'])->firstWhere('id', $this->ava->id)['cells'][4]['parts'][0]['cls']);
        $this->assertSame(0, Attendance::count());
    }

    public function test_a_day_in_a_week_already_gone_can_still_be_marked_not_scheduled(): void
    {
        // The following Tuesday: last week's ticks are frozen, so the tap is
        // kept as the DSS page's "not scheduled" mark and read back the same
        // way. Times on the day stay — it is still billable.
        $this->travelTo(Carbon::parse('2026-10-20 10:30:00'));
        $post = fn (string $state, array $extra = []) => $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), ['child_id' => $this->ava->id, 'date' => '2026-10-16', 'session' => 'FULL', 'state' => $state] + $extra);
        $cell = fn (array $json) => collect($json['rows'])->firstWhere('id', $this->ava->id)['cells'][4]['parts'][0];

        $json = $post('pres', ['in' => '8:05a', 'out' => '4:10p'])->assertOk()->json();
        $this->assertSame('8:05a', $cell($json)['in']);

        $json = $post('none')->assertOk()->json();
        $this->assertSame('none', $cell($json)['st']);
        $this->assertSame(DssDayMark::NONE, DssDayMark::where('child_id', $this->ava->id)->sole()->mark);
        $this->assertSame('8:05a', $cell($json)['in'], 'the times stay');

        $json = $post('abs')->assertOk()->json();
        $this->assertSame('abs', $cell($json)['st']);

        $json = $post('pres')->assertOk()->json();
        $this->assertSame('pres', $cell($json)['st']);
        $this->assertSame(0, DssDayMark::count());
    }

    public function test_times_typed_in_the_cell_land_on_the_register_row_with_an_amendment(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), [
            'child_id' => $this->ava->id, 'date' => '2026-10-13', 'session' => 'FULL', 'state' => 'pres', 'in' => '8:05a', 'out' => '5:10p',
        ])->assertOk();

        $row = Attendance::where('child_id', $this->ava->id)->whereDate('attendance_date', '2026-10-13')->sole();
        $this->assertSame(['08:05', '17:10'], [$row->signed_in_at->timezone(config('app.timezone'))->format('H:i'), $row->signed_out_at->timezone(config('app.timezone'))->format('H:i')]);
        $this->assertSame(AttendanceAmendment::ADDED, AttendanceAmendment::sole()->action);

        // Moved: same row, retimed, and the cell wears the "fixed by hand" dot.
        $json = $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), [
            'child_id' => $this->ava->id, 'date' => '2026-10-13', 'session' => 'FULL', 'state' => 'pres', 'in' => '8:00a', 'out' => '5:10p',
        ])->assertOk()->json();
        $this->assertSame($row->id, Attendance::where('child_id', $this->ava->id)->whereDate('attendance_date', '2026-10-13')->sole()->id);
        $cell = collect($json['rows'])->firstWhere('id', $this->ava->id)['cells'][1]['parts'][0];
        $this->assertTrue($cell['edited']);
        $this->assertSame('8:00a–5:10p', $cell['text']);

        // And it is the row Teacher Attendance reads: the day page shows it.
        $this->assertStringContainsString('8:00a', $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-10-13']))->assertOk()->getContent());

        // The afternoon of a School Age day is its own row.
        $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), [
            'child_id' => $this->ruby->id, 'date' => '2026-10-13', 'session' => 'PM', 'state' => 'pres', 'in' => '3:02p', 'out' => '5:58p',
        ])->assertOk();
        $this->assertSame('PM', Attendance::where('child_id', $this->ruby->id)->sole()->session);
    }

    public function test_what_is_refused(): void
    {
        // Times on a day still to come.
        $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), ['child_id' => $this->ava->id, 'date' => '2026-10-16', 'session' => 'FULL', 'state' => 'pres', 'in' => '8:00a'])->assertStatus(422);
        // Out before in.
        $this->actingAs($this->admin)->postJson(route('attendance.week.cell'), ['child_id' => $this->ava->id, 'date' => '2026-10-13', 'session' => 'FULL', 'state' => 'pres', 'in' => '9:00a', 'out' => '8:00a'])->assertStatus(422);
        // A teacher from another room.
        $infant = User::create(['name' => 'Farhana', 'email' => 'f@example.com', 'password' => 'password', 'role' => 'teacher', 'classroom' => 'Infant']);
        $this->actingAs($infant)->postJson(route('attendance.week.cell'), ['child_id' => $this->ava->id, 'date' => '2026-10-13', 'session' => 'FULL', 'state' => 'abs'])->assertNotFound();

        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, DssDayMark::count());
    }

    public function test_the_room_chips_filter_and_the_classic_register_is_one_link_away(): void
    {
        $html = $this->actingAs($this->admin)->get(route('attendance.index', ['room' => 'Toddler']))->assertOk()->getContent();
        $w = $this->actingAs($this->admin)->get(route('attendance.index', ['room' => 'Toddler']))->viewData('w');

        $this->assertSame(['Cruz, Ava'], array_column($w['rows'], 'name'));
        $this->assertSame(2, $w['total']);
        $this->assertStringContainsString(route('attendance.classic', ['date' => today()->toDateString()]), $html);

        // The old address by room still works: ?toddler.
        $w = $this->actingAs($this->admin)->get(route('attendance.index').'?toddler')->assertOk()->viewData('w');
        $this->assertSame(['Cruz, Ava'], array_column($w['rows'], 'name'));

        $this->actingAs($this->admin)->get(route('attendance.classic'))->assertOk()->assertSee('x-data="attendanceApp()"', false);
    }

    private function sign(Child $child, string $date, string $in, ?string $out, string $session = 'FULL'): void
    {
        Attendance::create([
            'child_id' => $child->id, 'attendance_date' => $date, 'session' => $session,
            'signed_in_at' => Carbon::parse($date.' '.$in, config('app.timezone')),
            'signed_out_at' => $out ? Carbon::parse($date.' '.$out, config('app.timezone')) : null,
        ]);
    }
}
