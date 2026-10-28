<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\KioskEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\StaffPresence;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The tablet in a classroom (HANDOFF §5.3).
 *
 * The RULES:
 *  - The gear and a four-digit room code switch the room, and it is logged.
 *  - A room tap from a break ends the break.
 *  - Somebody not clocked in is clocked in there and then, placed, and
 *    flagged for the office; the punch is marked as the tablet's.
 *  - It cannot clock anyone out, and has no office tile.
 *  - It has to be a paired device.
 *  - Room codes are set in Settings and may not equal a staff PIN.
 */
class RoomTabletTest extends TestCase
{
    use RefreshDatabase;

    private StaffDevice $tablet;

    private StaffDevice $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);

        Setting::put('rooms.PreK.tablet_code', '2104');
        Setting::put('rooms.UPK-4.tablet_code', '2105');

        $this->tablet = new StaffDevice(['name' => 'PreK iPad', 'kind' => StaffDevice::KIND_ROOM, 'classroom' => 'PreK']);
        $this->tablet->token_hash = '';
        $this->tablet->save();

        $this->door = new StaffDevice(['name' => 'Front desk']);
        $this->door->token_hash = '';
        $this->door->save();
    }

    public function test_the_pairing_link_for_a_room_tablet_opens_the_room_page(): void
    {
        $this->tablet->issueToken();
        $this->door->issueToken();

        $this->assertStringStartsWith(url('/room?token='), $this->tablet->pairingUrl());
        $this->assertStringStartsWith(url('/clock?token='), $this->door->pairingUrl());

        $this->get($this->tablet->pairingUrl())->assertRedirect(route('room.kiosk'));
        $this->get(route('room.kiosk'))->assertOk()->assertSee('Little Angels')->assertSee('Coming into');
    }

    public function test_a_browser_never_paired_becomes_a_room_tablet_set_by_its_code(): void
    {
        // No pairing step any more (Jordan, 29 Oct 2026): the tablet is a
        // device on first visit, and the gear and room code say where it is.
        $this->get(route('room.kiosk'))->assertOk()->assertDontSee('not set up yet');

        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])->assertOk()->assertJson(['status' => 'ok', 'room' => 'UPK-4']);

        $device = StaffDevice::latest('id')->first();
        $this->assertNotSame($this->tablet->id, $device->id);
        $this->assertSame([StaffDevice::KIND_ROOM, 'UPK-4'], [$device->kind, $device->classroom]);
    }

    public function test_the_gear_and_a_room_code_switch_the_room_and_log_it(): void
    {
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])
            ->assertOk()->assertJson(['status' => 'ok', 'room' => 'UPK-4', 'was' => 'PreK']);

        $this->assertSame('UPK-4', $this->tablet->fresh()->classroom);
        // An ordinary grey line, not a flag.
        $this->assertDatabaseHas('kiosk_events', ['kind' => KioskEvent::TABLET, 'staff_device_id' => $this->tablet->id, 'text' => 'Room tablet set to UPK-4 (was PreK)']);
        $this->assertSame(0, KioskEvent::flags()->count());
    }

    public function test_a_wrong_code_moves_nothing(): void
    {
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.setup'), ['code' => '0000'])->assertJson(['status' => 'bad_code']);

        $this->assertSame('PreK', $this->tablet->fresh()->classroom);
        $this->assertSame(0, KioskEvent::count());
    }

    public function test_a_room_code_may_not_be_a_staff_pin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->teacher('Lana Ortiz', '3333', 'PreK');

        $rows = collect(\App\Services\ClassroomAssignment::rooms())->map(fn ($room, $i) => ['room' => $room, 'ratio' => 5, 'capacity' => 10, 'tablet_code' => $room === 'Infant' ? '3333' : null])->values()->all();
        $centre = ['centre_opens' => '07:15', 'centre_closes' => '18:00', 'earliest_start' => '07:30', 'early_dropoff_room' => 'PreK', 'school_age_leaves' => '08:00', 'school_age_returns' => '15:00', 'longest_shift_hours' => 9, 'max_week_hours' => 40];

        $this->actingAs($admin)->from(route('settings.rooms'))
            ->put(route('settings.rooms.update'), ['rooms' => $rows, 'centre' => $centre])
            ->assertRedirect(route('settings.rooms'))
            ->assertSessionHasErrors(['rooms.0.tablet_code' => 'A room code cannot be the same as a staff PIN.']);

        $rows[0]['tablet_code'] = '2101';
        $rows[1]['tablet_code'] = '2101';
        $this->actingAs($admin)->put(route('settings.rooms.update'), ['rooms' => $rows, 'centre' => $centre])
            ->assertSessionHasErrors('rooms.0.tablet_code');

        $rows[1]['tablet_code'] = '2102';
        $this->actingAs($admin)->put(route('settings.rooms.update'), ['rooms' => $rows, 'centre' => $centre])
            ->assertSessionHasNoErrors();

        $this->assertSame('2101', app(\App\Services\CentreSettings::class)->tabletCode('Infant'));
        $this->assertSame('Transition', app(\App\Services\CentreSettings::class)->roomForTabletCode('2102'));

        // Settings shows the code column and which tablets are in each room.
        $this->actingAs($admin)->get(route('settings.rooms'))
            ->assertOk()
            ->assertSee('Room tablet code')
            ->assertSee('1 tablet · never used')
            ->assertSee('No tablet yet');
    }

    public function test_somebody_not_clocked_in_is_clocked_in_here_placed_and_flagged(): void
    {
        $sultana = $this->teacher('Sultana Begum', '4444', 'Toddler');
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.identify'), ['pin' => '4444'])
            ->assertOk()
            ->assertJson(['status' => 'done'])
            ->assertJsonPath('done.title', 'Clocked in for you')
            ->assertJsonPath('done.sub', 'You weren’t clocked in, so we clocked you in at 10:30 AM and checked you into PreK. Next time, start at the front door.')
            ->assertJsonMissing(['ticket']);

        // The punch is marked as the tablet's so payroll can see it.
        $this->assertDatabaseHas('time_punches', ['user_id' => $sultana->id, 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_ROOM_TABLET, 'staff_device_id' => $this->tablet->id]);
        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $sultana->id, 'classroom' => 'PreK', 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_AUTO]);
        $this->assertDatabaseHas('kiosk_events', ['kind' => KioskEvent::FLAG, 'user_id' => $sultana->id, 'text' => 'Sultana Begum skipped the front door — office to confirm start time', 'resolved_at' => null]);
        $this->assertSame(TimeClock::WORKING, app(TimeClock::class)->state($sultana, '2026-10-15'));
    }

    public function test_somebody_working_elsewhere_can_check_into_this_room(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        Setting::put('rooms.Toddler.ratio', 5);
        $this->childrenIn('Toddler', 4);
        $this->clockInAtDoor('3333', 'Toddler');
        $this->pair($this->tablet);

        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->assertOk()->assertJson(['status' => 'ok']);
        $screen->assertJsonPath('staff.can_here', true)
            ->assertJsonPath('staff.is_here', false)
            ->assertJsonPath('staff.on_break', false)
            ->assertJsonPath('staff.now', 'Now: Toddler since 10:30 AM')
            ->assertJsonPath('staff.here_sub', 'Checks you out of Toddler · No children right now')
            ->assertJsonPath('staff.can_break', true);

        // The tablet's own room is the big button, not a tile; Toddler shows "You're here".
        $rooms = collect($screen->json('staff.rooms'));
        $this->assertFalse($rooms->contains('room', 'PreK'));
        $this->assertTrue($rooms->firstWhere('room', 'Toddler')['is_here']);

        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in', 'room' => 'PreK'])
            ->assertOk()->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.title', 'You’re in PreK')
            ->assertJsonPath('done.sub', 'Checked out of Toddler. Checked into PreK at 10:30 AM.')
            // Four toddlers at 1:5 needed one; leaving made it short.
            ->assertJsonPath('done.warning', 'Toddler now has 0 teachers for 4 children (needs 1). The office has been told.');

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'Toddler', 'ended_reason' => StaffRoomCheckin::ENDED_MOVE, 'ended_device_id' => $this->tablet->id]);
        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_TABLET]);
        // A move is no punch: the payroll record is untouched.
        $this->assertSame(1, TimePunch::where('user_id', $lana->id)->count());
    }

    public function test_a_room_tap_from_a_break_ends_the_break(): void
    {
        $evana = $this->teacher('Evana Roy', '5555', 'Infant');
        $this->clockInAtDoor('5555', 'Infant');
        $this->punchAtDoor('5555', 'break_start');
        $this->travel(15)->minutes();
        $this->pair($this->tablet);

        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJson(['status' => 'ok']);
        $screen->assertJsonPath('staff.on_break', true)
            ->assertJsonPath('staff.now', 'On break since 10:30 AM · was in Infant')
            ->assertJsonPath('staff.here_sub', 'Ends your break · No children right now')
            ->assertJsonPath('staff.can_break', false);

        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in'])
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.sub', 'Break ended. Checked into PreK at 10:45 AM.');

        $this->assertDatabaseHas('time_punches', ['user_id' => $evana->id, 'type' => TimePunch::BREAK_END, 'source' => TimePunch::SOURCE_ROOM_TABLET]);
        $this->assertSame(TimeClock::WORKING, app(TimeClock::class)->state($evana, '2026-10-15'));
        $this->assertSame('PreK', app(StaffPresence::class)->status($evana)['room']);
        $this->assertDatabaseHas('kiosk_events', ['kind' => KioskEvent::PUNCH, 'text' => 'Evana Roy ended break (15 min) · PreK tablet']);
    }

    public function test_taking_a_break_at_the_tablet_is_start_break_at_the_door(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        Setting::put('rooms.PreK.ratio', 7);
        $this->childrenIn('PreK', 7);
        $this->clockInAtDoor('3333', 'PreK');
        $this->pair($this->tablet);

        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->assertJson(['status' => 'ok']);
        $screen->assertJsonPath('staff.is_here', true)->assertJsonPath('staff.can_here', false);

        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'break_start'])
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.title', 'Enjoy your break')
            ->assertJsonPath('done.sub', 'Break started at 10:30 AM. End it at the front door or by tapping into any room.')
            ->assertJsonPath('done.warning', 'PreK now has 0 teachers for 7 children (needs 1). The office has been told.');

        $this->assertDatabaseHas('time_punches', ['user_id' => $lana->id, 'type' => TimePunch::BREAK_START, 'source' => TimePunch::SOURCE_ROOM_TABLET]);
        $this->assertSame(TimeClock::BREAK, app(TimeClock::class)->state($lana, '2026-10-15'));
    }

    public function test_a_tablet_cannot_clock_anyone_out_and_has_no_office_tile(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->clockInAtDoor('3333', 'PreK');
        $this->pair($this->tablet);

        $ticket = fn () => $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->json('ticket');

        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket(), 'action' => 'clock_out'])->assertJson(['status' => 'stale']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket(), 'action' => 'check_in', 'room' => 'office'])->assertJson(['status' => 'stale']);

        $this->assertSame(TimeClock::WORKING, app(TimeClock::class)->state($lana, '2026-10-15'));
        $this->assertSame('PreK', app(StaffPresence::class)->status($lana)['room']);
        $this->get(route('room.kiosk'))->assertSee('Clock out at the front door.')->assertDontSee('Office / no room');
    }

    public function test_a_tablet_with_no_room_yet_takes_nobody(): void
    {
        $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->tablet->setRoom(null);
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->assertJson(['status' => 'no_room']);

        $this->assertSame(0, TimePunch::count());
        $this->get(route('room.kiosk'))->assertSee('has not been told which room it is in');
    }

    public function test_a_ticket_is_the_tablet_s_own_and_is_spent_once(): void
    {
        $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->clockInAtDoor('3333', 'Toddler');
        $this->pair($this->tablet);

        $ticket = $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->json('ticket');

        // A press with somebody else's ticket is refused — and, as at the
        // door, it spends the real one: whoever keyed the PIN has walked away.
        $this->postJson(route('room.kiosk.punch'), ['ticket' => 'made-up', 'action' => 'check_in'])->assertJson(['status' => 'expired']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket, 'action' => 'check_in'])->assertJson(['status' => 'expired']);

        // Keyed again: one press, and the ticket is gone.
        $ticket = $this->postJson(route('room.kiosk.identify'), ['pin' => '3333'])->json('ticket');
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket, 'action' => 'check_in'])->assertJson(['status' => 'ok']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket, 'action' => 'check_in'])->assertJson(['status' => 'expired']);
    }

    public function test_the_tablet_has_no_teachers_children_ratio_strip(): void
    {
        // Added from the full-width mock-up, then taken off again (Jordan,
        // 29 Oct 2026: "remove this"). Its endpoint went with it.
        $this->pair($this->tablet);

        $html = $this->get(route('room.kiosk'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-room-strip', $html);
        $this->assertStringNotContainsString('room/summary', $html);
        $this->get('/room/summary')->assertNotFound();
    }

    /* ---- helpers ---- */

    private function teacher(string $name, string $pin, string $room): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $room]);
        $user->setKioskPin($pin);

        return $user;
    }

    private function childrenIn(string $room, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $child = Child::create(['lan' => $room.$i, 'first_name' => "Kid$i", 'last_name' => $room, 'status' => 'Active', 'classroom' => $room]);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => now()->subHours(2)]);
        }
    }

    /** Visit once with the token, the way a tablet is set up, as its own browser. */
    private function pair(StaffDevice $device): void
    {
        $this->flushSession();
        $this->get(route($device->kioskRoute(), ['token' => $device->issueToken()]))->assertRedirect();
    }

    private function clockInAtDoor(string $pin, string $room): void
    {
        $this->punchAtDoor($pin, 'clock_in', $room);
    }

    private function punchAtDoor(string $pin, string $action, ?string $room = null): void
    {
        $this->pair($this->door);
        $ticket = $this->postJson(route('clock.kiosk.identify'), ['pin' => $pin])->assertJson(['status' => 'ok'])->json('ticket');
        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertJson(['status' => 'ok']);
    }
}
