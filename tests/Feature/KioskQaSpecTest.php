<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\KioskEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\StaffPresence;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA spec for the kiosks (28 Oct 2026), case for case: A keypad, B front
 * door, C room tablet, D tablet setup, E Who's where, F activity log,
 * G edge cases.
 *
 * Fixture: the spec's 10:30 AM starting state, rebuilt before every test
 * (RefreshDatabase). Each kiosk action advances the clock one minute.
 * Logic and state only: where a behaviour lives in the screen's script
 * (the keypad, Cancel, done-screen titles at the door), the test reads the
 * script and the server's answer rather than pixels.
 */
class KioskQaSpecTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-10-15';

    private User $admin;

    /** @var array<string, User> */
    private array $t = [];

    private StaffDevice $door;

    private StaffDevice $tablet;

    private StaffPresence $presence;

    private TimeClock $clock;

    private int $mark = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('10:30');
        config(['daycare.kiosk.enabled' => true]);
        $this->presence = app(StaffPresence::class);
        $this->clock = app(TimeClock::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ray K']);

        foreach (['Infant' => [7, 4], 'Transition' => [5, 5], 'Toddler' => [5, 5], 'PreK' => [7, 7], 'UPK-4' => [16, 8], 'School Age' => [0, 10]] as $room => [$kids, $ratio]) {
            Setting::put("rooms.$room.ratio", $ratio);
            for ($i = 1; $i <= $kids; $i++) {
                $c = Child::create(['lan' => substr($room, 0, 3).$i, 'first_name' => "K$i", 'last_name' => $room, 'status' => 'Active', 'classroom' => $room, 'classroom_override' => $room]);
                Attendance::create(['child_id' => $c->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => $this->time('07:40')]);
            }
        }
        foreach (['Infant' => '2101', 'Transition' => '2102', 'Toddler' => '2103', 'PreK' => '2104', 'UPK-4' => '2105', 'School Age' => '2106'] as $room => $code) {
            Setting::put("rooms.$room.tablet_code", $code);
        }

        // name, pin, scheduled room, shift, [in, room, since]
        $rows = [
            ['LaQuora', '1111', 'PreK', ['10:45', '18:00'], null],
            ['Frenci', '2222', 'UPK-4', ['08:30', '17:00'], ['08:31', 'UPK-4', '08:31']],
            ['Lana', '3333', 'PreK', ['07:00', '15:30'], ['07:12', 'PreK', '07:12']],
            ['Sultana', '4444', 'Toddler', ['11:00', '18:00'], null],
            ['Evana', '5555', 'Infant', ['07:30', '16:00'], ['07:28', 'Infant', '07:28']],
            ['Farhana', '6666', 'Infant', ['08:00', '16:30'], ['07:58', 'Infant', '07:58']],
            ['Mary Jo', '7777', 'Toddler', ['07:00', '15:30'], ['06:55', 'Toddler', '06:55']],
            ['Chandler', '8888', 'Transition', ['08:00', '16:30'], ['08:02', 'Transition', '08:02']],
            ['Denise', '9999', 'UPK-4', ['08:00', '16:30'], ['07:57', 'UPK-4', '07:57']],
            ['Zameera', '1212', 'Transition', ['15:00', '18:00'], null],
            ['Tammy', '1313', 'Toddler', null, null],
        ];
        foreach ($rows as [$name, $pin, $room, $shift, $on]) {
            $u = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $room]);
            $u->setKioskPin($pin);
            $this->t[$name] = $u;
            if ($shift) {
                StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $u->id, 'shift_date' => self::DAY, 'day' => 4, 'starts_at' => $this->min($shift[0]), 'ends_at' => $this->min($shift[1]), 'classroom' => $room, 'role' => 'STAFF']);
            }
            if ($on) {
                $this->clock->punch($u, TimePunch::IN, $this->time($on[0]));
                StaffRoomCheckin::create(['user_id' => $u->id, 'classroom' => $on[1], 'started_at' => $this->time($on[2]), 'started_via' => StaffRoomCheckin::VIA_DOOR]);
            }
        }
        // Evana: on break since 10:15, from Infant.
        $this->clock->punch($this->t['Evana'], TimePunch::BREAK_START, $this->time('10:15'));
        StaffRoomCheckin::where('user_id', $this->t['Evana']->id)->update(['ended_at' => $this->time('10:15'), 'ended_reason' => StaffRoomCheckin::ENDED_BREAK]);

        StaffTimeOff::create(['user_id' => $this->t['Zameera']->id, 'date' => self::DAY, 'kind' => 'until', 'from_minute' => 15 * 60, 'reason' => 'Appointment', 'paid' => false, 'created_by' => $this->admin->id]);
        StaffTimeOff::create(['user_id' => $this->t['Tammy']->id, 'date' => self::DAY, 'kind' => 'all', 'reason' => 'Personal', 'paid' => false, 'created_by' => $this->admin->id]);

        $this->door = $this->device('Front desk', 'clock', null);
        $this->tablet = $this->device('PreK tablet', 'room', 'PreK');

        KioskEvent::query()->delete();
        $this->mark = 0;
    }

    /* ================= A. Keypad ================= */

    public function test_A1_wrong_pin_on_either_device(): void
    {
        $before = $this->snapshot();
        $this->asDoor();
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '0000'])->assertJson(['status' => 'not_found']);
        $this->asTablet();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '0000'])->assertJson(['status' => 'not_found']);
        $this->assertSame($before, $this->snapshot(), 'state changed');
        foreach (['clock/kiosk', 'room/kiosk'] as $view) {
            $js = $this->src($view);
            $this->assertStringContainsString("title: 'Not recognised'", $js);
            $this->assertStringContainsString("this.entry = '';", $js, "$view does not clear the PIN");
        }
    }

    public function test_A2_clear_empties_the_pin(): void
    {
        foreach (['clock/kiosk', 'room/kiosk'] as $view) {
            $this->assertStringContainsString('@click="entry = \'\'"', $this->src($view), "$view has no Clear");
        }
    }

    public function test_A3_backspace_deletes_one_digit(): void
    {
        foreach (['clock/kiosk', 'room/kiosk'] as $view) {
            $this->assertStringContainsString('entry = entry.slice(0, -1)', $this->src($view), "$view PIN pad has no ⌫");
        }
    }

    public function test_A4_only_four_digits_are_used(): void
    {
        foreach (['clock/kiosk', 'room/kiosk'] as $view) {
            $this->assertStringContainsString('if (this.entry.length === 4) this.identify();', $this->src($view));
        }
        $this->asDoor();
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '33331'])->assertStatus(422);
    }

    /* ================= B. Front door ================= */

    public function test_B1_status_card_for_someone_off(): void
    {
        $this->asDoor();
        $r = $this->identify('1111');
        $r->assertJsonPath('staff.presence_label', 'Not on the clock');
        $this->assertStringContainsString('10:45 AM – 6:00 PM · PreK', (string) $r->json('staff.today'));
        $this->assertSame(['clock_in'], array_column($r->json('staff.actions'), 'action'));
    }

    public function test_B2_room_picker_scheduled_first_and_office(): void
    {
        $this->asDoor();
        $rooms = $this->identify('1111')->json('staff.rooms');
        $this->assertSame('PreK', $rooms[0]['room']);
        $this->assertTrue($rooms[0]['mine']);
        $this->assertStringContainsString('Your schedule', $this->src('clock/kiosk'));
        $this->assertStringContainsString('Office / no room', $this->src('clock/kiosk'));
    }

    public function test_B3_clock_in_to_prek(): void
    {
        $this->asDoor();
        $r = $this->doorPunch('1111', 'clock_in', 'PreK');
        $this->assertSame('clock_in', $r->json('action'));
        $this->assertDoorTitle('clock_in', 'You’re clocked in');
        $s = $this->presence->status($this->t['LaQuora']);
        $this->assertSame([StaffPresence::IN_ROOM, 'PreK'], [$s['state'], $s['room']]);
        $this->assertSame('10:31', $this->firstIn('LaQuora'));
        $this->assertSame(0, $this->breakMinutes('LaQuora'));
        $this->assertLogContains(['LaQuora clocked in (front door)', 'LaQuora → PreK']);
    }

    public function test_B4_clock_in_to_the_office_is_not_counted(): void
    {
        $this->asDoor();
        $this->doorPunch('1111', 'clock_in', 'office');
        $this->assertSame(StaffPresence::NO_ROOM, $this->presence->status($this->t['LaQuora'])['state']);
        $this->assertFalse($this->presence->teachersIn('PreK')->contains('user_id', $this->t['LaQuora']->id));
    }

    public function test_B5_lana_starts_a_break_and_prek_goes_short(): void
    {
        $this->asDoor();
        $r = $this->doorPunch('3333', 'break_start');
        $this->assertDoorTitle('break_start', 'Enjoy your break');
        $s = $this->presence->status($this->t['Lana']);
        $this->assertSame([StaffPresence::ON_BREAK, 'PreK'], [$s['state'], $s['last_room']]);
        $this->assertSame('PreK now has 0 teachers for 7 children (needs 1). The office has been told.', $r->json('warning'));
        $this->assertLogContains(['Ratio: PreK short after Lana left']);
        $this->assertSame('#be123c', $this->colourOf('Ratio: PreK short after Lana left'));
    }

    public function test_B6_evana_ends_her_break(): void
    {
        $this->asDoor();
        $this->doorPunch('5555', 'break_end');
        $this->assertDoorTitle('break_end', 'Welcome back');
        $this->assertSame('Infant', $this->presence->status($this->t['Evana'])['room']);
        $this->assertSame(16, $this->breakMinutes('Evana'));
        $this->assertLogContains(['Evana ended break (16 min)', 'Evana → back to Infant']);
        $this->assertFalse($this->room('Infant')['short']);
    }

    public function test_B7_evana_goes_somewhere_else(): void
    {
        $this->asDoor();
        $this->doorPunch('5555', 'break_end', 'Toddler');
        $this->assertSame('Toddler', $this->presence->status($this->t['Evana'])['room']);
        $this->assertSame(16, $this->breakMinutes('Evana'));
        $this->assertTrue($this->room('Infant')['short']);
    }

    public function test_B8_farhana_clocks_out(): void
    {
        $this->asDoor();
        $r = $this->doorPunch('6666', 'clock_out');
        $this->assertDoorTitle('clock_out', 'See you tomorrow');
        $this->assertSame(StaffPresence::OFF, $this->presence->status($this->t['Farhana'])['state']);
        $this->assertTrue(TimePunch::where('user_id', $this->t['Farhana']->id)->where('type', TimePunch::OUT)->exists());
        $this->assertNotNull($r->json('warning'));
        $this->assertLogContains(['Farhana left Infant', 'Ratio: Infant short after Farhana left', 'Farhana clocked out · 2h 33m']);
    }

    public function test_B9_hours_worked_for_evana_at_1030(): void
    {
        // 10:30 − 7:28 − the 15-minute break still running = 2h 47m.
        $this->assertSame(167, (int) $this->clock->day($this->t['Evana']->id, self::DAY)['so_far']);
    }

    public function test_B10_clocking_out_from_the_office(): void
    {
        $this->asDoor();
        $this->doorPunch('1111', 'clock_in', 'office');
        $this->mark();
        $r = $this->doorPunch('1111', 'clock_out');
        $this->assertNull($r->json('warning'));
        $this->assertEmpty(array_filter($this->newLog(), fn ($l) => str_contains($l, ' left ')));
    }

    public function test_B11_cancel_changes_nothing(): void
    {
        $before = $this->snapshot();
        $this->asDoor();
        $this->identify('3333');
        $this->assertSame($before, $this->snapshot());
        $this->assertStringContainsString('Not <span x-text="firstName"></span>? Cancel', $this->src('clock/kiosk'));
        $this->assertStringContainsString('@click="reset()"', $this->src('clock/kiosk'));
    }

    /* ================= C. Room tablet (PreK) ================= */

    public function test_C1_sultana_is_clocked_in_at_the_tablet(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '4444'])->assertJson(['status' => 'done'])->assertJsonPath('done.title', 'Clocked in for you');
        $this->assertSame('PreK', $this->presence->status($this->t['Sultana'])['room']);
        $this->assertLogContains(['Sultana clocked in (auto, PreK tablet)', 'Sultana → PreK', 'Sultana skipped the front door — office to confirm start time']);
        $this->assertSame('#be123c', $this->colourOf('Sultana skipped the front door — office to confirm start time'));
    }

    public function test_C2_lana_already_in_prek(): void
    {
        $this->asTablet();
        $r = $this->postJson(route('room.kiosk.identify'), ['pin' => '3333']);
        $r->assertJsonPath('staff.is_here', true)->assertJsonPath('staff.can_here', false);
    }

    public function test_C3_frenci_moves_into_prek_and_upk4_goes_short(): void
    {
        $this->asTablet();
        $r = $this->tabletPunch('2222', 'check_in');
        $r->assertJsonPath('done.title', 'You’re in PreK')
            ->assertJsonPath('done.warning', 'UPK-4 now has 1 teacher for 16 children (needs 2). The office has been told.');
        $this->assertSame('PreK', $this->presence->status($this->t['Frenci'])['room']);
        $this->assertLogContains(['Frenci left UPK-4', 'Frenci → PreK (PreK tablet)', 'Ratio: UPK-4 short after Frenci left']);
    }

    public function test_C4_frencis_own_room_is_marked_and_not_clickable(): void
    {
        $this->asTablet();
        $rooms = collect($this->postJson(route('room.kiosk.identify'), ['pin' => '2222'])->json('staff.rooms'));
        $this->assertTrue($rooms->firstWhere('room', 'UPK-4')['is_here']);
        $this->assertStringContainsString(':disabled="sending || other.is_here"', $this->src('room/kiosk'));
    }

    public function test_C5_evana_on_break_checks_into_prek(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJsonPath('staff.on_break', true);
        $this->assertStringContainsString('ends your break', $this->src('room/kiosk'));
        // One action, one minute: the check-in lands at 10:31, 16 minutes after the break began.
        $this->tabletPunch('5555', 'check_in')->assertJsonPath('done.sub', 'Break ended. Checked into PreK at 10:31 AM.');
        $this->assertSame(TimeClock::WORKING, $this->clock->state($this->t['Evana'], self::DAY));
        $this->assertSame(16, $this->breakMinutes('Evana'));
        $this->assertTrue($this->room('Infant')['short']);
    }

    public function test_C6_denise_takes_a_break_at_the_tablet(): void
    {
        $this->asTablet();
        $r = $this->tabletPunch('9999', 'break_start');
        $r->assertJsonPath('done.title', 'Enjoy your break')
            ->assertJsonPath('done.warning', 'UPK-4 now has 1 teacher for 16 children (needs 2). The office has been told.');
        $s = $this->presence->status($this->t['Denise']);
        $this->assertSame([StaffPresence::ON_BREAK, 'UPK-4'], [$s['state'], $s['last_room']]);
        $this->assertLogContains(['Ratio: UPK-4 short after Denise left']);
    }

    public function test_C7_no_break_button_while_on_break(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '5555'])->assertJsonPath('staff.can_break', false);
    }

    public function test_C8_no_clock_out_on_the_tablet(): void
    {
        $this->asTablet();
        $this->tabletPunch('3333', 'clock_out', expect: 'stale');
        $this->assertSame(TimeClock::WORKING, $this->clock->state($this->t['Lana'], self::DAY));
        $this->assertStringContainsString('Clock out at the front door.', $this->src('room/kiosk'));
    }

    /* ================= D. Tablet setup ================= */

    public function test_D1_setup_screen_shows_the_current_room(): void
    {
        $this->asTablet();
        $html = $this->get(route('room.kiosk'))->assertOk()->getContent();
        $this->assertStringContainsString('Set up this tablet', $html);
        $this->assertStringContainsString('Right now this tablet is', $html);
        $this->assertStringContainsString("room: 'PreK'", $html);
    }

    public function test_D2_code_2101_makes_it_infant(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2101'])->assertJson(['status' => 'ok', 'room' => 'Infant', 'was' => 'PreK']);
        $this->assertSame('Infant', $this->tablet->fresh()->classroom);
        $this->assertLogContains(['Room tablet set to Infant (was PreK)']);
        $this->assertStringContainsString('title: `This tablet is now ${data.room}`', $this->src('room/kiosk'));
    }

    public function test_D3_a_bad_code(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.setup'), ['code' => '9999'])->assertJson(['status' => 'bad_code']);
        $this->assertSame('PreK', $this->tablet->fresh()->classroom);
        $this->assertStringContainsString('That’s not a room code', $this->src('room/kiosk'));
    }

    public function test_D4_same_room_code(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104'])->assertJson(['status' => 'ok', 'room' => 'PreK']);
        $this->assertLogContains(['Room tablet set to PreK']);
        $this->assertEmpty(array_filter($this->newLog(), fn ($l) => str_contains($l, '(was')));
    }

    public function test_D5_after_2101_sultana_lands_in_infant(): void
    {
        $this->asTablet();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2101']);
        $this->act();
        $this->postJson(route('room.kiosk.identify'), ['pin' => '4444'])->assertJson(['status' => 'done']);
        $this->assertSame('Infant', $this->presence->status($this->t['Sultana'])['room']);
    }

    /* ================= E. Who's where ================= */

    public function test_E1_initial_room_badges(): void
    {
        $this->assertSame(['1 teacher · need 2 · 7 kids', 'bad'], $this->badge('Infant'));
        foreach (['Transition', 'Toddler', 'PreK'] as $room) {
            $this->assertSame('ok', $this->badge($room)[1], "$room not green");
        }
        $this->assertSame(['2 teachers · need 2 · 16 kids', 'ok'], $this->badge('UPK-4'));
        $this->assertSame(['no children', 'mut'], $this->badge('School Age'));
    }

    public function test_E2_on_break_list(): void
    {
        $board = $this->presence->board();
        $this->assertCount(1, $board['on_break']);
        $this->assertSame(['name' => 'Evana', 'since' => '10:15 AM', 'last' => 'Infant'], $board['on_break'][0]);
    }

    public function test_E3_not_clocked_in_labels(): void
    {
        $notes = $this->notIn();
        $this->assertSame('Due 10:45 AM', $notes['LaQuora']);
        $this->assertSame('Due 11:00 AM', $notes['Sultana']);
        $this->assertSame('Time off until 3:00 PM', $notes['Zameera']);
        $this->assertSame('Off today', $notes['Tammy']);
    }

    public function test_E4_laquora_late_at_1100(): void
    {
        $this->travel(30)->minutes();
        $this->assertSame('Late · due 10:45 AM', $this->notIn()['LaQuora']);
    }

    public function test_E5_farhana_clocked_out(): void
    {
        $this->asDoor();
        $this->doorPunch('6666', 'clock_out');
        $this->assertSame('Clocked out 10:31 AM', $this->notIn()['Farhana']);
    }

    public function test_E6_zameera_late_after_three(): void
    {
        $this->at('15:06');
        $this->assertSame('Late · due 3:00 PM', $this->notIn()['Zameera']);
    }

    /* ================= F. Activity log ================= */

    public function test_F1_newest_first(): void
    {
        $this->asDoor();
        $this->doorPunch('1111', 'clock_in', 'PreK');
        $this->doorPunch('3333', 'break_start');
        // LaQuora is in PreK now, so Lana leaving does not make it short: no flag.
        $texts = array_column($this->presence->board()['activity'], 'text');
        $this->assertSame(['Lana left PreK', 'Lana started break (front door)', 'LaQuora → PreK', 'LaQuora clocked in (front door)'], $texts);
        $this->assertNotContains('Ratio: PreK short after Lana left', $texts);
    }

    public function test_F2_never_more_than_12(): void
    {
        $this->asDoor();
        foreach (['1111' => 'PreK', '4444' => 'Toddler', '1313' => 'Infant', '1212' => 'Transition'] as $pin => $room) {
            $this->doorPunch($pin, 'clock_in', $room);
            $this->doorPunch($pin, 'break_start');
        }
        $this->assertGreaterThan(12, KioskEvent::count());
        $this->assertLessThanOrEqual(12, count($this->presence->board()['activity']));
    }

    public function test_F3_colours(): void
    {
        $this->asDoor();
        $this->doorPunch('3333', 'break_start');
        $this->asTablet();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104']);
        $this->assertSame('#1b5e91', $this->colourOf('Lana started break (front door)'));
        $this->assertSame(\App\Services\ScheduleEditor::COLOURS['PreK'][1], $this->colourOf('Lana left PreK'));
        $this->assertSame('#be123c', $this->colourOf('Ratio: PreK short after Lana left'));
        $this->assertSame('#9aa3b5', $this->colourOf('Room tablet set to PreK'));
    }

    public function test_F4_ratio_flag_above_the_actions_own_entries(): void
    {
        $this->asDoor();
        $this->doorPunch('3333', 'break_start');
        $texts = array_column($this->presence->board()['activity'], 'text');
        $this->assertSame(['Ratio: PreK short after Lana left', 'Lana left PreK', 'Lana started break (front door)'], array_slice($texts, 0, 3));
    }

    /* ================= G. Edge cases ================= */

    public function test_G1_door_then_tablet_one_state_one_room(): void
    {
        $this->asDoor();
        $this->doorPunch('1111', 'clock_in', 'Toddler');
        $this->asTablet();
        $this->tabletPunch('1111', 'check_in');
        $this->asDoor();
        $this->doorPunch('1111', 'break_start');
        $this->asTablet();
        $this->tabletPunch('1111', 'check_in');
        $this->assertSame(1, StaffRoomCheckin::open()->where('user_id', $this->t['LaQuora']->id)->count());
        $this->assertSame('PreK', $this->presence->status($this->t['LaQuora'])['room']);
    }

    public function test_G2_breaks_never_negative_and_accumulate(): void
    {
        $this->asDoor();
        $this->doorPunch('3333', 'break_start');
        $this->travel(10)->minutes();
        $this->doorPunch('3333', 'break_end');
        $this->doorPunch('3333', 'break_start');
        $this->travel(5)->minutes();
        $this->doorPunch('3333', 'break_end');
        $this->assertSame(17, $this->breakMinutes('Lana')); // 11 + 6
    }

    public function test_G3_reset_demo(): void
    {
        $this->markTestSkipped('There is no "Reset demo" in the app — it is a mock-up control. Every test here starts from the fixture again.');
    }

    public function test_G4_clock_in_again_after_clocking_out(): void
    {
        $this->asDoor();
        $this->doorPunch('6666', 'clock_out'); // 10:31, worked 2h 33m
        $this->travel(29)->minutes();
        $this->doorPunch('6666', 'clock_in', 'Infant'); // 11:01
        $this->travel(29)->minutes();
        $this->act(); // 11:31
        $line = $this->presence->line($this->t['Farhana'], $this->presence->status($this->t['Farhana']), []);
        $this->assertStringContainsString('since 11:01 AM', $line);
        $this->assertSame(153 + 30, (int) $this->clock->day($this->t['Farhana']->id, self::DAY)['so_far']);
    }

    public function test_G5_tammy_with_no_shift_clocks_in(): void
    {
        $this->asDoor();
        $this->doorPunch('1313', 'clock_in', 'Toddler');
        $this->assertSame('Toddler', $this->presence->status($this->t['Tammy'])['room']);
        $this->assertArrayNotHasKey('Tammy', $this->notIn());
    }

    /* ================= helpers ================= */

    private function time(string $hm): Carbon
    {
        return Carbon::parse(self::DAY.' '.$hm, config('app.timezone'));
    }

    private function at(string $hm): void
    {
        $this->travelTo($this->time($hm));
    }

    private function min(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return $h * 60 + $m;
    }

    private function act(): void
    {
        $this->travel(1)->minutes();
    }

    private function device(string $name, string $kind, ?string $room): StaffDevice
    {
        $d = new StaffDevice(['name' => $name, 'kind' => $kind, 'classroom' => $room]);
        $d->created_by = $this->admin->id;
        $d->token_hash = '';
        $d->save();

        return $d;
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

    private function identify(string $pin)
    {
        return $this->postJson(route('clock.kiosk.identify'), ['pin' => $pin])->assertOk()->assertJson(['status' => 'ok']);
    }

    private function doorPunch(string $pin, string $action, ?string $room = null)
    {
        $this->act();
        $ticket = $this->identify($pin)->json('ticket');

        return $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertOk()->assertJson(['status' => 'ok']);
    }

    private function tabletPunch(string $pin, string $action, ?string $room = null, string $expect = 'ok')
    {
        $this->act();
        $ticket = $this->postJson(route('room.kiosk.identify'), ['pin' => $pin])->assertJson(['status' => 'ok'])->json('ticket');

        return $this->postJson(route('room.kiosk.punch'), ['ticket' => $ticket, 'action' => $action, 'room' => $room])->assertJson(['status' => $expect]);
    }

    private function src(string $name): string
    {
        return file_get_contents(resource_path("views/$name.blade.php"));
    }

    private function assertDoorTitle(string $action, string $title): void
    {
        $this->assertStringContainsString("$action: '$title'", $this->src('clock/kiosk'));
    }

    private function snapshot(): array
    {
        return [TimePunch::count(), StaffRoomCheckin::count(), StaffRoomCheckin::open()->count(), KioskEvent::count()];
    }

    private function firstIn(string $name): string
    {
        return Carbon::parse(TimePunch::where('user_id', $this->t[$name]->id)->where('type', TimePunch::IN)->value('punched_at'))->timezone(config('app.timezone'))->format('H:i');
    }

    private function breakMinutes(string $name): int
    {
        $day = $this->clock->day($this->t[$name]->id, self::DAY);

        return (int) $day['paid_break'] + (int) $day['unpaid_break'];
    }

    private function room(string $room): array
    {
        return $this->presence->roomSummary($room);
    }

    private function badge(string $room): array
    {
        $r = collect($this->presence->board()['rooms'])->firstWhere('room', $room);

        return [$r['label'], $r['tone']];
    }

    private function notIn(): array
    {
        return collect($this->presence->board()['not_in'])->pluck('note', 'name')->all();
    }

    private function mark(): void
    {
        $this->mark = (int) KioskEvent::max('id');
    }

    private function newLog(): array
    {
        return KioskEvent::where('id', '>', $this->mark)->orderBy('id')->pluck('text')->all();
    }

    private function assertLogContains(array $lines): void
    {
        $log = $this->newLog();
        foreach ($lines as $line) {
            $this->assertContains($line, $log, "log has no \"$line\" — it has: ".implode(' | ', $log));
        }
    }

    private function colourOf(string $text): ?string
    {
        return collect($this->presence->board(100)['activity'])->firstWhere('text', $text)['colour'] ?? null;
    }
}
