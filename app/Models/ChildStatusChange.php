<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a child's history on the roll.
 *
 * Written by the Child model itself rather than by the form, so it catches
 * every way a status can change — the edit screen, an import, a command run
 * from the shell — instead of only the one somebody remembered to instrument.
 *
 * Since Phase 2 the same table holds the rest of the enrollment history —
 * a room move, a schedule change, the subsidy starting, the reason given at
 * a withdrawal — told apart by `event`. For those rows from_status carries
 * the summary line and to_status the status at the time.
 *
 * Append-only. Nothing here is ever updated or deleted except with the child.
 */
class ChildStatusChange extends Model
{
    protected $fillable = [
        'child_id',
        'event',
        'from_status',
        'to_status',
        'note',
        'changed_by',
    ];

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function isStatusChange(): bool
    {
        return ($this->event ?? 'status') === 'status';
    }

    /**
     * The line as it reads on the record.
     *
     * The first row has no "from", because a child added to the roll came from
     * nothing rather than from having been Inactive — so it says "Added as
     * Active" rather than "→ Active", which would leave the reader looking for
     * a previous state that never existed. Statuses read as the screen names
     * them: the column says Inactive, the page says Withdrawn.
     */
    public function summary(): string
    {
        if (! $this->isStatusChange()) {
            return (string) $this->from_status;
        }

        return $this->from_status === null
            ? 'Added as '.Child::statusLabel($this->to_status)
            : Child::statusLabel($this->from_status).' → '.Child::statusLabel($this->to_status);
    }

    /** Which colour the dot on the timeline takes. */
    public function tone(): string
    {
        return match (true) {
            $this->event === 'withdrawn', $this->event === 'cancelled', $this->to_status === 'Inactive' && $this->isStatusChange() => 'bad',
            in_array($this->event, ['started', 'reenrolled', 'enrolled'], true), $this->isStatusChange() && $this->to_status === 'Active' => 'ok',
            $this->event === 'subsidy' => 'dss',
            default => 'mut',
        };
    }
}
