<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\ScheduleEditor;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA retest, 18 Nov 2026 — the schedule when staff-hours run short:
 *
 *   1  the generator gave everybody 10-hour days Monday to Thursday and
 *      left Friday with nobody in any room
 *   2  "Schedule generated with 76 things to look at", in green, never said
 *      the cause: too few staff-hours for the bookings
 *   3  "Who could cover" only ever offered the two openers
 *
 * Settings as QA had them: open 7:00, close 18:00, others from 7:30, longest
 * shift 10h, most 40h a week. Today is Tuesday 17 Nov 2026, 9:00 AM; the
 * week solved is the next one, Nov 23–27, which nobody has opened.
 */
class StaffScheduleQaNov18Test extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-11-23';

    private const DAYS = ['MON' => '2026-11-23', 'TUE' => '2026-11-24', 'WED' => '2026-11-25', 'THU' => '2026-11-26', 'FRI' => '2026-11-27'];

    private User $admin;

    private int $lan = 7000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 09:00:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** 1 — six teachers, children in every room all week, 7a–6p. */
    public function test_a_short_week_spreads_the_shortage_and_leaves_no_day_empty(): void
    {
        $staff = $this->staff(['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'], openers: [0, 2]);
        foreach (['Infant' => 4, 'Transition' => 5, 'Toddler' => 8, 'PreK' => 10, 'UPK-4' => 8, 'School Age' => 6] as $room => $n) {
            $this->children($room, $n);
        }

        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);
        $shifts = StaffShift::where('week_start', self::WEEK)->get();

        // No open day is left with nobody, and the hours are spread: no day
        // gets much more than another while somebody is short elsewhere.
        $byDay = collect(self::DAYS)->map(fn ($date, $day) => $shifts->where('day', $day));
        foreach ($byDay as $day => $theirs) {
            $this->assertNotEmpty($theirs, "$day has no staff at all");
        }
        $minutes = $byDay->map(fn ($s) => $s->sum(fn (StaffShift $x) => $x->minutes()));
        $this->assertLessThanOrEqual($minutes->min() * 1.2, $minutes->max(), 'staff-hours spread across the week: '.json_encode($minutes));

        // Every teacher works Friday — none has burned their hours by Thursday.
        $this->assertCount(6, $shifts->where('day', 'FRI')->pluck('user_id')->unique());

        // The limits still hold.
        foreach ($shifts->groupBy(fn ($s) => $s->user_id.'|'.$s->day) as $day) {
            $this->assertLessThanOrEqual(600, $day->max('ends_at') - $day->min('starts_at'), 'no day over 10h');
        }
        foreach ($shifts->groupBy('user_id') as $theirs) {
            $this->assertLessThanOrEqual(2400, $theirs->sum(fn (StaffShift $s) => $s->minutes()), 'nobody over 40h');
        }
        $openers = [$staff[0]->id, $staff[2]->id];
        $this->assertTrue($shifts->reject(fn ($s) => in_array($s->user_id, $openers, true))->every(fn ($s) => $s->starts_at >= 450), 'non-openers from 7:30');

        // Somebody opens and somebody closes, every day.
        foreach ($byDay as $day => $theirs) {
            $this->assertTrue($theirs->contains(fn ($s) => $s->starts_at <= 420), "$day: nobody at 7:00");
            $this->assertTrue($theirs->contains(fn ($s) => $s->ends_at >= 1080), "$day: nobody at 6:00 PM");
        }

        // One adult in every booked room all day is impossible on these
        // hours — and it is said so, not hidden.
        $summary = app(ScheduleEditor::class)->summary(self::WEEK);
        $this->assertGreaterThan(0, $summary['short']);
        $this->assertSame('bad', $summary['state']);
        $this->assertStringStartsWith('Short about', $summary['headline']);
        $this->assertNotEmpty($summary['empty'], 'the stretches with nobody in a room are listed');
    }

    /** 1 — hours the later days do not need are still used as cover. */
    public function test_spare_hours_still_cover_a_gap_without_emptying_a_later_day(): void
    {
        // Two teachers in Infant, one part-time teacher with room to spare.
        $this->staff(['Infant', 'Infant'], openers: [0]);
        $part = $this->staff(['Toddler'], employment: 'PT')[0];
        $this->children('Infant', 6);

        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);
        $shifts = StaffShift::where('week_start', self::WEEK)->get();

        foreach (self::DAYS as $day => $date) {
            $this->assertTrue($shifts->where('day', $day)->contains('user_id', $part->id), "$day: the part-timer still works their own day");
        }
        $this->assertLessThanOrEqual(2400, $shifts->where('user_id', $part->id)->sum(fn ($s) => $s->minutes()));
    }

    /** 2 — the numbers: need from bookings and ratios, have from contracts. */
    public function test_the_summary_says_how_many_staff_hours_are_short(): void
    {
        $this->staff(['Infant']);
        $this->children('Infant', 4);   // 1:4 — one adult 7a–6p, 11h a day
        // A shared room is counted as one: UPK-4 1:10 and School Age 1:15
        // together need one adult, not two.
        $this->children('UPK-4', 5);
        $this->children('School Age', 3);

        $summary = app(ScheduleEditor::class)->summary(self::WEEK);

        $this->assertSame(2 * 55 * 60, $summary['need'], 'Infant 55h + the shared room 55h');
        $this->assertSame(40 * 60, $summary['have']);
        $this->assertSame(70 * 60, $summary['short']);
        $this->assertSame('Short about 70 staff-hours this week — needs ~110, have 40. Add staff, substitutes or floaters, or reduce bookings.', $summary['headline']);
    }

    /** 2 — a closed day and a day of leave come off what is available. */
    public function test_closed_days_and_leave_come_off_the_hours_available(): void
    {
        [$a, $b] = $this->staff(['Infant', 'Infant']);
        ClosureDay::create(['closed_on' => '2026-11-26', 'reason' => 'Thanksgiving']);
        StaffTimeOff::create(['user_id' => $b->id, 'date' => '2026-11-23', 'kind' => 'all', 'reason' => 'sick']);

        $summary = app(ScheduleEditor::class)->summary(self::WEEK);

        // 80h, less 8h each for Thursday, less 8h for b's Monday.
        $this->assertSame((80 - 16 - 8) * 60, $summary['have']);
    }

    /** 2 — green, amber, red. */
    public function test_the_banner_is_green_only_with_nothing_to_look_at(): void
    {
        [$a, $b] = $this->staff(['Infant', 'Infant'], openers: [0]);
        $kids = $this->children('Infant', 4);
        StaffScheduleWeek::create(['week_start' => self::WEEK, 'generated_at' => now()]);
        foreach (self::DAYS as $day => $date) {
            $this->shift($a, $day, 'Infant', 420, 900);
            $this->shift($b, $day, 'Infant', 600, 1080);
        }

        // A fresh controller each time, as each real request has: in a test
        // the route keeps the first one, and its editor keeps the week it read.
        $page = function () {
            foreach (app('router')->getRoutes() as $route) {
                $route->controller = null;
            }

            return $this->actingAs($this->admin)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk();
        };

        $summary = app(ScheduleEditor::class)->summary(self::WEEK);
        $this->assertSame('ok', $summary['state']);
        $this->assertSame('Every room at ratio all week.', $summary['headline']);
        $page()->assertSee('data-state="ok"', false);

        // A fifth child: two adults needed, only one 7–10 and 3–6 — amber.
        $this->children('Infant', 1);
        $summary = app(ScheduleEditor::class)->summary(self::WEEK);
        $this->assertSame('warn', $summary['state']);
        $this->assertStringContainsString('Infant short 7a–10a — 1 of 2 staff', implode(' | ', $summary['days'][0]['lines']));
        $page()->assertSee('data-state="warn"', false)->assertSee('Gaps by day');

        // Nobody on Friday at all — red, and said for that day.
        StaffShift::where('day', 'FRI')->delete();
        $summary = app(ScheduleEditor::class)->summary(self::WEEK);
        $this->assertSame('bad', $summary['state']);
        $friday = collect($summary['days'])->firstWhere('day', 'FRI');
        $this->assertSame('No staff at all this day while children are booked.', $friday['lines'][0]);
        $page()->assertSee('data-state="bad"', false)->assertSee('Children booked with nobody in their room');
        $this->assertNotEmpty($kids);
    }

    /** 2 — generating no longer says "N things to look at" in green. */
    public function test_generating_shows_the_summary_not_a_count(): void
    {
        $this->staff(['Infant']);
        $this->children('Infant', 4);

        $this->actingAs($this->admin)->post(route('staff-schedule.generate'), ['week' => self::WEEK])
            ->assertSessionHas('generated')->assertSessionMissing('success');

        $this->actingAs($this->admin)->get(route('staff-schedule.index', ['week' => self::WEEK]))
            ->assertSee('Schedule generated. It is a draft until you publish it.')
            ->assertSee('Short about 15 staff-hours this week')
            ->assertDontSee('things to look at');
    }

    /** 3 — the brief's case: one short slot at 10:00, four people free. */
    public function test_everybody_free_for_a_short_slot_is_suggested(): void
    {
        $staff = $this->staff(['Infant', 'Toddler', 'PreK', 'Transition', 'UPK-4', 'School Age'], openers: [0, 1]);
        $this->children('Infant', 4, drop: '10:00', pick: '10:30');
        $this->shift($staff[0], 'FRI', 'Toddler', 420, 900);
        $this->shift($staff[1], 'FRI', 'PreK', 420, 900);

        $cover = $this->cover('FRI', 'Infant');

        $this->assertSame('10a–10:30a', $cover['when']);
        $this->assertEqualsCanonicalizing(
            [$staff[2]->name, $staff[3]->name, $staff[4]->name, $staff[5]->name],
            array_column($cover['who'], 'name'),
        );
    }

    /** 3 — a gap from 7:00 offers the people who start at 7:30, not only openers. */
    public function test_a_gap_from_opening_offers_people_who_start_later(): void
    {
        $staff = $this->staff(['Infant', 'Toddler', 'PreK', 'Transition', 'UPK-4', 'School Age'], openers: [0, 1]);
        $this->children('Infant', 4, drop: '07:00', pick: '11:00');
        $this->shift($staff[0], 'FRI', 'Toddler', 420, 900);

        $cover = $this->cover('FRI', 'Infant');
        $names = array_column($cover['who'], 'name');

        $this->assertContains($staff[1]->name, $names, 'the other opener');
        foreach ([2, 3, 4, 5] as $i) {
            $this->assertContains($staff[$i]->name, $names, 'non-openers are offered from 7:30');
        }
        $this->assertStringContainsString('free 7:30a–11a', collect($cover['who'])->firstWhere('name', $staff[2]->name)['note']);
    }

    /** 3 — limits are offered after, with why; Must rules are explained. */
    public function test_people_past_a_limit_come_after_with_the_reason_and_the_rest_are_explained(): void
    {
        $staff = $this->staff(['Infant', 'Toddler', 'PreK', 'Transition', 'UPK-4', 'School Age'], openers: [0, 1]);
        $this->children('Infant', 4, drop: '10:00', pick: '10:30');
        $this->shift($staff[0], 'FRI', 'Toddler', 420, 900);
        // 40 hours Monday to Thursday.
        foreach (['MON', 'TUE', 'WED', 'THU'] as $day) {
            $this->shift($staff[4], $day, 'UPK-4', 420, 1020);
        }
        $staff[5]->staffRules()->create(['rule_type' => 'UNAVAILABLE_DAY', 'priority' => 'HARD', 'day' => 'FRI']);

        $cover = $this->cover('FRI', 'Infant');

        $this->assertEqualsCanonicalizing([$staff[1]->name, $staff[2]->name, $staff[3]->name], array_column($cover['who'], 'name'));
        $this->assertSame([$staff[4]->name], array_column($cover['soft'], 'name'));
        $this->assertStringStartsWith('at 40h this week', $cover['soft'][0]['note']);
        $this->assertStringContainsString($staff[5]->name.' — can’t work Fri', $cover['why']);
        $this->assertStringContainsString($staff[0]->name.' — working elsewhere then', $cover['why']);
    }

    /* ----------------------------------------------------------------- */

    private function cover(string $day, string $room): array
    {
        $json = $this->actingAs($this->admin)->getJson(route('staff-schedule.day', ['week' => self::WEEK, 'day' => $day]))->assertOk()->json();
        $cover = collect($json['cover'])->firstWhere('room', $room);
        $this->assertNotNull($cover, "$room is short on $day");

        return $cover;
    }

    /** @return list<User> */
    private function staff(array $rooms, array $openers = [], string $employment = 'FT'): array
    {
        static $n = 0;
        $out = [];
        foreach ($rooms as $i => $room) {
            $n++;
            $out[] = $user = User::create([
                'name' => "{$room} Teacher {$n}", 'email' => "t{$n}@example.test", 'password' => 'password',
                'role' => 'teacher', 'employment' => $employment, 'title' => $room, 'classroom' => $room,
            ]);
            if (in_array($i, $openers, true)) {
                $user->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD']);
            }
        }

        return $out;
    }

    /** @return list<Child> */
    private function children(string $room, int $count, ?string $drop = null, ?string $pick = null): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $this->lan++;
            $out[] = Child::create([
                'lan' => (string) $this->lan, 'first_name' => $room, 'last_name' => "Kid{$this->lan}", 'status' => 'Active',
                'classroom' => $room, 'classroom_override' => $room, 'schedule_days' => [1, 2, 3, 4, 5],
                'drop_off_time' => $drop, 'pick_up_time' => $pick,
            ]);
        }

        return $out;
    }

    private function shift(User $user, string $day, string $room, int $from, int $to): void
    {
        StaffShift::create([
            'week_start' => self::WEEK, 'user_id' => $user->id, 'shift_date' => self::DAYS[$day], 'day' => $day,
            'starts_at' => $from, 'ends_at' => $to, 'classroom' => $room, 'role' => StaffShift::ROLE_STAFF,
        ]);
    }
}
