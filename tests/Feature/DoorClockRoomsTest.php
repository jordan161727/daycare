<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The front door clock, now that a clock-in asks which room (HANDOFF §5.2).
 *
 * The RULES this holds the clock to:
 *  - Clock In asks for a room; the tile makes the punch and the check-in.
 *  - Start Break takes the person out of their room and warns — never
 *    blocks — when the room is short, and the office is told.
 *  - On a break the only button is End Break, and it goes back to the room
 *    the break was taken from; "Going somewhere else?" is the picker.
 *  - Clock Out takes them out of the room, with the same warning.
 *  - The card's status line is always about now (the staging bug).
 */
class DoorClockRoomsTest extends TestCase
{
    use RefreshDatabase;

    private StaffDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 Oct 2026, 10:30 in the centre's own clock.
        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);

        $this->device = new StaffDevice(['name' => 'Front desk', 'location' => 'Lobby']);
        $this->device->token_hash = '';
        $this->device->save();
        $this->get(route('clock.kiosk', ['token' => $this->device->issueToken()]))->assertRedirect();
    }

    public function test_clock_in_offers_the_room_picker_with_the_scheduled_room_first(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'Toddler');
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $lana->id, 'shift_date' => '2026-10-15', 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'PreK', 'role' => 'STAFF']);
        $this->childrenIn('PreK', 8);

        $card = $this->identify('3333');

        $card->assertJsonPath('staff.presence', StaffPresence::OFF)
            ->assertJsonPath('staff.presence_label', 'Not on the clock')
            ->assertJsonPath('staff.line', 'Not on the clock today.')
            ->assertJsonPath('staff.actions.0.action', 'clock_in')
            ->assertJsonPath('staff.actions.0.picks_room', true)
            ->assertJsonPath('staff.actions.0.hint', 'Next: pick the room you’re going to')
            // The roster's room, not the home room, comes first and is marked.
            ->assertJsonPath('staff.rooms.0.room', 'PreK')
            ->assertJsonPath('staff.rooms.0.mine', true)
            ->assertJsonPath('staff.rooms.0.sub', '8 children · 0 teachers, need 1')
            ->assertJsonPath('staff.rooms.1.mine', false);
    }

    public function test_picking_a_room_makes_the_punch_and_the_check_in_together(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');

        $this->punch('3333', 'clock_in', 'PreK')
            ->assertJson(['status' => 'ok', 'action' => 'clock_in', 'room' => 'PreK', 'placed' => true])
            ->assertJsonPath('staff.presence', StaffPresence::IN_ROOM)
            ->assertJsonPath('staff.line', 'On the clock since 10:30 AM · in PreK');

        $this->assertDatabaseHas('time_punches', ['user_id' => $lana->id, 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_CLOCK, 'staff_device_id' => $this->device->id]);
        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_DOOR]);

        // Two lines in the feed: the punch, and the room.
        $this->assertSame(['punch', 'room'], KioskEvent::orderBy('id')->pluck('kind')->all());
        $this->assertSame('Lana Ortiz → PreK', KioskEvent::where('kind', 'room')->value('text'));
    }

    public function test_the_office_tile_puts_them_on_the_clock_in_no_room(): void
    {
        $denise = $this->teacher('Denise Park', '9999', 'UPK-4');

        $this->punch('9999', 'clock_in', 'office')
            ->assertJson(['status' => 'ok', 'room' => null, 'placed' => true])
            ->assertJsonPath('staff.presence', StaffPresence::NO_ROOM)
            ->assertJsonPath('staff.presence_label', 'Working')
            ->assertJsonPath('staff.line', 'On the clock since 10:30 AM · office / no room');

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $denise->id, 'classroom' => null, 'ended_at' => null]);
        $this->assertSame(0, app(StaffPresence::class)->teachersIn('UPK-4')->count(), 'The office counts in nobody’s ratio.');
    }

    public function test_a_room_that_is_not_one_of_ours_is_refused_as_stale(): void
    {
        $this->teacher('Lana Ortiz', '3333', 'PreK');

        $this->punch('3333', 'clock_in', 'Broom cupboard')->assertJson(['status' => 'stale']);

        $this->assertSame(0, TimePunch::count());
        $this->assertSame(0, StaffRoomCheckin::count());
    }

    public function test_start_break_leaves_the_room_and_warns_when_it_is_short_but_never_blocks(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        Setting::put('rooms.PreK.ratio', 7);
        $this->childrenIn('PreK', 8);
        $this->punch('3333', 'clock_in', 'PreK');

        $this->identify('3333')
            ->assertJsonPath('staff.actions.0.action', 'break_start')
            ->assertJsonPath('staff.actions.0.hint', 'Checks you out of PreK')
            ->assertJsonPath('staff.actions.1.action', 'clock_out');

        $response = $this->punch('3333', 'break_start');

        // RULE: warned, never blocked. Eight PreK children at 1:7 need two;
        // the room now has none.
        $response->assertJson(['status' => 'ok', 'action' => 'break_start'])
            ->assertJsonPath('warning', 'PreK now has 0 teachers for 8 children (needs 2). The office has been told.')
            ->assertJsonPath('staff.presence', StaffPresence::ON_BREAK)
            ->assertJsonPath('staff.last_room', 'PreK')
            ->assertJsonPath('staff.line', 'On break since 10:30 AM (0 min) · was in PreK');

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_reason' => StaffRoomCheckin::ENDED_BREAK]);
        $this->assertDatabaseHas('kiosk_events', ['kind' => KioskEvent::FLAG, 'classroom' => 'PreK', 'text' => 'Ratio: PreK short after Lana Ortiz left', 'resolved_at' => null]);
    }

    public function test_leaving_a_room_that_is_still_covered_warns_nobody(): void
    {
        $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->teacher('Frenci Ahmed', '2222', 'PreK');
        Setting::put('rooms.PreK.ratio', 7);
        $this->childrenIn('PreK', 6);
        $this->punch('3333', 'clock_in', 'PreK');
        $this->punch('2222', 'clock_in', 'PreK');

        $this->punch('3333', 'break_start')->assertJson(['status' => 'ok'])->assertJsonPath('warning', null);

        $this->assertSame(0, KioskEvent::flags()->count());
    }

    public function test_on_a_break_the_only_button_is_end_break_and_it_goes_back_to_the_last_room(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->punch('3333', 'clock_in', 'PreK');
        $this->punch('3333', 'break_start');
        $this->travel(15)->minutes();

        $this->identify('3333')
            ->assertJsonCount(1, 'staff.actions')
            ->assertJsonPath('staff.actions.0.action', 'break_end')
            ->assertJsonPath('staff.actions.0.hint', 'Back to PreK')
            ->assertJsonPath('staff.line', 'On break since 10:30 AM (15 min) · was in PreK');

        // RULE: no clock-out from a break.
        $this->punch('3333', 'clock_out')->assertJson(['status' => 'stale']);

        $this->punch('3333', 'break_end')
            ->assertJson(['status' => 'ok', 'room' => 'PreK', 'placed' => true])
            ->assertJsonPath('staff.presence', StaffPresence::IN_ROOM);

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_RETURN]);
        $this->assertSame('Lana Ortiz → back to PreK', KioskEvent::where('kind', 'room')->latest('id')->value('text'));
    }

    public function test_going_somewhere_else_after_a_break_takes_the_room_from_the_picker(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->punch('3333', 'clock_in', 'PreK');
        $this->punch('3333', 'break_start');

        $this->punch('3333', 'break_end', 'Toddler')
            ->assertJson(['status' => 'ok', 'room' => 'Toddler']);

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'Toddler', 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_DOOR]);
        $this->assertSame(1, StaffRoomCheckin::open()->where('user_id', $lana->id)->count(), 'Never more than one open row per person.');
    }

    public function test_a_break_taken_from_the_office_ends_back_in_the_office(): void
    {
        $denise = $this->teacher('Denise Park', '9999', 'UPK-4');
        $this->punch('9999', 'clock_in', 'office');

        $this->identify('9999')->assertJsonPath('staff.actions.0.hint', 'Keeps your place in the office');
        $this->punch('9999', 'break_start')->assertJsonPath('warning', null);
        $this->identify('9999')->assertJsonPath('staff.actions.0.hint', 'Back to the office');
        $this->punch('9999', 'break_end')->assertJson(['status' => 'ok', 'room' => null, 'placed' => true]);

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $denise->id, 'classroom' => null, 'ended_at' => null, 'started_via' => StaffRoomCheckin::VIA_RETURN]);
    }

    public function test_clock_out_leaves_the_room_and_the_card_no_longer_says_since_when(): void
    {
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');
        Setting::put('rooms.PreK.ratio', 7);
        $this->childrenIn('PreK', 3);
        $this->punch('3333', 'clock_in', 'PreK');
        $this->travel(2)->hours();

        $this->punch('3333', 'clock_out')
            ->assertJson(['status' => 'ok', 'action' => 'clock_out'])
            ->assertJsonPath('warning', 'PreK now has 0 teachers for 3 children (needs 1). The office has been told.')
            ->assertJsonPath('staff.presence', StaffPresence::OFF)
            // The staging bug: no "On the clock since" under somebody who is off.
            ->assertJsonPath('staff.since', null)
            ->assertJsonPath('staff.line', 'Not on the clock today.');

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_reason' => StaffRoomCheckin::ENDED_CLOCK_OUT]);
        $this->assertSame(0, StaffRoomCheckin::open()->count());
    }

    public function test_the_card_reflects_the_current_shift_after_clocking_in_again(): void
    {
        $this->teacher('Lana Ortiz', '3333', 'PreK');
        $this->punch('3333', 'clock_in', 'PreK');
        $this->travel(1)->hours();
        $this->punch('3333', 'clock_out');
        $this->travel(1)->hours();

        // Since the second clock-in, not the first: the card is about now.
        $this->punch('3333', 'clock_in', 'Toddler')
            ->assertJsonPath('staff.line', 'On the clock since 12:30 PM · in Toddler')
            ->assertJsonPath('staff.room', 'Toddler');
    }

    public function test_a_clock_in_with_no_room_still_clocks_in_but_places_nobody(): void
    {
        // An older screen, or a scanner that posts without the picker: the
        // punch is the payroll fact and is never held hostage to the room.
        $lana = $this->teacher('Lana Ortiz', '3333', 'PreK');

        $this->punch('3333', 'clock_in')
            ->assertJson(['status' => 'ok', 'placed' => false])
            ->assertJsonPath('staff.presence', StaffPresence::NO_ROOM);

        $this->assertSame(1, TimePunch::where('user_id', $lana->id)->count());
        $this->assertSame(0, StaffRoomCheckin::count());
    }

    public function test_the_clock_screen_has_the_picker_and_the_warning_slots(): void
    {
        $this->get(route('clock.kiosk'))
            ->assertOk()
            ->assertSee('Which room are you going to?')
            ->assertSee('Where are you going after your break?')
            ->assertSee('Office / no room')
            ->assertSee('Going somewhere else?')
            ->assertSee('data-short-warning', false);
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

    private function identify(string $pin)
    {
        return $this->postJson(route('clock.kiosk.identify'), ['pin' => $pin])->assertOk()->assertJson(['status' => 'ok']);
    }

    private function punch(string $pin, string $action, ?string $room = null)
    {
        $ticket = $this->identify($pin)->json('ticket');

        return $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertOk();
    }
}
