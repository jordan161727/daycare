<?php

namespace Tests\Feature;

use App\Mail\UpkSheetMail;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\Setting;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use App\Services\UpkEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Reports — Phase 8.2: three sheets, each on screen and as a file, and the
 * monthly email of the UPK-4 sheet to the school district.
 */
class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday, mid-October.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));
        config()->set('daycare.timesheet.clock.enabled', true);

        $this->admin = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->teacher = User::create(['name' => 'Mary Jo', 'email' => 'mj@example.com', 'password' => 'password', 'role' => 'teacher', 'employment' => 'FT', 'classroom' => 'Toddler']);
    }

    public function test_the_picker_opens_on_child_attendance_and_keeps_the_weekly_sheet_by_date(): void
    {
        $html = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-report-picker', $html);
        $this->assertStringContainsString('data-child-report', $html);
        $this->assertStringContainsString('Attendance sheet', $html);

        // The register's old address still lands on the weekly sheet.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['date' => '2026-10-12', 'classroom' => '']))->assertOk()->getContent();
        $this->assertStringContainsString('Daily sheet', $html);
        $this->assertStringNotContainsString('data-child-report', $html);

        // A teacher gets the children's reports and not the staff hours or the district's sheet.
        $html = $this->actingAs($this->teacher)->get(route('reports.index', ['report' => 'staff']))->assertOk()->getContent();
        $this->assertStringContainsString('data-child-report', $html);
        $this->assertStringNotContainsString('data-staff-report', $html);
        $this->assertStringNotContainsString('data-upk-email', $html);
    }

    public function test_child_attendance_lists_the_month_by_room_with_absences_and_hours(): void
    {
        $ava = $this->child('Ava', 'Cruz', 'Toddler');
        $mia = $this->child('Mia', 'Alvarez', 'Infant');
        ClosureDay::create(['closed_on' => '2026-10-12', 'reason' => 'Columbus Day']);

        $this->sign($ava, '2026-10-13', '07:50', '17:20');
        $this->sign($ava, '2026-10-14', '08:00', '16:00');
        $this->sign($mia, '2026-10-13', '07:30', '17:00');
        // Ava was expected on Oct 5 and never came: absent.

        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'child', 'month' => '2026-10']))->assertOk()->getContent();

        $this->assertStringContainsString('Child attendance · October 2026 · all rooms', $html);
        $this->assertStringContainsString('Cruz, Ava', $html);
        $this->assertStringContainsString('7:50a', $html);
        $this->assertStringContainsString('5:20p', $html);
        $this->assertStringContainsString('17h 30m', $html);
        $this->assertStringContainsString('3 days present', $html);

        $csv = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'child', 'month' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('Toddler,Cruz,Ava,10/13/2026,Present,7:50a,5:20p,9.50', $csv);
        $this->assertStringContainsString('Toddler,Cruz,Ava,10/5/2026,Absent,,,', $csv);
        $this->assertStringNotContainsString('10/12/2026', $csv);

        // One room, as ticks.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'child', 'month' => '2026-10', 'room' => 'Infant', 'mode' => 'marks']))->assertOk()->getContent();
        $this->assertStringContainsString('Alvarez, Mia', $html);
        $this->assertStringNotContainsString('Cruz, Ava', $html);
        $this->assertStringContainsString('✓', $html);
    }

    public function test_staff_attendance_reads_the_clock_with_breaks_late_and_edits(): void
    {
        $clock = app(TimeClock::class);
        $punch = fn (User $u, string $at, string $type) => $clock->punch($u, $type, Carbon::parse($at));

        StaffShift::create(['week_start' => '2026-10-05', 'user_id' => $this->teacher->id, 'shift_date' => '2026-10-06', 'day' => 'TUE', 'starts_at' => 8 * 60, 'ends_at' => 16 * 60, 'classroom' => 'Toddler', 'role' => StaffShift::ROLE_STAFF]);

        $punch($this->teacher, '2026-10-05 08:00', TimePunch::IN);
        $punch($this->teacher, '2026-10-05 12:00', TimePunch::LUNCH_START);
        $punch($this->teacher, '2026-10-05 12:30', TimePunch::LUNCH_END);
        $punch($this->teacher, '2026-10-05 16:00', TimePunch::OUT);
        // Tuesday: twelve minutes late against the shift.
        $punch($this->teacher, '2026-10-06 08:12', TimePunch::IN);
        $punch($this->teacher, '2026-10-06 16:00', TimePunch::OUT);
        // Wednesday: a clock-out the director typed in.
        $punch($this->teacher, '2026-10-07 08:00', TimePunch::IN);
        $clock->punch($this->teacher, TimePunch::OUT, Carbon::parse('2026-10-07 16:00'), TimePunch::SOURCE_SUPERVISOR, $this->admin, 'Forgot to punch');

        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'staff', 'range' => 'last']))->assertOk()->getContent();

        $this->assertStringContainsString('Staff attendance · Oct 5 – 9', $html);
        $this->assertStringContainsString('8:00a – 4:00p', $html);
        $this->assertStringContainsString('7h 30m · br 30m', $html);
        $this->assertStringContainsString('late 12m', $html);
        $this->assertStringContainsString('✎', $html);
        $this->assertStringContainsString('23h 18m', $html);

        $csv = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'staff', 'range' => 'last']))->assertOk()->getContent();
        $this->assertStringContainsString('Mary Jo,10/5/2026,Worked,8:00a,4:00p,30,7.50,,', $csv);
        $this->assertStringContainsString('Mary Jo,10/6/2026,Worked,8:12a,4:00p,0,7.80,12,', $csv);
        $this->assertStringContainsString('Mary Jo,10/7/2026,Worked,8:00a,4:00p,0,8.00,,yes', $csv);

        // Not the teacher's.
        $this->actingAs($this->teacher)->get(route('reports.export', ['report' => 'staff']))->assertForbidden();
    }

    public function test_the_upk_sheet_follows_the_paper_layout(): void
    {
        $jack = $this->child('Jack', 'Ibarra', 'UPK-4');
        $sofia = $this->child('Sofia', 'Rivera', 'UPK-4');
        $sofia->update(['status' => 'Inactive', 'withdrawn_on' => '2026-10-09']);
        $eden = $this->child('Eden', 'Walker', 'UPK-4', ['enrolled_on' => '2026-10-13', 'enrollment_note' => 'Transfer from Head Start']);
        ClosureDay::create(['closed_on' => '2026-10-12', 'reason' => 'Columbus Day']);

        $this->sign($jack, '2026-10-05', '08:00', '14:30');
        $this->sign($jack, '2026-10-06', '08:00', '14:30');
        DssDayMark::create(['child_id' => $jack->id, 'marked_on' => '2026-10-07', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->admin->id]);
        $this->sign($sofia, '2026-10-05', '08:00', '14:30');
        $this->sign($eden, '2026-10-13', '08:00', '14:30');

        $up = app(\App\Services\Reports::class)->upkSheet(2026, 10);

        // Five weeks of Mon–Fri from the week of the first open day; Oct 12 is blank.
        $this->assertCount(25, $up['cols']);
        $this->assertSame('2026-09-28', $up['cols'][0]['date']);
        $this->assertFalse($up['cols'][0]['in']);
        $this->assertSame('1', $up['cols'][3]['n']);
        $this->assertFalse(collect($up['cols'])->firstWhere('date', '2026-10-12')['in']);

        $rows = collect($up['rows'])->keyBy('name');
        $this->assertCount(25, $up['rows']);

        $jackRow = $rows['Ibarra, Jack'];
        $cells = collect($jackRow['cells'])->pluck('v')->all();
        $this->assertSame('✓', $cells[5]);   // Mon Oct 5
        $this->assertSame('✓', $cells[6]);
        $this->assertSame('A', $cells[7]);   // marked absent
        $this->assertSame('A', $cells[8]);   // expected, nothing recorded
        $this->assertSame('', $cells[15]);   // today, Oct 15: not yet
        // Oct 1, 2, 7, 8, 9, 13 and 14: seven, with the closure and today left out.
        $this->assertSame('7', $jackRow['tot']);

        $this->assertSame('1st day 13th', $rows['Walker, Eden']['note']);
        $this->assertStringContainsString('No longer in class · last day Oct 9', $rows['Rivera, Sofia']['note']);
        $this->assertSame('', collect($rows['Rivera, Sofia']['cells'])->pluck('v')->all()[13], 'a withdrawn child has no mark after their last day');

        // Head count along the bottom: two present on Oct 5.
        $this->assertSame('2', $up['grand'][5]['v']);

        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'upk', 'month' => '2026-10', 'room' => 'UPK-4']))->assertOk()->getContent();
        $this->assertStringContainsString('Attendance Sheet', $html);
        $this->assertStringContainsString('Grand Total', $html);
        $this->assertStringContainsString('data-upk-email', $html);

        $pdf = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'upk', 'month' => '2026-10', 'room' => 'UPK-4', 'format' => 'pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('UPK4-attendance-October-2026.pdf', $pdf->headers->get('Content-Disposition'));
    }

    public function test_a_day_pressed_on_the_sheet_cycles_tick_absent_blank_and_writes_the_register(): void
    {
        $jack = $this->child('Jack', 'Ibarra', 'UPK-4', ['drop_off_time' => '08:00', 'pick_up_time' => '14:30']);
        $this->sign($jack, '2026-10-05', '08:00', '14:30');

        $cell = fn () => collect(app(\App\Services\Reports::class)->upkSheet(2026, 10)['rows'])->firstWhere('name', 'Ibarra, Jack');
        $press = fn (string $state) => $this->actingAs($this->admin)->postJson(route('reports.sheet.cell'), ['child_id' => $jack->id, 'date' => '2026-10-05', 'state' => $state, 'month' => '2026-10', 'room' => 'UPK-4']);

        // The page marks the day's cell as live, with the child and the date on it.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'upk', 'month' => '2026-10', 'room' => 'UPK-4']))->assertOk()->getContent();
        $this->assertStringContainsString('data-cell="'.$jack->id.'|2026-10-05"', $html);
        $this->assertStringNotContainsString('data-cell="'.$jack->id.'|2026-10-15"', $html, 'today is not pressable');
        $this->assertStringContainsString('data-tot="'.$jack->id.'"', $html);

        // ✓ → A: the sign-in goes, an Absent mark is written, the totals come back.
        $json = $press('abs')->assertOk()->json();
        $this->assertSame('A', $cell()['cells'][5]['v']);
        // The total counts every A on the row — the marked day and the booked days nothing was recorded for.
        $absences = fn () => (string) collect($cell()['cells'])->where('v', 'A')->count();
        $this->assertSame($absences(), $cell()['tot']);
        $this->assertSame(DssDayMark::ABSENT, DssDayMark::where('child_id', $jack->id)->whereDate('marked_on', '2026-10-05')->sole()->mark);
        $this->assertSame(0, Attendance::where('child_id', $jack->id)->count());
        $row = collect($json['rows'])->firstWhere('child_id', $jack->id);
        $this->assertSame(['A', $absences(), '0'], [$row['cells'][5], $row['tot'], $json['grand'][5]]);

        // A → blank: not scheduled that day.
        $press('none')->assertOk();
        $this->assertSame(['', $absences()], [$cell()['cells'][5]['v'], $cell()['tot']]);
        $this->assertSame(DssDayMark::NONE, DssDayMark::where('child_id', $jack->id)->whereDate('marked_on', '2026-10-05')->sole()->mark);

        // blank → ✓: present at the agreed times, on the register as the DSS page would put it.
        $json = $press('pres')->assertOk()->json();
        $this->assertSame('✓', $cell()['cells'][5]['v']);
        $row = Attendance::where('child_id', $jack->id)->whereDate('attendance_date', '2026-10-05')->sole();
        $this->assertSame(['8:00 AM', '2:30 PM'], [$row->signed_in_at->timezone(config('app.timezone'))->format('g:i A'), $row->signed_out_at->timezone(config('app.timezone'))->format('g:i A')]);
        $this->assertSame('1', $json['grand'][5]);
        $this->assertSame(0, DssDayMark::where('child_id', $jack->id)->count());

        // Today and tomorrow are refused; a teacher is refused.
        $this->actingAs($this->admin)->postJson(route('reports.sheet.cell'), ['child_id' => $jack->id, 'date' => '2026-10-15', 'state' => 'abs'])->assertStatus(422);
        $this->actingAs($this->teacher)->postJson(route('reports.sheet.cell'), ['child_id' => $jack->id, 'date' => '2026-10-05', 'state' => 'abs'])->assertForbidden();
    }

    public function test_the_sheet_is_drawn_for_any_room_and_the_district_email_stays_with_upk4(): void
    {
        $jack = $this->child('Jack', 'Ibarra', 'UPK-4');
        $lila = $this->child('Lila', 'Moreno', 'Toddler');
        $this->sign($jack, '2026-10-05', '08:00', '14:30');
        $this->sign($lila, '2026-10-05', '07:45', '16:10');
        $this->sign($lila, '2026-10-06', '07:50', '16:00');

        // Toddler's sheet: Lila alone, the room as the Grade, the room in the file's name.
        $up = app(\App\Services\Reports::class)->upkSheet(2026, 10, 'Toddler');
        $this->assertSame(['Toddler', 'Toddler', 'Toddler-attendance-October-2026', 1], [$up['room'], $up['grade'], $up['file'], $up['children']]);
        $this->assertSame('Moreno, Lila', $up['rows'][0]['name']);
        $this->assertSame('', $up['rows'][1]['name']);
        $this->assertSame('1', $up['grand'][5]['v']);

        // Asked for by room on the page: the chips, the Grade box, no email panel; the PDF link carries the room.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'upk', 'month' => '2026-10', 'room' => 'Toddler']))->assertOk()->getContent();
        $this->assertStringContainsString('data-sheet-rooms', $html);
        // Each chip and each month arrow points at its own room or month, not the page's
        // (the links kept the page's values until 27 Oct 2026, so nothing was clickable).
        $this->assertStringContainsString(e(route('reports.index', ['room' => 'Infant', 'report' => 'upk', 'month' => '2026-10'])), $html);
        $this->assertStringContainsString(e(route('reports.index', ['month' => '2026-09', 'report' => 'upk', 'room' => 'Toddler'])), $html);
        $this->assertStringContainsString('data-month-prev', $html);
        $this->assertStringContainsString('data-sheet-grade>Toddler<', $html);
        $this->assertStringContainsString('Moreno, Lila', $html);
        $this->assertStringNotContainsString('Ibarra, Jack', $html);
        $this->assertStringNotContainsString('data-upk-email', $html);
        $this->assertStringContainsString('room=Toddler&amp;format=pdf', $html);

        // With no room, or a made-up one, the sheet opens on Infant, the first room on the plan.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'upk', 'month' => '2026-10', 'room' => 'Gym']))->assertOk()->getContent();
        $this->assertStringContainsString('data-sheet-grade>Infant<', $html);
        $this->assertStringNotContainsString('Ibarra, Jack', $html);
        $this->assertStringNotContainsString('data-upk-email', $html);

        // UPK-4 asked for by name: the district's sheet, email panel and all.
        $html = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'upk', 'month' => '2026-10', 'room' => 'UPK-4']))->assertOk()->getContent();
        $this->assertStringContainsString('data-sheet-grade>UPK4<', $html);
        $this->assertStringContainsString('Ibarra, Jack', $html);
        $this->assertStringContainsString('data-upk-email', $html);

        // The file follows the room too.
        $csv = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'upk', 'month' => '2026-10', 'room' => 'Toddler']))->assertOk();
        $this->assertStringContainsString('Toddler-attendance-October-2026.csv', $csv->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Moreno, Lila', $csv->getContent());
        $pdf = $this->actingAs($this->admin)->get(route('reports.export', ['report' => 'upk', 'month' => '2026-10', 'room' => 'Toddler', 'format' => 'pdf']))->assertOk();
        $this->assertStringContainsString('Toddler-attendance-October-2026.pdf', $pdf->headers->get('Content-Disposition'));
    }

    public function test_the_monthly_email_is_saved_sent_and_logged(): void
    {
        Mail::fake();
        $this->child('Jack', 'Ibarra', 'UPK-4');
        $this->sign(Child::first(), '2026-09-14', '08:00', '14:30');

        $this->actingAs($this->admin)->postJson(route('reports.upk-email.save'), [
            'on' => true, 'to' => ['upk@district.example', 'Coordinator@District.example'], 'when' => 'weekday', 'time' => '06:00', 'csv' => true, 'hold' => false,
        ])->assertOk()->assertJsonPath('settings.to', ['upk@district.example', 'coordinator@district.example'])->assertJsonPath('settings.when', 'weekday');

        $this->assertSame('1', Setting::get('upk_email.on'));

        $this->actingAs($this->admin)->postJson(route('reports.upk-email.save'), ['on' => true, 'to' => ['not an email'], 'when' => 'first', 'time' => '07:00', 'csv' => false, 'hold' => true])->assertStatus(422);

        // A test goes to the director alone.
        $this->actingAs($this->admin)->postJson(route('reports.upk-email.test'))->assertOk()->assertJsonPath('flash', 'Test sent to ray@example.com only.');
        Mail::assertSent(UpkSheetMail::class, fn (UpkSheetMail $m) => $m->hasTo('ray@example.com') && ! $m->hasTo('upk@district.example') && $m->subjectLine === 'UPK-4 attendance — September 2026 — Little Angels');

        // Send now goes to the list and is written down.
        $json = $this->actingAs($this->admin)->postJson(route('reports.upk-email.send'))->assertOk()->json();
        Mail::assertSent(UpkSheetMail::class, fn (UpkSheetMail $m) => $m->hasTo('upk@district.example') && $m->hasTo('coordinator@district.example') && $m->csv !== null);
        $this->assertSame('Delivered', $json['settings']['log'][0]['st']);
        $this->assertStringContainsString('September · 2 people', $json['settings']['log'][0]['what']);
    }

    public function test_the_scheduled_send_waits_for_the_director_when_sign_ins_are_missing(): void
    {
        Mail::fake();
        $this->child('Jack', 'Ibarra', 'UPK-4');
        $this->sign(Child::first(), '2026-09-14', '08:00', '14:30');
        // Every other September day is expected and empty: missing sign-ins.

        $email = app(UpkEmail::class);
        $email->save(['on' => true, 'to' => ['upk@district.example'], 'when' => 'first', 'time' => '07:00', 'csv' => false, 'hold' => true]);

        // Not the day.
        $this->assertNull($email->runScheduled(Carbon::parse('2026-10-15')));

        // The day, with gaps: the director hears first, the district does not.
        $this->assertSame('held', $email->runScheduled(Carbon::parse('2026-10-01')));
        Mail::assertSent(UpkSheetMail::class, fn (UpkSheetMail $m) => $m->hasTo('ray@example.com') && str_starts_with($m->subjectLine, 'Check: UPK-4 attendance for September'));
        Mail::assertNotSent(UpkSheetMail::class, fn (UpkSheetMail $m) => $m->hasTo('upk@district.example'));
        $this->assertSame('Held', $email->settings()['log'][0]['st']);

        // Without the hold, it goes.
        $email->save(['on' => true, 'to' => ['upk@district.example'], 'when' => 'first', 'time' => '07:00', 'csv' => false, 'hold' => false]);
        $this->assertSame('sent', $email->runScheduled(Carbon::parse('2026-10-01')));
        Mail::assertSent(UpkSheetMail::class, fn (UpkSheetMail $m) => $m->hasTo('upk@district.example'));

        // First weekday: Nov 1 2026 is a Sunday, so Monday Nov 2 is the day.
        $email->save(['on' => true, 'to' => ['upk@district.example'], 'when' => 'weekday', 'time' => '07:00', 'csv' => false, 'hold' => false]);
        $this->assertNull($email->runScheduled(Carbon::parse('2026-11-01')));
        $this->assertSame('sent', $email->runScheduled(Carbon::parse('2026-11-02')));
    }

    private function child(string $first, string $last, string $room, array $extra = []): Child
    {
        return Child::create(['lan' => (string) (1000 + Child::count()), 'first_name' => $first, 'last_name' => $last, 'status' => 'Active', 'classroom' => $room, 'classroom_override' => $room, 'schedule_days' => [1, 2, 3, 4, 5]] + $extra);
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
