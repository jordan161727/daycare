<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The hour a day still to come is booked for.
 *
 *              Live sheet          Edit
 *   Past       locked              the register: correct what happened
 *   Today      the register        the register
 *   Future     locked              the plan: who is coming, and at what hour
 *
 * Edit mode had two of those three. A future day could be ticked "expected"
 * and no more, which is half a booking: a room that knows six children are
 * coming on Thursday and not that four of them arrive at seven cannot staff
 * Thursday morning.
 *
 * The whole point of these is the line down the middle. An hour on a future
 * day is the plan and writes nothing to `attendances` — Thursday's headcount,
 * its ratios and its bill stay empty until somebody actually walks in. If that
 * line ever moves, the register stops being a record of what happened, and
 * every one of these is here to make that break loudly.
 */
class PlannedArrivalTimeTest extends TestCase
{
    use RefreshDatabase;

    /** A Wednesday, so Monday and Friday are both inside the same week. */
    private const WEDNESDAY = '2026-09-16';

    private const FRIDAY = '2026-09-18';

    private const MONDAY = '2026-09-14';

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->child = Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
            'drop_off_time' => '08:30',
            // Monday to Friday on her record, so the week opens with every day
            // already ticked — which is the state the excusing test needs, and
            // the ordinary state of a full-time child.
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        // After the child exists, so the week is seeded from her record.
        app(WeekSchedule::class)->open(self::MONDAY);
    }

    public function test_a_day_still_to_come_can_be_given_an_hour(): void
    {
        $this->plan(self::FRIDAY, '07:15')->assertOk()->assertJson([
            'success' => true,
            'planned_time' => '07:15',
            'is_scheduled' => true,
        ]);

        $this->assertSame('07:15', $this->slot(self::FRIDAY)->plannedTimeValue());
    }

    public function test_an_hour_writes_nothing_to_the_register(): void
    {
        /*
         * The one that matters.
         *
         * A planned hour that quietly created an attendance row would put a
         * child into Friday's headcount, Friday's ratios and Friday's invoice
         * before Friday happened — and nobody would find it until somebody
         * reconciled a month and found a day that never was.
         */
        $this->plan(self::FRIDAY, '07:15')->assertOk();

        $this->assertSame(0, Attendance::count());
    }

    public function test_an_hour_books_the_day_it_is_given_for(): void
    {
        // An hour on a child marked "not attending" is a state nobody meant.
        $this->slot(self::FRIDAY)->update(['is_scheduled' => false]);

        $this->plan(self::FRIDAY, '07:15')->assertOk()->assertJson(['is_scheduled' => true]);

        $this->assertTrue($this->slot(self::FRIDAY)->is_scheduled);
    }

    public function test_clearing_the_hour_leaves_the_booking_alone(): void
    {
        /*
         * Booked, hour no longer agreed. Taking the hour off says nothing
         * about whether they are coming, so the tick is not touched — unlike
         * the grid's own tap, which walks off the end of the cycle and clears
         * both deliberately.
         */
        $this->plan(self::FRIDAY, '07:15')->assertOk();

        $this->plan(self::FRIDAY, null)->assertOk()->assertJson(['planned_time' => null]);

        $slot = $this->slot(self::FRIDAY);

        $this->assertNull($slot->planned_time);
        $this->assertTrue($slot->is_scheduled);
    }

    public function test_today_is_refused(): void
    {
        // Today has real arrival times. An intention printed over one would be
        // read as the fact.
        $this->plan(self::WEDNESDAY, '07:15')->assertStatus(422);

        $this->assertNull($this->slot(self::WEDNESDAY)->planned_time);
    }

    public function test_a_day_already_gone_is_refused(): void
    {
        $this->plan(self::MONDAY, '07:15')->assertStatus(422);

        $this->assertNull($this->slot(self::MONDAY)->planned_time);
    }

    public function test_a_day_the_centre_is_shut_is_refused(): void
    {
        ClosureDay::create(['closed_on' => self::FRIDAY, 'reason' => 'Holiday']);

        $this->plan(self::FRIDAY, '07:15')->assertStatus(422);
    }

    public function test_an_hour_the_clock_does_not_have_is_refused(): void
    {
        $this->plan(self::FRIDAY, '25:99')->assertStatus(422)->assertJsonValidationErrors('planned_time');
    }

    public function test_a_teacher_may_not_plan_another_rooms_child(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']);

        $this->actingAs($teacher)
            ->postJson(route('attendance.schedule.time'), [
                'child_id' => $this->child->id,
                'slot_date' => self::FRIDAY,
                'session' => 'FULL',
                'planned_time' => '07:15',
            ])
            ->assertForbidden();
    }

    public function test_the_sheet_carries_the_hours_it_has(): void
    {
        $this->plan(self::FRIDAY, '07:15')->assertOk();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        /*
         * The map the grid reads.
         *
         * @js hands it over as JSON.parse('…') with every quote written as
         * \u0022, so the assertion is on the day and the hour rather than on
         * the shape of the literal — which is Laravel's to change.
         */
        $this->assertStringContainsString('planned:', $html);
        $this->assertStringContainsString('2026-09-18', $html);
        $this->assertStringContainsString('07:15', $html);

        // And the class the hour is drawn in, which is what tells it apart
        // from an arrival at a glance.
        $this->assertStringContainsString('att-due', $html);
    }

    public function test_a_child_down_for_every_weekday_can_be_excused_from_one(): void
    {
        /*
         * The reason a future column is opened at all.
         *
         * A parent rings on Wednesday to say Ada is not in on Friday. Her
         * record says Monday to Friday, and that record is right — it is the
         * standing arrangement, not a claim about this particular Friday.
         *
         * So the day has to be able to disagree with the record, in one tap,
         * without anybody editing her profile. The registered days seed the
         * week; after that the box is the answer.
         */
        // Her record seeded the week, so Friday starts ticked.
        $this->assertTrue($this->slot(self::FRIDAY)->is_scheduled);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.schedule.update'), [
                'week_start' => self::MONDAY,
                'is_scheduled' => false,
                'slots' => [[
                    'child_id' => $this->child->id,
                    'slot_date' => self::FRIDAY,
                    'session' => 'FULL',
                ]],
            ])
            ->assertOk();

        $this->assertFalse($this->slot(self::FRIDAY)->is_scheduled);

        // And her record is untouched: she is still a Monday-to-Friday child
        // who happens to be off this Friday.
        $this->assertSame([1, 2, 3, 4, 5], $this->child->fresh()->scheduleDays());

        // Nothing was written to the register either way — Friday has not
        // happened, so there is nothing in it to be absent from.
        $this->assertSame(0, Attendance::count());
    }

    public function test_the_tap_on_a_day_to_come_reaches_the_dot_in_one(): void
    {
        /*
         * The hour was briefly a middle step, which put a second tap in front
         * of the excuse — the common job — to save a keystroke on the rare one.
         * The tap is the yes/no; the pencil is the hour.
         */
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Expected. Tap: not attending. Pencil: the hour they are due.', $html);
        $this->assertStringContainsString("this.canPlanTime(date) && this.isScheduled(child.id, date, session)", $html);
    }

    public function test_a_day_to_come_wears_the_same_marks_as_every_other_day(): void
    {
        /*
         * One mark, one meaning, across the whole sheet.
         *
         * A future column briefly had a filled dot for "coming" and a hollow
         * ring for "not", which made the dot mean one thing on Thursday and
         * the opposite on Tuesday, in columns three inches apart. The dashed
         * box and the dot already said this, everywhere else on the sheet, and
         * a day still to come is not a different kind of day.
         */
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        // The dashed box says "expected", the dot says "not attending", and
        // both read the same on Thursday as they do on Tuesday.
        $this->assertStringContainsString("html += '<span class=\"att-dot\" aria-hidden=\"true\"></span>';", $html);
        $this->assertStringNotContainsString('att-ring', $html);

        // The hour sits inside that same box, never a colour of its own.
        $this->assertStringContainsString("html += '<span class=\"att-due\">'", $html);

        // An arrival is still a bare time and wears neither mark: that is the
        // difference between a plan and a record, and it is the one thing on
        // this sheet that must never blur.
        $this->assertStringContainsString("html += '<span>' + this.esc(this.displayTime(child.id, date, session)) + '</span>';", $html);
    }

    public function test_the_key_says_a_booked_hour_is_not_an_arrival(): void
    {
        /*
         * An hour in a box has meant "they arrived" since the first version of
         * this sheet, and dashed-versus-solid is a difference somebody has to
         * be looking for. So the key says it in words instead.
         */
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringContainsString('coming, at the hour agreed — not an arrival', $html);
    }

    private function plan(string $date, ?string $time)
    {
        return $this->actingAs($this->admin)->postJson(route('attendance.schedule.time'), [
            'child_id' => $this->child->id,
            'slot_date' => $date,
            'session' => 'FULL',
            'planned_time' => $time,
        ]);
    }

    private function slot(string $date): ScheduleSlot
    {
        return ScheduleSlot::where('child_id', $this->child->id)
            ->where('slot_date', $date)
            ->where('session', 'FULL')
            ->sole();
    }
}
