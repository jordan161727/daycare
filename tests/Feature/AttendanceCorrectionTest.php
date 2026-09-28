<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Correcting the register.
 *
 *              Live sheet          Edit
 *   Past       locked              tappable, any week already begun
 *   Today      tappable            tappable
 *   Future     locked              locked
 *
 * Live is the sheet a teacher stands in front of all day, and it offers today
 * and nothing else — the commonest mistake on a five-column grid is the column
 * next to the one you meant. Edit is the mode you go into deliberately to put
 * right a day that has already gone.
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday, so yesterday and tomorrow are both inside the same week. */
    private const WEDNESDAY = '2026-09-16';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /*
     * The leaving time can be corrected too. It never could, anywhere: a child
     * who left at four and was clocked out at six was a day paid wrong with
     * nothing able to say so. Check In's Edit mode needed it, and it is the
     * register's endpoint that does it — logged like any other retime.
     */
    public function test_the_leaving_time_can_be_moved(): void
    {
        $child = $this->makeChild();

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-09-14',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-14 08:00'),
            'signed_out_at' => Carbon::parse('2026-09-14 18:00'),
        ]);

        $json = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-14',
                'session' => 'FULL',
                'signed_out_time' => '16:00',
            ])
            ->assertOk()
            ->json();

        $attendance = Attendance::sole();

        $this->assertSame('16:00', $attendance->signed_out_at->format('H:i'));
        $this->assertSame('08:00', $attendance->signed_in_at->format('H:i'), 'the arrival is untouched');
        $this->assertSame('4:00p', $json['out_time']);
        $this->assertSame($attendance->id, $json['attendance_id']);

        // Written down like any other move of a time on a day already gone.
        $this->assertSame(AttendanceAmendment::RETIMED, AttendanceAmendment::sole()->action);
    }

    public function test_a_clock_out_today_is_a_retime_with_no_amendment(): void
    {
        /*
         * The cards on the register clock a child out by giving retime the
         * hour of now. Today it is the door recording a departure, not a
         * correction of one, so the row moves and nothing is written to the
         * amendment log — the same rule every write to today follows.
         *
         * A School Age child, because they are the ones who book a morning
         * and an afternoon; every other room books one FULL session.
         */
        $child = $this->makeChild(['classroom' => 'School Age', 'birth_date' => '2018-03-04']);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => self::WEDNESDAY,
            'session' => 'AM',
            'signed_in_at' => Carbon::parse(self::WEDNESDAY.' 08:05'),
        ]);

        $json = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), [
                'child_id' => $child->id,
                'attendance_date' => self::WEDNESDAY,
                'session' => 'AM',
                'signed_out_time' => '11:30',
            ])
            ->assertOk()
            ->json();

        $this->assertSame('11:30', Attendance::sole()->signed_out_at->format('H:i'));
        $this->assertSame('11:30a', $json['out_time']);
        $this->assertSame(0, AttendanceAmendment::count(), 'today is recorded, not amended');

        // And the afternoon is its own row: a second arrival on the PM
        // session leaves the morning's departure exactly where it was.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), ['child_id' => $child->id, 'attendance_date' => self::WEDNESDAY, 'session' => 'PM', 'signed_in_time' => '12:30'])
            ->assertOk();

        $this->assertSame(2, Attendance::count());
        $this->assertSame('11:30', Attendance::where('session', 'AM')->sole()->signed_out_at->format('H:i'));
        $this->assertNull(Attendance::where('session', 'PM')->sole()->signed_out_at);
    }

    public function test_a_leaving_time_before_the_arrival_is_refused(): void
    {
        $child = $this->makeChild();

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-09-14',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-14 08:00'),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-14',
                'session' => 'FULL',
                'signed_out_time' => '07:30',
            ])
            ->assertStatus(422);

        $this->assertNull(Attendance::sole()->signed_out_at);
    }

    public function test_a_retime_with_neither_time_is_refused(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-14',
                'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['signed_in_time', 'signed_out_time']);
    }

    public function test_a_day_already_gone_this_week_can_be_put_right(): void
    {
        $child = $this->makeChild(['drop_off_time' => '08:30']);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-14',
                'session' => 'FULL',
            ])
            ->assertOk();

        $attendance = Attendance::sole();

        $this->assertSame('2026-09-14', $attendance->attendance_date->toDateString());

        // Stamped with the hours that day was agreed for, not with this
        // morning: nobody was standing at the door on Monday, and now() would
        // write Wednesday's clock onto Monday's row.
        $this->assertSame('2026-09-14 08:30:00', $attendance->signed_in_at->format('Y-m-d H:i:s'));
    }

    public function test_a_child_with_no_hours_agreed_takes_the_hour_the_centre_opens(): void
    {
        $child = $this->makeChild(['drop_off_time' => null]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertOk();

        $this->assertSame('2026-09-15 07:00:00', Attendance::sole()->signed_in_at->format('Y-m-d H:i:s'));
    }

    /**
     * The other half of a correction. Without it Edit could only ever add,
     * which would take the sheet further from the truth rather than closer.
     */
    public function test_an_arrival_can_be_taken_back_off(): void
    {
        $child = $this->makeChild();
        $this->arrive($child, '2026-09-15');

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.remove'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'removed' => 1]);

        $this->assertSame(0, Attendance::count());
    }

    public function test_taking_one_off_is_bounded_exactly_as_putting_one_on_is(): void
    {
        $child = $this->makeChild();
        $this->arrive($child, '2026-09-11');

        // A finished week can be corrected both ways, or the register could
        // only ever grow when somebody went back to reconcile it.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.remove'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-11',
                'session' => 'FULL',
            ])
            ->assertOk();

        $this->assertSame(0, Attendance::count());

        // The future is the one thing neither half will take: there is nothing
        // on a day that has not happened to take off.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.remove'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-17',
                'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date');
    }

    /**
     * Every hand-made change to a day already gone is written down.
     *
     * The register used to be self-evidently a record of what happened: a row
     * existed because somebody tapped a cell with a child in front of them.
     * Rows can now appear and disappear long after the fact, in weeks already
     * reported and billed from — so the row can move, but not quietly.
     */
    public function test_a_correction_is_written_down_with_who_did_it(): void
    {
        $child = $this->makeChild(['drop_off_time' => '08:30']);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-08',
                'session' => 'FULL',
            ])
            ->assertOk();

        $added = \App\Models\AttendanceAmendment::sole();

        $this->assertSame('added', $added->action);
        $this->assertSame('2026-09-08', $added->attendance_date->toDateString());
        $this->assertSame($this->admin->id, $added->performed_by);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.remove'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-08',
                'session' => 'FULL',
            ])
            ->assertOk();

        $removed = \App\Models\AttendanceAmendment::where('action', 'removed')->sole();

        // The time that was on the row survives the row: a deletion leaves
        // nothing in `attendances` to ask about afterwards.
        $this->assertSame('2026-09-08 08:30:00', $removed->signed_in_at->format('Y-m-d H:i:s'));

        // Append-only — the log of what happened is not itself edited.
        $this->assertSame(2, \App\Models\AttendanceAmendment::count());
        $this->assertSame(0, Attendance::count());
    }

    /**
     * Today's arrivals are not amendments. A log that fills with three hundred
     * routine sign-ins a week is one nobody reads.
     */
    public function test_an_ordinary_sign_in_today_is_not_logged_as_a_correction(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => self::WEDNESDAY,
                'session' => 'FULL',
            ])
            ->assertOk();

        $this->assertSame(1, Attendance::count());
        $this->assertSame(0, \App\Models\AttendanceAmendment::count());
    }

    public function test_the_sheet_marks_a_cell_that_was_corrected(): void
    {
        $child = $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertOk();

        $map = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->viewData('amendmentMap');

        // A row that was not a live sign-in is visibly not one.
        $this->assertArrayHasKey($child->id.'|2026-09-15|FULL', $map);
        $this->assertSame('added', $map[$child->id.'|2026-09-15|FULL']['action']);
        $this->assertSame($this->admin->name, $map[$child->id.'|2026-09-15|FULL']['by']);
    }

    /**
     * A room teacher is the one who knows who actually turned up, so this is
     * not a director-only job — but it stays their own rooms.
     */
    public function test_a_teacher_may_correct_their_own_room_and_no_other(): void
    {
        $mine = $this->makeChild(['classroom' => 'Toddler', 'birth_date' => '2024-02-10']);
        $theirs = $this->makeChild(['classroom' => 'Infant', 'lan' => '2002', 'birth_date' => '2026-02-10']);
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        $this->actingAs($teacher)
            ->postJson(route('attendance.signin'), [
                'child_id' => $mine->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertOk();

        $this->actingAs($teacher)
            ->postJson(route('attendance.signin'), [
                'child_id' => $theirs->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertNotFound();

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_child_clocked_out_can_come_back_and_the_trip_out_is_kept(): void
    {
        /*
         * Collected at eleven for the dentist, back at one, gone for good at
         * four. One day, one row — DSS bills first in, last out — but the
         * two hours out of the room were not care given, so they are written
         * down: the departure the return closes, and the moment of coming
         * back, on a return of their own.
         */
        $child = $this->makeChild();
        $row = $this->arrive($child, self::WEDNESDAY);
        $row->update(['signed_out_at' => Carbon::parse(self::WEDNESDAY.' 11:00')]);

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 13:00:00'));

        $json = $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id,
                'attendance_date' => self::WEDNESDAY,
                'session' => 'FULL',
            ])
            ->assertOk()
            ->json();

        // The row is open again — every screen that reads "no out" as "still
        // here" goes on being right — and the pair it closed is kept.
        $row->refresh();
        $this->assertNull($row->signed_out_at);
        $this->assertSame(1, Attendance::count());
        $this->assertSame([['11:00a', '1:00p']], $json['returns']);
        $this->assertNull($json['out_time']);
        $this->assertSame('11:00', $row->returns->sole()->left_at->format('H:i'));
        $this->assertSame('13:00', $row->returns->sole()->returned_at->format('H:i'));
        $this->assertSame($this->admin->id, $row->returns->sole()->performed_by);

        // The next clock-out is the ordinary one, and it lands on the row.
        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.retime'), [
                'child_id' => $child->id, 'attendance_date' => self::WEDNESDAY, 'session' => 'FULL', 'signed_out_time' => '16:00',
            ])
            ->assertOk();

        $this->assertSame('16:00', $row->fresh()->signed_out_at->format('H:i'));

        // And the register hands the cards the whole day, trips included.
        app(WeekSchedule::class)->open('2026-09-14');
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringContainsString('11:00a', $html);
        $this->assertStringContainsString('1:00p', $html);
        $this->assertStringContainsString('4:00p', $html);
    }

    public function test_a_child_still_in_the_room_has_nothing_to_come_back_from(): void
    {
        $child = $this->makeChild();
        $this->arrive($child, self::WEDNESDAY);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id, 'attendance_date' => self::WEDNESDAY, 'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ada Lovelace has not been clocked out on Wednesday, Sep 16, so there is nothing to come back from.']);

        $this->assertNull(Attendance::sole()->signed_out_at);
    }

    public function test_a_return_on_a_day_gone_needs_its_hour_and_it_must_follow_the_departure(): void
    {
        // Yesterday's return cannot be "now": the time has to be given, and
        // it has to be after the departure it closes.
        $child = $this->makeChild();
        $row = $this->arrive($child, '2026-09-15');
        $row->update(['signed_out_at' => Carbon::parse('2026-09-15 11:00')]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id, 'attendance_date' => '2026-09-15', 'session' => 'FULL',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('returned_time');

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id, 'attendance_date' => '2026-09-15', 'session' => 'FULL', 'returned_time' => '10:30',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Coming back has to be after leaving — 11:00a.']);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id, 'attendance_date' => '2026-09-15', 'session' => 'FULL', 'returned_time' => '13:00',
            ])
            ->assertOk();

        $this->assertSame('13:00', $row->fresh()->returns->sole()->returned_at->format('H:i'));
        $this->assertNull($row->fresh()->signed_out_at);
    }

    public function test_a_parent_cannot_bring_a_child_back_either(): void
    {
        $child = $this->makeChild();
        $row = $this->arrive($child, self::WEDNESDAY);
        $row->update(['signed_out_at' => Carbon::parse(self::WEDNESDAY.' 08:30')]);

        $this->actingAs(User::factory()->create(['role' => 'parent']))
            ->postJson(route('attendance.signin.return'), [
                'child_id' => $child->id, 'attendance_date' => self::WEDNESDAY, 'session' => 'FULL',
            ])
            ->assertForbidden();

        $this->assertNotNull($row->fresh()->signed_out_at);
    }

    public function test_a_parent_cannot_reach_the_removal_route_at_all(): void
    {
        $child = $this->makeChild();
        $this->arrive($child, '2026-09-15');

        $this->actingAs(User::factory()->create(['role' => 'parent']))
            ->postJson(route('attendance.signin.remove'), [
                'child_id' => $child->id,
                'attendance_date' => '2026-09-15',
                'session' => 'FULL',
            ])
            ->assertForbidden();

        $this->assertSame(1, Attendance::count());
    }

    /**
     * Live or Edit, as a switch. The mode is a state the whole sheet is in —
     * every column changes with it — and a switch says "in it" or "not" the
     * way a button labelled Edit never quite did.
     */
    public function test_the_sheet_offers_the_mode_as_a_switch_and_says_which_it_is_in(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="switch" class="att-switch"', $html);
        // The switch reloads the sheet in the other mode — see ModeSwitchReloadsTest.
        $this->assertStringContainsString('@click="switchMode()"', $html);
        $this->assertStringContainsString("x-text=\"editing ? 'Edit mode' : 'Live mode'\"", $html);

        // Locked columns say so in their header rather than by being grey.
        $this->assertStringContainsString('class="att-lock" x-html="icons.lock"', $html);

        // And the hint under the sheet says what a tap does in this mode.
        $this->assertStringContainsString('Tap a cell to cycle not attending → expected → time', $html);
    }
    /**
     * A week nobody ever opened has no cells in it, so the mode is not offered
     * — there is nothing it could unlock.
     *
     * This is not about the week being finished. The test below opens a
     * finished week and finds it correctable, which is the point: the plan for
     * a week that is over is over, and the record of what happened in it is not.
     */
    public function test_a_week_that_was_never_opened_is_not_offered_the_mode(): void
    {
        $this->makeChild();

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '2026-09-09']))
            ->assertOk()
            ->assertDontSee('@click="switchMode()"', false);
    }

    /**
     * A finished week is offered the mode too � those are exactly the weeks a
     * missing day is noticed in, when the month is being reconciled.
     */
    public function test_a_finished_week_can_still_be_corrected(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-07');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '2026-09-09']))
            ->assertOk()
            ->getContent();

        // The plan for a week that is over is over � no ticking, no copying.
        $this->assertStringContainsString('the schedule is locked', $html);

        // The record of what happened in it is not.
        $this->assertStringContainsString('@click="switchMode()"', $html);
    }

    /**
     * A tap moves the box one step along, and a tap that can be tapped again
     * is its own undo — so nothing asks first. The reference design has no
     * dialogs, and sixty confirmations a fortnight of corrections is a dialog
     * nobody reads.
     */
    public function test_a_tap_cycles_the_box_and_nothing_asks_first(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // Ahead: plan only. Today or gone: dot → expected → time → dot.
        $this->assertStringContainsString('return this.cycle(childId, date, session);', $html);
        $this->assertStringContainsString('if (date > this.today) {', $html);
        $this->assertStringContainsString('this.removeSignIn(childId, date, session);', $html);
        $this->assertStringContainsString('if (this.canSignIn(date)) this.signIn(childId, date, session);', $html);

        // No dialog left on the page, in markup or in script.
        $this->assertStringNotContainsString('confirming', $html);
        $this->assertStringNotContainsString('askBeforeTapping', $html);
    }
    /**
     * At the door a tap is a child standing in front of you: today, and the
     * one session's next step — in, then out, then back in. A School Age
     * child's morning and afternoon boxes are two ins and two outs. Nothing
     * on the live sheet is ever taken off it: the worst a passing elbow can
     * do is clock a child out, and the next tap puts them back.
     */
    public function test_the_live_sheet_steps_todays_box_in_out_and_back(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('if (! this.editing) {', $html);
        $this->assertStringContainsString('if (date !== this.today) return;', $html);
        $this->assertStringContainsString('if (! this.isPresent(childId, date, session)) return this.signIn(childId, date, session);', $html);
        $this->assertStringContainsString('if (! this.isOut(childId, date, session)) return this.clockOut(childId, date, session);', $html);
        $this->assertStringContainsString('return this.clockBack(childId, date, session);', $html);

        // And the box says which, and shows both hours once it has them.
        $this->assertStringContainsString("' — tap: clock out'", $html);
        $this->assertStringContainsString("' — tap: clock in again'", $html);
        $this->assertStringContainsString('return this.sessionSpan(childId, date, session);', $html);
    }
    /**
     * A time in Edit carries a pencil; press it, or E, and the hour is typed.
     * Enter or leaving saves, Escape puts it back, and an empty field changes
     * nothing — a blur with nothing in it is a change of mind, not an order.
     */
    public function test_the_pencil_types_the_exact_hour(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // The pencil is drawn into the box rather than bound as an element of
        // its own, and the press is caught once on the table — so what is
        // asserted is that it is still marked as the pencil, and that a press
        // on that mark still opens the field rather than moving the box along.
        $this->assertStringContainsString('class="att-pencil" data-pencil', $html);
        $this->assertStringContainsString("closest('[data-pencil]')", $html);
        $this->assertStringContainsString('return this.beginRetime(Number(childId), date, session);', $html);

        // E on the box opens it too, through the same one handler.
        $this->assertStringContainsString("event.key === 'e' || event.key === 'E'", $html);

        $this->assertStringContainsString('@blur="commitRetime(child.id,', $html);
        $this->assertStringContainsString('@keydown.escape.prevent="cancelRetime()"', $html);
        $this->assertStringContainsString("if (! value) return;", $html);

        // Whatever was typed becomes HH:MM; a time on a recorded arrival moves
        // it, a time on an empty cell records one.
        $this->assertStringContainsString("/^(\\d{1,2})[:.]?(\\d{2})?(a|p|am|pm)?$/", $html);
        $this->assertStringContainsString('? this.retime(childId, date, session, value)', $html);
        $this->assertStringContainsString(': this.signIn(childId, date, session, value);', $html);
    }

    private function arrive(Child $child, string $date): Attendance
    {
        return Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => $date,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' 08:00:00'),
        ]);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
        ]);
    }
}
