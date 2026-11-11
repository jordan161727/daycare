<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone with the parent app on it, and where to send its pushes. */
class ParentDevice extends Model
{
    protected $fillable = ['person_id', 'push_token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
