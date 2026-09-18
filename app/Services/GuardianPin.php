<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Support\Facades\Hash;

/**
 * Turning six digits typed at a door into a guardian, or into a refusal.
 *
 * Four answers, and the difference between them is the whole of the design:
 *
 *   ok         one person, proven
 *   ambiguous  two families chose the same PIN — ask for the last four of a
 *              phone number rather than guessing which one is standing there
 *   not_found  no match, and the attempt is counted
 *   locked     too many wrong tries; the kiosk stops answering for a while
 *
 * "Not found" and "locked" are told apart to the caller but say almost the same
 * thing on screen. Somebody at the door should not be able to learn from the
 * wording whether a PIN exists.
 */
class GuardianPin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 5;

    /**
     * @return array{status: string, person?: Person}
     */
    public function resolve(string $pin, ?string $last4 = null): array
    {
        // The index narrows; the hash decides. Looking a PIN up by its keyed
        // hash is what keeps this one query instead of a bcrypt check against
        // every guardian in the centre.
        $candidates = Person::where('pin_index', Person::indexFor($pin))->get();

        // A locked row is out of the running before anything else happens, so a
        // lockout cannot be walked around by a second guardian sharing the PIN.
        if ($candidates->isNotEmpty() && $candidates->every(fn (Person $p) => $p->isLocked())) {
            return ['status' => 'locked'];
        }

        $matches = $candidates
            ->reject(fn (Person $person) => $person->isLocked())
            ->filter(fn (Person $person) => Hash::check($pin, $person->pin_hash));

        if ($matches->isEmpty()) {
            $this->countFailure($candidates);

            return ['status' => 'not_found'];
        }

        if ($matches->count() > 1) {
            if ($last4 === null) {
                return ['status' => 'ambiguous'];
            }

            $matches = $matches->filter(fn (Person $person) => $person->phone_last4 === $last4);

            if ($matches->count() !== 1) {
                $this->countFailure($candidates);

                return ['status' => 'not_found'];
            }
        }

        $person = $matches->first();
        $person->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

        return ['status' => 'ok', 'person' => $person];
    }

    /**
     * Count a wrong try against the rows the PIN pointed at.
     *
     * A PIN matching nobody has nothing to count against, which is correct: the
     * throttle on guessing at random is the rate limiter on the route, not a
     * counter on a row that does not exist.
     */
    private function countFailure($candidates): void
    {
        foreach ($candidates as $person) {
            $attempts = $person->failed_attempts + 1;

            $person->forceFill([
                'failed_attempts' => $attempts,
                'locked_until' => $attempts >= self::MAX_ATTEMPTS
                    ? now()->addMinutes(self::LOCKOUT_MINUTES)
                    : $person->locked_until,
            ])->save();
        }
    }
}
