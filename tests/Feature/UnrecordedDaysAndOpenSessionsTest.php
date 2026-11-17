<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA #3 and #5, 17 Nov 2026.
 *
 * #3: a weekday gone by on which nobody in the centre was signed in showed
 * as an absence for every booked child (~97 on a day). Such a day is one
 * nobody recorded — a closure not entered, or sign-ins never saved — so it
 * is flagged to check, is not counted as absences, and stays out of the
 * KinderConnect file until a person enters a closure or marks the children.
 *
 * #5: a sign-in on a day gone by with no sign-out showed as "7:36a / —".
 * It is flagged "no out" on the DSS grid and in Reports, counts 0 h, and
 * stays out of the KinderConnect file until a time out is put in.
 */
class UnrecordedDaysAndOpenSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $ray;

    private Child $ava;

    private Child $ben;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 8 Oct 2026. The days gone by this month: Thu 1, Fri 2, Mon 5, Tue 6, Wed 7.
        $this->travelTo(Carbon::parse('2026-10-08 10:30:00'));
        config()->set('daycare.dss.users', 'ray@example.com');

        $this->ray = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);

        $this->ava = Child::create([
            'lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler',
            'dob' => '2024-04-05', 'dss_cin' => 'AC55667F', 'dss_case_no' => 'S1100107A', 'expected_hours_per_week' => 45,
            'drop_off_time' => '07:45', 'pick_up_time' => '17:30', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);
        $this->ben = Child::create([
            'lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Diaz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler',
            'dob' => '2024-02-01', 'dss_cin' => 'BD11223K', 'dss_case_no' => 'S1100108A', 'expected_hours_per_week' => 45,
            'drop_off_time' => '08:00', 'pick_up_time' => '17:00', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        // Ben came every day but Tuesday the 6th, when nobody was signed in at all.
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-07'] as $date) {
            $this->sign($this->ben, $date, '08:00', '17:00');
        }
        // Ava came the 1st and 2nd, missed the 5th (an ordinary absence: Ben was in),
        // and on the 7th was signed in at 7:36 and never signed out.
        $this->sign($this->ava, '2026-10-01', '07:45', '17:15');
        $this->sign($this->ava, '2026-10-02', '07:45', '17:15');
        $this->sign($this->ava, '2026-10-07', '07:36', null);
        // Today: Ben is in and not out yet — still here, not a missing sign-out.
        $this->sign($this->ben, '2026-10-08', '08:00', null);
    }

    public function test_a_day_nobody_was_signed_in_is_flagged_on_the_dss_grid_not_left_as_absences(): void
    {
        $json = $this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->assertOk()->json();

        $this->assertSame(['Tue Oct 6'], $json['unrecorded']);
        $this->assertTrue(collect($json['days'])->firstWhere('date', '2026-10-06')['nodata']);
        $this->assertFalse(collect($json['days'])->firstWhere('date', '2026-10-05')['nodata']);
        $this->assertFalse(collect($json['days'])->firstWhere('date', '2026-10-08')['nodata'], 'today is never a no-data day');

        $ava = collect($json['rows'])->firstWhere('id', $this->ava->id);
        $cell = $ava['cells']['2026-10-06'];
        $this->assertSame('open', $cell['st']);
        $this->assertTrue($cell['nodata']);
        $this->assertSame('check', $cell['lines'][0]['t']);
        $this->assertStringContainsString('No sign-ins for anyone this day', $cell['tip']);
        // The ordinary missed day stays an ordinary "no time".
        $this->assertSame('no time', $ava['cells']['2026-10-05']['lines'][0]['t']);

        $this->actingAs($this->ray)->get(route('dss.index', ['month' => '2026-10']))->assertOk()->assertSee('data-review', false);
    }

    public function test_a_day_nobody_was_signed_in_stays_out_of_the_kinderconnect_file_until_someone_says_what_it_was(): void
    {
        $preview = $this->actingAs($this->ray)->getJson(route('dss.preview', ['month' => '2026-10']))->assertOk()->json();
        $check = collect($preview['checks'])->first(fn ($c) => str_contains($c['text'], 'Nobody was signed in'));
        $this->assertNotNull($check);
        $this->assertSame('Fix first', $check['tag']);
        $this->assertStringContainsString('Tue Oct 6', $check['text']);

        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10']))->assertOk()->getContent();
        $this->assertStringNotContainsString('10/06/2026', $csv);

        // A person marks Ava absent that day: that one is now a real absence and is claimed as one.
        DssDayMark::create(['child_id' => $this->ava->id, 'marked_on' => '2026-10-06', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->ray->id]);
        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10']))->getContent();
        $this->assertStringContainsString('Cruz,Ava,04/05/2024,AC55667F,S1100107A,Toddler,10/06/2026,,,,Y', $csv);
        $this->assertStringNotContainsString('Diaz,Ben,02/01/2024,BD11223K,S1100108A,Toddler,10/06/2026', $csv);

        // Or the day was a closure nobody entered: then nothing is left to check.
        ClosureDay::create(['closed_on' => '2026-10-06', 'reason' => 'Water main']);
        $json = $this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->json();
        $this->assertSame([], $json['unrecorded']);
        $preview = $this->actingAs($this->ray)->getJson(route('dss.preview', ['month' => '2026-10']))->json();
        $this->assertNull(collect($preview['checks'])->first(fn ($c) => str_contains($c['text'], 'Nobody was signed in')));
    }

    public function test_a_day_nobody_was_signed_in_is_a_question_mark_in_reports_not_an_absence(): void
    {
        $html = $this->actingAs($this->ray)->get(route('reports.index', ['report' => 'child', 'month' => '2026-10']))->assertOk()->getContent();

        // Ava: absent on the 5th only. Ben: no absences. The 6th is two "?" to check.
        $this->assertStringContainsString('1 absences', $html);
        $this->assertStringContainsString('No sign-ins for anyone on Tue Oct 6 — 2 booked days are marked ? and not counted as absences.', $html);

        $csv = $this->actingAs($this->ray)->get(route('reports.export', ['report' => 'child', 'month' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('Toddler,Cruz,Ava,10/5/2026,Absent,,,', $csv);
        $this->assertStringContainsString('Toddler,Cruz,Ava,10/6/2026,No sign-ins - check,,,', $csv);
        $this->assertStringContainsString('Toddler,Diaz,Ben,10/6/2026,No sign-ins - check,,,', $csv);
        $this->assertStringNotContainsString('10/6/2026,Absent', $csv);
    }

    public function test_a_sign_in_never_signed_out_is_flagged_on_the_dss_grid_counts_no_hours_and_is_not_exported(): void
    {
        $json = $this->actingAs($this->ray)->getJson(route('dss.grid', ['month' => '2026-10']))->assertOk()->json();
        $ava = collect($json['rows'])->firstWhere('id', $this->ava->id);
        $ben = collect($json['rows'])->firstWhere('id', $this->ben->id);

        $cell = $ava['cells']['2026-10-07'];
        $this->assertSame('pres', $cell['st']);
        $this->assertTrue($cell['noOut']);
        $this->assertStringContainsString('c-noout', $cell['cls']);
        $this->assertSame(['7:36a', 'no out'], array_column($cell['lines'], 't'));
        $this->assertStringContainsString('No sign-out', $cell['tip']);
        $this->assertSame(1, $ava['noOut']);
        $this->assertStringContainsString('1 day with no sign-out', $ava['noOutTxt']);
        $this->assertSame(1, $json['noOut']);
        // 0 h for the open visit: only the two full days (9.5 h each) count.
        $this->assertSame('19 h', $ava['hrs']);

        // Today's open visit is "still in", not a missing sign-out.
        $this->assertFalse($ben['cells']['2026-10-08']['noOut']);
        $this->assertSame(0, $ben['noOut']);

        $preview = $this->actingAs($this->ray)->getJson(route('dss.preview', ['month' => '2026-10']))->json();
        $check = collect($preview['checks'])->first(fn ($c) => str_contains($c['text'], 'no sign-out'));
        $this->assertSame('Fix first', $check['tag']);
        $this->assertStringContainsString('1 visit has no sign-out (Ava Cruz Oct 7)', $check['text']);

        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10']))->getContent();
        $this->assertStringNotContainsString('Cruz,Ava,04/05/2024,AC55667F,S1100107A,Toddler,10/07/2026', $csv);
        $this->assertStringContainsString('Diaz,Ben,02/01/2024,BD11223K,S1100108A,Toddler,10/07/2026,Full day,8:00 AM,5:00 PM,N', $csv);

        // Once a time out is put in, the flag goes and the visit is claimed.
        $this->actingAs($this->ray)->postJson(route('dss.apply', ['month' => '2026-10']), ['ops' => [
            ['child_id' => $this->ava->id, 'date' => '2026-10-07', 'state' => ['st' => 'pres', 'segs' => [['in' => '7:36a', 'out' => '5:00p']]]],
        ]])->assertOk()->assertJsonPath('noOut', 0);
        $csv = $this->actingAs($this->ray)->get(route('dss.export', ['month' => '2026-10']))->getContent();
        $this->assertStringContainsString('Cruz,Ava,04/05/2024,AC55667F,S1100107A,Toddler,10/07/2026,Full day,7:36 AM,5:00 PM,N', $csv);
    }

    public function test_a_sign_in_never_signed_out_is_flagged_in_reports_and_counts_no_hours(): void
    {
        $html = $this->actingAs($this->ray)->get(route('reports.index', ['report' => 'child', 'month' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('no out', $html);
        $this->assertStringContainsString('1 day has a sign-in with no sign-out — that visit counts 0 h until a time out is put in.', $html);
        // Today's open visit still reads "here".
        $this->assertStringContainsString('here', $html);

        $csv = $this->actingAs($this->ray)->get(route('reports.export', ['report' => 'child', 'month' => '2026-10']))->getContent();
        $this->assertStringContainsString('Toddler,Cruz,Ava,10/7/2026,Present - no sign-out,7:36a,,', $csv);
        $this->assertStringNotContainsString('10/8/2026,Present - no sign-out', $csv);
    }

    private function sign(Child $child, string $date, string $in, ?string $out): void
    {
        Attendance::create([
            'child_id' => $child->id, 'attendance_date' => $date, 'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' '.$in, config('app.timezone')),
            'signed_out_at' => $out ? Carbon::parse($date.' '.$out, config('app.timezone')) : null,
        ]);
    }
}
