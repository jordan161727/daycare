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

        $this->assertDatabaseHas('schedule_weeks', ['week_start' => $nextWeek]);
        $this->assertSame(5, ScheduleSlot::where('week_start', $nextWeek)->where('is_scheduled', true)->count());
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

    public function test_the_page_offers_both_views_and_the_copy_control(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk();

        $response->assertSee('Set schedule');
        $response->assertSee('Sign in');
        $response->assertSee('Copy from another week');
        $response->assertSee('Schedule copied from');
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

    public function test_copying_a_week_from_the_page_redirects_back_with_the_new_pattern(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
        ])->assertRedirect(route('attendance.index', ['date' => self::MONDAY]));

        $this->assertSame(5, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
    }

    public function test_the_copy_reports_what_it_did_on_the_page(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.copy'), [
                'week_start' => self::MONDAY,
                'source_week_start' => '2026-07-20',
                'mode' => 'add',
            ])
            ->assertSessionHas('success');

        // The banner has to survive the redirect and reach the sheet.
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertSee('5 day(s) added');
    }

    public function test_a_copy_that_changes_nothing_says_so_instead_of_claiming_success(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        // An empty source week: copying it can only ever be a no-op.
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.copy'), [
                'week_start' => self::MONDAY,
                'source_week_start' => '2026-07-20',
                'mode' => 'add',
            ])
            ->assertSessionHas('warning')
            ->assertSessionMissing('success');

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertSee('has no days ticked');
    }

    public function test_a_no_op_add_points_at_replace_when_this_week_holds_extra_days(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Source runs Monday only; this week runs Monday and Friday. Adding the
        // source can change nothing, but replacing would drop the Friday.
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', '2026-07-20')->where('slot_date', '2026-07-20')->update(['is_scheduled' => true]);

        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', self::MONDAY)->whereIn('slot_date', [self::MONDAY, '2026-07-31'])->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
            'mode' => 'add',
        ]);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertSee('Nothing changed')
            ->assertSee('1 day(s) that week does not')
            ->assertSee('Replace this week');

        // And it really was a no-op: the Friday is still there.
        $this->assertSame(2, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
        $this->assertNotNull($child->id);
    }

    public function test_adding_a_week_keeps_the_days_already_ticked_here(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Source week runs Monday and Tuesday only.
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', '2026-07-20')->whereIn('slot_date', ['2026-07-20', '2026-07-21'])->update(['is_scheduled' => true]);

        // This week has a hand-set Friday that the source knows nothing about.
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', self::MONDAY)->where('slot_date', '2026-07-31')->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
            'mode' => 'add',
        ])->assertRedirect(route('attendance.index', ['date' => self::MONDAY]));

        $ticked = ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)
            ->orderBy('slot_date')->pluck('slot_date')->map->toDateString()->all();

        // Monday and Tuesday arrived; Friday survived.
        $this->assertSame(['2026-07-27', '2026-07-28', '2026-07-31'], $ticked);
        $this->assertSame(5, ScheduleSlot::where('week_start', self::MONDAY)->count());
    }

    public function test_replacing_a_week_clears_days_the_source_does_not_have(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        app(WeekSchedule::class)->open(self::MONDAY);

        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', '2026-07-20')->where('slot_date', '2026-07-20')->update(['is_scheduled' => true]);

        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);
        ScheduleSlot::where('week_start', self::MONDAY)->where('slot_date', '2026-07-31')->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
            'mode' => 'replace',
        ]);

        $ticked = ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)
            ->pluck('slot_date')->map->toDateString()->all();

        $this->assertSame(['2026-07-27'], $ticked);
    }

    public function test_copying_with_sign_ins_reproduces_them_on_the_matching_weekday(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::MONDAY);

        // Signed in on the Wednesday of the source week.
        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-07-22',
            'session' => 'FULL',
            'signed_in_at' => '2026-07-22 08:15:00',
        ]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
            'with_sign_ins' => '1',
        ])->assertRedirect(route('attendance.index', ['date' => self::MONDAY]));

        // Same weekday, same time of day, new date.
        $copy = Attendance::where('child_id', $child->id)->where('attendance_date', '2026-07-29')->first();
        $this->assertNotNull($copy);
        $this->assertSame('08:15', $copy->signed_in_at->format('H:i'));
        $this->assertSame(2, Attendance::count());
    }

    public function test_copying_leaves_sign_ins_alone_unless_asked(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::MONDAY);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-07-22',
            'session' => 'FULL',
            'signed_in_at' => '2026-07-22 08:15:00',
        ]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
        ]);

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_teacher_cannot_copy_sign_ins(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::MONDAY);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => '2026-07-22',
            'session' => 'FULL',
            'signed_in_at' => '2026-07-22 08:15:00',
        ]);

        $this->actingAs($teacher)->post(route('attendance.schedule.copy'), [
            'week_start' => self::MONDAY,
            'source_week_start' => '2026-07-20',
            'with_sign_ins' => '1',
        ]);

        // The pattern copies; the attendance does not.
        $this->assertSame(5, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
        $this->assertSame(1, Attendance::count());
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

    public function test_the_childs_name_opens_their_record_for_an_admin(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertSee('profileUrl(child.id)', false)
            ->assertSee('/children/__ID__/edit', false);

        // And the record itself carries the dates that govern the boxes.
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

    public function test_the_picker_shows_how_busy_each_week_was(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        // A normal week…
        app(WeekSchedule::class)->open('2026-07-13');
        ScheduleSlot::where('week_start', '2026-07-13')->update(['is_scheduled' => true]);

        // …and one thinned out by a holiday.
        app(WeekSchedule::class)->open('2026-07-20');
        ScheduleSlot::where('week_start', '2026-07-20')->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->setClosure('2026-07-22', true, 'Holiday');

        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Choosing "a normal week" means being able to tell them apart.
        $this->assertStringContainsString('5 days ticked', $html);
        $this->assertStringContainsString('4 days ticked', $html);
        $this->assertStringContainsString('1 closed day', $html);
    }

    public function test_the_copy_control_offers_last_week_first(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open('2026-07-13');
        app(WeekSchedule::class)->open('2026-07-20');
        // A week later than the one being viewed must not steal the default.
        app(WeekSchedule::class)->open('2026-08-03');

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // The week just gone leads the picker and is the checked radio.
        $this->assertStringContainsString('value="2026-07-20" checked', $html);
        $this->assertStringNotContainsString('value="2026-08-03" checked', $html);
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
        $this->assertStringContainsString('Not enrolled on this date', $html);          // nothing
        $this->assertStringContainsString('border-indigo-500 bg-indigo-200', $html);    // scheduled
        $this->assertStringContainsString('border-slate-300 bg-slate-100', $html);      // not scheduled
        $this->assertStringContainsString('border-emerald-500 bg-emerald-100', $html);  // signed in
        $this->assertStringContainsString('border-amber-500 bg-amber-100', $html);      // signed in off-schedule
    }

    public function test_the_sheet_carries_a_key_to_its_own_colours(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        // Every state the grid can paint is named beside it, so a colour never
        // has to be learned from the documentation.
        $this->assertStringContainsString('Signed in, not scheduled', $html);
        $this->assertStringContainsString('Scheduled', $html);
        $this->assertStringContainsString('Not scheduled', $html);
        $this->assertStringContainsString('Not enrolled', $html);
        $this->assertStringContainsString('the projection disagrees with the schedule', $html);

        // And the key for the other view, which has ticks rather than sign-ins.
        $this->assertStringContainsString('Ticked', $html);
        $this->assertStringContainsString('Centre closed', $html);
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
            $response->assertDontSee('Set schedule');
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

    public function test_a_finished_week_cannot_be_copied_into(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $past = '2026-07-20';
        app(WeekSchedule::class)->open($past);
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.copy'), [
                'week_start' => $past,
                'source_week_start' => self::MONDAY,
            ])
            ->assertSessionHas('warning');

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
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        // Next week's Wednesday is a holiday before that week is ever opened.
        app(WeekSchedule::class)->setClosure('2026-08-05', true, 'Holiday');
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
