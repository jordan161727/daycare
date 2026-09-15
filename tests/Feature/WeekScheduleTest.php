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

class WeekScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK_1 = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul
    private const WEEK_2 = '2026-08-03';
    private const WEEK_3 = '2026-08-10';

    private WeekSchedule $weeks;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand inside week 1. These weeks are fixed dates, so without pinning
        // the clock they drift into the past as real time passes and quietly
        // become frozen — which changes what every test here is asking.
        $this->travelTo(Carbon::parse(self::WEEK_1.' 09:00:00'));

        $this->weeks = app(WeekSchedule::class);
    }

    public function test_the_first_week_opens_empty_because_there_is_nothing_to_copy(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $week = $this->weeks->open(self::WEEK_1);

        $this->assertNull($week->copied_from_week_start);
        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->count());
        $this->assertSame(0, ScheduleSlot::where('is_scheduled', true)->count());
    }

    /**
     * Opening a week copies the week before it forward, weekday by weekday.
     *
     * That is the whole of how a week gets built: one button, and last week's
     * shape comes with it. A centre's weeks are the same week over and over
     * with exceptions, and typing the exceptions is less work than typing the
     * rule every Monday.
     */
    public function test_opening_a_week_copies_the_pattern_forward_by_weekday(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27', '2026-07-29', '2026-07-31']);   // Mon / Wed / Fri

        $week = $this->weeks->open(self::WEEK_2);

        $this->assertSame(self::WEEK_1, $week->copied_from_week_start->toDateString());
        $this->assertTrue($this->scheduled($child, '2026-08-03'));   // Mon
        $this->assertFalse($this->scheduled($child, '2026-08-04'));  // Tue
        $this->assertTrue($this->scheduled($child, '2026-08-05'));   // Wed
        $this->assertFalse($this->scheduled($child, '2026-08-06'));  // Thu
        $this->assertTrue($this->scheduled($child, '2026-08-07'));   // Fri
    }

    /**
     * The registered days seed a child the source week says nothing about.
     *
     * Somebody enrolled last Thursday has nothing to inherit, and used to
     * arrive with a blank week that a person had to notice and tick by hand. A
     * record saying Tue/Thu has already asked for those days.
     *
     * Per child, and that distinction is load bearing: a child who IS in the
     * source week carries it forward untouched, including a week somebody
     * deliberately cleared. The registration seeds a first week; it never
     * overrules a later decision.
     */
    public function test_a_child_the_source_week_never_saw_falls_back_to_their_record(): void
    {
        $this->weeks->open(self::WEEK_1);

        $late = $this->makeChild('Turing', 'Alan', 'Toddler');
        $late->forceFill(['schedule_days' => [2, 4]])->save();   // Tue / Thu

        $this->weeks->open(self::WEEK_2);

        $this->assertFalse($this->scheduled($late, '2026-08-03'));  // Mon
        $this->assertTrue($this->scheduled($late, '2026-08-04'));   // Tue
        $this->assertTrue($this->scheduled($late, '2026-08-06'));   // Thu
    }

    /**
     * A day attended is a day scheduled; a day missed is still scheduled.
     *
     * Both halves matter and they are not symmetrical. A child who turned up
     * on a day nobody booked was, in the only sense next week cares about,
     * coming on that day — and leaving the drop-in out meant the staff
     * re-ticked the same Tuesday every week and a standing arrangement never
     * became one. But an absence must not clear a ticked day: a sick Monday is
     * not a change of schedule, which is the whole reason the plan and the
     * record are separate things.
     */
    public function test_a_day_attended_carries_forward_but_a_day_missed_is_not_cleared(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);

        // Turned up on an unscheduled Tuesday, missed the scheduled Monday.
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-07-28', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->weeks->open(self::WEEK_2);

        $this->assertTrue($this->scheduled($child, '2026-08-03'), 'a missed Monday is still a scheduled Monday');
        $this->assertTrue($this->scheduled($child, '2026-08-04'), 'the Tuesday they actually came on carries forward');
    }

    /** The arrivals themselves never travel: next week is a plan, not a record. */
    public function test_the_attendance_itself_is_never_copied(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-07-28', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->weeks->open(self::WEEK_2);

        $this->assertSame(1, Attendance::count());
        $this->assertSame('2026-07-28', Attendance::sole()->attendance_date->toDateString());
    }

    public function test_a_week_is_independent_once_built(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);
        $this->weeks->open(self::WEEK_2);

        // Change week 1 after week 2 already exists.
        $this->schedule($child, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);

        $this->assertTrue($this->scheduled($child, '2026-08-03'));
        $this->assertFalse($this->scheduled($child, '2026-08-04'), 'week 2 must not follow later edits to week 1');
    }

    public function test_opening_a_week_twice_does_not_rebuild_it(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);

        $this->weeks->open(self::WEEK_1);

        $this->assertSame(1, ScheduleWeek::count());
        $this->assertTrue($this->scheduled($child, '2026-07-27'), 're-opening must not wipe the hand-corrected pattern');
    }

    public function test_a_skipped_week_copies_from_the_newest_week_that_exists(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);

        // Jump straight to week 3 without ever opening week 2.
        $week3 = $this->weeks->open(self::WEEK_3);

        $this->assertSame(self::WEEK_1, $week3->copied_from_week_start->toDateString());
        $this->assertTrue($this->scheduled($child, '2026-08-10'));
    }

    public function test_a_child_gets_no_slots_outside_their_enrolment_dates(): void
    {
        $starter = $this->makeChild('Reyes', 'Cara', 'Toddler', ['enrolled_on' => '2026-07-29']);
        $leaver = $this->makeChild('Nguyen', 'Bao', 'UPK-4', ['withdrawn_on' => '2026-07-29']);

        $this->weeks->open(self::WEEK_1);

        $this->assertSame(3, ScheduleSlot::where('child_id', $starter->id)->count(), 'Wed to Fri only');
        $this->assertSame(3, ScheduleSlot::where('child_id', $leaver->id)->count(), 'Mon to Wed only');
        $this->assertDatabaseMissing('schedule_slots', ['child_id' => $starter->id, 'slot_date' => '2026-07-28']);
        $this->assertDatabaseMissing('schedule_slots', ['child_id' => $leaver->id, 'slot_date' => '2026-07-30']);
    }

    public function test_a_newly_enrolled_child_starts_unticked_rather_than_scheduled(): void
    {
        $existing = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($existing, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);

        $newcomer = $this->makeChild('Reyes', 'Cara', 'Toddler', ['enrolled_on' => '2026-08-03']);
        $this->weeks->open(self::WEEK_2);

        $this->assertSame(5, ScheduleSlot::where('child_id', $newcomer->id)->count());
        $this->assertSame(0, ScheduleSlot::where('child_id', $newcomer->id)->where('is_scheduled', true)->count());

        $this->assertTrue($this->scheduled($existing, '2026-08-03'), 'the existing child still inherits');
    }

    public function test_school_age_children_get_a_slot_per_session(): void
    {
        $schoolAge = $this->makeChild('Turing', 'Alan', 'School Age');
        $this->weeks->open(self::WEEK_1);

        $this->assertSame(10, ScheduleSlot::where('child_id', $schoolAge->id)->count());
        $this->assertSame(['AM', 'PM'], ScheduleSlot::where('child_id', $schoolAge->id)
            ->where('slot_date', '2026-07-27')->orderBy('session')->pluck('session')->all());
    }

    public function test_am_and_pm_copy_forward_independently(): void
    {
        $schoolAge = $this->makeChild('Allen', 'Mark', 'School Age');
        $this->weeks->open(self::WEEK_1);
        ScheduleSlot::where('child_id', $schoolAge->id)->where('session', 'PM')->update(['is_scheduled' => true]);

        $this->weeks->open(self::WEEK_2);

        $this->assertTrue($this->scheduled($schoolAge, '2026-08-03', 'PM'));
        $this->assertFalse($this->scheduled($schoolAge, '2026-08-03', 'AM'), 'an afternoon-only child stays afternoon-only');
    }

    public function test_inactive_children_are_left_out_of_the_schedule(): void
    {
        $this->makeChild('Babbage', 'Charles', 'Toddler', ['status' => 'Inactive']);

        $this->weeks->open(self::WEEK_1);

        $this->assertSame(0, ScheduleSlot::count());
    }

    public function test_a_child_added_after_the_week_was_built_still_gets_boxes(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);

        $latecomer = $this->makeChild('Reyes', 'Cara', 'Toddler');
        $this->weeks->open(self::WEEK_1);

        $this->assertSame(5, ScheduleSlot::where('child_id', $latecomer->id)->count());
        $this->assertSame(0, ScheduleSlot::where('child_id', $latecomer->id)->where('is_scheduled', true)->count());
    }

    public function test_a_child_added_today_gets_no_boxes_in_a_week_that_has_ended(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);

        // Stand in the week after: WEEK_1 has now finished.
        $this->travelTo(Carbon::parse(self::WEEK_2.' 09:00:00'));

        $latecomer = $this->makeChild('Reyes', 'Cara', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->weeks->open(self::WEEK_2);

        // A finished week is what DSS bills against. Someone who was not on the
        // roster then does not acquire a schedule for it now.
        $this->assertSame(0, ScheduleSlot::where('child_id', $latecomer->id)->where('week_start', self::WEEK_1)->count());
        $this->assertSame(5, ScheduleSlot::where('child_id', $latecomer->id)->where('week_start', self::WEEK_2)->count());
    }

    /** Two teachers opening the same new week must not build it twice. */
    public function test_opening_the_same_week_concurrently_creates_one_week(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');

        $this->weeks->open(self::WEEK_1);
        app(WeekSchedule::class)->open(self::WEEK_1);

        $this->assertSame(1, ScheduleWeek::where('week_start', self::WEEK_1)->count());
        $this->assertSame(5, ScheduleSlot::count());
    }

    // ---------------- helpers ----------------

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

    private function schedule(Child $child, array $dates, string $session = 'FULL'): void
    {
        ScheduleSlot::where('child_id', $child->id)
            ->whereIn('slot_date', $dates)
            ->where('session', $session)
            ->update(['is_scheduled' => true]);
    }

    private function scheduled(Child $child, string $date, string $session = 'FULL'): bool
    {
        return (bool) ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', $date)
            ->where('session', $session)
            ->value('is_scheduled');
    }
}
