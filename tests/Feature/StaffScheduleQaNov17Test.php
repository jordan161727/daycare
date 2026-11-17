<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\DashboardSnapshot;
use App\Services\RoomDemand;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA with real saves, 17 Nov 2026 — staff scheduling, closures:
 *
 *   1  the week arrows all pointed at the week on screen
 *   2  the generator broke the longest shift, the week's most and the
 *      earliest start for non-openers (it read config, not Settings)
 *   3  a week not built yet had no children in it, so nobody covered 3–6
 *   4  closing a day left the staff shifts on it
 *   5  a draft schedule made staff late and counted toward ratios, and was
 *      written onto days already gone by
 *
 * Settings as QA had them: open 7:00, close 18:00, others from 7:30, longest
 * shift 10h, most 40h a week. Today is Tuesday 17 Nov 2026, 9:00 AM.
 */
class StaffScheduleQaNov17Test extends TestCase
{
    use RefreshDatabase;

    private const THIS_WEEK = '2026-11-16';

    private const NEXT_WEEK = '2026-11-23';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 09:00:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** 1 */
    public function test_the_week_arrows_go_to_the_weeks_either_side_and_keep_the_view(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('staff-schedule.index', ['week' => '2026-11-30', 'view' => 'room', 'day' => 'WED']))
            ->assertOk()->getContent();

        $href = function (string $label) use ($html) {
            preg_match('/<a class="btn" href="([^"]+)">'.preg_quote($label, '/').'<\/a>/u', $html, $m);

            return html_entity_decode($m[1] ?? '');
        };

        $this->assertSame(route('staff-schedule.index', ['week' => '2026-11-23', 'view' => 'room', 'day' => 'WED']), $href('‹ Week'));
        $this->assertSame(route('staff-schedule.index', ['week' => '2026-12-07', 'view' => 'room', 'day' => 'WED']), $href('Week ›'));
        $this->assertSame(route('staff-schedule.index', ['week' => self::THIS_WEEK, 'view' => 'room', 'day' => 'WED']), $href('This week'));
    }

    /** 2 + 3 */
    public function test_a_week_not_built_yet_is_staffed_for_its_bookings_inside_the_limits(): void
    {
        $staff = $this->sixTeachers();
        // Full-day children, booked by their standing days only — the week
        // of the 23rd has never been opened, so no ticks exist for it.
        $this->children('Infant', 6);
        $this->children('Toddler', 8);
        $this->children('PreK', 12);
        $wednesdayOnly = $this->children('Transition', 1, days: [3])[0];
        $this->assertSame(0, \App\Models\ScheduleSlot::where('week_start', self::NEXT_WEEK)->count(), 'nothing built');

        // Bug 3: the bookings are counted, room by room, day by day.
        $demand = app(RoomDemand::class)->forWeek(self::NEXT_WEEK);
        $peak = fn (string $day, string $room) => app(RoomDemand::class)->peak($demand[$day][$room] ?? []);
        $this->assertSame([6, 8, 12, 0, 1], [$peak('MON', 'Infant'), $peak('MON', 'Toddler'), $peak('MON', 'PreK'), $peak('MON', 'Transition'), $peak('WED', 'Transition')]);
        $this->assertNotNull($wednesdayOnly);

        $week = app(StaffSchedule::class)->generate(self::NEXT_WEEK, $this->admin);
        $shifts = StaffShift::where('week_start', self::NEXT_WEEK)->get();
        $openers = collect($staff)->filter(fn (User $u) => $u->staffRules()->where('rule_type', 'CAN_OPEN')->exists())->pluck('id')->all();

        // Bug 2: the limits, every one.
        $this->assertTrue($shifts->every(fn (StaffShift $s) => $s->minutes() <= 600), 'no shift over 10h');
        foreach ($shifts->groupBy(fn ($s) => $s->user_id.'|'.$s->shift_date->toDateString()) as $day) {
            $this->assertLessThanOrEqual(600, $day->max('ends_at') - $day->min('starts_at'), 'nobody\'s day runs past 10h');
        }
        foreach ($shifts->groupBy('user_id') as $theirs) {
            $this->assertLessThanOrEqual(2400, $theirs->sum(fn (StaffShift $s) => $s->minutes()), 'nobody over 40h');
        }
        $this->assertTrue($shifts->reject(fn ($s) => in_array($s->user_id, $openers, true))->every(fn ($s) => $s->starts_at >= 450), 'non-openers from 7:30');

        // Bug 3: somebody is on from 3 to 6 wherever children are, or the gap is listed.
        $coverage = app(StaffSchedule::class)->coverage(self::NEXT_WEEK);
        foreach ($coverage as $day => $rooms) {
            foreach ($rooms as $room => $bands) {
                foreach ($bands as $band) {
                    if ($band['to'] > 900 && $band['have'] < $band['need']) {
                        $this->assertTrue(collect($week->warnings)->contains(fn ($w) => str_starts_with($w, "$day $room,") && str_contains($w, 'short by')), "$day $room short at ".$band['from'].' but not reported');
                    }
                }
            }
        }
        $this->assertTrue($shifts->contains(fn (StaffShift $s) => $s->ends_at >= 1050), 'the late afternoon is staffed');

        // Gaps the limits leave are "things to look at", never "no conflicts".
        $this->assertNotEmpty(collect($week->warnings)->filter(fn ($w) => str_contains($w, 'short by')));
        $day = $this->actingAs($this->admin)->getJson(route('staff-schedule.day', ['week' => self::NEXT_WEEK, 'day' => 'MON']))->json();
        $this->assertFalse($day['ok'], 'not "Every room at ratio" while a room is short');
        $this->assertStringContainsString('12 children at peak', collect($day['rooms'])->firstWhere('key', 'PreK')['kidsLine']);
    }

    /** 4 */
    public function test_closing_a_day_takes_its_shifts_off_and_reopening_puts_them_back(): void
    {
        $this->sixTeachers();
        $this->children('Toddler', 8);
        app(StaffSchedule::class)->generate(self::NEXT_WEEK, $this->admin);
        $thursday = StaffShift::whereDate('shift_date', '2026-11-26')->count();
        $this->assertGreaterThan(0, $thursday);
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::NEXT_WEEK]);

        $this->actingAs($this->admin)->post(route('holidays.store'), ['closed_on' => '2026-11-26', 'reason' => 'Thanksgiving'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, "{$thursday} staff shift(s)") && str_contains($m, 'let them know'));
        $this->assertSame(0, StaffShift::whereDate('shift_date', '2026-11-26')->count(), 'no shifts on a closed day');

        $page = $this->actingAs($this->admin)->get(route('staff-schedule.index', ['week' => self::NEXT_WEEK]))->assertOk();
        $page->assertSee('data-day-closed="2026-11-26"', false)->assertSee('Closed · Thanksgiving');

        // The reopen dialog says what happens to the staff too.
        $holidays = $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk();
        $closure = ClosureDay::firstWhere('closed_on', '2026-11-26');
        $this->assertSame($thursday, $holidays->viewData('staffRestores')[$closure->id]['shifts']);

        $this->actingAs($this->admin)->delete(route('holidays.destroy', $closure))
            ->assertSessionHas('success', fn ($m) => str_contains($m, "{$thursday} staff shift(s)") && str_contains($m, 'put back on the schedule'));
        $this->assertSame($thursday, StaffShift::whereDate('shift_date', '2026-11-26')->count(), 'reopening restores them');
    }

    /** 4 — a week generated again after the closure is not given old shifts back. */
    public function test_reopening_after_the_week_was_generated_again_says_to_generate(): void
    {
        $this->sixTeachers();
        $this->children('Toddler', 8);
        app(StaffSchedule::class)->generate(self::NEXT_WEEK, $this->admin);
        $this->actingAs($this->admin)->post(route('holidays.store'), ['closed_on' => '2026-11-26']);
        $this->travel(5)->minutes();
        app(StaffSchedule::class)->generate(self::NEXT_WEEK, $this->admin);

        $this->actingAs($this->admin)->delete(route('holidays.destroy', ClosureDay::firstWhere('closed_on', '2026-11-26')))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'were not put back') && str_contains($m, 'Generate the week again'));
        $this->assertSame(0, StaffShift::whereDate('shift_date', '2026-11-26')->count());
    }

    /** 5 */
    public function test_a_draft_is_not_live_and_late_runs_from_the_publish(): void
    {
        $staff = $this->sixTeachers();
        $this->children('Toddler', 8);

        // A week already started: Monday is left alone.
        app(StaffSchedule::class)->generate(self::THIS_WEEK, $this->admin);
        $this->assertSame(0, StaffShift::whereDate('shift_date', '2026-11-16')->count(), 'no shifts for a day gone by');
        $this->assertGreaterThan(0, StaffShift::whereDate('shift_date', '2026-11-17')->count());

        // Draft: nobody late, nothing scheduled as far as ratios go.
        $this->assertSame(0, StaffShift::live()->whereDate('shift_date', '2026-11-17')->count());
        $timesheets = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Not in · due', $timesheets);
        $this->assertStringNotContainsString('Late · due', $timesheets);
        $toddler = collect(app(DashboardSnapshot::class)->for($this->admin)['ratios'])->firstWhere('room', 'Toddler');
        $this->assertSame(0, $toddler['rostered'], 'the draft is not "on today\'s schedule"');

        // Published at 9:00: live now, but nobody due at 7:00 or 7:30 is late
        // for a schedule they could not have seen — late counts from 9:00.
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::THIS_WEEK]);
        $today = StaffShift::live()->whereDate('shift_date', '2026-11-17')->get();
        $this->assertGreaterThan(0, $today->count());
        $early = $today->firstWhere(fn (StaffShift $s) => $s->starts_at < 540);
        $this->assertSame(540, $early->lateFrom(), 'from the publish, not the start');
        $this->assertFalse($early->isLateAt(9 * 60 + 2, 5));
        $this->assertSame(1, collect(app(DashboardSnapshot::class)->for($this->admin)['ratios'])->firstWhere('room', 'Toddler')['rostered'] > 0 ? 1 : 0);

        $this->travelTo(Carbon::parse('2026-11-17 09:02:00', config('app.timezone')));
        $timesheets = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Not in · due', $timesheets, 'nobody late two minutes after publishing');

        // Tomorrow's shifts were published the day before: late from their start.
        $wednesday = StaffShift::whereDate('shift_date', '2026-11-18')->orderBy('starts_at')->first();
        $this->assertSame($wednesday->starts_at, $wednesday->lateFrom());

        // Regenerating makes it a draft again.
        app(StaffSchedule::class)->generate(self::THIS_WEEK, $this->admin);
        StaffScheduleWeek::where('week_start', self::THIS_WEEK)->update(['published_at' => null]);
        $this->assertSame(0, StaffShift::live()->whereDate('shift_date', '2026-11-18')->count());
        $this->assertNotEmpty($staff);
    }

    /** 5 — the past days are written only when asked for. */
    public function test_past_days_are_generated_only_when_asked(): void
    {
        $this->sixTeachers();
        $this->children('Toddler', 8);

        app(StaffSchedule::class)->generate(self::THIS_WEEK, $this->admin, includePast: true);
        $this->assertGreaterThan(0, StaffShift::whereDate('shift_date', '2026-11-16')->count());

        // And a later generate leaves Monday as it was.
        $monday = StaffShift::whereDate('shift_date', '2026-11-16')->orderBy('id')->pluck('id')->all();
        app(StaffSchedule::class)->generate(self::THIS_WEEK, $this->admin);
        $this->assertSame($monday, StaffShift::whereDate('shift_date', '2026-11-16')->orderBy('id')->pluck('id')->all());
    }

    /** @return list<User> six FT teachers, one a keyholder */
    private function sixTeachers(): array
    {
        $rooms = ['Infant', 'Toddler', 'PreK', 'Transition', 'Infant', 'Toddler'];
        $out = [];
        foreach ($rooms as $i => $room) {
            $out[] = $user = User::create([
                'name' => "Teacher {$i}", 'email' => "teacher{$i}@example.test", 'password' => 'password',
                'role' => 'teacher', 'employment' => 'FT', 'title' => $room, 'classroom' => $room,
            ]);
            if ($i === 0) {
                $user->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD']);
            }
        }

        return $out;
    }

    /** @return list<Child> */
    private function children(string $room, int $count, array $days = [1, 2, 3, 4, 5]): array
    {
        static $lan = 5000;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = Child::create([
                'lan' => (string) ++$lan, 'first_name' => $room, 'last_name' => "Kid{$lan}", 'status' => 'Active',
                'classroom' => $room, 'classroom_override' => $room, 'schedule_days' => $days,
            ]);
        }

        return $out;
    }
}
