<?php

namespace Tests\Feature;

use App\Models\ClosureDay;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffRule;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

/**
 * A teacher's own week — the screen they open in a corridor on a phone.
 */
class MyScheduleTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

    private const WEEK = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul

    private User $aisha;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday morning, before the shift below starts.
        $this->travelTo(Carbon::parse(self::WEEK.' 06:30:00'));

        $this->aisha = User::create([
            'name' => 'Aisha Khan',
            'email' => 'aisha@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => 'FT',
            'title' => 'UPK-4',
            'classroom' => 'UPK-4',
        ]);
    }

    public function test_the_next_shift_is_named_before_the_week(): void
    {
        $this->rosterAisha();

        $this->actingAs($this->aisha)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            // Monday's shift has not started yet, so it is the one to lead with.
            ->assertSee('On today')
            ->assertSee('8:00 AM – 4:00 PM', false);
    }

    public function test_a_shift_already_finished_is_not_offered_as_next(): void
    {
        $this->rosterAisha();

        // Monday evening: Monday is done, Tuesday is the next one.
        $this->travelTo(Carbon::parse(self::WEEK.' 19:00:00'));

        $this->actingAs($this->aisha)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('On tomorrow')
            ->assertDontSee('On today');
    }

    public function test_the_banner_is_gone_once_the_week_is_over(): void
    {
        $this->rosterAisha();

        $this->travelTo(Carbon::parse('2026-07-31 23:30:00'));

        $this->actingAs($this->aisha)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertDontSee('Next shift')
            ->assertDontSee('On today');
    }

    public function test_a_closed_day_says_why_instead_of_not_scheduled(): void
    {
        ClosureDay::create(['closed_on' => '2026-07-29', 'reason' => 'Civic Holiday']);

        $this->rosterAisha();

        // The roster is never generated for a closed day, so without this the
        // Wednesday card would read "Not scheduled" and say nothing about why.
        $this->actingAs($this->aisha)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('Centre closed')
            ->assertSee('Civic Holiday');

        $this->assertSame(0, StaffShift::where('user_id', $this->aisha->id)->where('shift_date', '2026-07-29')->count());
    }

    public function test_the_week_still_reads_when_only_a_closure_falls_in_it(): void
    {
        ClosureDay::create(['closed_on' => '2026-07-29', 'reason' => 'Civic Holiday']);

        // Nothing rostered at all: the empty state must not swallow the closure.
        $this->actingAs($this->aisha)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertSee('Civic Holiday')
            ->assertDontSee('You are not scheduled for any shift this week');
    }

    public function test_a_teacher_only_ever_sees_their_own_week(): void
    {
        $other = User::create([
            'name' => 'Bilal Osei',
            'email' => 'bilal@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => 'FT',
            'title' => 'Toddler',
            'classroom' => 'Toddler',
        ]);

        $this->rosterAisha();

        $this->actingAs($other)
            ->get(route('staff-schedule.mine'))
            ->assertOk()
            ->assertDontSee('8:00 AM – 4:00 PM', false);
    }

    /** Give Aisha a fixed 8–4 every day of the test week. */
    private function rosterAisha(): void
    {
        $this->aisha->staffRules()->create([
            'rule_type' => 'FIXED_SHIFT',
            'priority' => 'HARD',
            'day' => 'ALL',
            'time_1' => 8 * 60,
            'time_2' => 16 * 60,
        ]);

        $this->bookChildren('UPK-4', 6);

        app(StaffSchedule::class)->generate(self::WEEK);
    }

    /** Book children into a room for every day of the test week. */
    private function bookChildren(string $room, int $count): void
    {
        $dates = collect(range(0, 4))->map(fn ($offset) => Carbon::parse(self::WEEK)->addDays($offset)->toDateString());

        for ($i = 0; $i < $count; $i++) {
            $child = Child::create([
                'lan' => (string) (1000 + Child::count() + 1),
                'first_name' => 'Child',
                'last_name' => "Number{$i}",
                'dob' => $this->dobForRoom($room),
                'status' => 'Active',
            ]);

            foreach ($dates as $date) {
                ScheduleSlot::create([
                    'week_start' => self::WEEK,
                    'child_id' => $child->id,
                    'slot_date' => $date,
                    'session' => 'FULL',
                    'is_scheduled' => true,
                ]);
            }
        }
    }
}
