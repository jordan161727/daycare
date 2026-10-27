<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One paper on a child's file: the enrollment packet, the medical statement,
 * the consents. Kinds are Child::DOCUMENT_KINDS. The file itself is on the
 * private disk and only ever served through the record (see
 * ChildRecordController::document); a row with no path records that the
 * paper is in the cabinet.
 */
class ChildDocument extends Model
{
    protected $fillable = ['child_id', 'kind', 'name', 'path', 'signed_on', 'expires_on', 'uploaded_by'];

    protected $casts = [
        'signed_on' => 'date:Y-m-d',
        'expires_on' => 'date:Y-m-d',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** "On file", "Renews Jan 12", "Expires Dec 31", "Expired Sep 30" and the pill tone for each. */
    public function status(): array
    {
        if ($this->expires_on === null) {
            return ['On file', 'ok'];
        }

        $today = Carbon::today();

        if ($this->expires_on->lt($today)) {
            return ['Expired '.$this->expires_on->format('M j'), 'bad'];
        }

        if ($this->expires_on->lte($today->copy()->addDays(60))) {
            return ['Expires '.$this->expires_on->format('M j'), 'warn'];
        }

        return ['Renews '.$this->expires_on->format('M j'), 'ok'];
    }
}
