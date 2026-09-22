<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffClockController;
use App\Models\StaffDevice;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\StaffKiosk;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The time clock on the wall: one screen per state.
 *
 * Three states and one set of buttons each, so an impossible action is never
 * on screen to be tapped. Most of what is checked here is what the clock
 * refuses — a screen in a lobby with nobody signed in at it is defined by what
 * it will not do.
 *
 * The thing it exists for is still one sentence: a card under a scanner is the
 * same punch a supervisor would have typed, in the same table, so timesheets
 * and payroll need to know nothing about kiosks.
 */
class StaffClockKioskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-21 07:52:00'));
        config(['daycare.kiosk.enabled' => true]);
    }

    public function test_clocked_out_offers_only_clock_in(): void
    {
        [, $token] = $this->device();
        [, $card] = $this->staffWithCard();

        $this->pair($token);

        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonPath('staff.state_label', 'Not clocked in')
            ->assertJsonPath('staff.actions.0.action', 'clock_in')
            ->assertJsonCount(1, 'staff.actions');
    }

    public function test_clocked_in_offers_a_break_and_a_clock_out(): void
    {
        [$device, $token] = $this->device();
        [$staff, $card] = $this->staffWithCard();

        $this->pair($token);
        $this->press($card, 'clock_in')->assertJson(['status' => 'ok', 'title' => 'Clocked in']);

        // The same row the employee's own clock writes, so everything
        // downstream sees one kind of punch and not two.
        $this->assertDatabaseHas('time_punches', [
            'user_id' => $staff->id,
            'type' => TimePunch::IN,
            'staff_device_id' => $device->id,
            'method' => StaffKiosk::METHOD_CARD,
        ]);

        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJsonPath('staff.state_label', 'Clocked in')
            ->assertJsonCount(2, 'staff.actions')
            ->assertJsonPath('staff.actions.0.action', 'break_start')
            ->assertJsonPath('staff.actions.1.action', 'clock_out');
    }

    public function test_a_break_offers_only_the_way_back_on_the_clock(): void
    {
        /*
         * The rule the whole shape rests on: a break always ends back on the
         * clock, so leaving for the day is End Break then Clock Out. Offering
         * Clock Out here would record a day whose break never ended.
         */
        [, $token] = $this->device();
        [, $card] = $this->staffWithCard();

        $this->pair($token);
        $this->press($card, 'clock_in');
        $this->press($card, 'break_start')->assertJson(['status' => 'ok', 'title' => 'Break started']);

        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJsonPath('staff.state_label', 'On break')
            ->assertJsonCount(1, 'staff.actions')
            ->assertJsonPath('staff.actions.0.action', 'break_end');

        $this->press($card, 'break_end')->assertJson(['status' => 'ok', 'title' => 'Back on the clock']);

        // And back on the clock is back to two buttons.
        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJsonPath('staff.state_label', 'Clocked in');
    }

    public function test_an_action_the_state_does_not_allow_is_refused(): void
    {
        // Drawn on the screen and checked again here: a stale tablet somebody
        // comes back to must not post an action that was true a minute ago.
        [, $token] = $this->device();
        [$staff, $card] = $this->staffWithCard();

        $this->pair($token);

        $this->press($card, 'clock_out')->assertJson(['status' => 'stale']);

        $this->assertSame(0, TimePunch::count());
        $this->assertSame(TimeClock::OFF, app(TimeClock::class)->state($staff, '2026-09-21'));
    }

    public function test_a_punch_needs_a_card_presented_at_this_screen(): void
    {
        // Otherwise the punch endpoint on its own is a way to clock anybody in
        // from anywhere on the network.
        [, $token] = $this->device();
        $this->staffWithCard();

        $this->pair($token);

        $this->postJson(route('clock.kiosk.punch'), ['ticket' => 'made-up', 'action' => 'clock_in'])
            ->assertOk()
            ->assertJson(['status' => 'expired']);

        $this->assertSame(0, TimePunch::count());
    }

    public function test_a_ticket_is_spent_once(): void
    {
        // Two presses on one scan would be two punches from one person walking
        // up once.
        [, $token] = $this->device();
        [, $card] = $this->staffWithCard();

        $this->pair($token);

        $ticket = $this->postJson(route('clock.kiosk.identify'), ['card' => $card])->json('ticket');

        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => 'clock_in'])
            ->assertJson(['status' => 'ok']);

        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => 'clock_out'])
            ->assertJson(['status' => 'expired']);

        $this->assertSame(1, TimePunch::count());
    }

    public function test_a_ticket_goes_stale_when_somebody_walks_away(): void
    {
        [, $token] = $this->device();
        [, $card] = $this->staffWithCard();

        $this->pair($token);

        $ticket = $this->postJson(route('clock.kiosk.identify'), ['card' => $card])->json('ticket');

        $this->travel(StaffClockController::TICKET_SECONDS + 5)->seconds();

        // The screen belongs to whoever is standing at it now.
        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => 'clock_in'])
            ->assertJson(['status' => 'expired']);

        $this->assertSame(0, TimePunch::count());
    }

    public function test_the_pin_is_the_fallback_and_is_recorded_as_one(): void
    {
        [, $token] = $this->device();
        $staff = User::factory()->create(['role' => 'teacher']);
        $staff->setKioskPin('4821');

        $this->pair($token);

        $ticket = $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])
            ->assertOk()
            ->json('ticket');

        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => 'clock_in'])
            ->assertJson(['status' => 'ok']);

        // Which one was presented is the thing a disputed morning turns on.
        $this->assertDatabaseHas('time_punches', [
            'user_id' => $staff->id,
            'method' => StaffKiosk::METHOD_PIN,
        ]);
    }

    public function test_an_unpaired_screen_neither_identifies_nor_punches(): void
    {
        [, $card] = $this->staffWithCard();

        // The address on its own must not be a time clock anybody on the
        // network can press.
        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJson(['status' => 'unpaired']);

        $this->postJson(route('clock.kiosk.punch'), ['ticket' => 'x', 'action' => 'clock_in'])
            ->assertOk()
            ->assertJson(['status' => 'unpaired']);

        $this->assertSame(0, TimePunch::count());
    }

    public function test_a_wrong_pairing_token_is_refused(): void
    {
        $this->device();

        $this->get(route('clock.kiosk', ['token' => 'not-the-token']))->assertForbidden();
    }

    public function test_pairing_drops_the_token_from_the_address(): void
    {
        [, $token] = $this->device();

        // Otherwise the token rides around in a history, a proxy log and any
        // screenshot of the tablet.
        $this->get(route('clock.kiosk', ['token' => $token]))
            ->assertRedirect(route('clock.kiosk'));

        $this->get(route('clock.kiosk'))->assertOk()->assertSee('Scan your card');
    }

    public function test_the_clock_is_plain(): void
    {
        /*
         * The door kiosk has a drawn sky because a four-year-old stands in
         * front of it. This is an employment record being made, and the
         * balloons behind a clock-out at the end of a long shift were the
         * wrong note — so it keeps the app's palette and its tap targets and
         * drops the decoration.
         */
        [, $token] = $this->device();
        $this->pair($token);

        $html = $this->get(route('clock.kiosk'))->assertOk()->getContent();

        // Asserted as properties rather than as exact class strings — the last
        // version of this test pinned the border widths and broke on a redesign
        // that changed nothing it was there to protect.

        // Touch: the kiosk rules, and targets big enough to hit from a queue.
        $this->assertStringContainsString('kiosk-touch', $html);
        $this->assertMatchesRegularExpression('/min-h-1[2-9]|min-h-\[4\.5rem\]/', $html, 'wall-sized tap targets');

        // The app's own palette, not a terminal's.
        $this->assertStringContainsString('bg-indigo-600', $html);

        // No illustration and no glass: this is an employment record being
        // made, not the screen a four-year-old stands at.
        foreach (['kids-bg', 'glass-card'] as $decoration) {
            $this->assertStringNotContainsString($decoration, $html, "{$decoration} should not be on a formal screen");
        }

        // Nothing left of the dark terminal it was before that.
        foreach (['night-950', 'night-900', 'bg-white/5'] as $leftover) {
            $this->assertStringNotContainsString($leftover, $html, "{$leftover} is left over from the dark design");
        }
    }

    public function test_the_masthead_says_where_and_when_in_every_state(): void
    {
        // The one part of the screen that never moves. It is also the room's
        // clock on the wall, whatever the screen happens to be doing.
        [$device, $token] = $this->device();
        $this->pair($token);

        $html = $this->get(route('clock.kiosk'))->assertOk()->getContent();

        $this->assertStringContainsString($device->name, $html);
        $this->assertStringContainsString('Staff time clock', $html);
        $this->assertStringContainsString('x-text="now"', $html);
        $this->assertStringContainsString('x-text="today"', $html);
    }

    public function test_five_wrong_pins_stop_that_number_being_answered(): void
    {
        [, $token] = $this->device();
        $staff = User::factory()->create(['role' => 'teacher']);
        $staff->setKioskPin('4821');

        $this->pair($token);

        // Wrong before the first try: the index still points at this row, so
        // the clock finds them and then fails to prove it is them.
        $staff->forceFill(['kiosk_pin_hash' => bcrypt('0000')])->save();

        for ($try = 0; $try < 4; $try++) {
            $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])
                ->assertOk()
                ->assertJson(['status' => 'not_found']);

            $this->assertFalse($staff->fresh()->kioskIsLocked(), "locked after only {$try} tries");
        }

        $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])->assertOk();

        $this->assertTrue($staff->fresh()->kioskIsLocked(), 'five wrong tries did not lock it');
        $this->assertSame(0, TimePunch::count());
    }

    public function test_a_locked_number_reads_the_same_as_an_unknown_one(): void
    {
        // Somebody at a lobby screen must not learn from the wording whether a
        // number is in use. The caller is told apart; the screen is not.
        [, $token] = $this->device();
        $this->pair($token);

        $unknown = $this->postJson(route('clock.kiosk.identify'), ['pin' => '0000'])->json('status');

        $staff = User::factory()->create(['role' => 'teacher']);
        $staff->setKioskPin('4821');
        $staff->forceFill(['kiosk_locked_until' => now()->addMinutes(5)])->save();

        $locked = $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])->json('status');

        $this->assertSame('not_found', $unknown);
        $this->assertSame('locked', $locked);

        // Both render as "Not recognised" — asserted on the page, since that is
        // where the indistinguishability has to hold.
        $page = $this->get(route('clock.kiosk'))->getContent();
        $this->assertSame(2, substr_count($page, "title: 'Not recognised'"));
    }

    public function test_two_people_sharing_a_pin_are_asked_rather_than_guessed_at(): void
    {
        [, $token] = $this->device();
        $this->pair($token);

        foreach (['Grace', 'Marcus'] as $name) {
            User::factory()->create(['role' => 'teacher', 'name' => $name])->setKioskPin('4821');
        }

        // The wrong guess here is somebody else's day on somebody's timesheet.
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])
            ->assertOk()
            ->assertJson(['status' => 'ambiguous']);

        $this->assertSame(0, TimePunch::count());
    }

    public function test_somebody_left_at_lunch_by_the_app_is_offered_the_way_back(): void
    {
        // Nothing here starts a lunch, but the app can — and a kiosk with no
        // way out of a state somebody is in is a kiosk they are stuck at.
        [, $token] = $this->device();
        [$staff, $card] = $this->staffWithCard();

        $this->pair($token);
        $this->press($card, 'clock_in');

        app(TimeClock::class)->punch($staff, TimePunch::LUNCH_START, now());

        $this->postJson(route('clock.kiosk.identify'), ['card' => $card])
            ->assertOk()
            ->assertJsonPath('staff.state_label', 'At lunch')
            ->assertJsonCount(1, 'staff.actions')
            ->assertJsonPath('staff.actions.0.action', 'lunch_end');
    }

    public function test_the_card_code_is_never_readable_after_it_is_issued(): void
    {
        [$staff, $card] = $this->staffWithCard();

        $row = (array) \Illuminate\Support\Facades\DB::table('users')->where('id', $staff->id)->first();

        // A card whose number can be read out of the database is one anybody
        // with a database connection can clone.
        foreach ($row as $column => $value) {
            $this->assertNotSame($card, $value, "the card code is stored in plain text in {$column}");
        }

        $this->assertNotNull($row['card_hash']);
    }

    public function test_the_kiosk_is_off_unless_the_centre_turns_it_on(): void
    {
        config(['daycare.kiosk.enabled' => false]);

        $this->get(route('clock.kiosk'))->assertNotFound();
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])->assertNotFound();
        $this->postJson(route('clock.kiosk.punch'), ['ticket' => 'x', 'action' => 'clock_in'])->assertNotFound();
    }

    /** Scan, then press one of the buttons that state offered. */
    private function press(string $card, string $action)
    {
        $ticket = $this->postJson(route('clock.kiosk.identify'), ['card' => $card])->json('ticket');

        return $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => $action])->assertOk();
    }

    /** @return array{0: StaffDevice, 1: string} */
    private function device(): array
    {
        $device = new StaffDevice(['name' => 'Front desk kiosk', 'location' => 'Lobby']);
        $device->token_hash = '';
        $device->save();

        return [$device, $device->issueToken()];
    }

    /** @return array{0: User, 1: string} */
    private function staffWithCard(): array
    {
        $staff = User::factory()->create(['role' => 'teacher', 'name' => 'Jana Mercado']);

        return [$staff, $staff->issueCard()];
    }

    /** Visit once with the token, the way a tablet is set up. */
    private function pair(string $token): void
    {
        $this->get(route('clock.kiosk', ['token' => $token]))->assertRedirect();
    }
    /* ---- staying paired ----

       A clock on a wall is idle from Friday evening to Monday morning, which
       is longer than any sensible session. These are about the morning after. */

    public function test_each_device_pairs_independently(): void
    {
        [$lobby, $lobbyToken] = $this->device();
        [$kitchen, $kitchenToken] = $this->device();

        // Two separate browsers, as two tablets are.
        $this->get('/clock?token='.$lobbyToken)->assertRedirect(route('clock.kiosk'));
        $this->assertSame($lobby->id, session('staff_clock.device'));

        $this->flushSession();

        $this->get('/clock?token='.$kitchenToken)->assertRedirect(route('clock.kiosk'));
        $this->assertSame($kitchen->id, session('staff_clock.device'));
    }

    public function test_a_paired_device_is_remembered_after_its_session_has_gone(): void
    {
        [$device, $token] = $this->device();

        $this->get('/clock?token='.$token);

        // The weekend: the session expires, the cookie does not.
        $this->flushSession();

        $this->withCookie('staff_clock_device', $token)
            ->get('/clock')
            ->assertOk();

        $this->assertSame($device->id, session('staff_clock.device'), 'The cheap lookup is seeded again, so the hash check is not paid per press.');
    }

    public function test_a_retired_device_is_cut_off_at_once_despite_its_cookie(): void
    {
        [$device, $token] = $this->device();

        $device->forceFill(['is_active' => false])->save();

        $this->flushSession();

        $this->withCookie('staff_clock_device', $token)
            ->postJson(route('clock.kiosk.identify'), ['pin' => '1234'])
            ->assertOk()
            ->assertJson(['status' => 'unpaired']);
    }

    public function test_a_made_up_cookie_pairs_nothing(): void
    {
        $this->withCookie('staff_clock_device', 'not-a-real-token')
            ->postJson(route('clock.kiosk.identify'), ['pin' => '1234'])
            ->assertOk()
            ->assertJson(['status' => 'unpaired']);
    }
}