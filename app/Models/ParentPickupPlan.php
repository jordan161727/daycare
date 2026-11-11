<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who a parent says is collecting a child on one day. With person_id it is
 * somebody already on the pick-up list; without, a one-time person the
 * office has to check — it never adds anyone to the list.
 */
class ParentPickupPlan extends Model
{
    protected $fillable = ['child_id', 'pickup_on', 'person_id', 'name', 'relation', 'phone', 'set_by', 'message_id'];

    protected $casts = ['pickup_on' => 'date:Y-m-d'];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function isOneTime(): bool
    {
        return $this->person_id === null;
    }
}
