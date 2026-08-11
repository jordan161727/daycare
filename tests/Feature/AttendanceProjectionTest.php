<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\AttendanceProjection;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The forecast: what a week is expected to look like before it happens, worked
 * out from last week's attendance, the enrolment dates and the contracted hours.
 *
 * It is a second opinion, not a second schedule. Nothing here writes a tick
 * unless the director asked for it in as many words.
 */
class AttendanceProjectionTest extends TestCase
{
    use RefreshDatabase;

    /** Mon 27 Jul – Fri 31 Jul: the week the projection reads. */
    private const PRIOR = '2026-07-27';

    /** Mon 3 Aug – Fri 7 Aug: the week being projected. */
    private const WEEK = '2026-08-03';

    private const MON = '2026-08-03';
    private const TUE = '2026-08-04';
    private const WED = '2026-08-05';
    private const THU = '2026-08-06';
    private const FRI = '2026-08-07';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand inside the week being projected: the week before it has finished
        // and is therefore a record, which is exactly the shape the forecast
        // reads from.
        $this->travelTo(Carbon::parse(self::WEEK.' 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    // ---------------- what decides the days ----------------

    public function test_last_weeks_attendance_becomes_this_weeks_expectation(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27', '2026-07-29', '2026-07-31']);   // Mon / Wed / Fri

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $child, self::MON));
        $this->assertFalse($this->expects($projection, $child, self::TUE));
        $this->assertTrue($this->expects($projection, $child, self::WED));
        $this->assertFalse($this->expects($projection, $child, self::THU));
        $this->assertTrue($this->expects($projection, $child, self::FRI));
        $this->assertSame('attendance', $projection['children'][$child->id]['basis']);
    }

    /** The forecast is a forecast: it must not quietly become the plan. */
    public function test_the_projection_never_ticks_a_day_by_itself(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);

        // The page opens the week, reads the forecast and renders it.
        $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::WEEK]))->assertOk();

        $this->assertSame(0, ScheduleSlot::where('week_start', self::WEEK)->where('is_scheduled', true)->count());
        $this->assertTrue($this->expects($this->project(), $child, self::MON), 'the forecast still says Monday');
    }

    public function test_a_day_outside_the_enrolment_dates_is_never_projected(): void
    {
        $leaver = $this->makeChild('Nguyen', 'Bao', 'UPK-4', ['withdrawn_on' => self::TUE]);
        $starter = $this->makeChild('Reyes', 'Cara', 'Toddler', ['enrolled_on' => self::THU]);

        // Both were here every day last week.
        foreach ([$leaver, $starter] as $child) {
            $this->attended($child, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);
        }

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $leaver, self::TUE), 'the last day is still a working day');
        $this->assertFalse($this->expects($projection, $leaver, self::WED));
        $this->assertFalse($this->expects($projection, $starter, self::WED));
        $this->assertTrue($this->expects($projection, $starter, self::THU), 'the first day is a working day');
        $this->assertSame(2, $projection['children'][$leaver->id]['days']);
        $this->assertSame(2, $projection['children'][$starter->id]['days']);
    }

    public function test_a_closed_day_is_never_projected(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27', '2026-07-29']);
        ClosureDay::create(['closed_on' => self::WED, 'reason' => 'Holiday']);

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $child, self::MON));
        $this->assertFalse($this->expects($projection, $child, self::WED), 'nobody is expected on a day the centre is shut');
        $this->assertSame(0, $projection['day_totals'][self::WED]);
    }

    /**
     * A snow day last Thursday says nothing about this Thursday. Read as absence
     * it would erase every Thursday after it.
     */
    public function test_a_day_the_centre_was_shut_last_week_falls_back_to_the_tick(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27']);                                  // Monday only
        ClosureDay::create(['closed_on' => '2026-07-30', 'reason' => 'Snow']);     // last Thursday

        $this->openWeek();
        $this->tick($child, [self::THU]);

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $child, self::MON));
        $this->assertTrue($this->expects($projection, $child, self::THU), 'no evidence either way, so the tick stands');
        $this->assertFalse($this->expects($projection, $child, self::TUE), 'a day with evidence still reads as absence');
    }

    /**
     * Planning next week midweek: the days of this week that have not happened
     * yet are not everybody staying home.
     */
    public function test_a_day_that_has_not_happened_yet_falls_back_to_the_tick(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $nextWeek = '2026-08-10';

        // Stand on the Wednesday of the week being read from, with Monday and
        // Tuesday signed in and the rest of it still to come.
        $this->travelTo(Carbon::parse(self::WED.' 09:00:00'));
        $this->attended($child, [self::MON]);
        app(WeekSchedule::class)->open(self::WEEK);
        app(WeekSchedule::class)->open($nextWeek);
        $this->tick($child, ['2026-08-13']);              // next Thursday

        $projection = app(AttendanceProjection::class)->forWeek($nextWeek);

        $this->assertTrue($this->expects($projection, $child, '2026-08-10'), 'Monday happened and he was here');
        $this->assertFalse($this->expects($projection, $child, '2026-08-11'), 'Tuesday happened and he was not');
        $this->assertTrue($this->expects($projection, $child, '2026-08-13'), 'Thursday has not happened, so the tick stands');
    }

    public function test_a_child_with_no_attendance_last_week_falls_back_to_this_weeks_ticks(): void
    {
        $child = $this->makeChild('Reyes', 'Cara', 'Toddler', ['enrolled_on' => self::MON]);

        $this->openWeek();
        $this->tick($child, [self::MON, self::WED]);

        $projection = $this->project();

        $this->assertSame('schedule', $projection['children'][$child->id]['basis']);
        $this->assertTrue($this->expects($projection, $child, self::MON));
        $this->assertFalse($this->expects($projection, $child, self::TUE));
        $this->assertTrue($this->expects($projection, $child, self::WED));
    }

    // ---------------- what the expected hours do ----------------

    public function test_expected_hours_measure_the_projection_rather_than_shape_it(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['expected_hours_per_week' => 45]);
        $this->attended($child, ['2026-07-27', '2026-07-29', '2026-07-31']);

        $summary = $this->project()['children'][$child->id];

        // Three days is what happened, so three days is what is expected. The
        // contract is not made to add up by inventing a Tuesday nobody staffed.
        $this->assertSame(3, $summary['days']);
        $this->assertSame(27.0, $summary['projected_hours']);
        $this->assertSame(45.0, $summary['contract_hours']);
        $this->assertSame(-18.0, $summary['variance'], 'the shortfall is reported, not corrected');
    }

    public function test_a_projection_over_the_contracted_hours_reads_as_a_surplus(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['expected_hours_per_week' => 18]);
        $this->attended($child, ['2026-07-27', '2026-07-28', '2026-07-29']);

        $this->assertSame(9.0, $this->project()['children'][$child->id]['variance']);
    }

    public function test_a_child_contracted_for_nothing_is_not_projected_at_all(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['expected_hours_per_week' => 0]);
        $this->attended($child, ['2026-07-27', '2026-07-29']);

        $projection = $this->project();

        $this->assertSame('none', $projection['children'][$child->id]['basis']);
        $this->assertSame(0, $projection['children'][$child->id]['sessions']);
        $this->assertFalse($this->expects($projection, $child, self::MON));
    }

    public function test_blank_hours_leave_the_projection_without_a_target(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27']);

        $summary = $this->project()['children'][$child->id];

        $this->assertNull($summary['contract_hours']);
        $this->assertNull($summary['variance'], 'nobody has said what to expect, so nothing is claimed');
        $this->assertSame(9.0, $summary['projected_hours']);
    }

    /**
     * Hours say how much, never which days. A child with hours and no history is
     * named for the director rather than given a pattern nobody chose.
     */
    public function test_hours_with_no_pattern_name_the_child_instead_of_guessing_days(): void
    {
        $child = $this->makeChild('Reyes', 'Cara', 'Toddler', ['expected_hours_per_week' => 27]);

        $projection = $this->project();

        $this->assertSame('contract', $projection['children'][$child->id]['basis']);
        $this->assertSame(0, $projection['children'][$child->id]['days']);
        $this->assertSame(1, $projection['totals']['without_pattern']);
        $this->assertFalse($this->expects($projection, $child, self::MON));
    }

    // ---------------- half days ----------------

    public function test_school_age_sessions_project_independently(): void
    {
        $child = $this->makeChild('Turing', 'Alan', 'School Age');
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-07-27', 'session' => 'PM', 'signed_in_at' => now()]);

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $child, self::MON, 'PM'));
        $this->assertFalse($this->expects($projection, $child, self::MON, 'AM'), 'an afternoon child stays an afternoon child');
        $this->assertSame(4.5, $projection['children'][$child->id]['projected_hours']);
        $this->assertSame(1, $projection['day_totals'][self::MON], 'a half day is still one body in the room');
    }

    public function test_a_full_day_last_week_covers_both_sessions_after_a_move_into_school_age(): void
    {
        $child = $this->makeChild('Turing', 'Alan', 'Toddler');
        $this->attended($child, ['2026-07-27']);

        // Moved into School Age since: the day they attended splits in two.
        $child->forceFill(['classroom_override' => 'School Age', 'classroom_override_from' => self::MON])->save();

        $projection = $this->project();

        $this->assertTrue($this->expects($projection, $child, self::MON, 'AM'));
        $this->assertTrue($this->expects($projection, $child, self::MON, 'PM'));
        $this->assertSame(9.0, $projection['children'][$child->id]['projected_hours']);
    }

    // ---------------- it keeps up on its own ----------------

    public function test_the_projection_follows_a_profile_edit_with_nothing_rebuilt(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['expected_hours_per_week' => 45]);
        $this->attended($child, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);

        $this->assertSame(5, $this->project()['children'][$child->id]['days']);

        // Two edits to the record, nothing else touched.
        $child->update(['withdrawn_on' => self::WED, 'expected_hours_per_week' => 27]);

        $after = $this->project()['children'][$child->id];

        $this->assertSame(3, $after['days'], 'the leaving date takes the later days out at once');
        $this->assertSame(0.0, $after['variance'], 'the new hours are what it is measured against');
    }

    public function test_a_child_added_today_is_projected_from_their_own_start_date(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->openWeek();

        $newcomer = $this->makeChild('Reyes', 'Cara', 'Toddler', ['enrolled_on' => self::WED, 'expected_hours_per_week' => 18]);

        $projection = $this->project();

        $this->assertArrayHasKey($newcomer->id, $projection['children'], 'a child enrolled after the week was built is still forecast');
        $this->assertSame('contract', $projection['children'][$newcomer->id]['basis']);
    }

    public function test_an_inactive_child_drops_out_of_the_projection(): void
    {
        $child = $this->makeChild('Babbage', 'Charles', 'Toddler');
        $this->attended($child, ['2026-07-27']);

        $child->update(['status' => 'Inactive']);

        $this->assertArrayNotHasKey($child->id, $this->project()['children']);
    }

    // ---------------- filling the week from it ----------------

    public function test_filling_the_week_from_the_projection_ticks_the_projected_days(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27', '2026-07-29']);
        $this->openWeek();

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.project'), ['week_start' => self::WEEK, 'mode' => 'replace'])
            ->assertRedirect(route('attendance.index', ['date' => self::WEEK]))
            ->assertSessionHas('success');

        $this->assertTrue($this->ticked($child, self::MON));
        $this->assertFalse($this->ticked($child, self::TUE));
        $this->assertTrue($this->ticked($child, self::WED));
    }

    public function test_adding_from_the_projection_keeps_the_days_already_ticked(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27']);
        $this->openWeek();
        $this->tick($child, [self::FRI]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.project'), [
            'week_start' => self::WEEK,
            'mode' => 'add',
        ])->assertSessionHas('success');

        $this->assertTrue($this->ticked($child, self::MON), 'the projected day arrives');
        $this->assertTrue($this->ticked($child, self::FRI), 'the hand-set day survives');
    }

    public function test_replacing_from_the_projection_clears_the_days_it_does_not_expect(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27']);
        $this->openWeek();
        $this->tick($child, [self::MON, self::FRI]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.project'), [
            'week_start' => self::WEEK,
            'mode' => 'replace',
        ])->assertSessionHas('success');

        $this->assertTrue($this->ticked($child, self::MON));
        $this->assertFalse($this->ticked($child, self::FRI), 'replace makes the week an exact match of the forecast');
    }

    public function test_a_child_with_no_pattern_is_left_alone_by_a_fill(): void
    {
        $child = $this->makeChild('Reyes', 'Cara', 'Toddler', ['expected_hours_per_week' => 27]);
        $this->openWeek();
        $this->tick($child, [self::TUE]);

        $this->actingAs($this->admin)->post(route('attendance.schedule.project'), [
            'week_start' => self::WEEK,
            'mode' => 'add',
        ])->assertSessionHas('warning');

        // Their hours are known and their days are not. Clearing the one day
        // somebody did set would read as a decision nobody made.
        $this->assertTrue($this->ticked($child, self::TUE));
    }

    public function test_a_fill_that_changes_nothing_says_so_instead_of_claiming_success(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->openWeek();

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.project'), ['week_start' => self::WEEK, 'mode' => 'replace'])
            ->assertSessionHas('warning');
    }

    public function test_a_finished_week_cannot_be_filled_from_the_projection(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::PRIOR);

        $this->actingAs($this->admin)
            ->post(route('attendance.schedule.project'), ['week_start' => self::PRIOR, 'mode' => 'replace'])
            ->assertSessionHas('warning');

        $this->assertSame(0, ScheduleSlot::where('week_start', self::PRIOR)->where('is_scheduled', true)->count());
        $this->assertFalse($this->ticked($child, '2026-07-27'));
    }

    public function test_a_teacher_may_fill_the_week_and_a_parent_may_not(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->attended($child, ['2026-07-27']);
        $this->openWeek();

        $this->actingAs(User::factory()->create(['role' => 'parent']))
            ->post(route('attendance.schedule.project'), ['week_start' => self::WEEK])
            ->assertForbidden();

        $this->assertFalse($this->ticked($child, self::MON));

        $this->actingAs(User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']))
            ->post(route('attendance.schedule.project'), ['week_start' => self::WEEK])
            ->assertSessionHas('success');

        $this->assertTrue($this->ticked($child, self::MON));
    }

    // ---------------- on the page ----------------

    public function test_the_week_view_shows_the_forecast_and_the_way_to_accept_it(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler', ['expected_hours_per_week' => 45]);
        $this->attended($child, ['2026-07-27', '2026-07-29']);
        app(WeekSchedule::class)->open(self::PRIOR);

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEEK]))
            ->assertOk();

        $response->assertSee('Projected');
        $response->assertSee('18.0 h');                       // two full days
        $response->assertSee('against 45.0 h expected');
        $response->assertSee('Fill from projection');
    }

    public function test_a_teacher_sees_the_forecast_for_their_own_rooms_only(): void
    {
        $mine = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $theirs = $this->makeChild('Turing', 'Alan', 'UPK-4');
        $this->attended($mine, ['2026-07-27']);
        $this->attended($theirs, ['2026-07-27']);

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        $projection = app(AttendanceProjection::class)->forWeek(
            self::WEEK,
            Child::visibleTo($teacher)->where('status', 'Active')->get(),
        );

        $this->assertArrayHasKey($mine->id, $projection['children']);
        $this->assertArrayNotHasKey($theirs->id, $projection['children']);
        $this->assertSame(1, $projection['day_totals'][self::MON], "the totals add up to the teacher's roster");
    }

    public function test_the_expected_hours_are_set_on_the_childs_record(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->childForm(['expected_hours_per_week' => '22.5']))
            ->assertRedirect(route('children.index'));

        $this->assertSame(22.5, $child->fresh()->expected_hours_per_week);

        // A week has 168 hours in it, so anything past that is a typo.
        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->childForm(['expected_hours_per_week' => '200']))
            ->assertSessionHasErrors('expected_hours_per_week');

        $this->assertSame(22.5, $child->fresh()->expected_hours_per_week);
    }

    // ---------------- helpers ----------------

    private function childForm(array $extra = []): array
    {
        return array_merge([
            'lan' => '1001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'Active',
            'classroom_override' => 'Toddler',
        ], $extra);
    }

    private function project(): array
    {
        return app(AttendanceProjection::class)->forWeek(self::WEEK);
    }

    private function expects(array $projection, Child $child, string $date, string $session = 'FULL'): bool
    {
        return ($projection['expected'][$child->id][$date][$session] ?? false) === true;
    }

    private function openWeek(): void
    {
        app(WeekSchedule::class)->open(self::WEEK);
    }

    private function attended(Child $child, array $dates, string $session = 'FULL'): void
    {
        foreach ($dates as $date) {
            Attendance::create([
                'child_id' => $child->id,
                'attendance_date' => $date,
                'session' => $session,
                'signed_in_at' => Carbon::parse($date.' 08:15:00'),
            ]);
        }
    }

    private function tick(Child $child, array $dates, string $session = 'FULL'): void
    {
        ScheduleSlot::where('child_id', $child->id)
            ->whereIn('slot_date', $dates)
            ->where('session', $session)
            ->update(['is_scheduled' => true]);
    }

    private function ticked(Child $child, string $date, string $session = 'FULL'): bool
    {
        return (bool) ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', $date)
            ->where('session', $session)
            ->value('is_scheduled');
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
