<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\KioskEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\StaffPresence;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The "understand the workflow" to-do list (28 Oct 2026), run as a test.
 *
 * Sections 2–5 and 7 of the list, in order, through the real routes: set
 * up, Lana's day at the front door, the room tablet, the office side, and
 * the checks at the end — including the two the review found (a director's
 * corrected clock-out, and a check-in left open from yesterday), which must
 * take a teacher out of the room count.
 *
 * Prints a ticked list to STDERR, like KioskWalkthroughTest.
 */
class KioskWorkflowChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StaffDevice $door;

    private StaffDevice $tablet;

    /** @var array<string, User> */
    private array $t = [];

    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
    }

    public function test_the_workflow_to_do_list(): void
    {
        $presence = app(StaffPresence::class);
        $clock = app(TimeClock::class);

        /* ---- 2. Set up once ---- */

        foreach (['Lana Ortiz' => ['3333', 'PreK'], 'Sultana Begum' => ['4444', 'Toddler'], 'Evana Roy' => ['5555', 'Infant'], 'Pat Reyes' => ['6666', 'Infant']] as $name => [$pin, $room]) {
            $this->t[$name] = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $room]);
            $this->t[$name]->setKioskPin($pin);
        }
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $this->t['Lana Ortiz']->id, 'shift_date' => '2026-10-15', 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'PreK', 'role' => 'STAFF']);

        $this->actingAs($this->admin)->put(route('settings.rooms.update'), $this->roomsPayload(['PreK' => '2104', 'UPK-4' => '2105']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Front desk', 'kind' => 'clock'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'PreK iPad', 'kind' => 'room', 'classroom' => 'PreK'])->assertRedirect();
        $this->door = StaffDevice::where('name', 'Front desk')->firstOrFail();
        $this->tablet = StaffDevice::where('name', 'PreK iPad')->firstOrFail();
        $this->childrenIn('PreK', 8);
        $this->childrenIn('Toddler', 4);
        Setting::put('rooms.PreK.ratio', 7);
        Setting::put('rooms.Toddler.ratio', 5);
        $this->asDoor();
        $this->get(route('clock.kiosk'))->assertOk()->assertSee('Front desk');
        $this->asTablet();
        $this->get(route('room.kiosk'))->assertOk()->assertSee('Coming into');
        $this->actingAs($this->admin)->get(route('staff.presence'))->assertOk()->assertSee('Who’s where right now');
        $this->tick('2', 'codes 2104/2105 saved, clock and PreK tablet paired, 8 PreK + 4 Toddler children in, Who’s where opens');

        /* ---- 3. Front door: Lana's day ---- */

        $this->asDoor();
        $lana = $this->t['Lana Ortiz'];

        $this->doorPunch('3333', 'clock_in', 'PreK')->assertJsonPath('room', 'PreK');
        $this->assertSame(1, TimePunch::where('user_id', $lana->id)->where('type', TimePunch::IN)->count());
        $this->assertSame('PreK', $presence->openCheckin($lana)?->classroom);
        $this->tick('3', 'Clock In → PreK: the punch and the room check-in made together');

        $this->travelTo(now()->setTime(11, 0));
        $this->doorPunch('3333', 'break_start')->assertJsonPath('warning', 'PreK now has 0 teachers for 8 children (needs 2). The office has been told.');
        $this->assertTrue(KioskEvent::open()->where('text', 'Ratio: PreK short after Lana Ortiz left')->exists());
        $this->tick('3', 'Start Break → PreK short, amber warning, red flag');

        $this->travelTo(now()->setTime(11, 15));
        $actions = array_column($this->doorIdentify('3333')->json('staff.actions'), 'action');
        $this->assertSame(['break_end'], $actions);
        $this->tick('3', 'on break: only End Break, no Clock Out');

        $this->doorPunch('3333', 'break_end');
        $this->assertSame('PreK', $presence->status($lana)['room']);
        $this->tick('3', 'End Break → back in PreK');

        $this->travelTo(now()->setTime(11, 30));
        $this->doorPunch('3333', 'break_start');
        $this->travelTo(now()->setTime(11, 40));
        $this->doorPunch('3333', 'break_end', 'Toddler');
        $this->assertSame('Toddler', $presence->status($lana)['room']);
        $this->tick('3', '"Going somewhere else?" → break ended in Toddler instead');

        $this->travelTo(now()->setTime(12, 30));
        $this->doorPunch('3333', 'clock_out');
        $this->assertSame(StaffPresence::OFF, $presence->status($lana)['state']);
        // 10:30–12:30 with breaks of 15 and 10 minutes. A rest break of up
        // to 20 minutes is paid (FLSA; daycare.timesheet.clock.paid_break_cap),
        // so both count as worked: 120 minutes, none of it unpaid.
        $day = $clock->day($lana->id, '2026-10-15');
        $this->assertSame(120, (int) $day['so_far']);
        $this->assertSame(25, (int) $day['paid_break']);
        $this->assertSame(0, (int) $day['unpaid_break']);
        $this->tick('3', 'Clock Out → off; 2h 00m paid — breaks under 20 min are paid time');

        /* ---- 4. Room tablet ---- */

        $this->asTablet();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '4444'])->assertJson(['status' => 'done'])->assertJsonPath('done.title', 'Clocked in for you');
        $this->assertTrue(KioskEvent::open()->where('text', 'Sultana Begum skipped the front door — office to confirm start time')->exists());
        $this->assertDatabaseHas('time_punches', ['user_id' => $this->t['Sultana Begum']->id, 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_ROOM_TABLET]);
        $this->tick('4', 'Sultana not clocked in → clocked in for her at the tablet, office flagged');

        $this->asDoor();
        $this->doorPunch('5555', 'clock_in', 'Infant');
        $this->doorPunch('5555', 'break_start');
        $this->asTablet();
        $this->travel(5)->minutes();
        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJsonPath('staff.on_break', true);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in'])->assertJsonPath('done.title', 'You’re in PreK');
        $this->assertSame(TimeClock::WORKING, $clock->state($this->t['Evana Roy'], '2026-10-15'));
        $this->tick('4', 'Evana on break → tapping PreK at the tablet ends the break');

        $before = TimePunch::where('user_id', $this->t['Evana Roy']->id)->count();
        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in', 'room' => 'Toddler'])->assertJson(['status' => 'ok']);
        $this->assertSame($before, TimePunch::where('user_id', $this->t['Evana Roy']->id)->count());
        $this->assertSame('Toddler', $presence->status($this->t['Evana Roy'])['room']);
        $this->tick('4', 'moving Evana to Toddler → a room move, no new payroll punch');

        $ticket = fn () => $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->json('ticket');
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket(), 'action' => 'clock_out'])->assertJson(['status' => 'stale']);
        $this->get(route('room.kiosk'))->assertSee('Clock out at the front door.');
        $this->tick('4', 'no clock-out at the tablet: payroll stays at the door');

        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])->assertJson(['status' => 'ok', 'room' => 'UPK-4', 'was' => 'PreK']);
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104'])->assertJson(['status' => 'ok', 'room' => 'PreK']);
        $this->tick('4', 'gear + 2105 → the tablet becomes UPK-4 (and back with 2104)');

        /* ---- 7. Check you understand (before closing empties the rooms) ---- */

        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'break_start'])->assertJson(['status' => 'ok']);
        $this->assertFalse($presence->teachersIn('Toddler')->contains('user_id', $this->t['Evana Roy']->id));
        $this->tick('7', 'a teacher on break counts in no room');

        $this->assertTrue(TimePunch::where('user_id', $this->t['Evana Roy']->id)->exists());
        $this->assertTrue(StaffRoomCheckin::where('user_id', $this->t['Evana Roy']->id)->exists());
        $this->tick('7', 'pay time is the punches; where they stand is the room check-ins');

        // The review's bug: a director adds Sultana's clock-out in Staff
        // attendance (a supervisor punch). She is off — and must not still
        // count in PreK, nor show there on Who's where.
        $sultana = $this->t['Sultana Begum'];
        $clock->punch(user: $sultana, type: TimePunch::OUT, at: now(), source: TimePunch::SOURCE_SUPERVISOR, by: $this->admin, reason: 'Forgot to clock out');
        $this->assertSame(StaffPresence::OFF, $presence->status($sultana)['state']);
        $this->assertFalse($presence->teachersIn('PreK')->contains('user_id', $sultana->id), 'a corrected clock-out still counts her in PreK');
        $preK = collect($presence->board()['rooms'])->firstWhere('room', 'PreK');
        $this->assertNotContains('Sultana Begum', array_column($preK['people'], 'name'));
        $this->tick('7', 'a director’s corrected clock-out takes the teacher out of the room count');

        // And a check-in left open from yesterday (the closing job did not
        // run) is not somebody standing in Infant today.
        StaffRoomCheckin::create(['user_id' => $this->t['Pat Reyes']->id, 'classroom' => 'Infant', 'started_at' => now()->subDay()->setTime(8, 0), 'started_via' => StaffRoomCheckin::VIA_DOOR]);
        $this->assertFalse($presence->teachersIn('Infant')->contains('user_id', $this->t['Pat Reyes']->id), 'yesterday’s open check-in counts today');
        $this->tick('7', 'a check-in left open from yesterday does not count today');

        /* ---- 5. The office side ---- */

        $board = $presence->board();
        $this->assertTrue(collect($board['on_break'])->contains('name', 'Evana Roy'));
        $this->assertTrue(collect($board['not_in'])->contains(fn ($row) => $row['name'] === 'Lana Ortiz' && str_starts_with($row['note'], 'Clocked out')));
        $this->tick('5', 'Who’s where: rooms with badges, Evana on break, Lana “Clocked out …”');

        $activity = $this->actingAs($this->admin)->get(route('staff.activity'))->assertOk()->getContent();
        foreach (['Lana Ortiz clocked in @ 10:30 AM', 'Lana Ortiz checked into PreK', 'Flag for the office: Ratio: PreK short after Lana Ortiz left', 'Sultana Begum clocked in @'] as $line) {
            $this->assertStringContainsString($line, $activity);
        }
        $this->assertGreaterThan(10, Activity::count());
        $this->tick('5', 'Staff → Activity: every kiosk action as a sentence');

        $this->assertSame(
            [TimePunch::IN, TimePunch::BREAK_START, TimePunch::BREAK_END, TimePunch::BREAK_START, TimePunch::BREAK_END, TimePunch::OUT],
            TimePunch::where('user_id', $lana->id)->orderBy('punched_at')->pluck('type')->all(),
        );
        $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->assertSee('Lana Ortiz');
        $this->tick('5', 'Staff attendance: the door made Lana’s six payroll punches');

        $flag = KioskEvent::open()->newestFirst()->first();
        $this->actingAs($this->admin)->post(route('staff.presence.resolve', $flag))->assertRedirect(route('staff.presence'));
        $this->assertNotNull($flag->fresh()->resolved_at);
        $this->tick('5', '“Seen to” clears a flag from For the office');

        $this->travelTo(now()->setTime(18, 15));
        $this->artisan('staff:close-day')->expectsOutputToContain('still on the clock at closing')->assertSuccessful();
        $this->assertSame(0, $presence->teachersIn('PreK')->count() + $presence->teachersIn('Toddler')->count() + $presence->teachersIn('Infant')->count());
        $this->assertSame(0, TimePunch::where('user_id', $this->t['Evana Roy']->id)->where('type', TimePunch::OUT)->count());
        $this->tick('5', 'staff:close-day flags who is left, frees the rooms, writes no clock-out');

        fwrite(STDERR, "\n".implode("\n", $this->log)."\n");
    }

    /* ---- helpers ---- */

    private function tick(string $section, string $what): void
    {
        $this->log[] = "  [x] §{$section}  {$what}";
    }

    private function roomsPayload(array $codes): array
    {
        $rows = collect(\App\Services\ClassroomAssignment::rooms())->map(fn ($room) => ['room' => $room, 'ratio' => 5, 'capacity' => 10, 'tablet_code' => $codes[$room] ?? null])->values()->all();
        $centre = ['centre_opens' => '07:15', 'centre_closes' => '18:00', 'earliest_start' => '07:30', 'early_dropoff_room' => 'PreK', 'school_age_leaves' => '08:00', 'school_age_returns' => '15:00', 'longest_shift_hours' => 9, 'max_week_hours' => 40];

        return ['rooms' => $rows, 'centre' => $centre];
    }

    private function childrenIn(string $room, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $child = Child::create(['lan' => $room.$i, 'first_name' => "Kid$i", 'last_name' => $room, 'status' => 'Active', 'classroom' => $room, 'classroom_override' => $room]);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 07:40', config('app.timezone'))->addMinutes($i)]);
        }
    }

    private function asDoor(): void
    {
        $this->flushSession();
        $this->get(route('clock.kiosk', ['token' => $this->door->issueToken()]))->assertRedirect();
    }

    private function asTablet(): void
    {
        $this->flushSession();
        $this->get(route('room.kiosk', ['token' => $this->tablet->issueToken()]))->assertRedirect();
    }

    private function doorIdentify(string $pin)
    {
        return $this->postJson(route('clock.kiosk.identify'), ['pin' => $pin])->assertOk()->assertJson(['status' => 'ok']);
    }

    private function doorPunch(string $pin, string $action, ?string $room = null)
    {
        $ticket = $this->doorIdentify($pin)->json('ticket');

        return $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertOk()->assertJson(['status' => 'ok']);
    }
}
