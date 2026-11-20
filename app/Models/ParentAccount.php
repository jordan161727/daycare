<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent's own sign-in for the app: username, password, photo, and the
 * name they want shown. See the parent_accounts migration.
 */
class ParentAccount extends Model
{
    protected $fillable = ['person_id', 'username', 'password', 'display_name', 'email', 'photo_path', 'profile_done_at', 'last_login_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'profile_done_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Usernames compare without case: "Kate.M" and "kate.m" are one. */
    public static function normalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
