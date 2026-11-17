<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The room tablet's gear, the way a director now uses it (QA K4, 16 Nov
 * 2026): an administrator's PIN first, then the room code with the go-ahead
 * the PIN earned. Tests that only care which room the tablet ends up in key
 * the code through here rather than repeating the two posts.
 *
 * The administrator is the fixture's own if it has one — given a PIN if it
 * has none — so no extra member of staff turns up on boards and lists.
 */
trait SetsUpRoomTablets
{
    /** A PIN no fixture uses for anybody else. */
    protected string $setupAdminPin = '864213';

    protected function setupAdmin(): User
    {
        $admin = User::where('role', 'admin')->orderBy('id')->first()
            ?? User::factory()->create(['role' => 'admin', 'name' => 'Setup Director']);

        if (! User::kioskPinTaken($this->setupAdminPin, $admin) && ! \Illuminate\Support\Facades\Hash::check($this->setupAdminPin, (string) $admin->kiosk_pin_hash)) {
            $admin->setKioskPin($this->setupAdminPin);
        }

        return $admin;
    }

    /** Step one: the administrator's PIN at the gear. Returns the go-ahead. */
    protected function authorizeRoomSetup(?string $pin = null): ?string
    {
        if ($pin === null) {
            $this->setupAdmin();
        }

        return $this->postJson(route('room.kiosk.setup.authorize'), ['admin_pin' => $pin ?? $this->setupAdminPin])
            ->assertOk()
            ->json('setup_token');
    }

    /** Both steps: an administrator's PIN, then the room code. */
    protected function keyRoomCode(string $code): TestResponse
    {
        $token = $this->authorizeRoomSetup();

        return $this->postJson(route('room.kiosk.setup'), ['code' => $code, 'setup_token' => $token]);
    }
}
