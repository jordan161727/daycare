<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a health code: what it was, what it became, and who did it.
 *
 * Written once and never edited, like a punch and like a login. A health code
 * is a statement about a child made by a named adult at a named time, and the
 * whole reason to keep the history is that it can be shown to somebody who was
 * not there — a parent, or a licensing visit.
 *
 * Clearing a code is a change like any other and is written down too, with a
 * null new_code. A record that quietly forgot a fever is worse than one that
 * never held it.
 */
class HealthAudit extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Only created_at. There is no such thing as updating one of these. */
    public const UPDATED_AT = null;

    protected $fillable = ['attendance_id', 'direction', 'old_code', 'new_code', 'note', 'user_id'];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Note the change, unless nothing actually changed. */
    public static function note(Attendance $attendance, string $direction, ?int $old, ?int $new, ?string $noteText, ?User $by): ?self
    {
        return static::create([
            'attendance_id' => $attendance->id,
            'direction' => $direction,
            'old_code' => $old,
            'new_code' => $new,
            'note' => $noteText,
            'user_id' => $by?->id,
        ]);
    }
}
