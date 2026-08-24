<?php

namespace Tests\Unit;

use App\Models\TimePunch;
use App\Services\TimeClock;
use Tests\TestCase;

/**
 * What the clock will let somebody press, and what it will not.
 *
 * This table is the whole reason the exception queue stays short: the employee
 * is only ever offered a punch that is legal from where they stand, so the
 * ordinary day is correct by construction rather than by care.
 */
class ClockStateMachineTest extends TestCase
{
    public function test_the_only_way_into_a_day_is_clocking_in(): void
    {
        $this->assertSame([TimePunch::IN], TimeClock::NEXT[TimeClock::OFF]);
    }

    public function test_on_the_clock_you_may_break_for_lunch_rest_or_go_home(): void
    {
        $this->assertEqualsCanonicalizing(
            [TimePunch::LUNCH_START, TimePunch::BREAK_START, TimePunch::OUT],
            TimeClock::NEXT[TimeClock::WORKING]
        );
    }

    /**
     * The property that keeps lunches from swallowing an afternoon.
     *
     * A day ended from lunch would leave the meal open with no end punch —
     * unpaid time running to the last punch of the day. Coming back is the
     * only move, and going home is two presses, not one.
     */
    public function test_a_day_cannot_be_ended_from_lunch_or_a_break(): void
    {
        foreach ([TimeClock::LUNCH, TimeClock::BREAK] as $state) {
            $this->assertNotContains(TimePunch::OUT, TimeClock::NEXT[$state]);
            $this->assertCount(1, TimeClock::NEXT[$state], "There should be exactly one way out of {$state}.");
        }

        $this->assertSame([TimePunch::LUNCH_END], TimeClock::NEXT[TimeClock::LUNCH]);
        $this->assertSame([TimePunch::BREAK_END], TimeClock::NEXT[TimeClock::BREAK]);
    }

    public function test_a_break_can_never_be_ended_as_a_lunch(): void
    {
        // Ending the wrong one would silently move minutes between paid and
        // unpaid, which is a pay error rather than a display one.
        $this->assertNotContains(TimePunch::LUNCH_END, TimeClock::NEXT[TimeClock::BREAK]);
        $this->assertNotContains(TimePunch::BREAK_END, TimeClock::NEXT[TimeClock::LUNCH]);
    }

    public function test_no_state_is_a_dead_end(): void
    {
        foreach (TimeClock::NEXT as $state => $offered) {
            $this->assertNotEmpty($offered, "Nothing can be pressed from {$state}.");
        }
    }

    public function test_every_state_the_clock_can_reach_has_a_row(): void
    {
        $this->assertEqualsCanonicalizing(
            [TimeClock::OFF, TimeClock::WORKING, TimeClock::LUNCH, TimeClock::BREAK],
            array_keys(TimeClock::NEXT)
        );
    }

    /**
     * A punch type with no wording renders as its own constant — "LUNCH_START"
     * on a button a teacher is meant to press.
     */
    public function test_every_punch_the_clock_offers_has_a_button_and_a_record_label(): void
    {
        foreach (TimeClock::NEXT as $state => $offered) {
            foreach ($offered as $type) {
                $this->assertArrayHasKey($type, TimePunch::ACTIONS, "No button wording for {$type}.");
                $this->assertArrayHasKey($type, TimePunch::LABELS, "No record wording for {$type}.");
                $this->assertNotSame($type, TimePunch::action($type));
                $this->assertNotSame($type, TimePunch::LABELS[$type]);
            }
        }
    }

    public function test_every_punch_type_that_exists_can_be_reached_from_somewhere(): void
    {
        // A type nobody can press is either dead weight or a missing button.
        $reachable = collect(TimeClock::NEXT)->flatten()->unique()->values()->all();

        $this->assertEqualsCanonicalizing(array_keys(TimePunch::LABELS), $reachable);
    }
}
