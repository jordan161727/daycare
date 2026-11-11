<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * An adult connected to one or more children.
 *
 * One record per person, however many children they belong to. What they are
 * for a particular child — mother, allowed to collect, second on the emergency
 * list — is not here; it is on the link. See ChildPerson.
 *
 * Editing anything on this record changes it for every child the person is
 * linked to, which is the point of the table and also the thing the screens
 * have to say out loud before saving.
 */
class Person extends Model implements AuthenticatableContract
{
    // Signed in to the parent app, a Person is the request's user, and the
    // framework asks any user for its id the standard way (logs, limiters).
    // There is no password column: the app signs in by texted code.
    use Authenticatable;

    use HasFactory;

    // The parent app signs a person in with a token (see ParentPortal); the
    // API never sends this model as it is — it carries an SSN.
    use HasApiTokens;

    protected $fillable = [
        'name', 'address',
        'home_phone', 'work_phone', 'cell', 'alternate_phone', 'fax',
        'email', 'employer', 'title', 'ssn', 'drivers_license',
        'photo_path', 'notes', 'texts_stopped_at',
    ];

    protected $casts = [
        // Same treatment the child's own copies get, so moving them across
        // loses nothing.
        'ssn' => 'encrypted',
        'locked_until' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    /** The door's half of the record is never handed to a view, nor is the SSN. */
    protected $hidden = ['pin_index', 'pin_hash', 'ssn'];

    protected static function booted(): void
    {
        // Every SSN written, added or cleared, is a line in the activity log.
        static::saved(function (self $person) {
            if ($person->wasChanged('ssn') || ($person->wasRecentlyCreated && filled($person->ssn))) {
                \App\Services\SensitiveAccess::record(
                    \App\Services\SensitiveAccess::UPDATED,
                    (blank($person->ssn) ? 'cleared for ' : 'set for ').$person->name,
                    $person,
                    $person->name,
                );
            }
        });
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'child_people')
            ->using(ChildPerson::class)
            ->withPivot(['relationship', 'is_guardian', 'can_pickup', 'is_emergency', 'priority', 'restriction', 'source'])
            ->withTimestamps();
    }

    public function links(): HasMany
    {
        return $this->hasMany(ChildPerson::class);
    }

    /**
     * Digits only, which is how two telephone numbers are compared.
     *
     * "(585) 820-5029" and "585-820-5029" are the same number written by two
     * people, and matching on the text finds neither from the other.
     */
    public static function digits(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // +1 (716) 555-0134 is (716) 555-0134: the country code is not part
        // of what tells two US numbers apart.
        return strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
    }

    /**
     * A US number as stored (QA #28): +1 and ten digits, or null when it is
     * not one — ten digits, area code and exchange not starting 0 or 1.
     */
    public static function e164(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? '+1'.$digits : null;
    }

    /** A number as people read it: (716) 555-0134. Anything else as it was written. */
    public static function displayPhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return $phone;
        }
        $digits = self::digits($phone);

        return strlen($digits) === 10 ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6)) : $phone;
    }

    /**
     * A name reduced to what two spellings of it have in common: case, spacing
     * and punctuation removed. "O'Brien" and "OBrien" meet here.
     */
    public static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z]/', '', Str::lower((string) $name)) ?? '';
    }

    /** The last four of the SSN, which is all any screen shows. */
    public function ssnLast4(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->ssn);

        return strlen((string) $digits) >= 4 ? substr($digits, -4) : null;
    }

    /** Whether this person can be authenticated at the door at all. */
    public function hasPin(): bool
    {
        return filled($this->pin_hash);
    }

    /* ---- the door ----

       Moved here from the guardians table along with the columns. A PIN is a
       fact about an adult, and there is one record of an adult now. */

    /**
     * What a PIN is looked up by.
     *
     * Keyed on the app key, so the table on its own is not a list of six-digit
     * numbers waiting to be tried offline. Deliberately not unique: two
     * families choosing 246810 is a collision to resolve at the door, not an
     * error to refuse at the form.
     */
    public static function indexFor(string $pin): string
    {
        return hash_hmac('sha256', $pin, config('app.key'));
    }

    /** Set a PIN, writing both the lookup index and the hash that proves it. */
    public function setPin(string $pin): void
    {
        $this->forceFill([
            'pin_index' => static::indexFor($pin),
            'pin_hash' => Hash::make($pin),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Whether this person may take this child home, right now.
     *
     * The tick and the absence of a restriction, together — the same rule the
     * child's page and the export use. This is the one that matters: it is
     * asked at the door, with the child standing there.
     *
     * The old guardians table had no restriction to check, so a court order
     * typed into the office could not reach the kiosk. It can now.
     */
    public function mayCollect(Child $child): bool
    {
        return ChildPerson::where('person_id', $this->id)
            ->where('child_id', $child->id)
            ->allowedToCollect()
            ->exists();
    }

    /** The children this person may collect, for the kiosk's family screen. */
    public function collectable(): BelongsToMany
    {
        return $this->children()
            ->wherePivot('can_pickup', true)
            ->wherePivotNull('restriction');
    }
}
