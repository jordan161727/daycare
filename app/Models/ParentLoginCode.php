<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sign-in code texted to a parent: hashed, ten minutes, five tries. */
class ParentLoginCode extends Model
{
    protected $fillable = ['person_id', 'code_hash', 'expires_at', 'attempts', 'used_at', 'ip'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
