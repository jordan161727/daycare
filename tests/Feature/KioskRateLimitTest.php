<?php

namespace Tests\Feature;

use App\Models\StaffDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The signed-out screens share a building and an address, not a budget.
 *
 * All of it used to sit in one thirty-a-minute bucket keyed on the IP, counting
 * page loads and button presses alongside the PINs. Every tablet in a centre
 * leaves by one address, so a shift change spent the budget and the screens
 * started refusing everybody — which looks like a broken tablet, not a limit.
 */
class KioskRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('kiosk');
        RateLimiter::clear('kiosk-secret');
    }

    public function test_a_busy_door_does_not_stop_staff_clocking_in(): void
    {
        // A morning's worth of families at the door.
        for ($i = 0; $i < 40; $i++) {
            $this->post('/kiosk/unlock', ['pin' => '000000']);
        }

        // The clock beside it is untouched by that.
        $this->get('/clock')->assertStatus(200);
    }

    public function test_a_shift_change_does_not_exhaust_the_screens(): void
    {
        $device = new StaffDevice(['name' => 'Front desk']);
        $device->token_hash = '';
        $device->save();
        $token = $device->issueToken();

        $this->get('/clock?token='.$token);

        // Twelve staff, each loading the screen and pressing a button, plus the
        // door kiosk running beside them.
        for ($i = 0; $i < 12; $i++) {
            $this->get('/clock');
            $this->post('/clock/punch', ['ticket' => 'stale', 'action' => 'in']);
            $this->get('/kiosk');
        }

        $this->get('/clock')->assertStatus(200);
        $this->get('/kiosk')->assertStatus(200);
    }

    public function test_guessing_a_pin_is_still_capped(): void
    {
        $last = null;

        for ($i = 0; $i < 40; $i++) {
            $last = $this->postJson('/clock/identify', ['pin' => str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        }

        $last->assertStatus(429);
    }

    public function test_the_two_pin_screens_have_separate_budgets(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->postJson('/clock/identify', ['pin' => '0000']);
        }

        // The door still answers, because walking staff PINs is not walking
        // guardian PINs and the two are counted apart.
        $this->post('/kiosk/unlock', ['pin' => '000000'])->assertStatus(200);
    }
}
