<?php

namespace Tests\Feature;

use App\Models\Attendance;
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

    public function test_the_sheet_offers_the_mode_and_says_you_are_in_it(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // A mode you can see you are in: past columns take taps in it and do
        // not outside it, and nothing else on screen changes enough to notice.
        $this->assertStringContainsString('@click="editing = ! editing; retiming = null; drafting = null"', $html);
        $this->assertStringContainsString('Editing', $html);

        // The banner says what each mark does in this mode: an empty cell is
        // the plan and flips, an arrival is its time and can be retyped or
        // taken off, and a day gone by takes a new arrival.
        $this->assertStringContainsString('box means expected, dot means not', $html);
        $this->assertStringContainsString('retype it, or press ✓ to take it off', $html);
        $this->assertStringContainsString('＋ puts an arrival on', $html);

        // Removing is the half that can lose something, and is asked about.
        $this->assertStringContainsString('Taking anything off asks first.', $html);
        $this->assertStringContainsString('askBeforeTapping(childId, date, session, time = null)', $html);
        $this->assertStringContainsString('canRemove(childId, date, session)', $html);
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
            ->assertDontSee('@click="editing = ! editing"', false);
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
        $this->assertStringContainsString('@click="editing = ! editing; retiming = null; drafting = null"', $html);
    }

    /**
     * Every tap in Edit asks first, and the question names the condition.
     *
     * The shape of the question is deliberately the same every time — somebody
     * correcting a fortnight-old week is reading these to catch the tap they
     * did not mean, and a dialog whose shape moves about is one that gets
     * clicked through rather than read. Only the lines inside it change.
     */
    public function test_every_condition_gets_the_same_question_worded_for_it(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // Expected, not expected, closed, and a day already gone — one branch
        // each, all feeding the one dialog.
        $this->assertStringContainsString("' was expected'", $html);
        $this->assertStringContainsString('was not scheduled on', $html);
        $this->assertStringContainsString('this counts as unplanned, and is still billable', $html);
        $this->assertStringContainsString('The centre was closed: ', $html);
        $this->assertStringContainsString('has already gone', $html);

        // Taking one off is the destructive half and says what it costs.
        $this->assertStringContainsString('Take this arrival off?', $html);
        $this->assertStringContainsString('removes that day from what the centre bills for', $html);

        // Cancel is a real way out, and Escape is another.
        $this->assertStringContainsString('@click="confirming = null"', $html);
        $this->assertStringContainsString('@keydown.escape.window="confirming = null"', $html);
    }

    /**
     * At the door a tap is a child standing in front of you and the answer is
     * always yes. Sixty confirmations a morning is a dialog nobody reads, and a
     * dialog nobody reads is worse than none.
     */
    public function test_the_live_sheet_does_not_ask(): void
    {
        $this->makeChild();
        app(WeekSchedule::class)->open('2026-09-14');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // The branch, not the absence of the dialog: it is on the page either
        // way, and what matters is that only Edit routes through it.
        // In Edit a tap is the plan for the day, not an arrival; arrivals are
        // made and unmade through the field and the ✓, both of which ask.
        $this->assertStringContainsString('if (this.editing) {', $html);
        $this->assertStringContainsString('if (this.canSetExpected(date)) return this.toggleOne(childId, date, session);', $html);
        $this->assertStringContainsString('confirming: null,', $html);
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
