<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The door screen.
 *
 * Separate from the week sheet, and the separation is the point: this one is
 * worked standing up with a child in front of you, so it takes today and
 * nothing else and it will not record an arrival without a health check. The
 * register keeps its own rules, and these tests check that it kept them.
 */
class CheckInScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 08:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim']);

        $this->child = Child::create([
            'lan' => '10064',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
        ]);
    }

    public function test_the_screen_lists_todays_children_with_the_codes(): void
    {
        $this->actingAs($this->admin)
            ->get(route('check-in.index'))
            ->assertOk()
            ->assertSee('Check in')
            ->assertSee('Adkins', false)
            // The codes reach the page as data for the dialog's dropdown; the
            // "Symptom codes" key that used to sit in the header came off at
            // the centre's request.
            ->assertDontSee('Symptom codes')
            ->assertSee('Vomiting');
    }

    public function test_checking_in_records_the_arrival_and_the_check_together(): void
    {
        $this->actingAs($this->admin)->postJson(route('check-in.store'), [
            'child_id' => $this->child->id,
            'session' => 'FULL',
            'health_code' => 0,
        ])->assertOk()->assertJsonPath('health_in', 0);

        $attendance = Attendance::firstOrFail();

        $this->assertNotNull($attendance->signed_in_at);
        $this->assertSame(0, $attendance->health_in_code);
        $this->assertDatabaseHas('health_audits', [
            'attendance_id' => $attendance->id,
            'direction' => HealthAudit::IN,
            'new_code' => 0,
        ]);
    }

    public function test_an_arrival_cannot_be_recorded_without_a_check(): void
    {
        // The whole reason this screen is separate: somebody is standing in
        // front of the child, which is the only circumstance in which a health
        // check means anything.
        $this->actingAs($this->admin)->postJson(route('check-in.store'), [
            'child_id' => $this->child->id,
            'session' => 'FULL',
        ])->assertStatus(422)->assertJsonValidationErrors('health_code');

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_refused_code_leaves_no_arrival_behind_it(): void
    {
        // Checked before the row is written, so a rejected code does not leave
        // a child recorded as present.
        $this->actingAs($this->admin)->postJson(route('check-in.store'), [
            'child_id' => $this->child->id,
            'session' => 'FULL',
            'health_code' => 11,
        ])->assertStatus(422)->assertJsonValidationErrors('health_note');

        $this->assertSame(0, Attendance::count());
    }

    public function test_checking_out_records_the_departure_and_its_check(): void
    {
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
            'health_in_code' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.out', $attendance), ['health_code' => 4])
            ->assertOk()
            ->assertJsonPath('health_out', 4);

        $attendance->refresh();

        $this->assertNotNull($attendance->signed_out_at);
        $this->assertSame(4, $attendance->health_out_code);
        $this->assertTrue($attendance->isSick());
    }

    public function test_a_departure_cannot_be_recorded_without_a_check(): void
    {
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.out', $attendance), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('health_code');

        $this->assertNull($attendance->fresh()->signed_out_at);
    }

    /**
     * The client's rule: every clock-in and clock-out of the day is kept.
     * A child clocked out and back in again has both the morning's departure
     * and the afternoon's arrival on the record, and the screen lists them.
     */
    public function test_a_child_clocked_out_can_be_clocked_in_again_and_every_entry_is_kept(): void
    {
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
            'signed_out_at' => Carbon::parse('2026-09-23 11:02'),
            'health_in_code' => 0,
            'health_out_code' => 0,
        ]);

        $this->travelTo(Carbon::parse('2026-09-23 13:05:00'));

        $this->actingAs($this->admin)
            ->postJson(route('check-in.back', $attendance), ['health_code' => 0])
            ->assertOk()
            ->assertJsonPath('in_at', '8:12a')
            ->assertJsonPath('out_at', null)
            ->assertJsonPath('trips', [['11:02a', '1:05p']]);

        $attendance->refresh();

        // Back on premises, first arrival untouched, the departure kept as a trip.
        $this->assertNull($attendance->signed_out_at);
        $this->assertSame('08:12', $attendance->signed_in_at->format('H:i'));
        $this->assertCount(1, $attendance->returns);
        $this->assertSame('11:02', $attendance->returns[0]->left_at->format('H:i'));
        $this->assertSame('13:05', $attendance->returns[0]->returned_at->format('H:i'));
        $this->assertSame($this->admin->id, $attendance->returns[0]->performed_by);

        // Out again at the end of the day, then back a third time: three entries.
        $this->travelTo(Carbon::parse('2026-09-23 15:10:00'));
        $this->actingAs($this->admin)
            ->postJson(route('check-in.out', $attendance), ['health_code' => 0])
            ->assertOk()
            ->assertJsonPath('out_at', '3:10p')
            ->assertJsonPath('trips', [['11:02a', '1:05p']]);

        $this->travelTo(Carbon::parse('2026-09-23 16:00:00'));
        $this->actingAs($this->admin)
            ->postJson(route('check-in.back', $attendance), ['health_code' => 0])
            ->assertOk()
            ->assertJsonPath('trips', [['11:02a', '1:05p'], ['3:10p', '4:00p']]);

        $this->assertCount(2, $attendance->fresh()->returns);

        // The screen hands the roster every entry, per session and per day.
        $this->actingAs($this->admin)
            ->get(route('check-in.index'))
            ->assertOk()
            ->assertViewHas('rows', function ($rows) {
                $row = $rows->firstWhere('id', $this->child->id);

                return $row['bySession']['2026-09-23']['FULL']['trips'] === [['11:02a', '1:05p'], ['3:10p', '4:00p']]
                    && $row['byDay']['2026-09-23']['trips'] === [['11:02a', '1:05p'], ['3:10p', '4:00p']]
                    && $row['byDay']['2026-09-23']['out'] === null;
            })
            ->assertSee('Clock in again')
            ->assertSee('entriesOf(', false);
    }

    /**
     * The client's School Age day, in four presses: check in, check out when
     * the school collects them, check in when the school brings them back,
     * check out when they go home. Two entries on the screen, both kept —
     * and a fifth press is a return on the second, not a loss of anything.
     */
    public function test_a_school_age_child_has_four_actions_a_day_and_the_screen_lists_them_all(): void
    {
        $this->child->update(['classroom' => 'School Age']);

        $press = function (string $at, string $route, array $body, $attendance = null) {
            $this->travelTo(Carbon::parse('2026-09-23 '.$at));

            return $this->actingAs($this->admin)
                ->postJson($attendance ? route($route, $attendance) : route($route), $body + ['health_code' => 0])
                ->assertOk();
        };

        // 1. Check in (AM). The dialog books the first row for a fresh day.
        $first = $press('07:30', 'check-in.store', ['child_id' => $this->child->id, 'session' => 'AM'])->json('attendance_id');
        // 2. Check out (AM): the school picks them up.
        $press('08:15', 'check-in.out', [], Attendance::find($first))->assertJsonPath('out_at', '8:15a');
        // 3. Check in (PM): the school brings them back — the second row.
        $second = $press('15:10', 'check-in.store', ['child_id' => $this->child->id, 'session' => 'PM'])->json('attendance_id');
        $this->assertNotSame($first, $second);
        // 4. Check out (PM): they go home.
        $press('17:30', 'check-in.out', [], Attendance::find($second))->assertJsonPath('out_at', '5:30p');

        $rows = Attendance::where('child_id', $this->child->id)->orderBy('signed_in_at')->get();
        $this->assertSame(['AM', 'PM'], $rows->pluck('session')->all());
        $this->assertSame(['07:30', '15:10'], $rows->map(fn ($row) => $row->signed_in_at->format('H:i'))->all());
        $this->assertSame(['08:15', '17:30'], $rows->map(fn ($row) => $row->signed_out_at->format('H:i'))->all());

        $this->actingAs($this->admin)
            ->get(route('check-in.index'))
            ->assertOk()
            ->assertViewHas('rows', function ($rows) {
                $day = $rows->firstWhere('id', $this->child->id);
                $am = $day['bySession']['2026-09-23']['AM'];
                $pm = $day['bySession']['2026-09-23']['PM'];

                // Two entries, in order, and the day reads first in, last out.
                return [$am['in'], $am['out'], $pm['in'], $pm['out']] === ['7:30a', '8:15a', '3:10p', '5:30p']
                    && $day['byDay']['2026-09-23']['in'] === '7:30a'
                    && $day['byDay']['2026-09-23']['out'] === '5:30p';
            });

        // 5. Back again after going home: a return on the second row.
        $press('18:00', 'check-in.back', [], Attendance::find($second))
            ->assertJsonPath('out_at', null)
            ->assertJsonPath('trips', [['5:30p', '6:00p']]);

        $this->assertCount(2, Attendance::where('child_id', $this->child->id)->get());
        $this->assertCount(1, Attendance::find($second)->returns);
    }

    public function test_clocking_in_again_needs_a_check_and_a_prior_clock_out(): void
    {
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
            'health_in_code' => 0,
        ]);

        // Still here: nothing to come back from.
        $this->actingAs($this->admin)
            ->postJson(route('check-in.back', $attendance), ['health_code' => 0])
            ->assertStatus(422);

        $attendance->forceFill(['signed_out_at' => Carbon::parse('2026-09-23 11:02')])->save();

        // No code, no return — and the departure still stands.
        $this->actingAs($this->admin)
            ->postJson(route('check-in.back', $attendance), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('health_code');

        $this->assertNotNull($attendance->fresh()->signed_out_at);
        $this->assertCount(0, $attendance->fresh()->returns);
    }

    public function test_the_screen_refuses_a_day_already_gone(): void
    {
        // Corrections belong on the week sheet, where they are written to
        // attendance_amendments.
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.out', $attendance), ['health_code' => 0])
            ->assertForbidden();

        $this->assertNull($attendance->fresh()->signed_out_at);
    }

    public function test_the_screen_opens_on_a_roster_of_cards(): void
    {
        /*
         * A card a child, worked from a door: their face, their room, whether
         * they are here. Room pills with who is in over who is enrolled, and
         * the day's totals beside. The four-line sheet is still here, a tab
         * away, drawn from the same rows — so nothing the sheet knows is lost
         * and nothing the roster shows can disagree with it.
         */
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        // Opens on today, as the roster. The sheet is still rendered and still
        // reachable by address, but there is no tab to it on the face of the
        // screen — the centre asked for today and nothing else here.
        $this->assertStringContainsString("view: 'today'", $html);
        $this->assertStringNotContainsString('role="tab"', $html);
        $this->assertStringContainsString("x-show=\"view === 'sheet'\"", $html);

        // The cards carry the child's own avatar and a First Last name. The
        // rows reach the page through @js, which writes every quote as
        // ", so that is the shape the key and its value arrive in.
        // The loop variable is `card`, deliberately: the dialog is opened by
        // writing the component's `child`, and a loop variable of the same
        // name would take the write instead. That was a real bug.
        $this->assertStringContainsString('x-for="card in cards"', $html);
        $this->assertStringContainsString('x-html="card.avatar"', $html);
        $this->assertStringNotContainsString('x-for="child in', $html);

        // Built from chr(92) rather than written as an escape: the escape
        // sequence itself has a habit of being unescaped by editors, and then
        // the assertion is looking for quotes that never appear in a page.
        $q = chr(92).'u0022';

        $this->assertStringContainsString($q.'display'.$q.':'.$q.'Maeve Adkins'.$q, $html);
        $this->assertStringContainsString($q.'avatar'.$q.':'.$q, $html);

        // The pills and the panel count off the rows, not a running total.
        $this->assertStringContainsString("tally('').in + '/' + tally('').total", $html);
        $this->assertStringContainsString('Today at a glance', $html);
        $this->assertStringContainsString('Across all classrooms', $html);

        // And the dialog drives the same two endpoints the sheet does.
        $this->assertStringContainsString("leaving ? '/check-in/' + day.id + '/out' : (returning ? '/check-in/' + day.id + '/back' : '/check-in')", $html);
        $this->assertStringContainsString('Defaults to 0 · Normal', $html);
    }

    /*
     * Edit mode is where the symptom codes are corrected, and a director can
     * choose the day — the way the register lets a week be chosen. A day
     * already gone opens in Edit, its checks are corrected through the health
     * endpoint (codes only; the times are the register's), and nobody else is
     * shown anything but today whatever the address says.
     */
    public function test_a_director_can_open_a_day_already_gone_to_edit_its_checks(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['date' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        // The roster is about that day, and it opens in Edit.
        $this->assertStringContainsString("day: '2026-09-21'", $html);
        $this->assertStringContainsString('editing: true,', $html);
        $this->assertStringContainsString('Monday, Sep 21 · editing', $html);

        // The day is chosen from a field that stops at today.
        $this->assertStringContainsString('type="date" value="2026-09-21" max="'.today()->toDateString().'"', $html);

        /*
         * And the day is edited through the register, not beside it: the
         * arrival through its sign-in, the times through its retime, the
         * removal through its remove — each writing attendance_amendments —
         * and the checks through the health endpoint. One record, one trail.
         */
        // @js writes the address single-quoted with its slashes escaped, so
        // the assertion is on the route's own path in that form.
        $this->assertStringContainsString("signIn: '", $html);
        $this->assertStringContainsString('attendance\/sign-in\'', $html);
        $this->assertStringContainsString('attendance\/sign-in\/retime\'', $html);
        $this->assertStringContainsString('attendance\/sign-in\/remove\'', $html);
        $this->assertStringContainsString("post(this.urls.signIn, {", $html);
        $this->assertStringContainsString("post(this.urls.retime, { ...base, ...times })", $html);
        $this->assertStringContainsString("window.postJson(this.urls.remove,", $html);
        $this->assertStringContainsString("post('/attendance/' + day.id + '/health', { direction, code, note:", $html);
        $this->assertStringContainsString('id="ci-in" type="time"', $html);
        $this->assertStringContainsString('id="ci-out" type="time"', $html);
        $this->assertStringContainsString('Not attending — remove this day', $html);
    }

    public function test_today_opens_live_with_the_day_field_hidden_until_edit(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString("day: '".today()->toDateString()."'", $html);
        $this->assertStringContainsString('editing: false,', $html);
        // The field is there for the director, behind the switch.
        $this->assertStringContainsString('x-show="editing" x-cloak class="flex items-center gap-1.5', $html);
    }

    public function test_a_day_still_to_come_is_shown_as_today(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['date' => today()->addDays(3)->toDateString()]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("day: '".today()->toDateString()."'", $html);
    }

    public function test_only_a_director_can_choose_the_day(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'PreK']);

        $html = $this->actingAs($teacher)
            ->get(route('check-in.index', ['date' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("day: '".today()->toDateString()."'", $html);
        $this->assertStringNotContainsString('type="date"', $html);
    }

    public function test_check_in_has_no_view_menu(): void
    {
        // The client's ask: this screen is the cards and nothing else. The
        // ⋯ menu with Card mode and Attendance mode is the register's own.
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['date' => '2026-09-21']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('aria-label="More"', $html);
        $this->assertStringNotContainsString('<span>Card mode</span>', $html);
        $this->assertStringNotContainsString('<span>Attendance mode</span>', $html);
    }

    public function test_a_search_can_be_cleared_in_one_press(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('x-show="search" x-cloak @click="search = \'\'; $refs.checkInSearch?.focus()"', $html);
        $this->assertStringContainsString('aria-label="Clear search">×</button>', $html);
        $this->assertStringContainsString('x-ref="checkInSearch"', $html);

        // A bare ×, no disc behind it; and Escape clears the box as well.
        $this->assertStringNotContainsString('rounded-full text-slate-400 transition hover:bg-slate-100', $html);
        $this->assertStringContainsString('x-model="search" @keydown.escape="search = \'\'"', $html);
    }

    public function test_the_sheet_is_still_a_tab_away(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['view' => 'sheet']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("view: 'sheet'", $html);
    }

    public function test_the_screen_is_a_grid_of_four_lines_a_child(): void
    {
        // In, the check taken then, out, the check taken then — the same
        // shape as the sheet it prints to, whichever span is on screen.
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('>IN</td>', $html);
        $this->assertStringContainsString('>OUT</td>', $html);
        $this->assertSame(2, substr_count($html, '>health</td>'));
    }

    public function test_a_past_day_is_locked_until_edit_is_switched_on(): void
    {
        /*
         * Live mode is the one somebody stands in front of all day, and in it
         * the other columns are context: a glance left to see whether this
         * child came yesterday. Edit is the deliberate second step before any
         * of them takes a press, because on a grid thirty wide the commonest
         * mistake is the column next to the one you meant.
         */
        Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
            'health_in_code' => 4,
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        // Monday's code is on the page, and every cell carries the day it is
        // about so a press can be checked against it.
        $this->assertStringContainsString("'2026-09-21'", $html);
        $this->assertStringContainsString('cell(', $html);

        // Open in Live only for today; a past day needs both the director and
        // the switch. The server decides it again either way.
        $this->assertStringContainsString(
            'return date === this.today || (this.canAmend && this.editing);',
            $html,
        );

        // A day that is not open reads, and does not offer a press.
        $this->assertStringContainsString('att-chip att-chip-locked', $html);
    }

    public function test_the_arrival_check_can_be_corrected_on_the_day(): void
    {
        // A second look, or a mis-tap. The change is audited like any other.
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
            'health_in_code' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.in', $attendance), ['health_code' => 4])
            ->assertOk()
            ->assertJsonPath('health_in', 4);

        $this->assertSame(4, $attendance->fresh()->health_in_code);
        $this->assertDatabaseHas('health_audits', [
            'attendance_id' => $attendance->id,
            'direction' => HealthAudit::IN,
            'old_code' => 0,
            'new_code' => 4,
        ]);
    }

    public function test_an_arrival_check_cannot_be_corrected_on_a_day_already_gone(): void
    {
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
            'health_in_code' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.in', $attendance), ['health_code' => 4])
            ->assertForbidden();

        $this->assertSame(0, $attendance->fresh()->health_in_code);
    }

    public function test_the_grid_speaks_one_clock(): void
    {
        // The cells show the app's short clock, so a reply answering in any
        // other format would put two kinds of time in one column.
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
            'health_in_code' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('check-in.out', $attendance), ['health_code' => 0])
            ->assertOk()
            ->assertJsonPath('in_at', '8:12a');
    }

    public function test_the_times_are_the_registers_own(): void
    {
        /*
         * One record, read by two screens.
         *
         * The grid does not keep a time of its own: an arrival signed in on
         * the attendance register is the same row the door screen shows, and a
         * retimed arrival shows the corrected hour here without anybody
         * touching this screen. A second copy would be a second answer to
         * "when did this child arrive", and the two would drift the first time
         * somebody corrected one of them.
         */
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 07:42'),
        ]);

        $this->assertStringContainsString(
            '7:42a',
            $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent(),
        );

        // The register corrects the hour; the door screen reads the correction.
        $attendance->forceFill(['signed_in_at' => Carbon::parse('2026-09-23 08:05')])->save();

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('8:05a', $html);
        $this->assertStringNotContainsString('7:42a', $html);
    }

    public function test_checking_in_here_writes_the_row_the_register_reads(): void
    {
        // The other direction: the door screen creates the arrival, and the
        // register shows it without a second record being made.
        $this->actingAs($this->admin)->postJson(route('check-in.store'), [
            'child_id' => $this->child->id,
            'session' => 'FULL',
            'health_code' => 0,
        ])->assertOk();

        $this->assertSame(1, Attendance::count());

        $attendance = Attendance::firstOrFail();

        $this->assertSame('2026-09-23', $attendance->attendance_date->toDateString());
        $this->assertNotNull($attendance->signed_in_at);
    }

    public function test_every_child_on_the_roll_is_drawn_the_same(): void
    {
        /*
         * Nothing on this screen keys off a child's registered days.
         *
         * The door takes whoever comes through it, and a child arriving on a
         * day they were not booked for is precisely the arrival somebody needs
         * to be able to record — a screen that hid them, or drew them
         * differently, would send that morning unrecorded.
         */
        $unscheduled = Child::create([
            'lan' => '10099',
            'first_name' => 'Joseph',
            'last_name' => 'Anderson',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
            // Booked for Mondays only; today is a Wednesday.
            'schedule_days' => [1],
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Anderson, Joseph', $html);

        // Drawn with the same four lines and the same cells as anybody else.
        $this->assertStringContainsString('cell('.$unscheduled->id.',', $html);
    }

    public function test_an_empty_cell_is_empty_rather_than_dotted(): void
    {
        // A dot standing in for an absence is a mark somebody reads as a
        // value. The dashed outline of the chip is what says "not recorded".
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("'·'", $html);
        $this->assertStringNotContainsString('>·<', $html);
    }

    public function test_edit_mode_is_offered_to_the_director_alone(): void
    {
        /*
         * A check nobody recorded at the time can be put right, but only by
         * somebody who can be asked about it afterwards. A teacher's screen
         * offers today and nothing else, which is also what the server
         * enforces — this only decides whether the switch is drawn.
         */
        $this->assertStringContainsString(
            'att-switch',
            $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent(),
        );

        $this->assertStringNotContainsString(
            'att-switch',
            $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent(),
        );
    }

    public function test_a_missing_check_on_a_past_day_can_be_added_by_the_director(): void
    {
        // The thing Edit exists for: an arrival that was recorded at the time
        // and a health check that was not.
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
        ]);

        $this->assertNull($attendance->health_in_code);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4])
            ->assertOk();

        $this->assertSame(4, $attendance->fresh()->health_in_code);

        // And it says who added it and when, like every other change.
        $this->assertDatabaseHas('health_audits', [
            'attendance_id' => $attendance->id,
            'direction' => HealthAudit::IN,
            'old_code' => null,
            'new_code' => 4,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_a_teacher_in_a_stale_tab_is_still_refused_a_past_day(): void
    {
        // The switch is not drawn for them, so this is only reachable from a
        // tab left open — which is exactly why it is checked on the server.
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
        ]);

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4])
            ->assertForbidden();

        $this->assertNull($attendance->fresh()->health_in_code);
    }

    public function test_editing_a_past_day_changes_the_code_and_not_the_hour(): void
    {
        /*
         * The times on a day already gone are the register's to correct,
         * because that is where a changed time is written to
         * attendance_amendments. Edit here adds the check that was missed, and
         * leaves the arrival exactly as it was recorded.
         */
        $attendance = Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
            'signed_out_at' => Carbon::parse('2026-09-21 17:30'),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'out', 'code' => 10])
            ->assertOk();

        $attendance->refresh();

        $this->assertSame(10, $attendance->health_out_code);
        $this->assertSame('08:12', $attendance->signed_in_at->format('H:i'));
        $this->assertSame('17:30', $attendance->signed_out_at->format('H:i'));
    }

    public function test_the_grid_can_be_narrowed_to_this_week(): void
    {
        // Thirty columns is a lot to carry on a screen somebody works standing
        // up, so the week is one press away.
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['span' => 'week']))
            ->assertOk()
            ->getContent();

        // 21 to 27 September 2026 is the week holding the 23rd.
        $this->assertStringContainsString("'2026-09-21'", $html);
        $this->assertStringContainsString("'2026-09-27'", $html);
        $this->assertStringNotContainsString("'2026-09-01'", $html);
        $this->assertStringNotContainsString("'2026-09-30'", $html);
    }

    public function test_the_week_is_what_it_opens_on(): void
    {
        /*
         * This screen is worked standing up at a door, and the columns either
         * side of today are the ones somebody glances at — did she come
         * yesterday, is he in tomorrow. A month is thirty-one columns to
         * scroll through before reaching the useful ones.
         */
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        // 21 to 27 September 2026 is the week holding the 23rd.
        $this->assertStringContainsString("'2026-09-21'", $html);
        $this->assertStringContainsString("'2026-09-27'", $html);
        $this->assertStringNotContainsString("'2026-09-01'", $html);
    }

    public function test_the_month_is_one_press_away(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['span' => 'month']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("'2026-09-01'", $html);
        $this->assertStringContainsString("'2026-09-30'", $html);
    }

    public function test_an_invented_span_falls_back_to_the_week(): void
    {
        // A bookmarked link from before a rename is better answered with a
        // grid than with an error, and the week is what the screen opens on.
        $html = $this->actingAs($this->admin)
            ->get(route('check-in.index', ['span' => 'fortnight']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("'2026-09-21'", $html);
        $this->assertStringNotContainsString("'2026-09-01'", $html);
    }

    public function test_every_day_column_is_the_same_width(): void
    {
        /*
         * Left to itself the table sizes each column to what is in it, so a day
         * holding 17:25 comes out wider than one holding nothing — and a grid
         * whose columns are different widths is one nobody can read down.
         */
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('table-fixed', $html);

        // No width on a day column: under table-fixed they share what is left
        // after the name and the label, so thirty-one of them fit the screen
        // as readily as seven and every one comes out the same.
        $this->assertStringContainsString('px-1 py-2 text-center font-semibold', $html);
        $this->assertStringNotContainsString('w-16 px-1 py-2', $html);
    }

    public function test_a_time_is_given_room_to_be_read(): void
    {
        /*
         * A day column has a width of its own, wide enough for the widest
         * thing it can hold — "12:28p".
         *
         * Sharing the width between thirty-one columns instead was tried and
         * reverted: it gave each of them about thirty pixels, the times ran
         * into one another, and a grid you cannot read is not a grid. So a
         * week fits any screen and a month scrolls sideways, which is what the
         * paper sheet does when you unfold it.
         */
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('att-grid-table w-max table-fixed', $html);
        $this->assertStringContainsString('att-day-col', $html);
        $this->assertStringNotContainsString('w-full table-fixed', $html);
    }

    public function test_the_grid_says_who_is_expected_today(): void
    {
        /*
         * An empty column tells you nothing on its own. With sixty on the roll
         * and a dozen booked on a given day, the useful question at half past
         * eight is "who is still to arrive" — which is only answerable if the
         * grid knows which empty cells are waiting for somebody.
         */
        $this->child->update(['schedule_days' => [1, 2, 3, 4, 5]]);

        $mondaysOnly = Child::create([
            'lan' => '10099',
            'first_name' => 'Joseph',
            'last_name' => 'Anderson',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
            'schedule_days' => [1],
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        // Today is a Wednesday. Maeve is booked every weekday, Joseph only on
        // Mondays, and the grid carries that for each of them.
        // The map itself is in the component's state, quote-escaped by @js.
        $this->assertStringContainsString('booked', $html);
        $this->assertStringContainsString('Anderson, Joseph', $html);

        // Booked reads as something outstanding; not booked reads as an offer.
        $this->assertStringContainsString('Booked in today', $html);
        $this->assertStringContainsString('Not booked today', $html);
    }

    public function test_a_child_who_was_not_booked_can_still_be_checked_in(): void
    {
        // A child arriving on a day they were not booked for is precisely the
        // arrival somebody needs to record. The cell is quieter, not absent.
        $this->child->update(['schedule_days' => [1]]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('att-mark-spare', $html);

        $this->actingAs($this->admin)->postJson(route('check-in.store'), [
            'child_id' => $this->child->id,
            'session' => 'FULL',
            'health_code' => 0,
        ])->assertOk();

        $this->assertSame(1, Attendance::count());
    }

    public function test_the_awaited_count_is_the_one_somebody_is_chasing(): void
    {
        /*
         * "Not in" counts the whole roll, most of whom were never coming today.
         * This counts the children who were booked and have not arrived, which
         * is the number a director acts on.
         */
        $this->child->update(['schedule_days' => [1, 2, 3, 4, 5]]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString('awaited', $html);
        $this->assertMatchesRegularExpression('/<b>1<\/b> awaited/', $html);

        // Once they are in, they are no longer awaited.
        Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 08:12'),
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<b>0<\/b> awaited/', $html);
    }

    public function test_a_teacher_can_work_the_door(): void
    {
        // The person who saw the child is the person who should write it down.
        $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk();
    }

    public function test_the_door_offers_the_months_export_live(): void
    {
        /*
         * The Export used to be an Edit-only button, and so out of reach of
         * the teacher standing at the door in Live. The file is the month as
         * the paper form — what the door hands to the office — and there is
         * nothing about it that needs Edit unlocked.
         */
        $html = $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent();

        $export = e(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026]));

        // Followed by a cache-busting timestamp, so the address is never the
        // same twice — see the never-cached middleware.
        $this->assertMatchesRegularExpression('/href="'.preg_quote($export, '/').'&amp;t=\d+"/', $html);
        $this->assertStringContainsString('>Export</a>', $html);
        $this->assertStringNotContainsString('x-show="editing" x-cloak href="'.$export, $html);
        $this->assertStringNotContainsString('Export month', $html);

        // The month follows the day being looked at.
        $html = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-08-12']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="'.preg_quote(e(route('attendance.month-sheet.export', ['month' => 8, 'year' => 2026])), '/').'&amp;t=\d+"/', $html);
    }

    public function test_the_week_sheet_was_left_alone(): void
    {
        /*
         * The register is a different screen with different rules, and it was
         * deliberately put back to what it was. If a chip or a picker turns up
         * on it again, that is a regression rather than a feature.
         */
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-health', $html);
        $this->assertStringNotContainsString('chipHtml', $html);
        $this->assertStringNotContainsString('sickToday', $html);
    }

    /**
     * The code a teacher saved is read back, not just stored: the dialog's
     * entries list carries the arrival and leaving checks beside their times
     * as small numbered chips, the label on hover, so an entry stays on one
     * line. The roster card keeps to the time alone — the centre's ask.
     */
    public function test_the_saved_health_codes_are_shown_in_the_dialog_only(): void
    {
        Attendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-09-23', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-23 07:46'), 'signed_out_at' => Carbon::parse('2026-09-23 15:11'),
            'health_in_code' => 0, 'health_out_code' => 4,
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        // The data reaches the page with both codes on the day…
        $data = str_replace('\u0022', '"', $html);
        $this->assertStringContainsString('"in":"7:46a","in_code":0', $data);
        $this->assertStringContainsString('"out":"3:11p","out_code":4', $data);

        // …and the dialog's entries draw them as chips with the code's label.
        $this->assertStringContainsString('data-entry-in-code', $html);
        $this->assertStringContainsString('data-entry-out-code', $html);
        $this->assertStringContainsString('x-text="entry.in_code" data-entry-in-code', $html);
        $this->assertStringContainsString(':title="codeLabel(entry.in_code)', $html);
        $this->assertStringContainsString("return entry ? entry.code + ' · ' + entry.label : String(code);", $html);

        // The card's line is the time alone.
        $this->assertStringNotContainsString("' · code ' + day.in_code", $html);
        $this->assertStringContainsString("if (this.isIn(row)) return 'In · ' + last.in + nth;", $html);
    }

    /**
     * A School Age day is two blocks, and each keeps its own checks: the AM
     * arrival and leaving codes on the AM row, the PM ones on the PM row. The
     * door saves them per block and reads both back into the dialog.
     */
    public function test_school_age_blocks_each_save_and_show_their_own_health_codes(): void
    {
        $this->child->update(['classroom' => 'School Age', 'dob' => Carbon::parse('2026-09-23')->subYears(7)->toDateString()]);

        // Morning: in with 0, out with 3.
        $am = $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $this->child->id, 'session' => 'AM', 'health_code' => 0])
            ->assertOk()->assertJsonPath('health_in', 0)->json('attendance_id');
        $this->travelTo(Carbon::parse('2026-09-23 11:40:00'));
        $this->actingAs($this->admin)->postJson(route('check-in.out', $am), ['health_code' => 3])
            ->assertOk()->assertJsonPath('health_out', 3);

        // Afternoon: in with 4 and a note.
        $this->travelTo(Carbon::parse('2026-09-23 13:05:00'));
        $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $this->child->id, 'session' => 'PM', 'health_code' => 4, 'health_note' => 'warm'])
            ->assertOk()->assertJsonPath('health_in', 4);

        $rows = Attendance::where('child_id', $this->child->id)->get()->keyBy('session');
        $this->assertSame([0, 3], [$rows['AM']->health_in_code, $rows['AM']->health_out_code]);
        $this->assertSame([4, null], [$rows['PM']->health_in_code, $rows['PM']->health_out_code]);

        // Both blocks reach the page with their codes, and the per-block list draws them.
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();
        $data = str_replace('\u0022', '"', $html);
        $this->assertStringContainsString('"AM":{"id":'.$am.',"session":"AM","in":"8:00a","in_code":0,"in_note":null,"out":"11:40a","out_code":3', $data);
        $this->assertStringContainsString('"session":"PM","in":"1:05p","in_code":4,"in_note":"warm","out":null,"out_code":null', $data);
        $this->assertStringContainsString('data-entry-session', $html);
        $this->assertStringContainsString('data-session-summary', $html);
    }
}
