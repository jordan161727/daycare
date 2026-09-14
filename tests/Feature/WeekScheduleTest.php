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
     * A week opens from each child's registered days and from nothing else.
     *
     * It used to copy the week before it forward, which handed the first
     * person to look at a week last week's pattern — one-off Tuesdays and all
     * — as a plan nobody had made. Now the ticks come from the record, and
     * another week's pattern arrives only when somebody presses Copy.
     */
    public function test_opening_a_week_starts_from_the_registered_days_not_the_week_before(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $child->forceFill(['schedule_days' => [1, 3, 5]])->save();   // Mon / Wed / Fri on the record

        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-28', '2026-07-30']);        // but last week was Tue / Thu

        $week = $this->weeks->open(self::WEEK_2);

        // Nothing was copied, and the week says so.
        $this->assertNull($week->copied_from_week_start);

        $this->assertTrue($this->scheduled($child, '2026-08-03'));   // Mon
        $this->assertFalse($this->scheduled($child, '2026-08-04'));  // Tue — last week's, not the record's
        $this->assertTrue($this->scheduled($child, '2026-08-05'));   // Wed
        $this->assertFalse($this->scheduled($child, '2026-08-06'));  // Thu
        $this->assertTrue($this->scheduled($child, '2026-08-07'));   // Fri
    }

    /** A child whose record has never named a pattern opens unticked. */
    public function test_a_child_with_no_registered_days_opens_unticked_whatever_last_week_was(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27', '2026-07-29', '2026-07-31']);

        $this->weeks->open(self::WEEK_2);

        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->where('week_start', self::WEEK_2)->count());
        $this->assertSame(0, ScheduleSlot::where('child_id', $child->id)->where('week_start', self::WEEK_2)->where('is_scheduled', true)->count());
    }

    /** The whole point: a sick day must not become next week's schedule. */
    public function test_attendance_never_copies_forward(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);

        // Turned up on an unscheduled Tuesday, missed the scheduled Monday.
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-07-28', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->weeks->open(self::WEEK_2);

        // Neither the tick nor the arrival crosses into the new week on its own.
        $this->assertFalse($this->scheduled($child, '2026-08-03'), 'last week\'s tick is not this week\'s plan');
        $this->assertFalse($this->scheduled($child, '2026-08-04'), 'the unscheduled Tuesday attendance must not become a schedule');
    }

    public function test_a_week_is_independent_once_built(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);
        $this->weeks->open(self::WEEK_2);
        $this->weeks->copyFrom(self::WEEK_2, self::WEEK_1);

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

    public function test_a_skipped_week_opens_clean_and_offers_the_newest_week_to_copy(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27']);

        // Jump straight to week 3 without ever opening week 2.
        $week3 = $this->weeks->open(self::WEEK_3);

        $this->assertNull($week3->copied_from_week_start);
        $this->assertFalse($this->scheduled($child, '2026-08-10'));

        // The week before it is the one the copy dialog leads with.
        $this->assertSame(self::WEEK_1, $this->weeks->sourceFor(self::WEEK_3));
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

        // Nobody inherits: the existing child's five ticks were last week's
        // plan, and this week starts from the record, which for them is silent.
        $this->assertFalse($this->scheduled($existing, '2026-08-03'), 'last week\'s ticks are not this week\'s plan');
    }

    public function test_school_age_children_get_a_slot_per_session(): void
    {
        $schoolAge = $this->makeChild('Turing', 'Alan', 'School Age');
        $this->weeks->open(self::WEEK_1);

        $this->assertSame(10, ScheduleSlot::where('child_id', $schoolAge->id)->count());
        $this->assertSame(['AM', 'PM'], ScheduleSlot::where('child_id', $schoolAge->id)
            ->where('slot_date', '2026-07-27')->orderBy('session')->pluck('session')->all());
    }

    public function test_am_and_pm_copy_across_independently(): void
    {
        $schoolAge = $this->makeChild('Allen', 'Mark', 'School Age');
        $this->weeks->open(self::WEEK_1);
        ScheduleSlot::where('child_id', $schoolAge->id)->where('session', 'PM')->update(['is_scheduled' => true]);

        $this->weeks->open(self::WEEK_2);
        $this->weeks->copyFrom(self::WEEK_2, self::WEEK_1);

        $this->assertTrue($this->scheduled($schoolAge, '2026-08-03', 'PM'));
        $this->assertFalse($this->scheduled($schoolAge, '2026-08-03', 'AM'), 'an afternoon-only child stays afternoon-only');
    }

    public function test_copying_from_a_chosen_week_replaces_the_pattern_but_keeps_sign_ins(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->weeks->open(self::WEEK_1);
        $this->schedule($child, ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31']);

        $this->weeks->open(self::WEEK_2);
        ScheduleSlot::where('week_start', self::WEEK_2)->update(['is_scheduled' => false]);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-08-03', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->weeks->copyFrom(self::WEEK_2, self::WEEK_1);

        $this->assertSame(5, ScheduleSlot::where('week_start', self::WEEK_2)->where('is_scheduled', true)->count());
        $this->assertDatabaseHas('attendances', ['child_id' => $child->id, 'attendance_date' => '2026-08-03']);
        $this->assertSame(self::WEEK_1, ScheduleWeek::firstWhere('week_start', self::WEEK_2)->copied_from_week_start->toDateString());
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
