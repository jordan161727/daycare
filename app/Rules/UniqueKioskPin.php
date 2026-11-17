<?php

namespace App\Rules;

use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One PIN, one person — across staff and parents (QA K2, 16 Nov 2026).
 *
 * Staff PINs live on users (kiosk_pin_index), parent PINs on people
 * (pin_index). Both indexes are the same HMAC of the PIN keyed with APP_KEY,
 * so "is this PIN anybody's" is one indexed lookup per table and the PIN is
 * never stored or compared in plain text. Each table has a unique index as
 * the backstop; a database index cannot span two tables, so this rule is
 * what keeps a staff PIN from also being a parent's.
 *
 * Pass the record being changed to ignore it — setting your own PIN to the
 * PIN you already have is not a clash.
 */
class UniqueKioskPin implements ValidationRule
{
    public function __construct(private User|Person|null $ignore = null) {}

    public static function taken(string $pin, User|Person|null $ignore = null): bool
    {
        $index = User::kioskIndexFor($pin);

        $staff = User::where('kiosk_pin_index', $index)
            ->when($ignore instanceof User && $ignore->exists, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($staff) {
            return true;
        }

        return Person::where('pin_index', $index)
            ->when($ignore instanceof Person && $ignore->exists, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->exists();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value) && static::taken((string) $value, $this->ignore)) {
            $fail('Somebody else already uses that PIN. Pick different digits.');
        }
    }
}
