<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\DoorAttendance;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * QA probe — child record data sections (areas C, E, F, G, I, J-data).
 *
 * Each test asserts what the client's test plan expects. A failing test is a
 * reproduced finding; the failure message says what was observed instead.
 * Only TEST- records; sqlite :memory: via phpunit.xml. Today = 2026-10-29.
 */
class RecordDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $admin2;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00'));
        config()->set('daycare.dss.users', 'test-director@example.com');
        config()->set('daycare.kiosk.enabled', true);

        $this->admin = User::factory()->create(['name' => 'TEST-Director Dana', 'email' => 'test-director@example.com', 'role' => 'admin', 'must_change_password' => false]);
        $this->admin2 = User::factory()->create(['name' => 'TEST-Office Omar', 'email' => 'test-office@example.com', 'role' => 'admin', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['name' => 'TEST-Teacher Tina', 'role' => 'teacher', 'classroom' => 'Transition', 'must_change_password' => false]);
    }

    /* ------------------------------------------------------------ C: Overview */

    public function test_C1_overview_recalculates_hours_and_survives_reload(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.overview', $child), [
            'schedule_days' => ['', 1, 3, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
        ])->assertRedirect(route('children.show', $child))->assertSessionHasNoErrors();

        $this->assertSame(27.0, $child->fresh()->hoursPerWeek());

        foreach ([1, 2] as $reload) {
            $html = $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()->getContent();
            $this->assertStringContainsString('27 h a week', $html, "reload $reload");
            $this->assertStringContainsString('8:00 AM', $html);
        }
    }

    public function test_C2_overview_enforces_the_same_opening_hours_as_the_full_form(): void
    {
        $child = $this->child();

        // The full edit form refuses times outside 07:00–20:00; the overview card should too.
        $this->actingAs($this->admin)->put(route('children.overview', $child), [
            'schedule_days' => ['', 1], 'drop_off_time' => '05:00', 'pick_up_time' => '23:30',
        ])->assertSessionHasErrors();
    }

    public function test_C3_automatic_room_moves_on_the_birthday_via_nightly_command_and_writes_history(): void
    {
        // Born 2024-11-05: Transition today, Toddler (24 months) from 2026-11-05.
        $child = $this->child(['classroom_override' => null]);
        $this->assertSame('Transition', $child->fresh()->classroom);

        $this->travelTo(Carbon::parse('2026-11-04 23:59:00'));
        Artisan::call('classrooms:sync');
        $this->assertSame('Transition', $child->fresh()->classroom, 'not before the birthday');

        $this->travelTo(Carbon::parse('2026-11-05 00:05:00'));
        Artisan::call('classrooms:sync');
        $this->assertSame('Toddler', $child->fresh()->classroom, 'moved on the birthday');

        $events = $child->statusChanges()->where('event', 'room')->get()->map->summary()->all();
        $this->assertContains('Transition → Toddler', $events, 'Enrollment history should record the automatic move; got: '.json_encode($events));
    }

    public function test_C4_manual_move_stays_through_the_birthday_until_back_to_automatic(): void
    {
        $child = $this->child(['classroom_override' => null]);

        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'PreK', 'from' => '2026-10-29', 'why' => 'TEST ready early'])->assertRedirect();
        $this->assertSame('PreK', $child->fresh()->classroom);

        $this->travelTo(Carbon::parse('2026-11-05 00:05:00'));
        Artisan::call('classrooms:sync');
        $this->assertSame('PreK', $child->fresh()->classroom, 'override survives birthday sync');

        $this->actingAs($this->admin)->post(route('children.room', $child), ['automatic' => '1'])->assertRedirect();
        $this->assertSame('Toddler', $child->fresh()->classroom);
        $this->assertNull($child->fresh()->classroom_override);
    }

    public function test_C5_future_dated_move_lands_on_its_date_and_history_is_dated_then(): void
    {
        $child = $this->child(['classroom_override' => null]);

        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'Toddler', 'from' => '2026-11-02'])->assertRedirect();
        $this->assertSame('Transition', $child->fresh()->classroom);

        // Floor plan today still counts the child in Transition.
        $plan = $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()->viewData('plan');
        $this->assertSame(1, collect($plan['rooms'])->firstWhere('room', 'Transition')['enrolled']);

        $this->travelTo(Carbon::parse('2026-11-02 00:05:00'));
        Artisan::call('classrooms:sync');
        $this->assertSame('Toddler', $child->fresh()->classroom);

        $plan = $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()->viewData('plan');
        $this->assertSame(1, collect($plan['rooms'])->firstWhere('room', 'Toddler')['enrolled']);
        $this->assertSame(0, collect($plan['rooms'])->firstWhere('room', 'Transition')['enrolled']);

        $rows = $child->statusChanges()->where('event', 'room')->get()
            ->map(fn ($c) => $c->created_at->toDateString().' '.$c->summary())->all();
        $this->assertContains('2026-11-02 Transition → Toddler', $rows, 'history rows: '.json_encode($rows));
    }

    public function test_C6_room_history_line_reads_cleanly_for_a_future_dated_move(): void
    {
        $child = $this->child(['classroom_override' => null]);
        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'Toddler', 'from' => '2026-11-02'])->assertRedirect();

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/<span>Room history<\/span><span class="muted">(.*?)<\/span>/s', $html, $m);
        $line = trim(html_entity_decode($m[1] ?? ''));
        file_put_contents($this->scratch('C6-room-history.txt'), $line);
        $this->assertStringNotContainsString('Room set by hand', $line, 'Room history line: '.$line);
    }

    public function test_C7_backdated_move_history_is_dated_from_the_start_date(): void
    {
        $child = $this->child(['classroom_override' => null]);
        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'Toddler', 'from' => '2026-10-01'])->assertRedirect();

        $row = $child->statusChanges()->where('event', 'room')->first();
        $this->assertSame('Transition → Toddler', $row->summary());
        $this->assertSame('2026-10-01', $row->created_at->toDateString(), 'history line dated '.$row->created_at->toDateString().', note: '.$row->note);
    }

    public function test_C8_a_move_today_does_not_rewrite_earlier_weeks_on_attendance_reports_or_dss(): void
    {
        $child = $this->child(['classroom_override' => null, 'dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C', 'enrolled_on' => '2026-09-01']);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-20', 'signed_in_at' => '2026-10-20 12:00:00', 'signed_out_at' => '2026-10-20 20:00:00']);

        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'PreK', 'from' => '2026-10-29'])->assertRedirect();

        $observed = [];
        $children = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-10-20']))->assertOk()->viewData('children');
        $observed['director_attendance_week_of_oct_19'] = optional($children->firstWhere('id', $child->id))->classroom;

        $sheet = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-10-20']))->assertOk()->viewData('rows');
        $observed['teacher_attendance_oct_20'] = collect($sheet)->firstWhere('id', $child->id)['room'] ?? null;

        $blocks = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'week', 'date' => '2026-10-20']))->assertOk()->viewData('blocks');
        $observed['reports_week_of_oct_19'] = collect($blocks)->first(fn ($b) => collect($b['children'])->pluck('id')->contains($child->id))['room'] ?? null;

        $grid = $this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-09']))->assertOk()->json();
        $observed['dss_september'] = collect($grid['rows'])->firstWhere('id', $child->id)['room'] ?? null;

        file_put_contents($this->scratch('C8-past-room.json'), json_encode($observed, JSON_PRETTY_PRINT));

        $this->assertSame(['director_attendance_week_of_oct_19' => 'Transition', 'teacher_attendance_oct_20' => 'Transition', 'reports_week_of_oct_19' => 'Transition', 'dss_september' => 'Transition'], $observed);
    }

    public function test_C9_dss_numbers_validate_format(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->put(route('children.overview', $child), [
            'schedule_days' => ['', 1], 'dss_case_no' => 'hello world!!', 'dss_cin' => '12',
        ])->assertSessionHasErrors(['dss_case_no']);
    }

    public function test_C10_dss_numbers_save_and_show_on_the_dss_page(): void
    {
        $child = $this->child(['enrolled_on' => '2026-09-01']);

        $this->actingAs($this->admin)->put(route('children.overview', $child), [
            'schedule_days' => ['', 1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
            'dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('S1177706D')->assertSee('AB12345C');

        $grid = $this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-10']))->assertOk()->json();
        $row = collect($grid['rows'])->firstWhere('id', $child->id);
        file_put_contents($this->scratch('C10-dss-row.json'), json_encode(array_diff_key($row ?? [], ['cells' => 1, 'weeks' => 1]), JSON_PRETTY_PRINT));
        $this->assertNotNull($row);
        $this->assertStringContainsString('AB12345C', $row['cin']);
        $this->assertSame('8:00a–5:00p', $row['agreed']);

        // The case number itself is searchable on the DSS page.
        $grid = $this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-10', 'q' => 'S1177706D']))->json();
        $this->assertNotNull(collect($grid['rows'])->firstWhere('id', $child->id));
    }

    /* -------------------------------------------------------------- E: Health */

    public function test_E1_alerts_are_separate_entries_with_all_fields_and_can_be_removed(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.alerts.store', $child), ['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'severe', 'reaction' => 'TEST anaphylaxis', 'action' => 'TEST EpiPen, call 911', 'plan_on' => '2026-09-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('children.alerts.store', $child), ['type' => 'medical', 'text' => 'TEST asthma', 'severity' => 'mild'])->assertSessionHasNoErrors();
        // Empty "What" is rejected.
        $this->actingAs($this->admin)->post(route('children.alerts.store', $child), ['type' => 'allergy', 'text' => '   '])->assertSessionHasErrors('text');

        $alerts = $child->fresh()->alertList();
        $this->assertCount(2, $alerts);
        $this->assertSame(['severe', 'TEST anaphylaxis', 'TEST EpiPen, call 911', '2026-09-01'], [$alerts[0]['severity'], $alerts[0]['reaction'], $alerts[0]['action'], $alerts[0]['plan_on']]);

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('Care plan signed Sep 1, 2026')->assertSee('Do: TEST EpiPen, call 911');

        $this->actingAs($this->admin)->delete(route('children.alerts.destroy', [$child, 1]))->assertRedirect();
        $this->assertSame(['TEST peanuts'], array_column($child->fresh()->alertList(), 'text'));
    }

    public function test_E2_record_card_offers_an_edit_for_an_alert(): void
    {
        $child = $this->child(['alerts' => [['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'mild']]]);
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/data-alert="0".*?<\/form>/s', $html, $m);
        // Only Remove is offered on the Health card; editing means the full edit form.
        $this->assertMatchesRegularExpression('/>\s*Edit\s*</', $m[0] ?? '', 'alert row: '.strip_tags($m[0] ?? ''));
    }

    public function test_E3_a_severe_allergy_reaches_every_screen(): void
    {
        $child = $this->child(['alerts' => [['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'severe', 'action' => 'TEST EpiPen']]]);
        $parent = Person::create(['name' => 'TEST-Parent Pat', 'phone_last4' => '4417']);
        $parent->setPin('481902');
        $parent->forceFill(['phone_last4' => '4417'])->save();
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $parent->id, 'relationship' => 'Mother', 'can_pickup' => true, 'is_guardian' => true]);

        $seen = [];
        $record = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/data-record-header.*?<\/section>/s', $record, $m);
        $seen['record_header'] = str_contains($m[0] ?? '', 'TEST peanuts') && str_contains($m[0] ?? '', 'severe');

        $list = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        preg_match('/data-child-row="'.$child->lan.'".*?<\/tr>/s', $list, $m);
        $seen['children_list_alerts_column'] = str_contains($m[0] ?? '', 'TEST peanuts');
        $seen['children_list_shows_severity'] = str_contains(strtolower($m[0] ?? ''), 'severe');

        $rows = $this->actingAs($this->admin)->get(route('check-in.index'))->viewData('rows');
        $alerts = collect($rows)->firstWhere('id', $child->id)['alerts'] ?? [];
        $seen['check_in'] = collect($alerts)->contains(fn ($a) => $a['text'] === 'TEST peanuts' && $a['severity'] === 'severe');

        $director = $this->actingAs($this->admin)->get(route('attendance.index'))->getContent();
        $seen['director_attendance'] = str_contains($director, 'TEST peanuts');

        auth()->logout();
        $this->flushSession();
        $kiosk = $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertOk()->json();
        $seen['kiosk_unlock_json'] = str_contains(json_encode($kiosk), 'TEST peanuts');

        $tablet = $this->get(route('room.kiosk'))->getContent();
        $seen['room_tablet'] = str_contains($tablet, 'TEST peanuts');

        $seen['kitchen_list_route_exists'] = collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(fn ($r) => str_contains(strtolower($r->uri()), 'kitchen'));

        file_put_contents($this->scratch('E3-alert-reach.json'), json_encode($seen, JSON_PRETTY_PRINT)."\nkiosk payload: ".json_encode($kiosk));
        $this->assertSame(array_fill_keys(array_keys($seen), true), $seen);
    }

    public function test_E4_removing_an_alert_from_a_stale_page_removes_the_alert_that_was_clicked(): void
    {
        // Two alerts. Director A has the page open: row 0 = pollen, row 1 = peanuts.
        $child = $this->child(['alerts' => [
            ['type' => 'note', 'text' => 'TEST pollen (seasonal)'],
            ['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'severe'],
        ]]);
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('data-alert="0"', false);

        // Director B removes pollen first.
        $this->actingAs($this->admin2)->delete(route('children.alerts.destroy', [$child, 0]))->assertRedirect();

        // Director A, on the stale page, clicks Remove on the pollen row (index 0).
        $this->actingAs($this->admin)->delete(route('children.alerts.destroy', [$child, 0]));

        $this->assertContains('TEST peanuts', array_column($child->fresh()->alertList(), 'text'), 'severe allergy was deleted by a stale Remove');
    }

    public function test_E5_saving_a_stale_edit_form_keeps_an_alert_added_on_the_record_meanwhile(): void
    {
        $child = $this->child();

        // Director A opens the full edit form (no alerts yet).
        $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk();
        // Teacher/Director B adds a severe allergy from the record's Health card.
        $this->actingAs($this->admin2)->post(route('children.alerts.store', $child), ['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'severe'])->assertRedirect();
        // A saves the form as it was drawn: the hidden empty alerts input and a phone fix.
        $this->actingAs($this->admin)->put(route('children.update', $child), $this->staleForm($child, ['alerts' => '', 'telephone' => '716-555-0100']))->assertSessionHasNoErrors();

        $this->assertContains('TEST peanuts', array_column($child->fresh()->alertList(), 'text'), 'alerts after stale save: '.json_encode($child->fresh()->alerts));
    }

    public function test_E6_immunization_date_drives_the_chip_as_the_clock_moves(): void
    {
        $child = $this->child();
        $this->actingAs($this->admin)->put(route('children.health', $child), ['immunizations_due' => '2026-12-15'])->assertSessionHasNoErrors();

        $states = [];
        foreach (['2026-10-29', '2026-11-14', '2026-11-15', '2026-12-16'] as $day) {
            $this->travelTo(Carbon::parse($day.' 10:00'));
            $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
            $list = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
            preg_match('/data-child-row="'.$child->lan.'".*?<\/tr>/s', $list, $m);
            $states[$day] = [
                'record' => str_contains($html, '<span class="pill ok">Up to date</span>') ? 'Up to date' : (str_contains($html, '<span class="pill warn">Due</span>') ? 'Due' : '?'),
                'list_row_says_immunizations_due' => str_contains($m[0] ?? '', 'Immunizations due') || str_contains($m[0] ?? '', 'Imm'),
            ];
        }
        file_put_contents($this->scratch('E6-immunizations.json'), json_encode($states, JSON_PRETTY_PRINT));

        $this->assertSame('Up to date', $states['2026-10-29']['record']);
        $this->assertSame('Up to date', $states['2026-11-14']['record'], '31 days out');
        $this->assertSame('Due', $states['2026-11-15']['record'], '30 days out');
        $this->assertSame('Due', $states['2026-12-16']['record'], 'past the date');
        // Past the date it should read Overdue, not the same "Due" as 30 days ahead.
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertMatchesRegularExpression('/Overdue|overdue|Expired/', $html, 'past-due immunizations read the same as soon-due');
    }

    public function test_E7_medical_statement_status_follows_the_clock(): void
    {
        $child = $this->child();
        $this->actingAs($this->admin)->post(route('children.documents.store', $child), ['kind' => 'medical_statement', 'signed_on' => '2025-11-10'])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringContainsString('Expires Nov 10', $html);

        $this->travelTo(Carbon::parse('2026-11-11 10:00'));
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        $this->assertStringContainsString('Expired Nov 10', $html, 'Documents card');

        // The Health card's own line for the statement.
        preg_match('/<span>Medical statement<\/span><span>(.*?)<span>Medication/s', $html, $m);
        $line = trim(preg_replace('/\s+/', ' ', strip_tags($m[1] ?? '')));
        file_put_contents($this->scratch('E7-medical-line.txt'), $line);
        $this->assertStringNotContainsString('On file', $line, 'Health card line after expiry: '.$line);

        // And an expired statement should not count toward "Record x% complete".
        $before = $child->fresh()->load('documents')->completeness();
        $child->documents()->delete();
        $after = $child->fresh()->load('documents')->completeness();
        $this->assertSame($after, $before, 'expired statement still counts toward completeness: with expired='.$before.'% vs none='.$after.'%');
    }

    /* --------------------------------------------- F: Enrollment history & notes */

    public function test_F1_enrollment_note_save_update_clear_and_dss_page(): void
    {
        $child = $this->child(['dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C', 'enrolled_on' => '2026-09-01', 'schedule_days' => [1, 2, 3, 4, 5]]);

        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => 'TEST renewal sent'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('TEST renewal sent')->assertSee('Updated Oct 29');

        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => 'TEST renewal approved'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('TEST renewal approved')->assertSee('Updated Nov 3');
        preg_match('/data-enrollment-note.*?<\/form>/s', $this->actingAs($this->admin)->get(route('children.show', $child))->getContent(), $nb);
        $this->assertStringNotContainsString('TEST renewal sent', $nb[0] ?? '', 'old note still in the note box');

        $row = collect($this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-11']))->json('rows'))->firstWhere('id', $child->id);
        $this->assertSame('TEST renewal approved', $row['note'] ?? null);

        // Over-long is refused.
        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => str_repeat('x', 301)])->assertSessionHasErrors('enrollment_note');

        // Clear.
        $this->actingAs($this->admin)->put(route('children.enrollment-note', $child), ['enrollment_note' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSee('No note.');
        $row = collect($this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-11']))->json('rows'))->firstWhere('id', $child->id);
        $this->assertSame('', $row['note']);

        // History shows the two saves; clearing leaves no line.
        $this->assertSame(2, $child->statusChanges()->where('event', 'note')->count());
    }

    public function test_F2_history_lines_for_room_schedule_subsidy_and_status_changes(): void
    {
        $child = $this->child(['classroom_override' => null, 'schedule_days' => [1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00']);

        $this->actingAs($this->admin)->post(route('children.room', $child), ['room' => 'Toddler']);
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1, 2, 3], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00']);
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1, 2, 3], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00', 'dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C']);
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1, 2, 3], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00', 'dss_case_no' => 'S9999999Z', 'dss_cin' => 'AB12345C']);
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 1, 2, 3], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00', 'dss_case_no' => '', 'dss_cin' => '']);
        $this->actingAs($this->admin)->post(route('children.withdraw', $child), ['last_day' => '2026-10-29', 'reason' => 'Family choice', 'note' => 'TEST'])->assertSessionHasNoErrors();

        $rows = $child->statusChanges()->get()->map(fn ($c) => $c->created_at->toDateString().' ['.$c->event.'] '.$c->summary().($c->note ? ' — '.$c->note : '').($c->changedBy ? ' by '.$c->changedBy->name : ''))->all();
        file_put_contents($this->scratch('F2-history.txt'), implode("\n", $rows));

        $events = $child->statusChanges()->pluck('event')->all();
        $this->assertContains('room', $events);
        $this->assertContains('schedule', $events);
        $this->assertSame(3, $child->statusChanges()->where('event', 'subsidy')->count(), 'subsidy start, change and end should each leave a line; history: '.implode(' | ', $rows));
    }

    public function test_F3_teacher_comment_at_check_out_appears_in_notes_with_author_role_and_time(): void
    {
        $child = $this->child();

        $this->travelTo(Carbon::parse('2026-10-29 08:05:00'));
        $this->actingAs($this->teacher)->postJson(route('check-in.store'), ['child_id' => $child->id, 'session' => 'FULL', 'health_code' => 0])->assertOk();
        $door = DoorAttendance::firstOrFail();

        $this->travelTo(Carbon::parse('2026-10-29 16:40:00'));
        $this->actingAs($this->teacher)->postJson(route('check-in.out', $door), ['health_code' => 0, 'comment_body' => 'TEST ate all lunch, napped 2h', 'comment_tag' => 'health'])->assertOk();

        // And a director's check-out comment on another child.
        $other = $this->child(['lan' => '90002', 'first_name' => 'TEST-Milo']);
        $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $other->id, 'session' => 'FULL', 'health_code' => 0])->assertOk();
        $door2 = DoorAttendance::where('child_id', $other->id)->firstOrFail();
        $this->actingAs($this->admin)->postJson(route('check-in.out', $door2), ['health_code' => 0, 'comment_body' => 'TEST collected by grandma'])->assertOk();

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/data-section="notes".*?<\/section>/s', $html, $m);
        $notes = trim(preg_replace('/\s+/', ' ', strip_tags($m[0] ?? '')));
        $html2 = $this->actingAs($this->admin)->get(route('children.show', $other))->getContent();
        preg_match('/data-section="notes".*?<\/section>/s', $html2, $m2);
        $notes2 = trim(preg_replace('/\s+/', ' ', strip_tags($m2[0] ?? '')));
        file_put_contents($this->scratch('F3-notes.txt'), "teacher-written:\n$notes\n\ndirector-written:\n$notes2");

        $this->assertStringContainsString('TEST ate all lunch, napped 2h', $notes);
        $this->assertStringContainsString('TEST-Teacher Tina', $notes);
        $this->assertStringContainsString('TEST collected by grandma', $notes2);
        $this->assertStringNotContainsString('TEST-Director Dana · teacher', $notes2, 'director comment labelled as teacher');
        $this->assertMatchesRegularExpression('/4:40/', $notes, 'note shows no time of day');
    }

    public function test_F4_notes_reject_empty_filter_counts_and_older_than_sixty_are_reachable(): void
    {
        $child = $this->child();

        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => '   '])->assertSessionHasErrors('body');
        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => 'x', 'kind' => 'bogus'])->assertSessionHasErrors('kind');

        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => 'TEST health note', 'kind' => 'health'])->assertSessionHasNoErrors();
        for ($i = 1; $i <= 69; $i++) {
            $this->travel(1)->minutes();
            $child->notes()->create(['user_id' => $this->admin->id, 'kind' => 'office', 'body' => 'TEST office note '.$i]);
        }

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match_all('/class="fchip".*?>(All|Teacher|Office|Health) <span style="opacity: .7;">(\d+)<\/span>/', $html, $m, PREG_SET_ORDER);
        $counts = collect($m)->mapWithKeys(fn ($x) => [$x[1] => (int) $x[2]])->all();
        preg_match('/Show older \((\d+)\)/', $html, $older);
        file_put_contents($this->scratch('F4-notes-counts.json'), json_encode(['chips' => $counts, 'show_older' => $older[1] ?? null, 'oldest_visible' => str_contains($html, 'TEST health note')], JSON_PRETTY_PRINT));

        $this->assertSame(['All' => 70, 'Teacher' => 0, 'Office' => 69, 'Health' => 1], $counts);
        $this->assertStringContainsString('TEST health note', $html, 'the oldest note is not on the page even after Show older');
    }

    public function test_F5_newest_first_across_teacher_comments_and_office_notes_on_the_same_day(): void
    {
        $child = $this->child();
        $this->travelTo(Carbon::parse('2026-10-29 09:00:00'));
        $this->actingAs($this->admin)->post(route('children.notes.store', $child), ['body' => 'TEST morning office note']);

        $this->actingAs($this->teacher)->postJson(route('check-in.store'), ['child_id' => $child->id, 'session' => 'FULL', 'health_code' => 0])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-29 16:00:00'));
        $this->actingAs($this->teacher)->postJson(route('check-in.out', DoorAttendance::firstOrFail()), ['health_code' => 0, 'comment_body' => 'TEST afternoon teacher comment'])->assertOk();

        $this->actingAs($this->admin)->get(route('children.show', $child))->assertSeeInOrder(['TEST afternoon teacher comment', 'TEST morning office note']);
    }

    /* ------------------------------------------------------- G: attendance */

    public function test_G1_record_calendar_matches_teacher_attendance_for_a_door_check_in(): void
    {
        $child = $this->child(['schedule_days' => [1, 2, 3, 4, 5], 'enrolled_on' => '2026-09-01']);

        // Checked in and out at the door on Tue Oct 27.
        $this->travelTo(Carbon::parse('2026-10-27 08:10:00'));
        $this->actingAs($this->teacher)->postJson(route('check-in.store'), ['child_id' => $child->id, 'session' => 'FULL', 'health_code' => 0])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-27 16:30:00'));
        $this->actingAs($this->teacher)->postJson(route('check-in.out', DoorAttendance::firstOrFail()), ['health_code' => 0])->assertOk();

        $this->travelTo(Carbon::parse('2026-10-29 10:00:00'));
        $door = collect($this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-10-27']))->viewData('rows'))->firstWhere('id', $child->id);
        $doorSaysIn = ($door['byDay']['2026-10-27']['in'] ?? null) !== null;

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/<span class="cd c-(\w+)" title="Tue Oct 27/', $html, $m);
        $director = $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-10-27']))->viewData('attendanceMap');

        $observed = ['teacher_attendance_in' => $doorSaysIn, 'record_calendar_state' => $m[1] ?? null, 'director_attendance_has_row' => collect($director)->flatten()->isNotEmpty()];
        file_put_contents($this->scratch('G1-calendar.json'), json_encode($observed, JSON_PRETTY_PRINT));

        $this->assertTrue($doorSaysIn);
        $this->assertSame('in', $m[1] ?? null, 'record calendar for a day Teacher Attendance shows as in: '.json_encode($observed));
    }

    public function test_G2_open_register_opens_this_childs_attendance(): void
    {
        $child = $this->child();
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/<a href="([^"]+)"[^>]*>Open register/', $html, $m);
        file_put_contents($this->scratch('G2-open-register.txt'), $m[1] ?? '');
        $this->assertMatchesRegularExpression('/transition|room=|child|'.$child->lan.'/i', $m[1] ?? '', 'Open register href: '.($m[1] ?? 'none'));
    }

    /* ------------------------------------------- I: cross-screen consistency */

    public function test_I1_one_child_reads_the_same_everywhere(): void
    {
        $child = $this->child([
            'nickname' => 'Ellie', 'schedule_days' => [1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
            'dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C', 'enrolled_on' => '2026-09-01',
            'alerts' => [['type' => 'allergy', 'text' => 'TEST peanuts', 'severity' => 'severe']],
        ]);
        $parent = Person::create(['name' => 'TEST-Parent Pat', 'cell' => '7165550100', 'phone_last4' => '0100']);
        $parent->setPin('481902');
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $parent->id, 'relationship' => 'Mother', 'can_pickup' => true, 'is_guardian' => true, 'gets_texts' => true]);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-28', 'signed_in_at' => '2026-10-28 12:00:00', 'signed_out_at' => '2026-10-28 21:00:00']);

        $o = [];
        $list = $this->actingAs($this->admin)->get(route('children.index'))->getContent();
        preg_match('/data-child-row="'.$child->lan.'".*?<\/tr>/s', $list, $m);
        $o['children_list'] = trim(preg_replace('/\s+/', ' ', strip_tags($m[0] ?? '')));

        $rec = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/data-record-header.*?<\/section>/s', $rec, $m);
        $o['record_header'] = trim(preg_replace('/\s+/', ' ', strip_tags($m[0] ?? '')));

        $kids = $this->actingAs($this->admin)->get(route('attendance.index'))->viewData('children');
        $c = $kids->firstWhere('id', $child->id);
        $o['director_attendance'] = $c ? $c->first_name.' '.$c->last_name.' · '.$c->classroom.' · '.$c->scheduleLabel() : 'MISSING';

        $row = collect($this->actingAs($this->admin)->get(route('check-in.index'))->viewData('rows'))->firstWhere('id', $child->id);
        $o['teacher_attendance'] = $row ? $row['display'].' · '.$row['room'].' · '.$row['hours'].' · alerts '.json_encode($row['alerts']) : 'MISSING';

        $plan = $this->actingAs($this->admin)->get(route('floor-plan.index'))->viewData('plan');
        $o['floor_plan_transition_enrolled'] = collect($plan['rooms'])->firstWhere('room', 'Transition')['enrolled'];

        $msg = $this->actingAs($this->admin)->getJson(route('messages.search', ['q' => 'TEST-Ella']))->json('children');
        $o['messages_search'] = $msg;

        $blocks = $this->actingAs($this->admin)->get(route('reports.index', ['report' => 'week', 'date' => '2026-10-29']))->assertOk()->viewData('blocks');
        $o['reports_week_room_of_child'] = collect($blocks)->filter(fn ($b) => collect($b['children'])->pluck('id')->contains($child->id))->pluck('room')->all();

        $dss = collect($this->actingAs($this->admin)->getJson(route('dss.grid', ['month' => '2026-10']))->json('rows'))->firstWhere('id', $child->id);
        $o['dss'] = $dss ? array_intersect_key($dss, array_flip(['first', 'last', 'room', 'agreed', 'daysTxt', 'cin', 'note'])) : 'MISSING';

        auth()->logout();
        $this->flushSession();
        $o['kiosk'] = $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->json('children');

        file_put_contents($this->scratch('I1-cross-screen.json'), json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('Transition', $o['children_list']);
        $this->assertStringContainsString('Transition', $o['director_attendance']);
        $this->assertStringContainsString('Transition', $o['teacher_attendance']);
        $this->assertSame(1, $o['floor_plan_transition_enrolled']);
        $this->assertSame('Transition', $o['dss']['room']);
        $this->assertSame('Transition', $o['kiosk'][0]['room'] ?? null);
    }

    /* ------------------------------------------- J: persistence & concurrency */

    public function test_J1_data_survives_logout_and_login(): void
    {
        $child = $this->child();
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['schedule_days' => ['', 2, 4], 'drop_off_time' => '07:30', 'pick_up_time' => '15:30', 'nickname' => 'TEST-Elle'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('children.health', $child), ['doctor' => 'TEST Dr Who', 'immunizations_due' => '2027-03-01'])->assertSessionHasNoErrors();

        $this->post(route('logout'));
        $this->assertGuest();
        $this->post(route('login'), ['email' => 'test-director@example.com', 'password' => 'password']);
        $this->assertAuthenticated();

        $this->get(route('children.show', $child))->assertOk()->assertSee('16 h a week')->assertSee('TEST-Elle')->assertSee('TEST Dr Who')->assertSee('next due Mar 1, 2027');
    }

    public function test_J2_two_users_saving_the_overview_from_the_same_stale_form(): void
    {
        $child = $this->child(['schedule_days' => [1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00']);
        $stale = ['schedule_days' => ['', 1, 2, 3, 4, 5], 'drop_off_time' => '08:00', 'pick_up_time' => '17:00', 'dss_case_no' => '', 'dss_cin' => '', 'nickname' => ''];

        // A adds the subsidy numbers.
        $this->actingAs($this->admin)->put(route('children.overview', $child), ['dss_case_no' => 'S1177706D', 'dss_cin' => 'AB12345C'] + $stale)->assertSessionHasNoErrors();
        // B, page opened before A saved, only changes the nickname.
        $response = $this->actingAs($this->admin2)->put(route('children.overview', $child), ['nickname' => 'TEST-Elle'] + $stale);

        $child->refresh();
        file_put_contents($this->scratch('J2-stale-overview.json'), json_encode(['status' => $response->status(), 'errors' => session('errors')?->all(), 'dss_case_no' => $child->dss_case_no, 'nickname' => $child->nickname], JSON_PRETTY_PRINT));
        $this->assertSame('S1177706D', $child->dss_case_no, 'B\'s stale save wiped A\'s case number without warning');
    }

    public function test_J3_two_users_saving_the_full_edit_form_from_stale_copies(): void
    {
        $child = $this->child(['telephone' => '716-555-0000']);
        $this->actingAs($this->admin)->put(route('children.update', $child), $this->staleForm($child, ['telephone' => '716-555-1111']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin2)->put(route('children.update', $child), $this->staleForm($child, ['nickname' => 'TEST-Elle']))->assertSessionHasNoErrors();
        $this->assertSame('716-555-1111', $child->fresh()->telephone, 'second stale save reverted the first');
    }

    public function test_J4_last_change_by_names_the_right_user_and_time(): void
    {
        $child = $this->child();
        $this->travelTo(Carbon::parse('2026-10-29 14:37:00'));
        $this->actingAs($this->admin2)->put(route('children.health', $child), ['doctor' => 'TEST Dr A'])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/Created [^<]*last change[^<]*/', $html, $m);
        $line = trim(html_entity_decode($m[0] ?? ''));

        // A note by the teacher afterwards.
        $this->travelTo(Carbon::parse('2026-10-29 15:10:00'));
        $this->actingAs($this->teacher)->post(route('children.notes.store', $child), ['body' => 'TEST teacher note'])->assertSessionHasNoErrors();
        $html = $this->actingAs($this->admin)->get(route('children.show', $child))->getContent();
        preg_match('/Created [^<]*last change[^<]*/', $html, $m2);
        file_put_contents($this->scratch('J4-last-change.txt'), "after health save by Omar 14:37: $line\nafter teacher note 15:10: ".trim(html_entity_decode($m2[0] ?? '')));

        $this->assertStringContainsString('by TEST-Office Omar', $line);
        $this->assertMatchesRegularExpression('/2:37/', $line, 'footer has no time: '.$line);
    }

    /* ---------------------------------------------------------------- helpers */

    private function child(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '90001',
            'status' => 'Active',
            'first_name' => 'TEST-Ella',
            'last_name' => 'TEST-Foster',
            'birth_date' => '2024-11-05',
            'classroom' => 'Transition',
            'classroom_override' => null,
        ]);
    }

    /** The edit form as it was drawn before anybody else saved. */
    private function staleForm(Child $child, array $changes = []): array
    {
        return $changes + [
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birth_date?->toDateString() ?? '2024-11-05',
            'telephone' => $child->telephone,
            'nickname' => $child->nickname,
            'dss_case_no' => $child->dss_case_no,
            'dss_cin' => $child->dss_cin,
        ];
    }

    private function scratch(string $name): string
    {
        $dir = 'C:/Users/jorda/AppData/Local/Temp/claude/c--Users-jorda-daycare-attendance/096d9f8f-c923-4823-b98d-47b113f061fb/scratchpad/qa-children/record';
        @mkdir($dir, 0777, true);

        return $dir.'/'.$name;
    }
}
