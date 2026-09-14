<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;

/**
 * Somebody who may bring a child in or take one home.
 *
 * Not a user: no login, no session, no screen but the one at the door. They are
 * known to the kiosk by a PIN and to the centre by a name, and that is the whole
 * of it.
 */
class Guardian extends Model
{
    protected $fillable = ['name', 'relationship', 'phone', 'pin_index', 'pin_hash', 'phone_last4'];

    protected $hidden = ['pin_index', 'pin_hash'];

    protected $casts = [
        'locked_until' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class)->withPivot('can_collect')->withTimestamps();
    }

    /** The children this guardian may actually take home. */
    public function collectable(): BelongsToMany
    {
        return $this->children()->wherePivot('can_collect', true);
    }

    public function punches()
    {
        return $this->hasMany(ChildAttendancePunch::class);
    }

    /**
     * What the kiosk looks a PIN up by.
     *
     * Keyed on the app key, so the table on its own is not a list of six-digit
     * numbers waiting to be tried offline. Deliberately not unique: two families
     * choosing 246810 is a collision to resolve at the door, not an error to
     * refuse at the form.
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

    public function mayCollect(Child $child): bool
    {
        return $this->children()
            ->wherePivot('can_collect', true)
            ->whereKey($child->id)
            ->exists();
    }
}
