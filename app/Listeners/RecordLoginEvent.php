<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Write down every sign-in, every sign-out and every failure.
 *
 * Hung off the framework's own auth events rather than off the login
 * controller, so it cannot be bypassed by a second way in. The kiosk and the
 * door screen do not raise these — nobody logs in at those, they present a
 * card or a PIN, and those attempts are already recorded as punches and
 * lockouts.
 *
 * Every write is guarded. A login must not fail because the log could not be
 * written: locking somebody out of their own app to protect an audit trail is
 * the wrong way round, and a database mid-migration has no table to write to.
 */
class RecordLoginEvent
{
    public function handleLogin(Login $event): void
    {
        $this->write(LoginEvent::SUCCESS, $event->user->getAuthIdentifier(), $event->user->email ?? null);
    }

    public function handleLogout(Logout $event): void
    {
        // Null on a session that expired rather than a press of Log out.
        if ($event->user === null) {
            return;
        }

        $this->write(LoginEvent::LOGOUT, $event->user->getAuthIdentifier(), $event->user->email ?? null);
    }

    /**
     * A failure, with the address as it was typed.
     *
     * The address matters more here than the user id, which is usually null:
     * "somebody is working through addresses" is a pattern you can only see if
     * the ones that matched no account are written down too.
     */
    public function handleFailed(Failed $event): void
    {
        $this->write(
            LoginEvent::FAILED,
            $event->user?->getAuthIdentifier(),
            $event->credentials['email'] ?? null,
        );
    }

    private function write(string $outcome, mixed $userId, ?string $email): void
    {
        rescue(fn () => LoginEvent::create([
            'user_id' => is_int($userId) ? $userId : null,
            'email' => $email,
            'outcome' => $outcome,
            // Said rather than left null: the kiosk writes to this table too,
            // and a blank column would read as "unknown" rather than "the
            // sign-in form".
            'method' => 'password',
            'ip_address' => request()->ip(),
            // Truncated to the column: a browser string is not worth failing a
            // login over, and the useful part of one is at the front.
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
        ]), null, false);
    }
}
