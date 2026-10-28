<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\KioskEvent;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\CentreSettings;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The hand test in docs/kiosks.md, run without hands.
 *
 * One test, the twenty-three steps in order, as one day at the centre: the
 * director sets up the codes and the tablets, Lana, Sultana and Evana go
 * through the door and the PreK tablet, closing time comes. Every tick box
 * in the guide is an assertion here, in the same order, so a step that no
 * longer matches the guide is the one that fails.
 *
 * The three suites beside this one test each RULE on its own; this one
 * tests that they hold together across a day.
 */
class KioskWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StaffDevice $door;

    private StaffDevice $tablet;

    /** @var array<string, User> */
    private array $staff = [];

    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
    }

    public function test_the_guide_s_hand_test_passes_headlessly(): void
    {
        /* ---- Setting up ---- */

        // 1. Settings → Rooms & hours: codes for PreK and UPK-4.
        foreach (['Lana Ortiz' => ['3333', 'PreK'], 'Sultana Begum' => ['4444', 'Toddler'], 'Evana Roy' => ['5555', 'Infant']] as $name => [$pin, $room]) {
            $this->staff[$name] = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $room]);
            $this->staff[$name]->setKioskPin($pin);
        }
        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()->assertSee('Room tablet code')->assertSee('No tablet yet');
        $this->saveRooms(['Infant' => '3333'])->assertSessionHasErrors(['rooms.0.tablet_code' => 'A room code cannot be the same as a staff PIN.']);
        $this->tick(1, 'a code that is somebody’s PIN is refused');
        $this->saveRooms(['PreK' => '2104', 'UPK-4' => '2104'])->assertSessionHasErrors('rooms.3.tablet_code');
        $this->tick(1, 'the same code on two rooms is refused');
        $this->saveRooms(['PreK' => '2104', 'UPK-4' => '2105'])->assertSessionHasNoErrors();
        $this->assertSame('2104', app(CentreSettings::class)->tabletCode('PreK'));
        $this->tick(1, 'PreK 2104 and UPK-4 2105 saved; every room says "No tablet yet"');

        // 2. A clock and a PreK tablet, each in its own browser. Settings →
        // Devices is gone (29 Oct 2026); these two are already on the books,
        // as a screen that pairs itself on first visit would be.
        $this->door = tap(new StaffDevice(['name' => 'Front desk', 'kind' => StaffDevice::KIND_CLOCK, 'is_active' => true]))->issueToken();
        $this->tablet = tap(new StaffDevice(['name' => 'PreK iPad', 'kind' => StaffDevice::KIND_ROOM, 'classroom' => 'PreK', 'is_active' => true]))->issueToken();
        $this->asTablet();
        $this->get(route('room.kiosk'))->assertOk()->assertSee('Little Angels')->assertSee('Coming into');
        $this->asDoor();
        $this->get(route('clock.kiosk'))->assertOk()->assertSee('Front desk')->assertSee('Scan your card');
        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()->assertSee('1 tablet · never used');
        $this->tick(2, 'the clock opens on /clock, the PreK tablet on /room; Rooms & hours counts the tablet');

        // 3. Staff: PINs set above; Lana rostered in PreK today.
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $this->staff['Lana Ortiz']->id, 'shift_date' => '2026-10-15', 'day' => 4, 'starts_at' => 7 * 60, 'ends_at' => 15 * 60, 'classroom' => 'PreK', 'role' => 'STAFF']);
        $this->tick(3, 'three teachers with PINs; Lana rostered in PreK');

        // 4. Children signed in: eight PreK, four Toddler.
        $this->childrenIn('PreK', 8);
        $this->childrenIn('Toddler', 4);
        \App\Models\Setting::put('rooms.PreK.ratio', 7);
        \App\Models\Setting::put('rooms.Toddler.ratio', 5);
        $this->tick(4, '8 in PreK (1:7), 4 in Toddler (1:5)');

        /* ---- The door ---- */

        $this->asDoor();

        // 5.
        $card = $this->doorIdentify('3333');
        $card->assertJsonPath('staff.presence_label', 'Not on the clock')
            ->assertJsonPath('staff.line', 'Not on the clock today.')
            ->assertJsonCount(1, 'staff.actions')
            ->assertJsonPath('staff.actions.0.label', 'Clock In')
            ->assertJsonPath('staff.actions.0.tone', 'go')
            ->assertJsonPath('staff.actions.0.hint', 'Next: pick the room you’re going to');
        $this->tick(5, 'pill "Not on the clock", one green Clock In with its hint');

        // 6. The picker (sent with the card).
        $card->assertJsonPath('staff.rooms.0.room', 'PreK')
            ->assertJsonPath('staff.rooms.0.mine', true)
            ->assertJsonPath('staff.rooms.0.sub', '8 children · 0 teachers, need 2');
        $this->get(route('clock.kiosk'))->assertSee('Which room are you going to?')->assertSee('Office / no room');
        $this->tick(6, 'PreK first with "Your schedule" and "8 children · 0 teachers, need 2"; Office tile present');

        // 7. Tap PreK.
        $this->doorPunch('3333', 'clock_in', 'PreK')->assertJson(['status' => 'ok', 'room' => 'PreK']);
        $board = $this->board();
        $prek = collect($board['rooms'])->firstWhere('room', 'PreK');
        $this->assertSame(['Lana Ortiz'], array_column($prek['people'], 'name'));
        $this->assertSame(['1 teacher · need 2 · 8 kids', 'bad'], [$prek['label'], $prek['tone']]);
        $this->assertSame(['room', 'punch'], array_column($board['activity'], 'kind'));
        $this->tick(7, 'clocked in; Who’s where: Lana in PreK, red "1 teacher · need 2 · 8 kids"; a punch line and a room line');

        // 8.
        $this->doorIdentify('3333')
            ->assertJsonPath('staff.line', 'On the clock since 10:30 AM · in PreK')
            ->assertJsonPath('staff.actions.0.hint', 'Checks you out of PreK')
            ->assertJsonPath('staff.actions.1.hint', 'End of your day');
        $this->tick(8, '"On the clock since 10:30 AM · in PreK"; Start Break and Clock Out with hints');

        // 9. Start Break: warned, flagged, never blocked.
        $this->doorPunch('3333', 'break_start')
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('warning', 'PreK now has 0 teachers for 8 children (needs 2). The office has been told.');
        $board = $this->board();
        $this->assertSame([['name' => 'Lana Ortiz', 'since' => '10:30 AM', 'last' => 'PreK']], $board['on_break']);
        $this->assertSame('Ratio: PreK short after Lana Ortiz left', $board['flags']->first()->text);
        $this->tick(9, 'break started with the amber warning; Lana under On break "from PreK"; red flag for the office');

        // 10. Only End Break, plus the link.
        $this->travel(15)->minutes();
        $this->doorIdentify('3333')
            ->assertJsonCount(1, 'staff.actions')
            ->assertJsonPath('staff.actions.0.label', 'End Break')
            ->assertJsonPath('staff.actions.0.tone', 'go')
            ->assertJsonPath('staff.actions.0.hint', 'Back to PreK')
            ->assertJsonPath('staff.presence', 'on_break');
        $this->doorPunch('3333', 'clock_out')->assertJson(['status' => 'stale']);
        $this->get(route('clock.kiosk'))->assertSee('Going somewhere else?');
        $this->tick(10, 'only End Break (green, "Back to PreK") and the link; Clock Out refused');

        // 11. End Break goes back to PreK.
        $this->doorPunch('3333', 'break_end')->assertJson(['status' => 'ok', 'room' => 'PreK']);
        $this->assertSame(['Lana Ortiz'], array_column(collect($this->board()['rooms'])->firstWhere('room', 'PreK')['people'], 'name'));
        $this->tick(11, 'Welcome back; Lana back in PreK');

        // 12. Going somewhere else → Toddler.
        $this->doorPunch('3333', 'break_start')->assertJson(['status' => 'ok']);
        $this->get(route('clock.kiosk'))->assertSee('Where are you going after your break?');
        $this->doorPunch('3333', 'break_end', 'Toddler')->assertJson(['status' => 'ok', 'room' => 'Toddler']);
        $this->assertSame(['Lana Ortiz'], array_column(collect($this->board()['rooms'])->firstWhere('room', 'Toddler')['people'], 'name'));
        $this->tick(12, 'picker titled for after a break; Lana now in Toddler');

        // 13. Clock Out, then the card says nothing about "since".
        $this->travel(2)->hours();
        $out = $this->doorPunch('3333', 'clock_out')->assertJson(['status' => 'ok', 'action' => 'clock_out']);
        $out->assertJsonPath('warning', 'Toddler now has 0 teachers for 4 children (needs 1). The office has been told.')
            ->assertJsonPath('staff.worked_minutes', 135);
        $this->doorIdentify('3333')->assertJsonPath('staff.line', 'Not on the clock today.')->assertJsonPath('staff.since', null);
        $this->doorPunch('3333', 'clock_in', 'Infant')->assertJsonPath('staff.line', 'On the clock since 12:45 PM · in Infant');
        $this->tick(13, 'See you tomorrow with 2h 15m and the Toddler warning; then "Not on the clock today."; clocking in again reads "· in Infant"');

        /* ---- The room tablet ---- */

        $this->asTablet();

        // 14. Sultana, not clocked in, keys her PIN at the PreK tablet.
        $this->postJson(route('room.kiosk.identify'), ['pin' => '4444'])
            ->assertJson(['status' => 'done'])
            ->assertJsonPath('done.title', 'Clocked in for you')
            ->assertJsonPath('done.sub', 'You weren’t clocked in, so we clocked you in at 12:45 PM and checked you into PreK. Next time, start at the front door.');
        $board = $this->board();
        $this->assertContains('Sultana Begum', array_column(collect($board['rooms'])->firstWhere('room', 'PreK')['people'], 'name'));
        $this->assertTrue($board['flags']->contains('text', 'Sultana Begum skipped the front door — office to confirm start time'));
        $this->assertTrue(collect($board['activity'])->contains('text', 'Sultana Begum clocked in (auto, PreK tablet)'));
        $this->assertDatabaseHas('time_punches', ['user_id' => $this->staff['Sultana Begum']->id, 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_ROOM_TABLET]);
        $this->tick(14, 'clocked in for her, placed in PreK, flagged, punch source room_tablet');

        // 15. Evana: in at the door into Infant, Start Break, then the tablet.
        $this->asDoor();
        $this->doorPunch('5555', 'clock_in', 'Infant')->assertJson(['status' => 'ok']);
        $this->doorPunch('5555', 'break_start')->assertJson(['status' => 'ok']);
        $this->asTablet();
        $this->travel(10)->minutes();
        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJson(['status' => 'ok']);
        $screen->assertJsonPath('staff.first_name', 'Evana')
            ->assertJsonPath('staff.on_break', true)
            ->assertJsonPath('staff.now', 'On break since 12:45 PM · was in Infant')
            ->assertJsonPath('staff.here_sub', 'Ends your break · 8 children · 1 teacher, need 2')
            ->assertJsonPath('staff.can_break', false);
        $this->get(route('room.kiosk'))->assertSee('ends your break');
        $this->tick(15, '"Hi Evana", break banner, "was in Infant", button "Ends your break", no Taking a break');

        // 16. Check into PreK ends the break.
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in'])
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.title', 'You’re in PreK')
            ->assertJsonPath('done.sub', 'Break ended. Checked into PreK at 12:55 PM.');
        $this->assertSame(TimeClock::WORKING, app(TimeClock::class)->state($this->staff['Evana Roy'], '2026-10-15'));
        $this->assertTrue(collect($this->board()['activity'])->contains('text', 'Evana Roy ended break (10 min) · PreK tablet'));
        $this->tick(16, 'break ended by the tap; Evana in PreK; blue "ended break (10 min) · PreK tablet"');

        // 17. Already here; the other rooms; a move is no punch.
        $punchesBefore = TimePunch::where('user_id', $this->staff['Evana Roy']->id)->count();
        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJson(['status' => 'ok']);
        $screen->assertJsonPath('staff.is_here', true)->assertJsonPath('staff.can_here', false);
        $this->assertFalse(collect($screen->json('staff.rooms'))->contains('room', 'PreK'));
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'check_in', 'room' => 'Toddler'])
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.sub', 'Checked out of PreK. Checked into Toddler at 12:55 PM.');
        $this->assertSame($punchesBefore, TimePunch::where('user_id', $this->staff['Evana Roy']->id)->count());
        $this->tick(17, '"already checked into PreK"; PreK not a tile; move to Toddler with no new punch');

        // 18. Taking a break at the tablet.
        $screen = $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJson(['status' => 'ok']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $screen->json('ticket'), 'action' => 'break_start'])
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('done.title', 'Enjoy your break')
            ->assertJsonPath('done.sub', 'Break started at 12:55 PM. End it at the front door or by tapping into any room.')
            ->assertJsonPath('done.warning', 'Toddler now has 0 teachers for 4 children (needs 1). The office has been told.');
        $this->tick(18, 'break started at the tablet, Toddler warning shown');

        // 19. No way to clock out, no office tile.
        $this->asDoor();
        $this->doorPunch('5555', 'break_end')->assertJson(['status' => 'ok']);
        $this->asTablet();
        $ticket = fn () => $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->json('ticket');
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket(), 'action' => 'clock_out'])->assertJson(['status' => 'stale']);
        $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket(), 'action' => 'check_in', 'room' => 'office'])->assertJson(['status' => 'stale']);
        $this->get(route('room.kiosk'))->assertSee('Clock out at the front door.')->assertDontSee('Office / no room');
        $this->tick(19, 'no clock-out, no office tile at the tablet');

        // 20. The gear.
        $this->postJson(route('room.kiosk.setup'), ['code' => '0000'])->assertJson(['status' => 'bad_code']);
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])->assertJson(['status' => 'ok', 'room' => 'UPK-4', 'was' => 'PreK']);
        $this->get(route('room.kiosk'))->assertSee("'UPK-4'", false);
        $this->assertTrue(collect($this->board()['activity'])->contains('text', 'Room tablet set to UPK-4 (was PreK)'));
        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk();
        $this->assertStringStartsWith('1 tablet', app(CentreSettings::class)->tabletsLine('UPK-4'));
        $this->assertSame('No tablet yet', app(CentreSettings::class)->tabletsLine('PreK'));
        $this->tick(20, '0000 refused; 2105 makes it UPK-4; grey feed line; Rooms & hours counts it against UPK-4');

        // 21. A browser never paired: no pairing step any more (29 Oct 2026),
        // it becomes a tablet of its own and the gear says which room.
        $this->flushSession();
        $this->get(route('room.kiosk'))->assertOk()->assertDontSee('not set up yet');
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104'])->assertJson(['status' => 'ok', 'room' => 'PreK']);
        $this->assertSame(3, StaffDevice::count());
        $this->tick(21, 'a new browser becomes its own tablet; 2104 makes it PreK');

        /* ---- Closing ---- */

        // 22. Lana, Sultana and Evana are all still on the clock.
        $this->travelTo(now()->setTime(18, 15));
        $this->artisan('staff:close-day')->expectsOutputToContain('3 still on the clock at closing')->assertSuccessful();
        $this->assertSame(0, StaffRoomCheckin::open()->count());
        $this->assertSame(0, TimePunch::where('type', TimePunch::OUT)->whereIn('user_id', [$this->staff['Sultana Begum']->id, $this->staff['Evana Roy']->id])->count());
        $this->assertTrue($this->board()['flags']->contains('text', 'Lana Ortiz still on the clock at closing — office to fix the day'));
        $this->tick(22, 'three flagged at closing, nobody left in a room, no OUT punch invented');

        // 23. Seen to.
        $flag = KioskEvent::open()->newestFirst()->first();
        $this->actingAs($this->admin)->post(route('staff.presence.resolve', $flag))->assertRedirect(route('staff.presence'));
        $this->assertNotNull($flag->fresh()->resolved_at);
        $this->actingAs($this->admin)->get(route('staff.presence'))->assertOk()->assertSee('line-through', false)->assertSee('Who’s where right now');
        $this->tick(23, 'a flag seen to leaves For the office and stays in Activity, struck through');

        fwrite(STDERR, "\n".implode("\n", $this->log)."\n");
    }

    /* ---- helpers ---- */

    private function tick(int $step, string $what): void
    {
        $this->log[] = sprintf('  [x] %2d. %s', $step, $what);
    }

    private function saveRooms(array $codes)
    {
        $rows = collect(\App\Services\ClassroomAssignment::rooms())->map(fn ($room) => ['room' => $room, 'ratio' => 5, 'capacity' => 10, 'tablet_code' => $codes[$room] ?? null])->values()->all();
        $centre = ['centre_opens' => '07:15', 'centre_closes' => '18:00', 'earliest_start' => '07:30', 'early_dropoff_room' => 'PreK', 'school_age_leaves' => '08:00', 'school_age_returns' => '15:00', 'longest_shift_hours' => 9, 'max_week_hours' => 40];

        return $this->actingAs($this->admin)->from(route('settings.rooms'))->put(route('settings.rooms.update'), ['rooms' => $rows, 'centre' => $centre]);
    }

    private function childrenIn(string $room, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $child = Child::create(['lan' => $room.$i, 'first_name' => "Kid$i", 'last_name' => $room, 'status' => 'Active', 'classroom' => $room]);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => now()->subHours(2)]);
        }
    }

    /** Each screen is its own browser: a fresh session, paired by its cookie. */
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

        return $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertOk();
    }

    private function board(): array
    {
        return app(\App\Services\StaffPresence::class)->board();
    }
}
