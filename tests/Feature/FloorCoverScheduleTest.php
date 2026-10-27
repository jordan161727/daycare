<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\FloorPlan;
use App\Services\ScheduleEditor;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One morning, end to end: the floor plan's "Move LaQuora to Toddler until
 * Sultana arrives" lands on a published week, and the Schedule — By room,
 * By teacher, My schedule, the wall sheet — reads it, can edit it, and reads
 * the day again once Sultana clocks in. Phase 4.2 meets Phase 7.
 */
class FloorCoverScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-10-12';

    private const DAY = '2026-10-15';   // Thursday

    private User $admin;

    private User $mary;

    private User $sultana;

    private User $denise;

    private User $laQuora;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::DAY.' 10:30:00', $this->tz));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Director']);

        foreach (range(1, 10) as $i) {
            $this->child("Tod$i", 'Toddler', '07:40');
        }
        foreach (range(1, 7) as $i) {
            $this->child("Pre$i", 'PreK', '08:00');
        }

        $this->mary = $this->teacher('Mary Jo', 'Toddler', [7, 15], '07:29');
        $this->sultana = $this->teacher('Sultana Begum', 'Toddler', [9.5, 17.5], null);
        $this->denise = $this->teacher('Denise Hall', 'PreK', [7, 15], '07:45');
        $this->laQuora = $this->teacher('LaQuora Smith', 'PreK', [8, 16], '08:00');
        // A Must rule: LaQuora is never in Infant.
        $this->laQuora->staffRules()->create(['rule_type' => 'ROOM_FORBIDDEN', 'priority' => 'HARD', 'value_text' => 'Infant']);

        // The week is published: staff can see it on My schedule.
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::WEEK])->assertRedirect();
    }

    public function test_the_floor_plan_move_shows_on_every_schedule_screen_and_the_day_settles_when_she_arrives(): void
    {
        // Before: the Schedule's Thursday has LaQuora in PreK all day; My schedule says so too.
        $this->assertSame([['Denise Hall', 'PreK', '7a → 3p'], ['LaQuora Smith', 'PreK', '8a → 4p']], $this->lanes('PreK'));
        $this->actingAs($this->laQuora)->get(route('staff-schedule.mine', ['week' => self::WEEK]))->assertOk()
            ->assertSee('8:00 AM – 4:00 PM')->assertDontSee('Floating');

        // The director presses the button on the floor plan.
        $tip = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'Toddler')['tip'];
        $this->assertSame('Move LaQuora to Toddler until Sultana arrives', $tip['action']);
        $this->actingAs($this->admin)->post(route('floor-plan.move'), $tip['move'])->assertRedirect();

        // By room, Thursday: PreK keeps the morning, Toddler gets the rest as a float.
        $this->assertSame([['Denise Hall', 'PreK', '7a → 3p'], ['LaQuora Smith', 'PreK', '8a → 10:30a']], $this->lanes('PreK'));
        $this->assertSame([['Mary Jo', 'Toddler', '7a → 3p'], ['Sultana Begum', 'Toddler', '9:30a → 5:30p'], ['LaQuora Smith', 'Toddler', '10:30a → 4p']], $this->lanes('Toddler'));
        $day = app(ScheduleEditor::class)->day(self::WEEK, 'THU');
        $float = collect($day['rooms'])->firstWhere('key', 'Toddler');
        $this->assertTrue(collect($float['lanes'])->firstWhere('name', 'LaQuora Smith')['float'], 'drawn as a float: she is in two rooms that day');

        // By teacher: LaQuora's Thursday is two lines, and the week is still published with one edit noted.
        $row = collect(app(ScheduleEditor::class)->week(self::WEEK))->firstWhere('name', 'LaQuora Smith');
        $this->assertSame([['8a – 10:30a', 'PreK'], ['10:30a – 4p', 'Toddler']], array_map(fn ($l) => [$l['time'], $l['room']], $row['days']['THU']['lines']));
        $week = StaffScheduleWeek::firstWhere('week_start', self::WEEK);
        $this->assertTrue($week->isPublished());
        $this->assertSame(1, $week->edits);

        // The pages: the Schedule (By teacher) and the day's JSON the By-room view fetches.
        $this->actingAs($this->admin)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk()
            ->assertSee('8a – 10:30a')->assertSee('10:30a – 4p');
        $this->actingAs($this->admin)->getJson(route('staff-schedule.day', ['week' => self::WEEK, 'day' => 'THU']))->assertOk()
            ->assertJsonFragment(['label' => '10:30a → 4p']);

        // My schedule, as LaQuora: both shifts, the second marked floating.
        $this->actingAs($this->laQuora)->get(route('staff-schedule.mine', ['week' => self::WEEK]))->assertOk()
            ->assertSeeInOrder(['8:00 AM – 10:30 AM', 'PreK', '10:30 AM – 4:00 PM', 'Toddler', 'Floating']);

        // The wall sheet lists the two stretches.
        $this->actingAs($this->admin)->get(route('staff-schedule.print', ['week' => self::WEEK]))->assertOk()
            ->assertSee('LaQuora Smith');

        // The director can still edit the float on the Schedule: trim it to 2:00 PM.
        $floatShift = StaffShift::where('user_id', $this->laQuora->id)->where('classroom', 'Toddler')->firstOrFail();
        $this->assertSame($this->sultana->id, $floatShift->covers_user_id);
        $this->actingAs($this->admin)->postJson(route('staff-schedule.apply'), ['week' => self::WEEK, 'day' => 'THU', 'kind' => 'trim', 'id' => $floatShift->id, 'from' => 630, 'to' => 840])
            ->assertOk()->assertJson(['saved' => true]);
        $this->assertSame([630, 840], [$floatShift->fresh()->starts_at, $floatShift->fresh()->ends_at]);
        $this->assertSame(2, $week->fresh()->edits);

        // And a Must rule still guards a move of the float: LaQuora is never in Infant.
        // (The rule is made in setUp: within one test process the route keeps its
        // controller, and with it the editor's staff list, across requests.)
        $this->actingAs($this->admin)->postJson(route('staff-schedule.apply'), ['week' => self::WEEK, 'day' => 'THU', 'kind' => 'move', 'id' => $floatShift->id, 'to' => 'Infant'])
            ->assertStatus(422);
        $this->assertSame('Toddler', $floatShift->fresh()->classroom);

        // 10:50: Sultana clocks in at the kiosk. The float closes and LaQuora's afternoon goes back to PreK.
        $this->travelTo(Carbon::parse(self::DAY.' 10:50:00', $this->tz));
        app(TimeClock::class)->punch($this->sultana, TimePunch::IN, Carbon::now(), 'kiosk');

        $this->assertSame([['Denise Hall', 'PreK', '7a → 3p'], ['LaQuora Smith', 'PreK', '8a → 10:30a'], ['LaQuora Smith', 'PreK', '10:50a → 2p']], $this->lanes('PreK'));
        $this->assertSame([['Mary Jo', 'Toddler', '7a → 3p'], ['Sultana Begum', 'Toddler', '9:30a → 5:30p'], ['LaQuora Smith', 'Toddler', '10:30a → 10:50a']], $this->lanes('Toddler'));
        $this->assertNull($floatShift->fresh()->covers_user_id);
        $this->assertSame(3, $week->fresh()->edits);

        $row = collect(app(ScheduleEditor::class)->week(self::WEEK))->firstWhere('name', 'LaQuora Smith');
        $this->assertSame([['8a – 10:30a', 'PreK'], ['10:30a – 10:50a', 'Toddler'], ['10:50a – 2p', 'PreK']], array_map(fn ($l) => [$l['time'], $l['room']], $row['days']['THU']['lines']));
        $this->actingAs($this->laQuora)->get(route('staff-schedule.mine', ['week' => self::WEEK]))->assertOk()
            ->assertSeeInOrder(['8:00 AM – 10:30 AM', '10:30 AM – 10:50 AM', 'Floating', '10:50 AM – 2:00 PM']);

        // The floor plan agrees: Toddler covered by Mary and Sultana, LaQuora home in PreK.
        $rooms = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->keyBy('room');
        $this->assertSame(['Mary', 'Sultana'], collect($rooms['Toddler']['staff'])->pluck('name')->all());
        $this->assertSame(['Denise', 'LaQuora'], collect($rooms['PreK']['staff'])->pluck('name')->all());
        $this->assertSame(['Covered', '1 spare'], [$rooms['Toddler']['status'], $rooms['PreK']['status']]);
    }

    public function test_a_second_clock_in_the_same_day_does_not_touch_a_closed_cover(): void
    {
        $tip = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'Toddler')['tip'];
        $this->actingAs($this->admin)->post(route('floor-plan.move'), $tip['move'])->assertRedirect();

        $this->travelTo(Carbon::parse(self::DAY.' 10:50:00', $this->tz));
        app(TimeClock::class)->punch($this->sultana, TimePunch::IN, Carbon::now(), 'kiosk');
        $this->travelTo(Carbon::parse(self::DAY.' 12:00:00', $this->tz));
        app(TimeClock::class)->punch($this->sultana, TimePunch::OUT, Carbon::now(), 'kiosk');
        $this->travelTo(Carbon::parse(self::DAY.' 12:30:00', $this->tz));
        app(TimeClock::class)->punch($this->sultana, TimePunch::IN, Carbon::now(), 'kiosk');

        $this->assertSame([[480, 630, 'PreK'], [630, 650, 'Toddler'], [650, 960, 'PreK']],
            StaffShift::where('user_id', $this->laQuora->id)->orderBy('starts_at')->get()->map(fn ($s) => [$s->starts_at, $s->ends_at, $s->classroom])->all());
    }

    /** @return list<array{string,string,string}> name, room, label for the room's lanes on Thursday */
    private function lanes(string $room): array
    {
        $day = app(ScheduleEditor::class)->day(self::WEEK, 'THU');
        $r = collect($day['rooms'])->firstWhere('key', $room);

        return collect($r['lanes'])->sortBy('from')->values()->map(fn ($l) => [$l['name'], $room, $l['label']])->all();
    }

    private function child(string $name, string $room, string $in): void
    {
        $child = Child::create(['lan' => (string) (1000 + Child::count()), 'first_name' => $name, 'last_name' => 'Kid', 'status' => 'Active', 'classroom' => $room]);
        ScheduleSlot::create(['week_start' => self::WEEK, 'child_id' => $child->id, 'slot_date' => self::DAY, 'session' => 'FULL', 'is_scheduled' => true]);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' '.$in, $this->tz)->addMinutes(Child::count())]);
    }

    private function teacher(string $name, string $home, array $shift, ?string $clockIn): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $home, 'employment' => 'FT', 'title' => $home]);
        StaffShift::create(['week_start' => self::WEEK, 'user_id' => $user->id, 'shift_date' => self::DAY, 'day' => 'THU', 'starts_at' => (int) ($shift[0] * 60), 'ends_at' => (int) ($shift[1] * 60), 'classroom' => $home, 'role' => StaffShift::ROLE_STAFF]);
        if ($clockIn) {
            TimePunch::create(['user_id' => $user->id, 'work_date' => self::DAY, 'punched_at' => Carbon::parse(self::DAY.' '.$clockIn, $this->tz), 'type' => TimePunch::IN, 'source' => 'kiosk']);
        }

        return $user;
    }
}
