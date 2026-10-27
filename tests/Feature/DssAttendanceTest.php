<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\User;
use App\Services\DssAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DSS attendance — Phase 8.1: the county's month, for one person.
 *
 * What these pin: only the allowed person sees it; the grid reads the same
 * attendance rows the register writes and writes back to them with an
 * amendment; the week's hours are measured against the authorised hours;
 * and the export holds only days that have happened, one row per visit.
 */
class DssAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $ray;

    private User $other;

    private Child $ava;

    private Child $ruby;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday, mid-month.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));
        config()->set('daycare.dss.users', 'ray@example.com');

        $this->ray = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->other = User::create(['name' => 'Another Director', 'email' => 'other@example.com', 'password' => 'password', 'role' => 'admin']);

        $this->ava = Child::create([
            'lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler',
            'dob' => '2024-04-05', 'dss_cin' => 'AC55667F', 'dss_case_no' => 'S1100107A', 'expected_hours_per_week' => 45,
            'drop_off_time' => '07:45', 'pick_up_time' => '17:30', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
        $this->ruby = Child::create([
            'lan' => '10023', 'first_name' => 'Ruby', 'last_name' => 'Kim', 'status' => 'Active', 'classroom' => 'School Age', 'classroom_override' => 'School Age',
            'dob' => '2018-10-05', 'dss_cin' => 'AF54556Q', 'dss_case_no' => 'S1100121A', 'expected_hours_per_week' => 30,
            'drop_off_time' => '07:15', 'pick_up_time' => '18:00', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
        Child::create(['lan' => '10002', 'first_name' => 'Noah', 'last_name' => 'Bennett', 'status' => 'Active', 'classroom' => 'Infant', 'classroom_override' => 'Infant', 'drop_off_time' => '08:00', 'pick_up_time' => '16:00']);
    }

    public function test_only_the_named_person_sees_it(): void
    {
        $this->actingAs($this->ray)->get(route('dss.index'))->assertOk()->assertSee('Only visible to Ray K');
        $this->actingAs($this->other)->get(route('dss.index'))->assertForbidden();
        $this->actingAs($this->other)->getJson(route('dss.grid'))->assertForbidden();
    }

    public function test_the_grid_reads_the_register_and_measures_the_week_against_the_authorised_hours(): void
    {
        // Monday to Wednesday last week, nine and a half hours a day: 28.5 h.
        foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
            $this->sign($this->ava, $date, '07:45', '17:15');
        }
        // Thursday and Friday of that week, 9 h each: the week lands at 46.5, over the 45 authorised.
        $this->sign($this->ava, '2026-10-08', '08:00', '17:00');
        $this->sign($this->ava, '2026-10-09', '08:00', '17:00');
        ClosureDay::create(['closed_on' => '2026-10-12', 'reason' => 'Columbus Day']);

        $json = $this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->assertOk()->json();

        $this->assertSame('October 2026', $json['label']);
        $row = collect($json['rows'])->firstWhere('id', $this->ava->id);
        $this->assertSame('AC55667F · 45 h/wk', $row['cin']);
        $this->assertSame('7:45a–5:30p', $row['agreed']);

        $mon = $row['cells']['2026-10-05'];
        $this->assertSame('pres', $mon['st']);
        $this->assertSame(['7:45a', '5:15p'], [$mon['lines'][0]['t'], $mon['lines'][1]['t']]);

        $week = $row['weeks']['2026-10-05'];
        $this->assertSame('over', $week['cls']);
        $this->assertSame('46.5', $week['big']);
        $this->assertStringContainsString('OVER by 1.5 h', $week['tip']);

        // The closure, a past day with no times, and a day still to come.
        $this->assertSame('closed', $row['cells']['2026-10-12']['st']);
        $this->assertSame('open', $row['cells']['2026-10-13']['st']);
        $this->assertSame('plan', $row['cells']['2026-10-16']['st']);

        // Noah pays privately, so with the default filter he is not listed.
        $this->assertNull(collect($json['rows'])->firstWhere('lan', '10002'));
        $this->assertSame(2, $json['subsidyCount']);
    }

    public function test_setting_times_writes_the_register_row_with_an_amendment(): void
    {
        $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ava->id, 'date' => '2026-10-13', 'state' => ['st' => 'pres', 'segs' => [['in' => '8:00a', 'out' => '5:00p']]]],
        ]])->assertOk()->assertJsonPath('saved', true);

        $row = Attendance::where('child_id', $this->ava->id)->whereDate('attendance_date', '2026-10-13')->sole();
        $this->assertSame('FULL', $row->session);
        $this->assertSame('08:00', $row->signed_in_at->timezone(config('app.timezone'))->format('H:i'));
        $this->assertSame('17:00', $row->signed_out_at->timezone(config('app.timezone'))->format('H:i'));

        $amendment = AttendanceAmendment::sole();
        $this->assertSame([AttendanceAmendment::ADDED, $this->ray->id], [$amendment->action, $amendment->performed_by]);

        // Moved: the same row, retimed, written down again.
        $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ava->id, 'date' => '2026-10-13', 'state' => ['st' => 'pres', 'segs' => [['in' => '8:30a', 'out' => '5:00p']]]],
        ]])->assertOk();

        $this->assertSame($row->id, Attendance::where('child_id', $this->ava->id)->whereDate('attendance_date', '2026-10-13')->sole()->id);
        $this->assertSame(AttendanceAmendment::RETIMED, AttendanceAmendment::latest('id')->first()->action);
    }

    public function test_a_school_age_day_is_two_sessions_and_absent_takes_the_day_off_with_a_mark(): void
    {
        $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ruby->id, 'date' => '2026-10-13', 'state' => ['st' => 'pres', 'segs' => [['in' => '7:15a', 'out' => '8:30a'], ['in' => '3:00p', 'out' => '6:00p']]]],
        ]])->assertOk();

        $sessions = Attendance::where('child_id', $this->ruby->id)->whereDate('attendance_date', '2026-10-13')->orderBy('session')->pluck('session')->all();
        $this->assertSame(['AM', 'PM'], $sessions);

        // The afternoon only: one PM row.
        $json = $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ruby->id, 'date' => '2026-10-14', 'state' => ['st' => 'pres', 'segs' => [['in' => '3:00p', 'out' => '5:30p']]]],
        ]])->assertOk()->json();
        $this->assertSame(['PM'], Attendance::where('child_id', $this->ruby->id)->whereDate('attendance_date', '2026-10-14')->pluck('session')->all());
        $cell = collect($json['rows'])->firstWhere('id', $this->ruby->id)['cells']['2026-10-14'];
        $this->assertSame(['—', '—', '3:00p', '5:30p'], array_column($cell['lines'], 't'));

        // Absent: the rows go, with amendments, and the word stays.
        $json = $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ruby->id, 'date' => '2026-10-13', 'state' => ['st' => 'abs']],
        ]])->assertOk()->json();

        $this->assertSame(0, Attendance::where('child_id', $this->ruby->id)->whereDate('attendance_date', '2026-10-13')->count());
        $this->assertSame(DssDayMark::ABSENT, DssDayMark::where('child_id', $this->ruby->id)->sole()->mark);
        $this->assertSame(2, AttendanceAmendment::where('action', AttendanceAmendment::REMOVED)->count());
        $this->assertSame('abs', collect($json['rows'])->firstWhere('id', $this->ruby->id)['cells']['2026-10-13']['st']);
    }

    public function test_times_cannot_be_put_on_a_day_still_to_come(): void
    {
        $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ava->id, 'date' => '2026-10-20', 'state' => ['st' => 'pres', 'segs' => [['in' => '8:00a', 'out' => '5:00p']]]],
        ]])->assertStatus(422);

        $this->assertSame(0, Attendance::count());
    }

    public function test_the_export_holds_days_that_happened_one_row_per_visit(): void
    {
        $this->sign($this->ava, '2026-10-13', '07:50', '17:20');
        $this->sign($this->ruby, '2026-10-13', '07:15', '08:30', 'AM');
        $this->sign($this->ruby, '2026-10-13', '15:00', '18:05', 'PM');
        $this->sign($this->ava, '2026-10-15', '07:50', null);                     // today: not claimable yet
        DssDayMark::create(['child_id' => $this->ava->id, 'marked_on' => '2026-10-14', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->ray->id]);

        $preview = $this->actingAs($this->ray)->getJson(route('dss.preview', ['month' => '2026-10']))->assertOk()->json();
        $this->assertSame(4, $preview['rows']);
        $this->assertStringContainsString('Today (Oct 15) and future days are left out', implode(' ', array_column($preview['checks'], 'text')));

        $response = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10']))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->getContent();
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertSame('Child Last Name,Child First Name,Date of Birth,CIN,DSS Case Number,Class,Date,Session,Time In,Time Out,Absent', $lines[0]);
        $this->assertContains('Cruz,Ava,04/05/2024,AC55667F,S1100107A,Toddler,10/13/2026,Full day,7:50 AM,5:20 PM,N', $lines);
        $this->assertContains('Kim,Ruby,10/05/2018,AF54556Q,S1100121A,School Age,10/13/2026,AM,7:15 AM,8:30 AM,N', $lines);
        $this->assertContains('Kim,Ruby,10/05/2018,AF54556Q,S1100121A,School Age,10/13/2026,PM,3:00 PM,6:05 PM,N', $lines);
        $this->assertContains('Cruz,Ava,04/05/2024,AC55667F,S1100107A,Toddler,10/14/2026,,,,Y', $lines);
        $this->assertStringNotContainsString('10/15/2026', $csv);

        // Absences can be left out; the JSON carries the same visits.
        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10', 'abs' => 0]))->getContent();
        $this->assertStringNotContainsString(',Y', $csv);
        $json = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10', 'format' => 'json']))->assertOk()->json();
        $this->assertCount(4, $json['records']);
    }

    public function test_a_missing_cin_is_flagged_before_the_file_is_made(): void
    {
        $this->ava->update(['dss_cin' => null]);

        $preview = $this->actingAs($this->ray)->getJson(route('dss.preview', ['month' => '2026-10']))->assertOk()->json();

        $this->assertSame('Fix first', $preview['checks'][0]['tag']);
        $this->assertStringContainsString('1 subsidy child has no CIN', $preview['checks'][0]['text']);

        $row = collect($this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->json('rows'))->firstWhere('id', $this->ava->id);
        $this->assertStringStartsWith('CIN missing', $row['cin']);
    }

    public function test_a_withdrawn_child_stays_on_the_month_up_to_their_last_day(): void
    {
        $this->sign($this->ava, '2026-10-06', '07:50', '17:00');
        $this->ava->update(['status' => 'Inactive', 'withdrawn_on' => '2026-10-09', 'withdrawal_reason' => 'Moved out of the area']);

        $row = collect($this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->json('rows'))->firstWhere('id', $this->ava->id);

        $this->assertNotNull($row);
        $this->assertStringContainsString('Withdrawn · last day Oct 9 · Moved out of the area', $row['wd']);
        $this->assertSame('pres', $row['cells']['2026-10-06']['st']);
        $this->assertSame('gone', $row['cells']['2026-10-13']['st']);
        $this->assertFalse($row['cells']['2026-10-13']['editable']);
    }

    public function test_the_class_filter_narrows_the_grid_and_the_export_alike(): void
    {
        $this->sign($this->ava, '2026-10-13', '07:50', '17:20');
        $this->sign($this->ruby, '2026-10-13', '15:00', '18:00', 'PM');

        $rows = $this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10', 'rooms' => ['School Age']]))->json('rows');
        $this->assertSame(['Kim'], array_column($rows, 'last'));

        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10', 'rooms' => ['School Age']]))->getContent();
        $this->assertStringContainsString('Kim,Ruby', $csv);
        $this->assertStringNotContainsString('Cruz,Ava', $csv);
    }

    public function test_a_typed_time_is_read_the_way_people_write_it(): void
    {
        $this->assertSame(8 * 60, DssAttendance::minutes('8:00a'));
        $this->assertSame(17 * 60 + 30, DssAttendance::minutes('5:30 PM'));
        $this->assertSame(15 * 60, DssAttendance::minutes('3'));
        $this->assertSame(8 * 60, DssAttendance::minutes('08:00'));
        $this->assertNull(DssAttendance::minutes('soon'));
        $this->assertSame('3:30p', DssAttendance::clock(15 * 60 + 30));
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
