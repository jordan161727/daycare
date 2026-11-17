<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\LoginEvent;
use App\Models\Person;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SetsUpRoomTablets;
use Tests\TestCase;

/**
 * The kiosk QA round of 16 Nov 2026, K1–K6, and the two checks that came with
 * it: that wrong PINs are throttled and locked where they can be, and that no
 * signed-out kiosk hands out a list of children or staff before a PIN.
 *
 *   K1  PIN lengths from config/kiosk.php, never written into a screen
 *   K2  one staff PIN, one person — form, database, and a report for old pairs
 *   K3  a half-typed PIN clears itself after idle_clear_seconds
 *   K4  the room tablet's gear needs an administrator, and counts its tries
 *   K5  page titles carry the centre's name, not APP_NAME
 *   K6  US spelling on the kiosk screens
 */
class KioskHardeningTest extends TestCase
{
    use RefreshDatabase, SetsUpRoomTablets;

    private StaffDevice $tablet;

    private StaffDevice $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);
        Setting::put('company.name', 'Sunny Hill Daycare');

        \App\Models\RoomTabletCode::put('PreK', '2104');
        \App\Models\RoomTabletCode::put('UPK-4', '2105');

        $this->tablet = new StaffDevice(['name' => 'PreK iPad', 'kind' => StaffDevice::KIND_ROOM, 'classroom' => 'PreK']);
        $this->tablet->token_hash = '';
        $this->tablet->save();

        $this->door = new StaffDevice(['name' => 'Front desk']);
        $this->door->token_hash = '';
        $this->door->save();
    }

    /* ================= K1 — lengths from config ================= */

    public function test_K1_the_lengths_live_in_config(): void
    {
        // One length for every PIN, staff and parent; the room code its own.
        $this->assertSame(6, config('kiosk.pin_length'));
        $this->assertNull(config('kiosk.staff_pin_length'));
        $this->assertNull(config('kiosk.parent_pin_length'));
        $this->assertSame(4, config('kiosk.room_code_length'));
        $this->assertSame(15, config('kiosk.idle_clear_seconds'));
    }

    /**
     * The brief's own check: render every keypad at 6 and at 4 and see the
     * dots, the auto-submit and the server all follow the one value.
     */
    public function test_K1_pin_length_six_and_four_drive_every_kiosk(): void
    {
        foreach ([6 => ['482193', '4821'], 4 => ['4821', '482193']] as $length => [$good, $wrong]) {
            config(['kiosk.pin_length' => $length]);
            User::query()->update(['kiosk_pin_index' => null, 'kiosk_pin_hash' => null]);
            Person::query()->update(['pin_index' => null, 'pin_hash' => null]);
            $this->teacher('Lana '.$length, $good);
            $this->guardian('Amira '.$length, strrev($good));

            $this->pair($this->door);
            $this->get(route('clock.kiosk'))->assertOk()
                ->assertSee("pinLength: $length,", false)
                ->assertSee("key your $length-digit PIN");
            $this->postJson(route('clock.kiosk.identify'), ['pin' => $wrong])->assertStatus(422);
            $this->postJson(route('clock.kiosk.identify'), ['pin' => $good])->assertJson(['status' => 'ok']);

            $this->pair($this->tablet);
            $this->get(route('room.kiosk'))->assertOk()->assertSee("pinLength: $length,", false);
            $this->postJson(route('room.kiosk.identify'), ['pin' => $wrong])->assertStatus(422);
            $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => $wrong])->assertStatus(422);

            $this->flushSession();
            $this->get(route('kiosk.index'))->assertOk()->assertSee("x-data=\"kiosk(90, $length, 15)\"", false);
            $this->postJson(route('kiosk.unlock'), ['pin' => $wrong])->assertStatus(422);
            $this->postJson(route('kiosk.unlock'), ['pin' => strrev($good)])->assertJson(['status' => 'ok']);
            $this->flushSession();
        }
    }

    /** Changing the length to five changes both staff screens and the server. */
    public function test_K1_a_five_digit_staff_pin_is_what_the_clock_and_tablet_take(): void
    {
        config(['kiosk.pin_length' => 5]);
        $teacher = $this->teacher('Lana Ortiz', '48215');

        $this->pair($this->door);
        $this->get(route('clock.kiosk'))->assertOk()
            ->assertSee('pinLength: 5,', false)
            ->assertSee('x-for="i in pinLength"', false)
            ->assertSee('Scan your card or key your 5-digit PIN');

        $this->postJson(route('clock.kiosk.identify'), ['pin' => '4821'])->assertStatus(422);
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '48215'])->assertOk()->assertJson(['status' => 'ok']);

        $this->pair($this->tablet);
        $this->get(route('room.kiosk'))->assertOk()
            ->assertSee('pinLength: 5,', false)
            ->assertSee('x-for="i in pinLength"', false);

        $this->postJson(route('room.kiosk.identify'), ['pin' => '4821'])->assertStatus(422);
        $this->assertContains(
            $this->postJson(route('room.kiosk.identify'), ['pin' => '48215'])->assertOk()->json('status'),
            ['ok', 'done'],
        );

        // And the staff profile sets PINs of that length, not four.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from(route('teachers.show', $teacher))
            ->post(route('staff.card.pin', $teacher), ['kiosk_pin' => '1234', 'kiosk_pin_confirmation' => '1234'])
            ->assertSessionHasErrors('kiosk_pin');
        $this->actingAs($admin)->from(route('teachers.show', $teacher))
            ->post(route('staff.card.pin', $teacher), ['kiosk_pin' => '12345', 'kiosk_pin_confirmation' => '12345'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('teachers.show', $teacher))
            ->assertSee('5-digit clock PIN')
            ->assertSee('maxlength="5"', false);
    }

    public function test_K1_a_five_digit_parent_pin_is_what_the_door_takes(): void
    {
        config(['kiosk.pin_length' => 5]);
        $this->guardian('Amira', '48190');

        $this->get(route('kiosk.index'))->assertOk()
            ->assertSee('x-data="kiosk(90, 5, 15)"', false)
            ->assertSee('PHONE_DIGITS : pinLength', false);

        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertStatus(422);
        $this->postJson(route('kiosk.unlock'), ['pin' => '48190'])->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_K1_the_room_code_length_is_its_own(): void
    {
        config(['kiosk.room_code_length' => 5]);
        $this->pair($this->tablet);

        $this->get(route('room.kiosk'))->assertOk()
            ->assertSee('codeLength: 5,', false)
            ->assertSee('Key the 5-digit room code.', false);

        $token = $this->authorizeRoomSetup();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => $token])->assertStatus(422);
    }

    /** No bare 4 or 6 left where a PIN length belongs. */
    public function test_K1_no_pin_length_is_written_into_the_screens(): void
    {
        foreach (['clock/kiosk', 'room/kiosk', 'kiosk/index'] as $view) {
            $src = file_get_contents(resource_path("views/$view.blade.php"));

            $this->assertStringNotContainsString('i in 4', $src, $view);
            $this->assertStringNotContainsString('length === 4', $src, $view);
            $this->assertStringNotContainsString('length < 4', $src, $view);
            $this->assertStringNotContainsString('\d{4}', $src, $view);
            $this->assertStringNotContainsString('? 4 : 6', $src, $view);
            $this->assertStringNotContainsString('slice(0, 4)', $src, $view);
        }

        foreach (['StaffClockController', 'RoomTabletController', 'StaffCardController'] as $controller) {
            $this->assertStringNotContainsString("'digits:4'", file_get_contents(app_path("Http/Controllers/$controller.php")), $controller);
        }

        $this->assertStringNotContainsString("'digits:6'", file_get_contents(app_path('Http/Controllers/KioskController.php')));
    }

    public function test_K1_the_room_tablet_says_which_pin(): void
    {
        $this->pair($this->tablet);

        $this->get(route('room.kiosk'))->assertOk()
            ->assertSee('Same PIN as the staff time clock')
            ->assertDontSee('Same PIN as the front door');
    }

    /* ================= K2 — one staff PIN, one person ================= */

    public function test_K2_the_database_refuses_a_second_person_on_a_pin(): void
    {
        $this->teacher('Grace Hopper', '402121');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->teacher('Marcus Brown', '402121');
    }

    public function test_K2_the_staff_profile_refuses_it_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->teacher('Grace Hopper', '402121');
        $marcus = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($admin)->from(route('teachers.show', $marcus))
            ->post(route('staff.card.pin', $marcus), ['kiosk_pin' => '402121', 'kiosk_pin_confirmation' => '402121'])
            ->assertSessionHasErrors(['kiosk_pin' => 'Somebody else already uses that PIN. Pick different digits.']);

        $this->assertFalse($marcus->fresh()->hasKioskPin());
    }

    /** setKioskPin is called from one controller only, and that one validates. */
    public function test_K2_every_place_that_sets_a_staff_pin_validates_it(): void
    {
        $callers = collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
            ->filter(fn ($file) => str_contains($file->getContents(), '->setKioskPin('))
            ->map(fn ($file) => $file->getFilename())
            ->values()
            ->all();

        $this->assertSame(['StaffCardController.php'], $callers);
        $this->assertStringContainsString('User::kioskPinTaken', file_get_contents(app_path('Http/Controllers/StaffCardController.php')));
    }

    public function test_K2_no_ambiguous_answer_for_staff_any_more(): void
    {
        $this->assertStringNotContainsString("'ambiguous'", file_get_contents(app_path('Services/StaffKiosk.php')));

        $this->pair($this->door);
        $this->assertStringNotContainsString('share that PIN', $this->get(route('clock.kiosk'))->getContent());

        $this->pair($this->tablet);
        $this->assertStringNotContainsString('share that PIN', $this->get(route('room.kiosk'))->getContent());
    }

    /**
     * The migration stops on a collision, says what to do, and wipes nobody's
     * PIN; once the pair is sorted it runs.
     */
    public function test_K2_the_migration_refuses_to_run_over_shared_pins(): void
    {
        $migration = require database_path('migrations/2026_11_16_000001_make_kiosk_pin_index_unique_on_users_table.php');
        $migration->down();

        [$grace, $marcus] = $this->sharedPin();

        try {
            $migration->up();
            $this->fail('The migration ran over two people sharing a PIN.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('php artisan kiosk:duplicate-pins', $e->getMessage());
            $this->assertStringContainsString('Nothing has been changed', $e->getMessage());
        }

        $this->assertTrue($grace->fresh()->hasKioskPin(), 'the migration wiped a PIN');
        $this->assertTrue($marcus->fresh()->hasKioskPin(), 'the migration wiped a PIN');

        $marcus->forceFill(['kiosk_pin_index' => User::kioskIndexFor('731010'), 'kiosk_pin_hash' => bcrypt('731010')])->save();
        $migration->up();

        $this->assertTrue(collect(Schema::getIndexes('users'))->contains(
            fn ($index) => $index['columns'] === ['kiosk_pin_index'] && $index['unique']
        ));
    }

    public function test_K2_the_report_lists_who_shares_a_pin_and_never_the_pin(): void
    {
        $this->artisan('kiosk:duplicate-pins')->expectsOutput('No two people share a PIN.')->assertSuccessful();

        $migration = require database_path('migrations/2026_11_16_000001_make_kiosk_pin_index_unique_on_users_table.php');
        $migration->down();
        $this->sharedPin();
        User::factory()->create(['role' => 'teacher', 'name' => 'Ada Alone'])->setKioskPin('999999');

        Artisan::call('kiosk:duplicate-pins');
        $out = Artisan::output();

        $this->assertStringContainsString('Grace Hopper', $out);
        $this->assertStringContainsString('Marcus Brown', $out);
        $this->assertStringContainsString('grace@example.test', $out);
        $this->assertStringNotContainsString('Ada Alone', $out);
        $this->assertStringNotContainsString('402121', $out, 'the report printed a PIN');
        $this->assertStringNotContainsString(User::kioskIndexFor('402121'), $out, 'the report printed the index');
    }

    /* ================= K3 — idle clear ================= */

    public function test_K3_every_keypad_clears_a_half_typed_pin_after_the_idle_time(): void
    {
        config(['kiosk.idle_clear_seconds' => 7]);

        $this->pair($this->door);
        $clock = $this->get(route('clock.kiosk'))->getContent();
        $this->pair($this->tablet);
        $room = $this->get(route('room.kiosk'))->getContent();
        $door = $this->get(route('kiosk.index'))->getContent();

        $this->assertStringContainsString('idleSeconds: 7,', $clock);
        $this->assertStringContainsString('idleSeconds: 7,', $room);
        $this->assertStringContainsString('x-data="kiosk(90, 6, 7)"', $door);

        foreach (['clock' => $clock, 'room' => $room, 'door' => $door] as $name => $html) {
            $this->assertStringContainsString('armIdle()', $html, "$name never arms the idle clear");
            $this->assertStringContainsString("document.visibilityState === 'hidden'", $html, "$name keeps digits when hidden");
            $this->assertStringContainsString('idleSeconds * 1000', $html, "$name does not time the clear in seconds");
        }

        // The gear's two pads too: the administrator's PIN and the room code.
        $this->assertStringContainsString("this.adminPin = '';", $room);
        $this->assertStringContainsString("this.code = '';", $room);
        $this->assertStringContainsString('@click="pressAdmin(k)"', $room);
    }

    /* ================= K4 — the gear needs an administrator ================= */

    public function test_K4_the_gear_opens_on_an_administrators_pin(): void
    {
        $this->pair($this->tablet);
        $html = $this->get(route('room.kiosk'))->getContent();

        $this->assertStringContainsString("this.step = 'admin';", $html);
        $this->assertStringContainsString('An administrator’s PIN first.', $html);
        // @js() writes the address with escaped slashes.
        $this->assertStringContainsString(str_replace('/', '\/', route('room.kiosk.setup.authorize')), $html);
    }

    public function test_K4_a_teachers_pin_does_not_open_the_room_code_pad(): void
    {
        $this->teacher('Lana Ortiz', '333333');
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => '333333'])
            ->assertOk()
            ->assertJson(['status' => 'not_admin'])
            ->assertJsonMissing(['setup_token']);

        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])
            ->assertOk()->assertJson(['status' => 'needs_admin']);

        $this->assertSame('PreK', $this->tablet->fresh()->classroom);
    }

    public function test_K4_a_code_without_the_go_ahead_is_refused(): void
    {
        $this->pair($this->tablet);

        // No PIN at all, a made-up token, and a token from another tablet.
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105'])->assertJson(['status' => 'needs_admin']);
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => str_repeat('x', 40)])->assertJson(['status' => 'needs_admin']);

        $other = new StaffDevice(['name' => 'Toddler iPad', 'kind' => StaffDevice::KIND_ROOM, 'classroom' => 'Toddler']);
        $other->token_hash = '';
        $other->save();
        $this->pair($other);
        $token = $this->authorizeRoomSetup();
        $this->get(route('room.kiosk', ['token' => $this->tablet->issueToken()]));
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => $token])->assertJson(['status' => 'needs_admin']);

        $this->assertSame('PreK', $this->tablet->fresh()->classroom);
        $this->assertSame('Toddler', $other->fresh()->classroom);
    }

    public function test_K4_the_go_ahead_is_checked_again_and_spent_once(): void
    {
        $this->pair($this->tablet);

        // Used once.
        $token = $this->authorizeRoomSetup();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => $token])->assertJson(['status' => 'ok', 'room' => 'UPK-4']);
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104', 'setup_token' => $token])->assertJson(['status' => 'needs_admin']);

        // Out of date.
        $token = $this->authorizeRoomSetup();
        $this->travel(config('kiosk.setup_token_seconds') + 1)->seconds();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104', 'setup_token' => $token])->assertJson(['status' => 'needs_admin']);

        // No longer an administrator by the time the code arrives.
        $token = $this->authorizeRoomSetup();
        $this->setupAdmin()->forceFill(['role' => 'teacher'])->save();
        $this->postJson(route('room.kiosk.setup'), ['code' => '2104', 'setup_token' => $token])->assertJson(['status' => 'needs_admin']);

        $this->assertSame('UPK-4', $this->tablet->fresh()->classroom);
    }

    public function test_K4_the_sixth_room_code_in_ten_minutes_is_refused(): void
    {
        $this->pair($this->tablet);
        $token = $this->authorizeRoomSetup();

        for ($try = 1; $try <= 5; $try++) {
            $this->postJson(route('room.kiosk.setup'), ['code' => '0000', 'setup_token' => $token])
                ->assertOk()->assertJson(['status' => 'bad_code']);
        }

        // Even the right code, with a good go-ahead: the gear is resting.
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => $token])
            ->assertStatus(429)
            ->assertJson(['status' => 'too_many', 'minutes' => 10]);
        $this->assertSame('PreK', $this->tablet->fresh()->classroom);

        // The screen words it.
        $this->assertStringContainsString('Too many tries — wait 10 minutes', $this->get(route('room.kiosk'))->getContent());

        // Ten minutes on, it answers again.
        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->keyRoomCode('2105')->assertOk()->assertJson(['status' => 'ok', 'room' => 'UPK-4']);
    }

    public function test_K4_the_sixth_administrator_pin_in_ten_minutes_is_refused(): void
    {
        $this->pair($this->tablet);
        $this->setupAdmin();

        for ($try = 1; $try <= 5; $try++) {
            $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => '000101'])->assertOk()->assertJson(['status' => 'not_found']);
        }

        $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => $this->setupAdminPin])
            ->assertStatus(429)->assertJson(['status' => 'too_many']);
    }

    /** A wrong administrator PIN at the gear counts toward the same lockout as the clock. */
    public function test_K4_admin_pin_failures_lock_the_admin_at_the_clock_too(): void
    {
        $admin = $this->setupAdmin();
        $this->pair($this->tablet);

        // The clock finds the row by the index and then fails the hash — the
        // only kind of wrong try that can be pinned on one person.
        $realHash = $admin->kiosk_pin_hash;
        $admin->forceFill(['kiosk_pin_hash' => bcrypt('0000')])->save();

        for ($try = 1; $try <= 5; $try++) {
            $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => $this->setupAdminPin])->assertOk();
        }

        $this->assertTrue($admin->fresh()->kioskIsLocked());
        $admin->forceFill(['kiosk_pin_hash' => $realHash])->save();

        $this->pair($this->door);
        $this->postJson(route('clock.kiosk.identify'), ['pin' => $this->setupAdminPin])->assertJson(['status' => 'locked']);
    }

    public function test_K4_pairings_and_failed_tries_are_in_login_history(): void
    {
        $lana = $this->teacher('Lana Ortiz', '333333');
        $this->pair($this->tablet);

        $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => '333333'])->assertJson(['status' => 'not_admin']);
        $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => '000101'])->assertJson(['status' => 'not_found']);

        $token = $this->authorizeRoomSetup();
        $admin = $this->setupAdmin();
        $this->postJson(route('room.kiosk.setup'), ['code' => '0000', 'setup_token' => $token])->assertJson(['status' => 'bad_code']);
        $this->postJson(route('room.kiosk.setup'), ['code' => '2105', 'setup_token' => $token])->assertJson(['status' => 'ok']);

        $rows = LoginEvent::orderBy('id')->get(['user_id', 'outcome', 'method'])->toArray();

        $this->assertSame([
            ['user_id' => $lana->id, 'outcome' => LoginEvent::TABLET_PAIR_FAILED, 'method' => 'pin'],
            ['user_id' => null, 'outcome' => LoginEvent::TABLET_PAIR_FAILED, 'method' => 'pin'],
            ['user_id' => $admin->id, 'outcome' => LoginEvent::TABLET_PAIR_FAILED, 'method' => 'room_code'],
            ['user_id' => $admin->id, 'outcome' => LoginEvent::TABLET_PAIRED, 'method' => 'room_code'],
        ], $rows);

        // The presence feed keeps its grey line too.
        $this->assertDatabaseHas('kiosk_events', ['staff_device_id' => $this->tablet->id, 'text' => 'Room tablet set to UPK-4 (was PreK)']);

        // And the director sees them, failures counted.
        $this->actingAs($admin)->get(route('settings.history'))->assertOk()
            ->assertSee('Room tablet paired')
            ->assertSee('Room tablet pairing failed')
            ->assertSee('Room code');
        $this->assertSame(3, LoginEvent::failures()->count());
    }

    /** One room per code across the whole centre, not only across the rows posted. */
    public function test_K4_a_room_code_cannot_be_saved_to_two_rooms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $centre = ['centre_opens' => '07:15', 'centre_closes' => '18:00', 'earliest_start' => '07:30', 'early_dropoff_room' => 'PreK', 'school_age_leaves' => '08:00', 'school_age_returns' => '15:00', 'longest_shift_hours' => 9, 'max_week_hours' => 40];

        // In one save: two rows, one code.
        $rows = [
            ['room' => 'Infant', 'ratio' => 4, 'capacity' => 8, 'tablet_code' => '3101'],
            ['room' => 'Toddler', 'ratio' => 5, 'capacity' => 10, 'tablet_code' => '3101'],
        ];
        $this->actingAs($admin)->from(route('settings.rooms'))
            ->put(route('settings.rooms.update'), ['rooms' => $rows, 'centre' => $centre])
            ->assertSessionHasErrors('rooms.0.tablet_code');

        // Across saves: PreK holds 2104 already; a save that leaves PreK out
        // cannot hand 2104 to Toddler.
        $this->actingAs($admin)->from(route('settings.rooms'))
            ->put(route('settings.rooms.update'), ['rooms' => [['room' => 'Toddler', 'ratio' => 5, 'capacity' => 10, 'tablet_code' => '2104']], 'centre' => $centre])
            ->assertSessionHasErrors(['rooms.0.tablet_code' => 'That code is already PreK’s. Each room needs its own code.']);
        $this->assertNull(app(\App\Services\CentreSettings::class)->tabletCode('Toddler'));

        // Moving a code between two rooms in one save is fine.
        $this->actingAs($admin)->from(route('settings.rooms'))
            ->put(route('settings.rooms.update'), ['rooms' => [
                ['room' => 'PreK', 'ratio' => 8, 'capacity' => 16, 'tablet_code' => '2106'],
                ['room' => 'Toddler', 'ratio' => 5, 'capacity' => 10, 'tablet_code' => '2104'],
            ], 'centre' => $centre])
            ->assertSessionHasNoErrors();
        $this->assertSame('Toddler', app(\App\Services\CentreSettings::class)->roomForTabletCode('2104'));
    }

    /* ================= K5 — titles ================= */

    public function test_K5_the_titles_carry_the_centres_name(): void
    {
        config(['app.name' => 'Laravel']);

        $this->pair($this->tablet);
        $this->get(route('room.kiosk'))->assertSee('<title>Room tablet — Sunny Hill Daycare</title>', false)->assertDontSee('Laravel');

        $this->pair($this->door);
        $this->get(route('clock.kiosk'))->assertSee('<title>Staff time clock — Sunny Hill Daycare</title>', false)->assertDontSee('Laravel');

        $this->get(route('kiosk.index'))->assertSee('<title>Check in — Sunny Hill Daycare</title>', false);
    }

    /* ================= K6 — US spelling ================= */

    public function test_K6_the_kiosk_screens_are_in_us_spelling(): void
    {
        $this->pair($this->door);
        $pages['clock'] = $this->get(route('clock.kiosk'))->getContent();
        $this->pair($this->tablet);
        $pages['room'] = $this->get(route('room.kiosk'))->getContent();
        $pages['door'] = $this->get(route('kiosk.index'))->getContent();

        foreach ($pages as $name => $html) {
            // colour survives only as a property name the code reads.
            $text = preg_replace('/(\$\{|\.|get )colour\b/', '', $html);

            $this->assertDoesNotMatchRegularExpression('/recognis|forgotten|\bcentre\b|\bcolour\b/i', $text, "$name page");
        }

        $this->assertStringContainsString('Forgot your PIN? Please see a staff member.', $pages['door']);
        $this->assertStringContainsString('We did not recognize that PIN.', $pages['door']);
        $this->assertStringContainsString("title: 'Not recognized'", $pages['clock']);
        $this->assertStringContainsString("title: 'Not recognized'", $pages['room']);

        // The sidebar's Kiosk page frames the tablet; its one line of its own.
        $this->actingAs($this->setupAdmin())->get(route('staff.kiosk'))->assertOk()
            ->assertSee('tap ⚙, key an administrator’s PIN and then a room code', false);
    }

    /* ================= throttles and lockouts ================= */

    /** /room and /clock share one lockout: wrong tries at the tablet lock the door. */
    public function test_failures_at_the_room_tablet_lock_the_same_person_at_the_clock(): void
    {
        $lana = $this->teacher('Lana Ortiz', '333333');
        $lana->forceFill(['kiosk_pin_hash' => bcrypt('0000')])->save();
        $this->pair($this->tablet);

        for ($try = 1; $try <= User::KIOSK_MAX_ATTEMPTS; $try++) {
            $this->postJson(route('room.kiosk.identify'), ['pin' => '333333'])->assertJson(['status' => 'not_found']);
        }

        $this->assertTrue($lana->fresh()->kioskIsLocked());
        $lana->forceFill(['kiosk_pin_hash' => bcrypt('333333')])->save();

        $this->pair($this->door);
        $this->postJson(route('clock.kiosk.identify'), ['pin' => '333333'])->assertJson(['status' => 'locked']);
        $this->pair($this->tablet);
        $this->postJson(route('room.kiosk.identify'), ['pin' => '333333'])->assertJson(['status' => 'locked']);
    }

    /**
     * The door's lockout: five tries that find a parent's row and fail its
     * hash lock that parent, and the lock holds against the right PIN.
     */
    public function test_the_door_locks_a_pin_after_five_wrong_tries(): void
    {
        $grace = $this->guardian('Grace', '246810', '5581');
        $realHash = $grace->pin_hash;
        $grace->forceFill(['pin_hash' => bcrypt('000000')])->save();

        for ($try = 1; $try <= 5; $try++) {
            $this->postJson(route('kiosk.unlock'), ['pin' => '246810'])->assertJson(['status' => 'not_found']);
        }

        $this->assertTrue($grace->fresh()->isLocked());
        $grace->forceFill(['pin_hash' => $realHash])->save();
        $this->postJson(route('kiosk.unlock'), ['pin' => '246810'])->assertJson(['status' => 'locked']);
    }

    /* ================= K2 — across staff and parents ================= */

    public function test_K2_a_staff_pin_cannot_be_a_parents_and_the_other_way_round(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->guardian('Amira', '481902');
        $lana = User::factory()->create(['role' => 'teacher', 'name' => 'Lana Ortiz']);

        $this->actingAs($admin)->from(route('teachers.show', $lana))
            ->post(route('staff.card.pin', $lana), ['kiosk_pin' => '481902', 'kiosk_pin_confirmation' => '481902'])
            ->assertSessionHasErrors(['kiosk_pin' => 'Somebody else already uses that PIN. Pick different digits.']);
        $this->assertFalse($lana->fresh()->hasKioskPin());

        $this->teacher('Sultana Begum', '444444');
        $this->assertTrue(\App\Rules\UniqueKioskPin::taken('444444'));
        $this->assertFalse(\App\Rules\UniqueKioskPin::taken('444444', User::where('name', 'Sultana Begum')->first()));

        $this->expectException(\DomainException::class);
        $this->guardian('Marcus', '444444');
    }

    public function test_K2_two_parents_cannot_share_a_pin(): void
    {
        $grace = $this->guardian('Grace', '246810');

        try {
            $this->guardian('Marcus', '246810');
            $this->fail('A second parent was given a PIN somebody holds.');
        } catch (\DomainException) {
        }

        // A parent keeping their own PIN is not a clash.
        $grace->setPin('246810');
        $this->assertTrue($grace->fresh()->hasPin());

        // And the database holds it even past the model.
        $this->expectException(UniqueConstraintViolationException::class);
        Person::create(['name' => 'Ada'])->forceFill(['pin_index' => Person::indexFor('246810'), 'pin_hash' => bcrypt('246810')])->save();
    }

    public function test_K2_the_report_lists_pins_shared_across_staff_and_parents_and_wrong_lengths(): void
    {
        // A collision across the two tables can only predate the rule.
        $lana = $this->teacher('Lana Ortiz', '481902');
        $amira = Person::create(['name' => 'Amira Haddad']);
        $amira->forceFill(['pin_index' => Person::indexFor('481902'), 'pin_hash' => bcrypt('481902'), 'pin_length' => 6])->save();

        // A staff PIN set before lengths were recorded counts as four.
        $old = $this->teacher('Evana Roy', '555555');
        $old->forceFill(['kiosk_pin_length' => null])->save();

        Artisan::call('kiosk:duplicate-pins');
        $out = Artisan::output();

        $this->assertStringContainsString('1 PIN shared by more than one person', $out);
        $this->assertStringContainsString('Lana Ortiz', $out);
        $this->assertStringContainsString('Amira Haddad', $out);
        $this->assertStringContainsString('Parent', $out);
        $this->assertStringContainsString('1 PIN cannot be keyed: the kiosks take 6 digits.', $out);
        $this->assertStringContainsString('Evana Roy', $out);
        $this->assertStringNotContainsString('481902', $out, 'the report printed a PIN');
        $this->assertStringNotContainsString('555555', $out, 'the report printed a PIN');
        $this->assertStringNotContainsString(Person::indexFor('481902'), $out, 'the report printed the index');
    }

    /* ================= K4 — room codes ================= */

    public function test_K4_room_codes_have_a_unique_index(): void
    {
        $this->assertTrue(collect(Schema::getIndexes('room_tablet_codes'))->contains(fn ($i) => $i['columns'] === ['code'] && $i['unique']));
        $this->assertTrue(collect(Schema::getIndexes('room_tablet_codes'))->contains(fn ($i) => $i['columns'] === ['room'] && $i['unique']));

        $this->expectException(UniqueConstraintViolationException::class);
        \App\Models\RoomTabletCode::create(['room' => 'Toddler', 'code' => '2104']);
    }

    public function test_K4_the_migration_moves_the_codes_out_of_settings(): void
    {
        $migration = require database_path('migrations/2026_11_16_000003_create_room_tablet_codes_table.php');
        $migration->down();

        $this->assertDatabaseHas('settings', ['key' => 'rooms.PreK.tablet_code', 'value' => '2104']);
        $this->assertFalse(Schema::hasTable('room_tablet_codes'));

        // Two rooms on one code: it stops and changes nothing.
        Setting::put('rooms.Toddler.tablet_code', '2104');
        try {
            $migration->up();
            $this->fail('The migration ran over two rooms sharing a code.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PreK and Toddler', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('room_tablet_codes'));

        Setting::put('rooms.Toddler.tablet_code', null);
        $migration->up();
        Setting::forget();

        $this->assertSame('PreK', app(\App\Services\CentreSettings::class)->roomForTabletCode('2104'));
        $this->assertSame('2105', app(\App\Services\CentreSettings::class)->tabletCode('UPK-4'));
        $this->assertDatabaseMissing('settings', ['key' => 'rooms.PreK.tablet_code']);
    }

    /** And the route limiter in front of every PIN: thirty a minute per address and screen. */
    public function test_the_pin_endpoints_are_rate_limited(): void
    {
        $this->pair($this->tablet);

        for ($try = 1; $try <= 30; $try++) {
            $this->postJson(route('room.kiosk.identify'), ['pin' => '000101'])->assertOk();
        }

        $this->postJson(route('room.kiosk.identify'), ['pin' => '000101'])->assertStatus(429);
    }

    /* ================= nothing before a PIN ================= */

    public function test_no_kiosk_page_lists_children_or_staff_before_a_pin(): void
    {
        $this->teacher('Lana Ortiz', '333333');
        $noor = Child::create(['lan' => '4001', 'first_name' => 'Noor', 'last_name' => 'Haddad', 'classroom' => 'PreK', 'status' => 'Active']);
        $this->clear($this->guardian('Amira', '481902'), $noor);

        // Lana in PreK, Noor signed in: both would be on a page that leaked.
        $this->pair($this->door);
        $ticket = $this->postJson(route('clock.kiosk.identify'), ['pin' => '333333'])->json('ticket');
        $this->postJson(route('clock.kiosk.punch'), ['ticket' => $ticket, 'action' => 'clock_in', 'room' => 'PreK'])->assertJson(['status' => 'ok']);
        $this->flushSession();

        $this->pair($this->door);
        $clock = $this->get(route('clock.kiosk'))->getContent();
        $this->pair($this->tablet);
        $room = $this->get(route('room.kiosk'))->getContent();
        $this->flushSession();
        $door = $this->get(route('kiosk.index'))->getContent();

        foreach (['clock' => $clock, 'room' => $room, 'door' => $door] as $name => $html) {
            foreach (['Lana', 'Ortiz', 'Noor', 'Haddad', 'Amira'] as $who) {
                $this->assertStringNotContainsString($who, $html, "$name shows $who before any PIN");
            }
        }

        // A wrong PIN answers with a status and nothing else.
        $this->assertSame(['status' => 'not_found'], $this->postJson(route('kiosk.unlock'), ['pin' => '000000'])->json());
        $this->pair($this->tablet);
        $this->assertSame(['status' => 'not_found'], $this->postJson(route('room.kiosk.identify'), ['pin' => '000101'])->json());
        // And a punch with no open family screen.
        $this->assertSame(['status' => 'expired'], $this->postJson(route('kiosk.punch'), ['child_id' => $noor->id, 'direction' => 'in'])->json());
    }

    /** An open family screen cannot read the name of a child who is not theirs. */
    public function test_the_door_does_not_name_a_child_who_is_not_the_familys(): void
    {
        $noor = Child::create(['lan' => '4001', 'first_name' => 'Noor', 'last_name' => 'Haddad', 'classroom' => 'PreK', 'status' => 'Active']);
        $stranger = Child::create(['lan' => '4002', 'first_name' => 'Zeynep', 'last_name' => 'Kaya', 'classroom' => 'PreK', 'status' => 'Active']);
        $this->clear($this->guardian('Amira', '481902'), $noor);

        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertJson(['status' => 'ok']);

        $response = $this->postJson(route('kiosk.punch'), ['child_id' => $stranger->id, 'direction' => 'in'])
            ->assertJson(['status' => 'not_authorised', 'child' => null]);

        $this->assertStringNotContainsString('Zeynep', $response->getContent());
    }

    /* ---------------- helpers ---------------- */

    private function teacher(string $name, string $pin): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => 'PreK']);
        $user->setKioskPin($pin);

        return $user;
    }

    private function guardian(string $name, string $pin, string $last4 = '4417'): Person
    {
        $person = Person::create(['name' => $name, 'phone_last4' => $last4]);
        $person->setPin($pin);
        $person->forceFill(['phone_last4' => $last4])->save();

        return $person;
    }

    private function clear(Person $person, Child $child): ChildPerson
    {
        return ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'can_pickup' => true]);
    }

    /** Two staff on one PIN, as a centre may have from before the unique index. */
    private function sharedPin(): array
    {
        $grace = User::factory()->create(['role' => 'teacher', 'name' => 'Grace Hopper', 'email' => 'grace@example.test']);
        $marcus = User::factory()->create(['role' => 'teacher', 'name' => 'Marcus Brown', 'email' => 'marcus@example.test']);

        foreach ([$grace, $marcus] as $user) {
            $user->forceFill(['kiosk_pin_index' => User::kioskIndexFor('402121'), 'kiosk_pin_hash' => bcrypt('402121')])->save();
        }

        return [$grace, $marcus];
    }

    private function pair(StaffDevice $device): void
    {
        $this->flushSession();
        $this->get(route($device->kioskRoute(), ['token' => $device->issueToken()]))->assertRedirect();
    }
}
