<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleEditingTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand inside the week under test: a week only stays editable until its
        // Friday has passed, so these dates have to be the present, not the past.
        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_visiting_the_week_builds_its_schedule(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-07-29']))->assertOk();

        $this->assertDatabaseHas('schedule_weeks', ['week_start' => self::MONDAY]);
        $this->assertSame(5, ScheduleSlot::count());
    }

    /**
     * Only the week we are standing in builds itself. Looking ahead must not
     * plan the centre's next week on its behalf — a sheet covered in ticks
     * nobody asked for reads as a schedule that has been agreed.
     */
    public function test_looking_at_a_later_week_does_not_build_it(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $nextWeek = '2026-08-03';

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => $nextWeek]))
            ->assertOk()
            ->assertSee('has not been set up')
            ->assertSee('Open this week');

        $this->assertDatabaseMissing('schedule_weeks', ['week_start' => $nextWeek]);
        $this->assertSame(0, ScheduleSlot::where('week_start', $nextWeek)->count());
    }

    /**
     * Opening a later week copies the week before it forward — which is the
     * only way a pattern gets from one week to the next now.
     */
    public function test_opening_a_later_week_copies_the_week_before_it_forward(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $nextWeek = '2026-08-03';

        $this->actingAs($this->admin)
            ->post(route('attendance.week.open'), ['week_start' => $nextWeek])
            ->assertRedirect(route('attendance.index', ['date' => $nextWeek]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedule_weeks', ['week_start' => $nextWeek, 'copied_from_week_start' => self::MONDAY]);
        $this->assertSame(5, ScheduleSlot::where('week_start', $nextWeek)->where('is_scheduled', true)->count());
    }

    /**
     * The banner says where the week came from, and counts nothing.
     *
     * It used to report "12 day(s) copied forward" — a true number of the wrong
     * thing. It counts ticked boxes, which are a child on a weekday for one
     * session, so three children across a five-day week come to twelve and the
     * reader is left asking how a week holds twelve days. The provenance is the
     * part that is not obvious from looking at the sheet; the size of it is
     * visible on the grid the banner is sitting over.
     */
    public function test_the_open_banner_names_the_source_week_without_counting(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)
            ->post(route('attendance.week.open'), ['week_start' => '2026-08-03'])
            ->assertSessionHas('success', 'Week opened from Jul 27 copy.');
    }
    /** A finished week is a record. There is nothing left to plan in it. */
    public function test_a_finished_week_cannot_be_opened(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $past = '2026-07-20';

        $this->actingAs($this->admin)
            ->post(route('attendance.week.open'), ['week_start' => $past])
            ->assertSessionHas('warning');

        $this->assertDatabaseMissing('schedule_weeks', ['week_start' => $past]);
    }

    /**
     * The Schedule view is parked, not deleted.
     *
     * A centre that plans its weeks by opening them has no use for a second way
     * in, and a button leading somewhere nobody was asked to go is a button that
     * gets pressed by mistake. But the view behind it is built and tested, so it
     * waits behind one config line — and this checks the line is a switch, which
     * is the part that quietly stops being true once nothing uses it.
     */
    public function test_the_schedule_view_is_parked_behind_a_switch(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        config(['daycare.schedule_view' => false]);
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertDontSee("? 'Schedule' : 'Sign in'", false);

        config(['daycare.schedule_view' => true]);
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertSee("? 'Schedule' : 'Sign in'", false);
    }
    public function test_the_page_offers_both_views_and_no_copy_control(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk();

        // The Schedule view is parked behind daycare.schedule_view, so the way
        // into it is not on the page by default. The control itself is checked
        // rather than the word on it: "Schedule" is a common enough word on
        // this page to pass by accident.
        $response->assertDontSee("view = view === 'signin' ? 'schedule' : 'signin'", false);
        // There is no copy control: one button builds a week, and opening
        // is what brings the week before it across.
        $response->assertDontSee('Copy from another week');

        $response->assertDontSee('copyWeek');

        $response->assertDontSee('This week ends up an exact match of the week you choose');
        $response->assertDontSee('Add to this week');
        $response->assertDontSee('Replace this week');
        $response->assertDontSee('Also copy the sign-ins');

        // The two strips over the grid are gone: the tips were read once and
        // scrolled past every day after, and where the week came from is now
        // the copy button's own hover.
        $response->assertDontSee('Tick the days each child is expected');
        $response->assertDontSee('Schedule copied from');
        $response->assertDontSee('First week in the system');

        // The key can still be put away and brought back.
        $response->assertSee('@attendance-key.window="toggleKey()"', false);
    }

    /**
     * The prompt names the week it is about to bring across, and what travels
     * with it — the ticks and the arrivals both.
     */
    public function test_the_open_prompt_names_the_week_it_will_copy(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '2026-08-10']))
            ->assertOk()
            ->assertSee('has not been set up')
            ->assertSee('Opening it copies the week of')
            ->assertSee('the days somebody actually arrived on')
            ->assertDontSee('Copy from another week');
    }

    /**
     * The quick-set presets end the row, so they are the first thing to fall off
     * the side of a sheet that has grown too wide — which is exactly what had
     * happened: the column was there, and nobody could see it. Short labels keep
     * the whole row on screen, and the title still says what each one does.
     */
    /**
     * Quick set ticks the days the child is actually contracted for.
     *
     * It used to tick all five whoever the row belonged to, so a child down
     * for Monday, Wednesday and Friday came out with a Tuesday and a Thursday
     * that then had to be spotted and cleared by hand — on the sheet the
     * centre bills from. The pattern is on their record; the button reads it,
     * and says which days it means so nobody has to press it to find out.
     */
    public function test_quick_set_offers_the_days_on_the_childs_record(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $ada->forceFill(['schedule_days' => [1, 3, 5]])->save();

        // A record that has never named a pattern: null, not "no days".
        $this->makeChild('Turing', 'Alan', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Quick set', $html);

        // The pattern reaches the page per child, which is what lets one
        // button mean different days on different rows.
        $this->assertStringContainsString('schedule_days\u0022:[1,3,5]', $html);
        $this->assertStringContainsString('schedule_days\u0022:null', $html);
        $this->assertStringContainsString('schedule_days_label\u0022:\u0022Mon, Wed, Fri\u0022', $html);

        // Named on the button, and cleared elsewhere.
        $this->assertStringContainsString("x-text=\"presetLabel(child)\"", $html);
        $this->assertStringContainsString("applyPreset(child, 'days')", $html);
        $this->assertStringContainsString('>Clear<', $html);
    }

    /**
     * The row itself reads as their days, not as five identical boxes.
     *
     * A child down for Monday, Wednesday and Friday should be three boxes
     * across, so somebody scanning the sheet sees the arrangement without
     * opening the profile. The other two are drawn back rather than removed —
     * the printed register's shading, which keeps a white interior precisely
     * so an unplanned day can still be marked in it.
     */
    public function test_days_outside_the_childs_pattern_are_shaded_but_still_tickable(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $ada->forceFill(['schedule_days' => [1, 3, 5]])->save();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // The cell asks the row's own pattern which fill to wear.
        $this->assertStringContainsString('offPattern(child,', $html);
        $this->assertStringContainsString('border-dashed border-slate-200 bg-slate-100/70', $html);

        // Shaded, not disabled: every day keeps the handlers that tick it, and
        // the box interior stays white for the one-off to be marked in.
        $this->assertStringContainsString('Tick it anyway for a one-off', $html);
        $this->assertStringNotContainsString(':disabled="offPattern', $html);

        // And the key names the third state, since the grid now draws three.
        $this->assertStringContainsString('not one of their days, tick it for a one-off', $html);
    }

    /**
     * A record that has never named a pattern shades nothing.
     *
     * null is every child who predates the question. Shading their whole week
     * would be the app inventing an arrangement nobody entered.
     */
    public function test_a_child_with_no_registered_pattern_has_no_shaded_days(): void
    {
        $this->makeChild('Turing', 'Alan', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('schedule_days\u0022:null', $html);

        // offPattern() answers false for them, which is the guard worth
        // holding: it is the one line standing between "no pattern recorded"
        // and "expected on no days".
        $this->assertStringContainsString('if (days === null) return false;', $html);
    }

    /**
     * The sky ring checks the week against the child's record, not last week.
     *
     * It used to read the forecast, which is inferred from the week before —
     * so a child down for Mon/Wed/Fri who was off sick last Wednesday got a
     * ring on every Wednesday after it, and one unplanned Tuesday visit rang
     * every Tuesday. Neither is a disagreement about anything.
     *
     * The forecast still decides it for a child whose record has never named a
     * pattern, because for them it is all there is to go on.
     */
    public function test_the_sky_ring_reads_the_registered_days_where_there_are_any(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $ada->forceFill(['schedule_days' => [1, 3, 5]])->save();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // The registered pattern is consulted first, and the forecast is the
        // fallback rather than the rule.
        $this->assertStringContainsString('const days = this.registeredDaysFor(childId);', $html);
        $this->assertStringContainsString('days.includes(weekday) !== ticked', $html);
        $this->assertStringContainsString('return this.isProjected(childId, date, session) !== ticked;', $html);

        // And the ring says which of the two it is complaining about.
        $this->assertStringContainsString('Ticked, but not one of their days', $html);
        $this->assertStringContainsString('but not ticked this week', $html);
    }

    /**
     * In Edit, an empty cell is the plan for that day and a tap flips it.
     *
     * Box to dot, dot to box, on every date the sheet shows — today included,
     * because the live tap that signs a child in only exists outside Edit, so
     * the two cannot collide. It is as direct as a tick on the checklist: the
     * same act on the same slot, with no dialog, because nothing recorded is
     * at stake.
     *
     * Signing in still stops at today, and an arrival is never made by a tap
     * in this mode. It is made through ＋ and a typed time, and asked about.
     */
    public function test_edit_mode_flips_the_plan_on_a_tap_and_never_signs_in(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Every day, with the permission that ticks the checklist.
        $this->assertStringContainsString('return this.editing && this.canEdit;', $html);
        $this->assertStringContainsString('if (this.canSetExpected(date)) this.toggleOne(childId, date, session);', $html);

        // Ahead of today the cycle is the plan only: no step onto "time".
        $this->assertStringContainsString("if (date > this.today) {\n            if (this.canSetExpected(date)) this.toggleOne(childId, date, session);\n\n            return;", $html);

        // The hour is typed through the pencil, never tomorrow: the field
        // opens only where an arrival could be recorded.
        $this->assertStringContainsString('! (this.editing && this.canSignIn(date))', $html);
        $this->assertStringContainsString('@blur="commitRetime(child.id,', $html);

        // And signing in itself still stops at today.
        $this->assertStringContainsString('return this.editing && this.canAmend && date < this.today;', $html);
    }

    /** The word came off the box; the box is the mark and the key names it. */
    public function test_the_expected_box_is_empty_and_the_key_still_names_it(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("? '' : '·'", $html);
        $this->assertStringContainsString('scheduled, not in yet', $html);
        $this->assertStringNotContainsString('>expected<', $html);
    }

    /**
     * The two hard-coded American patterns are still gone.
     *
     * MWF and TTh were buttons on every row of a centre whose children come on
     * whatever days their parents contracted for. The row's own pattern
     * replaced them; it must not bring them back for everybody else.
     */
    public function test_the_old_hard_coded_patterns_have_not_returned(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('>MWF<', $html);
        $this->assertStringNotContainsString('>TTh<', $html);
        $this->assertStringNotContainsString('mwf', $html);
        $this->assertStringNotContainsString('tth', $html);

        // And the column stays narrow: these were the widths that pushed the
        // end of the sheet off the side of the screen.
        $this->assertStringNotContainsString('>Full week<', $html);
    }

    public function test_ticking_days_saves_only_this_week(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.update'), [
            'week_start' => self::MONDAY,
            'is_scheduled' => true,
            'slots' => [
                ['child_id' => $child->id, 'slot_date' => '2026-07-27', 'session' => 'FULL'],
                ['child_id' => $child->id, 'slot_date' => '2026-07-29', 'session' => 'FULL'],
            ],
        ])->assertOk()->assertJson(['success' => true, 'updated' => 2]);

        $this->assertDatabaseHas('schedule_slots', ['child_id' => $child->id, 'slot_date' => '2026-07-27', 'is_scheduled' => true]);
        $this->assertDatabaseHas('schedule_slots', ['child_id' => $child->id, 'slot_date' => '2026-07-28', 'is_scheduled' => false]);
        $this->assertSame(0, ScheduleSlot::where('week_start', '2026-07-20')->where('is_scheduled', true)->count());
    }

    public function test_a_slot_from_another_week_is_ignored(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.update'), [
            'week_start' => self::MONDAY,
            'is_scheduled' => true,
            'slots' => [['child_id' => $child->id, 'slot_date' => '2026-07-21', 'session' => 'FULL']],
        ])->assertOk()->assertJson(['updated' => 0]);

        $this->assertSame(0, ScheduleSlot::where('is_scheduled', true)->count());
    }

    public function test_a_teacher_cannot_tick_another_rooms_child(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
        $mine = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $theirs = $this->makeChild('Turing', 'Alan', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($teacher)->postJson(route('attendance.schedule.update'), [
            'week_start' => self::MONDAY,
            'is_scheduled' => true,
            'slots' => [
                ['child_id' => $mine->id, 'slot_date' => '2026-07-27', 'session' => 'FULL'],
                ['child_id' => $theirs->id, 'slot_date' => '2026-07-27', 'session' => 'FULL'],
            ],
        ])->assertOk()->assertJson(['updated' => 1]);

        $this->assertDatabaseHas('schedule_slots', ['child_id' => $mine->id, 'is_scheduled' => true]);
        $this->assertDatabaseHas('schedule_slots', ['child_id' => $theirs->id, 'is_scheduled' => false]);
    }

    public function test_a_new_last_day_takes_the_later_boxes_away(): void
    {
        $child = $this->makeChild('Reyes', 'Caleb', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);
        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->count());

        // "Mom says Wednesday is his last day" — after the week was already built.
        $child->update(['withdrawn_on' => '2026-07-29']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $left = ScheduleSlot::where('child_id', $child->id)->orderBy('slot_date')->pluck('slot_date')->map->toDateString()->all();

        // Inclusive: Wednesday keeps its box, Thursday has none at all.
        $this->assertSame(['2026-07-27', '2026-07-28', '2026-07-29'], $left);
    }

    public function test_putting_the_leaving_date_back_restores_the_boxes(): void
    {
        $child = $this->makeChild('Reyes', 'Caleb', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);

        $child->update(['withdrawn_on' => '2026-07-28']);
        app(WeekSchedule::class)->open(self::MONDAY);
        $this->assertSame(2, ScheduleSlot::where('child_id', $child->id)->count());

        // He is staying after all.
        $child->update(['withdrawn_on' => null]);
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->count());
        // Restored days come back unticked rather than silently scheduled.
        $this->assertSame(0, ScheduleSlot::where('child_id', $child->id)->where('slot_date', '2026-07-31')->where('is_scheduled', true)->count());
    }

    public function test_a_later_start_date_takes_the_earlier_boxes_away(): void
    {
        $child = $this->makeChild('Patel', 'Nora', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $child->update(['enrolled_on' => '2026-07-29']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $left = ScheduleSlot::where('child_id', $child->id)->orderBy('slot_date')->pluck('slot_date')->map->toDateString()->all();

        $this->assertSame(['2026-07-29', '2026-07-30', '2026-07-31'], $left);
    }

    public function test_a_day_already_signed_in_keeps_its_box_when_enrolment_shrinks(): void
    {
        $child = $this->makeChild('Reyes', 'Caleb', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-07-30',
            'session' => 'FULL',
            'signed_in_at' => '2026-07-30 08:05:00',
        ]);

        $child->update(['withdrawn_on' => '2026-07-28']);
        app(WeekSchedule::class)->open(self::MONDAY);

        // The Thursday he actually attended survives — DSS bills it, so hiding
        // the box would hide the money.
        $this->assertDatabaseHas('schedule_slots', ['child_id' => $child->id, 'slot_date' => '2026-07-30']);
        // The days he did not attend are gone.
        $this->assertDatabaseMissing('schedule_slots', ['child_id' => $child->id, 'slot_date' => '2026-07-31']);
    }

    public function test_a_finished_week_keeps_its_boxes_when_enrolment_changes(): void
    {
        $child = $this->makeChild('Reyes', 'Caleb', 'PreK');
        app(WeekSchedule::class)->open('2026-07-20');

        $child->update(['withdrawn_on' => '2026-07-21']);
        app(WeekSchedule::class)->open('2026-07-20');

        // A week that has ended is a record and is not rewritten.
        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->where('week_start', '2026-07-20')->count());
    }

    public function test_the_childs_name_opens_their_record(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        // The record rather than the edit form, so the teacher standing in the
        // room gets the numbers and the pick-up list from the same link the
        // director uses — this page only lists children they may already see.
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            // Built from the LAN, because that is how a child's page is
            // addressed. Built from the id it resolved to nothing, and a name on
            // the sheet led to a 404.
            ->assertSee('profileUrl(child.lan)', false)
            ->assertSee('/children/__LAN__"', false);

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']))
            ->get(route('children.show', $child))
            ->assertOk();

        // And the record behind it carries the dates that govern the boxes.
        $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('enrolled_on', false)
            ->assertSee('withdrawn_on', false);
    }

    public function test_set_schedule_lists_every_child_whatever_the_sign_in_filter(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Turing', 'Alan', 'PreK');
        $this->makeChild('Hopper', 'Grace', 'Infant', ['status' => 'Inactive']);
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // The checklist walks the unfiltered roster, not the sign-in view's.
        $this->assertStringContainsString("x-for=\"child in scheduleChildren\"", $html);
        $this->assertStringContainsString('Lovelace', $html);
        $this->assertStringContainsString('Turing', $html);
        // Inactive children stay out of both views.
        $this->assertStringNotContainsString('Hopper', $html);
    }

    public function test_the_grid_marks_an_unscheduled_sign_in_differently(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Four states are all expressible from the front end.
        $this->assertStringContainsString('Not enrolled on this date', $html);             // nothing
        $this->assertStringContainsString("classes.push('att-expected')", $html);   // scheduled, not in yet
        $this->assertStringContainsString("classes.push('att-none')", $html);       // not scheduled
        $this->assertStringContainsString("classes.push('att-time')", $html);       // signed in
        $this->assertStringContainsString("classes.push('att-unplanned')", $html);  // signed in off-schedule
    }

    public function test_the_sheet_carries_a_key_to_its_own_colours(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Every state the grid can paint is named on the sheet itself, so a
        // colour never has to be learned from the documentation — folded behind
        // the "?" that opens the key, rather than printed above every page.
        // A strip above the sheet, in the sheet's own marks, dismissable and
        // remembered — a key is read on the first morning and never again.
        $this->assertStringContainsString('signed in', $html);
        $this->assertStringContainsString('unplanned, still billable', $html);
        $this->assertStringContainsString('scheduled, not in yet', $html);
        $this->assertStringContainsString('not scheduled — tap to sign in anyway', $html);
        $this->assertStringContainsString('x-text="hint"', $html);
        $this->assertStringContainsString('@attendance-key.window="toggleKey()"', $html);
        $this->assertStringContainsString('Not enrolled', $html);
        $this->assertStringContainsString('this week departs from the days on their record', $html);

        // And the key for the other view, which has ticks rather than sign-ins.
        // Both strips are painted, and one "?" in the bar shows and hides the
        // pair: somebody who has put the key away has put away the idea of it,
        // not one view's copy of it. So there is exactly one control, and it is
        // not on the page at all.
        $this->assertStringContainsString('Ticked', $html);
        $this->assertStringContainsString('Centre closed', $html);
        $this->assertStringContainsString('this week departs from the days on their record', $html);
        $this->assertSame(0, substr_count($html, '@click="toggleKey()"'));
        $this->assertSame(1, substr_count($html, "\$dispatch('attendance-key')"));
        // The popover it replaces is gone, not merely unused.
        $this->assertFileDoesNotExist(resource_path('views/attendance/partials/legend.blade.php'));
    }

    public function test_a_gray_day_still_accepts_a_sign_in(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(ScheduleWeek::startOf(today()->toDateString()));

        // Nothing is ticked, so today is a gray box.
        $this->assertFalse((bool) ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', today()->toDateString())->value('is_scheduled'));

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $child->id,
            'attendance_date' => today()->toDateString(),
            'session' => 'FULL',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendances', ['child_id' => $child->id, 'attendance_date' => today()->toDateString()]);
    }

    public function test_the_checklist_is_hidden_from_users_who_cannot_edit(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $parent = User::factory()->create(['role' => 'parent']);

        $response = $this->actingAs($parent)->get(route('attendance.index', ['date' => self::MONDAY]));

        if ($response->status() === 200) {
            $response->assertDontSee("view = view === 'signin' ? 'schedule' : 'signin'", false);
        } else {
            $response->assertForbidden();
        }

        $this->actingAs($parent)->postJson(route('attendance.schedule.update'), [
            'week_start' => self::MONDAY,
            'is_scheduled' => true,
            'slots' => [['child_id' => 1, 'slot_date' => '2026-07-27', 'session' => 'FULL']],
        ])->assertForbidden();
    }

    public function test_a_week_that_has_ended_is_locked(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $past = '2026-07-20';
        app(WeekSchedule::class)->open($past);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.schedule.update'), [
                'week_start' => $past,
                'is_scheduled' => true,
                'slots' => [['child_id' => $child->id, 'slot_date' => $past, 'session' => 'FULL']],
            ])
            ->assertStatus(422);

        $this->assertSame(0, ScheduleSlot::where('week_start', $past)->where('is_scheduled', true)->count());
    }

    public function test_the_locked_week_hides_the_schedule_controls(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => '2026-07-20']))
            ->assertOk()
            ->assertSee('This week has ended')
            ->assertDontSee('Copy from another week');
    }

    public function test_closing_a_day_grays_every_child_at_once(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Turing', 'Alan', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $wednesday = '2026-07-29';

        $this->actingAs($this->admin)
            ->postJson(route('attendance.schedule.closure'), [
                'date' => $wednesday,
                'closed' => true,
                'reason' => 'Snow day',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'closed' => true, 'cleared' => 2]);

        $this->assertDatabaseHas('closure_days', ['closed_on' => $wednesday, 'reason' => 'Snow day']);
        $this->assertSame(0, ScheduleSlot::where('slot_date', $wednesday)->where('is_scheduled', true)->count());
        // Only that day: the other four are untouched, for both children.
        $this->assertSame(8, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
    }

    public function test_a_closed_day_cannot_be_ticked(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $wednesday = '2026-07-29';
        app(WeekSchedule::class)->setClosure($wednesday, true, 'Holiday');

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.update'), [
            'week_start' => self::MONDAY,
            'is_scheduled' => true,
            'slots' => [['child_id' => $child->id, 'slot_date' => $wednesday, 'session' => 'FULL']],
        ])->assertOk()->assertJson(['updated' => 0]);

        $this->assertFalse((bool) ScheduleSlot::where('slot_date', $wednesday)->value('is_scheduled'));
    }

    public function test_a_holiday_stays_gray_when_the_pattern_copies_forward(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler', ['schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        // Next week's Wednesday is a holiday before that week is ever opened.
        app(WeekSchedule::class)->setClosure('2026-08-05', true, 'Holiday');

        // Copied forward from a week where that Wednesday was ticked: the
        // closure is a fact about the date and wins over whatever arrives.
        app(WeekSchedule::class)->open('2026-08-03');

        $this->assertFalse((bool) ScheduleSlot::where('slot_date', '2026-08-05')->value('is_scheduled'));
        $this->assertTrue((bool) ScheduleSlot::where('slot_date', '2026-08-04')->value('is_scheduled'));
    }

    public function test_a_closed_day_still_accepts_a_sign_in(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        app(WeekSchedule::class)->setClosure(self::MONDAY, true, 'Snow day');

        // DSS bills what happened, so a child who turns up is still recorded.
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $child->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendances', ['child_id' => $child->id, 'attendance_date' => self::MONDAY]);
    }

    public function test_the_week_shows_the_hours_a_child_is_contracted_for(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler', [
            'drop_off_time' => '07:00',
            'pick_up_time' => '17:30',
        ]);

        $child = $this->childrenOnThePage()[0];

        $this->assertSame('7:00 AM – 5:30 PM', $child['schedule_hours']);
    }

    public function test_a_child_with_no_hours_agreed_carries_none_into_the_week(): void
    {
        $this->makeChild('Hopper', 'Grace', 'Toddler');

        $child = $this->childrenOnThePage()[0];

        $this->assertArrayHasKey('schedule_hours', $child);
        $this->assertNull($child['schedule_hours']);
    }

    /**
     * The rows Alpine draws, read back off the page.
     *
     * The grid is rendered client side, so the server only ships the data —
     * and @js hands it over as a JS string literal with every quote and every
     * non-ASCII character escaped, which is why this unwraps twice rather than
     * matching on the markup.
     */
    private function childrenOnThePage(string $date = self::MONDAY): array
    {
        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => $date]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));

        return json_decode(json_decode('"'.$matches[1].'"'), associative: true);
    }
    private function makeChild(string $last, string $first, string $room, array $extra = []): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => $room,
        ], $extra));
    }
}
