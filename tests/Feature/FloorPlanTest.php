<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\FloorPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The floor plan — Phase 4.2 of docs/work.md: the building room by room,
 * read off the same day the dashboard reads, with the ratio check, the
 * staff dots, the tips, the afternoon view and the room panel.
 */
class FloorPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tz;

    private const DAY = '2026-10-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::DAY.' 10:30:00', $this->tz));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_short_room_is_flagged_and_told_who_could_cover(): void
    {
        // Ten toddlers in, at 1:6: two adults needed.
        foreach (range(1, 10) as $i) {
            $child = Child::create(['lan' => "2$i", 'first_name' => "Tod$i", 'last_name' => 'Dler', 'status' => 'Active', 'classroom' => 'Toddler']);
            $this->tick($child);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 07:40', $this->tz)->addMinutes($i)]);
        }
        // Seven in PreK at 1:10 with two adults: one to spare.
        foreach (range(1, 7) as $i) {
            $child = Child::create(['lan' => "3$i", 'first_name' => "Pre$i", 'last_name' => 'Kay', 'status' => 'Active', 'classroom' => 'PreK']);
            $this->tick($child);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 08:00', $this->tz)->addMinutes($i)]);
        }

        $maryJo = $this->teacher('Mary Jo', 'Toddler', clockIn: '07:29', shift: [7, 15, 'Toddler']);
        $sultana = $this->teacher('Sultana Begum', 'Toddler', clockIn: null, shift: [9.5, 17.5, 'Toddler']);
        $this->teacher('Denise Hall', 'PreK', clockIn: '07:45', shift: [7, 15, 'PreK']);
        $laQuora = $this->teacher('LaQuora Smith', 'PreK', clockIn: '08:00', shift: [8, 16, 'PreK']);

        $plan = app(FloorPlan::class)->for($this->admin);
        $rooms = collect($plan['rooms'])->keyBy('room');

        // Toddler: short one, Sultana late, LaQuora free to cover.
        $toddler = $rooms['Toddler'];
        $this->assertSame(['Short 1', 'short', 10, 1, 2], [$toddler['status'], $toddler['tone'], $toddler['in'], $toddler['staffIn'], $toddler['needed']]);
        $staff = collect($toddler['staff'])->keyBy('name');
        $this->assertSame(['in', '7:29 AM'], [$staff['Mary']['status'], $staff['Mary']['in']]);
        $this->assertSame(['late', '9:30', '9:30 AM'], [$staff['Sultana']['status'], $staff['Sultana']['due'], $staff['Sultana']['dueFull']]);
        $this->assertSame("10 toddlers need 2 staff at 1:6. Sultana was due 9:30 and hasn't clocked in. PreK has 7 children and 2 staff, so LaQuora is free to cover.", $toddler['tip']['text']);
        $this->assertSame('Move LaQuora to Toddler until Sultana arrives', $toddler['tip']['action']);

        // PreK: one spare. The counters add up.
        $this->assertSame(['1 spare', 'spare'], [$rooms['PreK']['status'], $rooms['PreK']['tone']]);
        $this->assertSame(['in' => 17, 'returning' => 0, 'out' => 0, 'expected' => 0, 'staff' => 3, 'short' => 1], $plan['counters']);

        // And the page: the counters, the map, the flag, the panel's data.
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSee('Floor plan')
            ->assertSee('Live · updated 10:30 AM')
            ->assertSeeInOrder(['Checked in', '17', 'Rooms below ratio', '1'])
            ->assertSee('data-room="Toddler" data-room-tone="short"', false)
            ->assertSee('in · cap 10')
            ->assertSee('1 staff · need 2')
            ->assertSee('Front door · fire panel')->assertSee('Girls bathroom')->assertSee('Kitchen')->assertSee('Staff bathroom')
            ->assertSee('Move LaQuora to Toddler until Sultana arrives')
            ->assertSee('data-view-toggle', false);
    }

    public function test_school_age_away_says_who_is_coming_back_and_the_afternoon_counts_them_in(): void
    {
        $upk = Child::create(['lan' => '41', 'first_name' => 'Uma', 'last_name' => 'Pk', 'status' => 'Active', 'classroom' => 'UPK-4']);
        $this->tick($upk);
        Attendance::create(['child_id' => $upk->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 08:05', $this->tz)]);
        foreach (range(1, 6) as $i) {
            $child = Child::create(['lan' => "5$i", 'first_name' => "Sage$i", 'last_name' => 'Older', 'status' => 'Active', 'classroom' => 'School Age', 'dob' => '2019-03-02']);
            $this->tick($child, 'AM');
            $this->tick($child, 'PM', '15:00');
            Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'AM', 'signed_in_at' => Carbon::parse(self::DAY.' 07:15', $this->tz), 'signed_out_at' => Carbon::parse(self::DAY.' 08:20', $this->tz)]);
        }
        $this->teacher('Amanda Cole', 'School Age', clockIn: null, shift: [14.5, 18, 'School Age']);
        $this->teacher('Beverly Ng', 'UPK-4', clockIn: '07:30', shift: [7, 15, 'UPK-4']);

        $morning = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->keyBy('room');
        $this->assertSame(['Away', 'away', 0, 6, '3:00 PM'], [$morning['School Age']['status'], $morning['School Age']['tone'], $morning['School Age']['in'], $morning['School Age']['returning'], $morning['School Age']['returnLabel']]);
        $this->assertSame('School Age children are at school. 6 are booked back at 3:00 PM — that needs 1 staff at 1:15.', $morning['School Age']['tip']['text']);
        $this->assertSame('due', collect($morning['School Age']['staff'])->firstWhere('name', 'Amanda')['status']);

        // The afternoon, as booked: the six are back, Amanda is on, Beverly is off.
        $afternoon = app(FloorPlan::class)->for($this->admin, 'afternoon');
        $rooms = collect($afternoon['rooms'])->keyBy('room');
        $this->assertSame('afternoon', $afternoon['view']);
        $this->assertSame(['Covered', 6, 1], [$rooms['School Age']['status'], $rooms['School Age']['in'], $rooms['School Age']['staffIn']]);
        $this->assertSame('Short 1', $rooms['UPK-4']['status'], 'Uma is still in, Beverly has gone');

        $this->actingAs($this->admin)->get(route('floor-plan.index', ['view' => 'afternoon']))->assertOk()
            ->assertSee('Afternoon · as booked')
            ->assertSee('at 3:00 PM');
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()
            ->assertSee('6 checked out · returning at 3:00 PM');
    }

    public function test_the_move_button_puts_the_cover_on_todays_roster_until_the_late_teacher_clocks_in(): void
    {
        foreach (range(1, 10) as $i) {
            $child = Child::create(['lan' => "2$i", 'first_name' => "Tod$i", 'last_name' => 'Dler', 'status' => 'Active', 'classroom' => 'Toddler']);
            $this->tick($child);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 07:40', $this->tz)->addMinutes($i)]);
        }
        foreach (range(1, 7) as $i) {
            $child = Child::create(['lan' => "3$i", 'first_name' => "Pre$i", 'last_name' => 'Kay', 'status' => 'Active', 'classroom' => 'PreK']);
            $this->tick($child);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => Carbon::parse(self::DAY.' 08:00', $this->tz)->addMinutes($i)]);
        }
        $this->teacher('Mary Jo', 'Toddler', clockIn: '07:29', shift: [7, 15, 'Toddler']);
        $sultana = $this->teacher('Sultana Begum', 'Toddler', clockIn: null, shift: [9.5, 17.5, 'Toddler']);
        $this->teacher('Denise Hall', 'PreK', clockIn: '07:45', shift: [7, 15, 'PreK']);
        $laQuora = $this->teacher('LaQuora Smith', 'PreK', clockIn: '08:00', shift: [8, 16, 'PreK']);

        // The page carries the move with the button; a teacher gets the advice, not the button.
        $tip = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->firstWhere('room', 'Toddler')['tip'];
        $this->assertSame(['user_id' => $laQuora->id, 'to' => 'Toddler', 'for' => $sultana->id], $tip['move']);
        $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertSee('data-room-move', false);
        $this->actingAs(User::factory()->create(['role' => 'teacher']))->get(route('floor-plan.index'))->assertDontSee('data-room-move', false);
        $this->actingAs(User::factory()->create(['role' => 'teacher']))->post(route('floor-plan.move'), $tip['move'])->assertForbidden();

        // Press it at 10:30: LaQuora's PreK shift ends now and a float in Toddler takes the rest.
        $this->actingAs($this->admin)->post(route('floor-plan.move'), $tip['move'])
            ->assertRedirect(route('floor-plan.index', ['room' => 'Toddler']))
            ->assertSessionHas('floor', 'LaQuora is in Toddler from 10:30 AM until Sultana clocks in.');

        $shifts = StaffShift::where('user_id', $laQuora->id)->orderBy('starts_at')->get();
        $this->assertSame([[480, 630, 'PreK', 'teacher', null], [630, 960, 'Toddler', StaffShift::ROLE_FLOAT, $sultana->id]],
            $shifts->map(fn (StaffShift $s) => [$s->starts_at, $s->ends_at, $s->classroom, $s->role, $s->covers_user_id])->all());

        $rooms = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->keyBy('room');
        $this->assertSame(['Covered', 2, null], [$rooms['Toddler']['status'], $rooms['Toddler']['staffIn'], $rooms['Toddler']['tip']]);
        $this->assertSame(['Covered', 1], [$rooms['PreK']['status'], $rooms['PreK']['staffIn']]);
        $laLine = collect($rooms['Toddler']['staff'])->firstWhere('name', 'LaQuora');
        $this->assertSame([true, 'PreK', 'in'], [$laLine['moved'], $laLine['home'], $laLine['status']]);
        $this->actingAs($this->admin)->withSession(['floor' => 'LaQuora is in Toddler from 10:30 AM until Sultana clocks in.'])
            ->get(route('floor-plan.index', ['room' => 'Toddler']))->assertOk()
            ->assertSee('data-floor-flash', false)->assertSee('LaQuora is in Toddler from 10:30 AM until Sultana clocks in.')
            ->assertSee('data-room-tone="covered"', false)->assertDontSee('data-room-tone="short"', false);

        // Pressing it again changes nothing.
        $this->actingAs($this->admin)->post(route('floor-plan.move'), $tip['move'])->assertRedirect();
        $this->assertSame(2, StaffShift::where('user_id', $laQuora->id)->count());

        // 10:50: Sultana clocks in. The float closes and LaQuora is back in PreK for the rest of her day.
        $this->travelTo(Carbon::parse(self::DAY.' 10:50:00', $this->tz));
        app(\App\Services\TimeClock::class)->punch($sultana, TimePunch::IN, Carbon::now(), 'kiosk');

        $shifts = StaffShift::where('user_id', $laQuora->id)->orderBy('starts_at')->get();
        $this->assertSame([[480, 630, 'PreK', null], [630, 650, 'Toddler', null], [650, 960, 'PreK', null]],
            $shifts->map(fn (StaffShift $s) => [$s->starts_at, $s->ends_at, $s->classroom, $s->covers_user_id])->all());
        $this->assertSame(StaffShift::ROLE_STAFF, $shifts->last()->role);

        $rooms = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->keyBy('room');
        $this->assertSame(['Covered', 2], [$rooms['Toddler']['status'], $rooms['Toddler']['staffIn']]);
        $this->assertSame(['1 spare', 2], [$rooms['PreK']['status'], $rooms['PreK']['staffIn']]);
        $this->assertSame(['Mary', 'Sultana'], collect($rooms['Toddler']['staff'])->pluck('name')->all());
    }

    public function test_a_must_rule_keeps_a_person_out_of_a_room_and_the_page_says_so(): void
    {
        $person = $this->teacher('LaQuora Smith', 'PreK', clockIn: '08:00', shift: [8, 16, 'PreK']);
        \App\Models\StaffRule::create(['user_id' => $person->id, 'rule_type' => 'ROOM_FORBIDDEN', 'priority' => 'HARD', 'value_text' => 'Infant']);

        $this->actingAs($this->admin)->post(route('floor-plan.move'), ['user_id' => $person->id, 'to' => 'Infant'])
            ->assertRedirect(route('floor-plan.index', ['room' => 'Infant']))
            ->assertSessionHas('error', 'A Must rule says LaQuora is never assigned to Infant.');
        $this->assertSame(1, StaffShift::where('user_id', $person->id)->count());
    }

    public function test_teachers_can_see_it_and_guests_cannot(): void
    {
        $this->get(route('floor-plan.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'teacher']))->get(route('floor-plan.index'))->assertOk()->assertSee('Floor plan');
    }

    private function tick(Child $child, string $session = 'FULL', ?string $planned = null): void
    {
        ScheduleSlot::create(['week_start' => '2026-10-12', 'child_id' => $child->id, 'slot_date' => self::DAY, 'session' => $session, 'is_scheduled' => true, 'planned_time' => $planned]);
    }

    /** A teacher with a home room, maybe a shift (start hour, end hour, room), maybe clocked in. */
    private function teacher(string $name, string $home, ?string $clockIn, ?array $shift): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $home]);
        if ($shift) {
            StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $user->id, 'shift_date' => self::DAY, 'day' => 4, 'starts_at' => (int) ($shift[0] * 60), 'ends_at' => (int) ($shift[1] * 60), 'classroom' => $shift[2], 'role' => 'teacher']);
        }
        if ($clockIn) {
            TimePunch::create(['user_id' => $user->id, 'work_date' => self::DAY, 'punched_at' => Carbon::parse(self::DAY.' '.$clockIn, $this->tz), 'type' => TimePunch::IN, 'source' => 'kiosk']);
        }

        return $user;
    }
}
