<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An office note on a child — what the parent said at the desk, what the
 * kitchen is short of. The teachers' notes from check-in are
 * AttendanceComment; the record's Notes & comments card lists both.
 */
class ChildNote extends Model
{
    public const KINDS = ['office' => 'Office', 'health' => 'Health'];

    protected $fillable = ['child_id', 'user_id', 'kind', 'body'];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
