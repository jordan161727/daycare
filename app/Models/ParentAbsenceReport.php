<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parent's "my child will be away" from the app. Not a DSS mark: the
 * register stays the director's, and this sits beside it (see the migration).
 */
class ParentAbsenceReport extends Model
{
    public const REASONS = ['sick', 'appointment', 'family', 'vacation', 'other'];

    public const SYMPTOMS = ['fever', 'cough', 'stomach', 'rash'];

    protected $fillable = ['child_id', 'person_id', 'absent_on', 'reason', 'symptoms', 'note', 'message_id'];

    protected $casts = [
        'absent_on' => 'date:Y-m-d',
        'symptoms' => 'array',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
