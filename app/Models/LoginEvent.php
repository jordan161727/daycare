<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to identify yourself, however it was made.
 *
 * Two different acts share this table, and they are labelled apart rather than
 * merged: signing in at the browser, which opens the app, and presenting a
 * card or PIN at the kiosk, which does not. Somebody at the kiosk is not
 * logged in to anything — they are naming themselves to a clock — but from the
 * director's side both answer the same question, which is who identified
 * themselves as whom, from where, and when it failed.
 *
 * What is never recorded is the secret: no password, no PIN, no card code, in
 * any form, successful or not.
 *
 * Written once and never edited — like a punch, and for the same reason: the
 * value of this record is that it can be shown to somebody who was not there,
 * and a row somebody can change is not evidence of anything.
 */
class LoginEvent extends Model
{
    /* ---- the browser sign-in form ---- */

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const LOGOUT = 'logout';

    /* ---- the kiosk ---- */

    /** A card or PIN the clock recognised. Not a login. */
    public const KIOSK = 'kiosk';

    /** A card or PIN it did not, or one belonging to a locked-out account. */
    public const KIOSK_FAILED = 'kiosk_failed';

    /* ---- done to the account by somebody else ---- */

    /**
     * A director emailed this account a new temporary password. Not an
     * attempt to sign in, but it belongs beside them: it is why the next one
     * comes from a password the holder did not choose. by_user_id says who.
     */
    public const TEMP_PASSWORD = 'temp_password';

    /** The holder chose a new password from a Forgot password? email link. */
    public const PASSWORD_RESET = 'password_reset';

    /** A director typed a new password on the staff profile. by_user_id says who. */
    public const PASSWORD_SET = 'password_set';

    /** Only created_at. There is no such thing as updating one of these. */
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'by_user_id', 'email', 'outcome', 'method', 'ip_address', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who did it, on a row the account holder did not. */
    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    /** Everything that did not work, of either kind. */
    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereIn('outcome', [self::FAILED, self::KIOSK_FAILED]);
    }

    public function failed(): bool
    {
        return in_array($this->outcome, [self::FAILED, self::KIOSK_FAILED], true);
    }

    /** What each outcome is called on screen. */
    public function label(): string
    {
        return match ($this->outcome) {
            self::SUCCESS => 'Signed in',
            self::FAILED => 'Sign-in failed',
            self::LOGOUT => 'Signed out',
            self::KIOSK => 'Recognised at kiosk',
            self::KIOSK_FAILED => 'Not recognised at kiosk',
            self::TEMP_PASSWORD => 'Temporary password sent',
            self::PASSWORD_RESET => 'Password reset',
            self::PASSWORD_SET => 'Password set',
            default => ucfirst((string) $this->outcome),
        };
    }

    /**
     * Where the attempt came from, in the words the director uses.
     *
     * "Password" rather than "web": what distinguishes these rows is the thing
     * presented, which is also what decides how worried a run of failures
     * should make somebody.
     */
    public function source(): string
    {
        return match ($this->method) {
            'card' => 'Card',
            'pin' => 'PIN',
            'email' => 'Emailed by '.($this->by?->name ?? 'a director'),
            'link' => 'Email link',
            'director' => 'Set by '.($this->by?->name ?? 'a director'),
            default => 'Password',
        };
    }

    /** Whether this row came from the kiosk rather than the sign-in form. */
    public function fromKiosk(): bool
    {
        return in_array($this->outcome, [self::KIOSK, self::KIOSK_FAILED], true);
    }
}
