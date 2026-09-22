<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\StaffKiosk;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who signed in, and who tried and failed.
 *
 * The failures are the reason this exists. A successful login is a line in a
 * list; six failures against one address overnight is the thing a director
 * needs to be able to see, and nothing in the app recorded it before.
 */
class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
    }

    public function test_a_successful_sign_in_is_recorded(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => bcrypt('correct-horse')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse']);

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'outcome' => LoginEvent::SUCCESS,
        ]);
    }

    public function test_a_failed_attempt_is_recorded_with_the_address_as_typed(): void
    {
        // "Somebody is working through addresses" is a pattern you can only see
        // if the attempts that matched no account are written down too.
        $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'guessing',
        ]);

        $this->assertDatabaseHas('login_events', [
            'email' => 'nobody@example.com',
            'outcome' => LoginEvent::FAILED,
            'user_id' => null,
        ]);
    }

    public function test_no_password_is_ever_written_down(): void
    {
        $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'hunter2-should-never-appear',
        ]);

        foreach (LoginEvent::all() as $event) {
            $this->assertStringNotContainsString('hunter2-should-never-appear', json_encode($event->toArray()));
        }
    }

    public function test_signing_out_is_recorded(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->post(route('logout'));

        $this->assertDatabaseHas('login_events', [
            'user_id' => $user->id,
            'outcome' => LoginEvent::LOGOUT,
        ]);
    }

    public function test_the_page_counts_the_failures_of_the_last_day(): void
    {
        // Stated as a number rather than left to be counted down the page: it
        // is the thing somebody opens this screen to find.
        $admin = User::factory()->create(['role' => 'admin']);

        LoginEvent::create(['email' => 'a@example.com', 'outcome' => LoginEvent::FAILED]);
        LoginEvent::create(['email' => 'b@example.com', 'outcome' => LoginEvent::FAILED]);

        // Last week's failure is not part of "the last 24 hours".
        LoginEvent::create(['email' => 'c@example.com', 'outcome' => LoginEvent::FAILED])
            ->forceFill(['created_at' => now()->subWeek()])->save();

        $this->actingAs($admin)
            ->get(route('settings.history'))
            ->assertOk()
            ->assertSee('2 failed attempts in the last 24 hours');
    }

    public function test_a_card_or_pin_at_the_kiosk_is_recorded_too(): void
    {
        // The thing a director reaches for this page expecting to find. A
        // kiosk press is not a login, but it is somebody naming themselves,
        // and it is labelled apart rather than merged with one.
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'David Nguyen']);

        $pin = '4821';
        $teacher->forceFill([
            'kiosk_pin_index' => User::kioskIndexFor($pin),
            'kiosk_pin_hash' => Hash::make($pin),
        ])->save();

        app(StaffKiosk::class)->identify(null, $pin);

        $this->assertDatabaseHas('login_events', [
            'user_id' => $teacher->id,
            'outcome' => LoginEvent::KIOSK,
            'method' => 'pin',
        ]);
    }

    public function test_a_pin_nobody_holds_is_recorded_as_a_kiosk_failure(): void
    {
        app(StaffKiosk::class)->identify(null, '0000');

        $this->assertDatabaseHas('login_events', [
            'outcome' => LoginEvent::KIOSK_FAILED,
            'method' => 'pin',
            'user_id' => null,
        ]);
    }

    public function test_no_pin_or_card_is_ever_written_down(): void
    {
        // A log that recorded the secret would be a list of every PIN in the
        // centre, which is worse than having no log.
        app(StaffKiosk::class)->identify('CARD-SHOULD-NEVER-APPEAR-ANYWHERE', null);
        app(StaffKiosk::class)->identify(null, '7391');

        foreach (LoginEvent::all() as $event) {
            $row = json_encode($event->toArray());
            $this->assertStringNotContainsString('CARD-SHOULD-NEVER-APPEAR-ANYWHERE', $row);
            $this->assertStringNotContainsString('7391', $row);
        }
    }

    public function test_the_failure_count_covers_both_the_form_and_the_kiosk(): void
    {
        // Somebody reading this page is asking "did anything go wrong", not
        // "did anything go wrong at one particular door".
        $admin = User::factory()->create(['role' => 'admin']);

        LoginEvent::create(['email' => 'a@example.com', 'outcome' => LoginEvent::FAILED, 'method' => 'password']);
        LoginEvent::create(['outcome' => LoginEvent::KIOSK_FAILED, 'method' => 'pin']);

        $this->actingAs($admin)
            ->get(route('settings.history'))
            ->assertOk()
            ->assertSee('2 failed attempts in the last 24 hours');
    }

    public function test_the_page_says_which_door_each_attempt_came_through(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);

        LoginEvent::create(['user_id' => $admin->id, 'outcome' => LoginEvent::SUCCESS, 'method' => 'password']);
        LoginEvent::create(['user_id' => $admin->id, 'outcome' => LoginEvent::KIOSK, 'method' => 'card']);

        $html = $this->actingAs($admin)->get(route('settings.history'))->assertOk()->getContent();

        $this->assertStringContainsString('>Password<', $html);
        $this->assertStringContainsString('>Card<', $html);
        $this->assertStringContainsString('Recognised at kiosk', $html);
    }

    public function test_a_teacher_cannot_read_the_login_history(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get(route('settings.history'))->assertForbidden();
    }
}
