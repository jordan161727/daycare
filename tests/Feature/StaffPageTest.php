<?php

namespace Tests\Feature;

use App\Models\StaffRule;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Staff page as Phase 5 of docs/work.md draws it: the team down the left
 * with a dot for where each person stands, and one person on the right under
 * Details, Rules, Attendance and Time off.
 *
 * The kiosks are not on this page and not on the menu. What the page decides
 * reaches the clock on the wall through the data — the last test here is
 * that it does.
 */
class StaffPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $zameera;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday at ten in the morning.
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator', 'must_change_password' => false]);
        $this->zameera = User::factory()->create([
            'role' => 'teacher', 'name' => 'Zameera Khan', 'classroom' => 'Transition', 'classrooms' => ['Transition'],
            'employment' => 'FT', 'job_role' => 'Lead Teacher', 'must_change_password' => false,
        ]);
    }

    /* ---- the page ---- */

    public function test_the_page_lists_the_team_with_a_dot_each_and_opens_the_first_person(): void
    {
        $lana = User::factory()->create(['role' => 'teacher', 'name' => 'Lana Perez', 'classroom' => 'PreK', 'employment' => 'FT']);
        $cook = User::factory()->create(['role' => 'teacher', 'name' => 'Sinyoung Lee', 'job_role' => 'Cook']);

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->getContent();

        // Both teachers on the list, the cook under "Not on the schedule".
        $this->assertStringContainsString('data-staff-row="'.$lana->id.'"', $html);
        $this->assertStringContainsString('data-staff-row="'.$this->zameera->id.'"', $html);
        $this->assertStringNotContainsString('data-staff-row="'.$cook->id.'"', $html);
        $this->assertStringContainsString('Not on the schedule', $html);
        $this->assertStringContainsString('Sinyoung Lee · Cook', $html);
        $this->assertStringContainsString('Administrator · Admin', $html);

        // The first teacher is open, on Details, with the four tabs.
        $this->assertStringContainsString('data-staff-record="'.$lana->id.'"', $html);
        $this->assertStringContainsString('Rules · 0', $html);
        $this->assertStringContainsString('Time off · 0', $html);
        $this->assertStringContainsString('data-details-form', $html);
    }

    public function test_the_dot_says_in_late_due_or_off_from_the_clock_and_the_shift(): void
    {
        $late = User::factory()->create(['role' => 'teacher', 'name' => 'Sultana Ahmed', 'classroom' => 'Toddler', 'employment' => 'PT_FLEX']);
        $due = User::factory()->create(['role' => 'teacher', 'name' => 'Pat Lee', 'classroom' => 'PreK', 'employment' => 'PT']);

        // Zameera clocked in at 8:58; Sultana was due at 9:30 and is not in; Pat is due at noon.
        $this->shift($this->zameera, '2026-10-15', 540, 1080);
        $this->shift($late, '2026-10-15', 570, 1080);
        $this->shift($due, '2026-10-15', 720, 1080);
        $this->punch($this->zameera, '2026-10-15 08:58', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.show', $this->zameera))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-staff-row="'.$this->zameera->id.'".*?data-status-dot="in"/s', $html);
        $this->assertMatchesRegularExpression('/data-staff-row="'.$late->id.'".*?data-status-dot="late"/s', $html);
        $this->assertMatchesRegularExpression('/data-staff-row="'.$due->id.'".*?data-status-dot="due"/s', $html);
        $this->assertStringContainsString('In since 8:58 AM', $html);
    }

    public function test_a_teacher_may_not_open_it_and_the_kiosks_link_goes_to_whos_where(): void
    {
        $this->actingAs($this->zameera)->get(route('staff.index'))->assertForbidden();

        $html = $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('staff.index'), $html);
        // Kiosks is on the menu since Ray asked (28 Oct 2026), but it opens
        // Who's where: a desk browser is not a paired kiosk, so the menu
        // never links straight to the clock screen.
        $this->assertStringContainsString('>Kiosks<', $html);
        $this->assertStringContainsString('href="'.route('staff.presence').'" data-kiosks-link', $html);
        $this->assertStringNotContainsString(route('clock.kiosk'), $html);
        $this->assertStringNotContainsString('MOCKUP', $html);
    }

    /* ---- Details ---- */

    public function test_details_save_and_the_home_room_becomes_the_first_assigned_classroom(): void
    {
        // Pay is the director's to change (User::canSeePay).
        $this->admin->update(['job_role' => 'Director']);

        $this->actingAs($this->admin)
            ->put(route('staff.update', $this->zameera), [
                'name' => 'Zameera Khan', 'email' => $this->zameera->email, 'legal_name' => 'Zameera A. Khan',
                'phone' => '555-0113', 'emergency_contact' => 'Omar · husband', 'emergency_phone' => '555-0199',
                'transport' => 'Own car', 'employment' => 'PT_FLEX', 'job_role' => 'Assistant', 'classroom' => 'Toddler',
                'start_date' => '2025-03-03', 'aspire_id' => 'A123', 'evaluation_score' => '4.5',
                'pay_rate' => '18.50', 'direct_deposit' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('staff.show', [$this->zameera, 'tab' => 'details']));

        $this->zameera->refresh();
        $this->assertSame('Zameera A. Khan', $this->zameera->legal_name);
        $this->assertSame('PT_FLEX', $this->zameera->employment);
        $this->assertSame('PT flex', $this->zameera->employmentLabel());
        $this->assertSame('Toddler', $this->zameera->classroom);
        $this->assertSame(['Toddler', 'Transition'], $this->zameera->assignedClassrooms());
        $this->assertSame('18.50', $this->zameera->pay_rate);
        $this->assertTrue($this->zameera->direct_deposit);

        $html = $this->actingAs($this->admin)->get(route('staff.show', $this->zameera))->assertOk()->getContent();
        $this->assertStringContainsString('Assistant · PT flex · Toddler', $html);
        // Pay is there for the director, behind Show.
        $this->assertStringContainsString('data-pay', $html);
        $this->assertStringContainsString('Director only', $html);
    }

    public function test_the_time_clock_card_is_on_the_details_tab(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.show', $this->zameera))->assertOk()->getContent();

        $this->assertStringContainsString('data-time-clock', $html);
        $this->assertStringContainsString($this->zameera->staffId(), $html);
        $this->assertStringContainsString('Issue card', $html);
        $this->assertStringContainsString('Set PIN', $html);
        $this->assertStringContainsString(route('staff.card.issue', $this->zameera), $html);
        $this->assertStringContainsString(route('staff.card.pin', $this->zameera), $html);
        $this->assertStringContainsString('Send a new temporary password', $html);
    }

    /* ---- Rules ---- */

    public function test_rules_are_listed_as_must_and_prefer_and_a_new_one_posts_from_the_page(): void
    {
        $this->zameera->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD', 'source_note' => 'Keyholder']);
        $this->zameera->staffRules()->create(['rule_type' => 'ROOM_PREFERENCE', 'priority' => 'SOFT', 'value_text' => 'Transition']);

        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'rules']))->assertOk()->getContent();

        $this->assertStringContainsString('Rules · 2', $html);
        $this->assertStringContainsString('pill must', $html);
        $this->assertStringContainsString('pill pref', $html);
        $this->assertStringContainsString('May open the building at opening time', $html);
        $this->assertStringContainsString('Keyholder', $html);
        $this->assertStringContainsString('Prefers room: Transition', $html);
        $this->assertStringContainsString('data-rule-form', $html);

        $this->actingAs($this->admin)
            ->from(route('staff.show', [$this->zameera, 'tab' => 'rules']))
            ->post(route('teachers.rules.store', $this->zameera), [
                'rule_type' => 'AVAILABLE_AFTER', 'priority' => 'HARD', 'day' => 'TUE', 'time_1' => '12:00', 'source_note' => 'Class on Tuesday mornings',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('staff.show', [$this->zameera, 'tab' => 'rules']));

        $this->assertSame(3, $this->zameera->staffRules()->count());
    }

    public function test_with_no_rules_the_page_says_what_the_schedule_assumes(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'rules']))->assertOk()->getContent();

        $this->assertStringContainsString('data-no-rules', $html);
        $this->assertStringContainsString('No rules yet — the schedule treats Zameera as available', $html);
    }

    /* ---- Attendance ---- */

    public function test_attendance_reads_each_day_against_the_shift(): void
    {
        // Mon: on time. Tue: 22 min late. Wed: no clock-out. Thu (today): on shift. Fri: tomorrow.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'] as $date) {
            $this->shift($this->zameera, $date, 540, 1080);
        }
        $this->punch($this->zameera, '2026-10-12 08:57', TimePunch::IN);
        $this->punch($this->zameera, '2026-10-12 18:02', TimePunch::OUT);
        $this->punch($this->zameera, '2026-10-13 09:22', TimePunch::IN);
        $this->punch($this->zameera, '2026-10-13 18:00', TimePunch::OUT);
        $this->punch($this->zameera, '2026-10-14 09:01', TimePunch::IN);
        $this->punch($this->zameera, '2026-10-15 08:59', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'attendance', 'range' => 'this']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-12".*?On time/s', $html);
        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-13".*?Late 22 min/s', $html);
        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-14".*?No clock-out.*?Fix →/s', $html);
        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-15".*?Today · on shift/s', $html);
        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-16".*?Tomorrow/s', $html);

        // The cards: three days with a punch on them (the broken one counts as
        // worked — somebody was here), one late, one to fix.
        $this->assertMatchesRegularExpression('/Days worked<\/div><div class="n">3</', $html);
        $this->assertMatchesRegularExpression('/Late arrivals<\/div><div class="n"[^>]*>1</', $html);
        $this->assertMatchesRegularExpression('/Punches to fix<\/div><div class="n"[^>]*>1</', $html);

        // And the same rows as a spreadsheet.
        $csv = $this->actingAs($this->admin)->get(route('staff.attendance.export', [$this->zameera, 'range' => 'this']))->assertOk()->getContent();
        $this->assertStringContainsString('Late 22 min', $csv);
        $this->assertStringContainsString('"No clock-out"', $csv);
    }

    public function test_a_day_with_a_shift_and_no_punch_at_all_is_a_forgotten_clock_in(): void
    {
        $this->shift($this->zameera, '2026-10-13', 540, 1080);

        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'attendance']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-13".*?Forgot to clock in/s', $html);
        $this->assertMatchesRegularExpression('/data-attendance-row="2026-10-12".*?Not scheduled.*?>Off</s', $html);
    }

    /* ---- Time off ---- */

    public function test_a_change_saves_shows_its_impact_and_folds_away_the_day_after(): void
    {
        // Scheduled 9 – 6 next Tuesday; out until 3 for an appointment.
        $this->shift($this->zameera, '2026-10-20', 540, 1080);

        $this->actingAs($this->admin)
            ->post(route('staff.time-off.store', $this->zameera), [
                'kind' => 'until', 'date' => '2026-10-20', 'from' => '15:00', 'reason' => 'appointment', 'paid' => '0', 'note' => 'Doctor, bringing a note',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('staff.show', [$this->zameera, 'tab' => 'off']));

        $entry = StaffTimeOff::sole();
        $this->assertSame(900, $entry->from_minute);
        $this->assertSame($this->admin->id, $entry->created_by);
        $this->assertSame('Out until 3:00 PM · available after', $entry->describe());

        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'off']))->assertOk()->getContent();

        $this->assertStringContainsString('Time off · 1', $html);
        $this->assertStringContainsString('data-time-off-entry="'.$entry->id.'"', $html);
        $this->assertStringContainsString('Appointment', $html);
        $this->assertStringContainsString('Unpaid', $html);
        $this->assertStringContainsString('Zameera’s only available 3:00–6:00 PM. Transition needs cover 9:00 AM–3:00 PM.', $html);
        $this->assertStringContainsString('Added by Administrator', $html);

        // The day after, it is in Past with a total, not under Coming up.
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
        $html = $this->actingAs($this->admin)->get(route('staff.show', [$this->zameera, 'tab' => 'off']))->assertOk()->getContent();

        $this->assertStringContainsString('data-nothing-coming', $html);
        $this->assertStringContainsString('Past · 2026', $html);
        $this->assertStringContainsString('1 appointment', $html);
        $this->assertStringContainsString('data-past-entry="'.$entry->id.'"', $html);
        $this->assertStringContainsString('Time off · 0', $html);
    }

    public function test_the_form_refuses_a_change_with_no_time_and_a_window_that_ends_first(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.time-off.store', $this->zameera), ['kind' => 'until', 'date' => '2026-10-20', 'reason' => 'sick'])
            ->assertSessionHasErrors('from');

        $this->actingAs($this->admin)
            ->post(route('staff.time-off.store', $this->zameera), ['kind' => 'window', 'date' => '2026-10-20', 'from' => '15:00', 'to' => '12:00', 'reason' => 'personal'])
            ->assertSessionHasErrors('to');

        $this->assertSame(0, StaffTimeOff::count());
    }

    public function test_an_entry_can_be_edited_and_removed(): void
    {
        $entry = $this->zameera->timeOff()->create(['kind' => 'all', 'date' => '2026-10-20', 'reason' => 'sick', 'paid' => true]);

        $this->actingAs($this->admin)
            ->put(route('staff.time-off.update', [$this->zameera, $entry]), ['kind' => 'all', 'date' => '2026-10-20', 'until' => '2026-10-21', 'reason' => 'vacation', 'paid' => '1'])
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('vacation', $entry->reason);
        $this->assertSame('2026-10-21', $entry->until->toDateString());
        $this->assertTrue($entry->coversDate('2026-10-21'));

        $this->actingAs($this->admin)->delete(route('staff.time-off.destroy', [$this->zameera, $entry]))->assertRedirect();
        $this->assertSame(0, StaffTimeOff::count());
    }

    public function test_the_schedule_treats_a_change_as_a_must_for_that_date_only(): void
    {
        $this->zameera->timeOff()->create(['kind' => 'until', 'date' => '2026-10-20', 'from_minute' => 900, 'reason' => 'appointment']);
        $this->zameera->timeOff()->create(['kind' => 'all', 'date' => '2026-10-21', 'reason' => 'sick']);
        // A Friday they never work, opened for once.
        $this->zameera->staffRules()->create(['rule_type' => 'UNAVAILABLE_DAY', 'priority' => 'HARD', 'day' => 'FRI']);
        $this->zameera->timeOff()->create(['kind' => 'extra', 'date' => '2026-10-23', 'from_minute' => 540, 'to_minute' => 900, 'reason' => 'extra']);
        $this->zameera->load('staffRules');

        $schedule = app(StaffSchedule::class);
        $schedule->generate('2026-10-19', $this->admin);

        $shifts = StaffShift::where('user_id', $this->zameera->id)->get()->keyBy(fn (StaffShift $s) => $s->shift_date->toDateString());

        $this->assertTrue($shifts->has('2026-10-19'), 'Monday is an ordinary day');
        $this->assertGreaterThanOrEqual(900, $shifts['2026-10-20']->starts_at, 'Tuesday starts no earlier than 3 PM');
        $this->assertFalse($shifts->has('2026-10-21'), 'Wednesday is out all day');
        $this->assertTrue($shifts->has('2026-10-23'), 'the extra Friday is worked');
        $this->assertSame(540, $shifts['2026-10-23']->starts_at);
        $this->assertLessThanOrEqual(900, $shifts['2026-10-23']->ends_at);
    }

    /* ---- the kiosk ---- */

    public function test_the_clock_on_the_wall_shows_the_persons_day_as_the_office_planned_it(): void
    {
        $this->shift($this->zameera, '2026-10-15', 540, 1080);
        $this->zameera->timeOff()->create(['kind' => 'leave', 'date' => '2026-10-15', 'to_minute' => 720, 'reason' => 'family']);

        $screen = app(\App\Services\StaffKiosk::class)->screenFor($this->zameera);

        $this->assertSame('Scheduled 9:00 AM – 6:00 PM · Transition — Leaving at 12:00 PM · family', $screen['today']);

        // And the kiosk page has the line to put it on.
        $this->assertStringContainsString('data-today-line', file_get_contents(resource_path('views/clock/kiosk.blade.php')));
    }

    public function test_the_staff_list_exports(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('staff.export'))->assertOk()->getContent();

        $this->assertStringContainsString('"Staff ID",Name', $csv);
        $this->assertStringContainsString('Zameera Khan', $csv);
        $this->assertStringContainsString('FT', $csv);
    }

    /* ---- helpers ---- */

    private function shift(User $user, string $date, int $start, int $end): StaffShift
    {
        $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();

        return StaffShift::create([
            'week_start' => $monday, 'user_id' => $user->id, 'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')), 'starts_at' => $start, 'ends_at' => $end,
            'classroom' => $user->classroom, 'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    private function punch(User $user, string $at, string $type): void
    {
        app(\App\Services\TimeClock::class)->punch($user, $type, Carbon::parse($at));
    }
}
