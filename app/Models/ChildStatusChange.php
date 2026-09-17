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
 * Append-only. Nothing here is ever updated or deleted except with the child.
 */
class ChildStatusChange extends Model
{
    protected $fillable = [
        'child_id',
        'from_status',
        'to_status',
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

    /**
     * The line as it reads on the record.
     *
     * The first row has no "from", because a child added to the roll came from
     * nothing rather than from having been Inactive — so it says "Added as
     * Active" rather than "→ Active", which would leave the reader looking for
     * a previous state that never existed.
     */
    public function summary(): string
    {
        return $this->from_status === null
            ? 'Added as '.$this->to_status
            : $this->from_status.' → '.$this->to_status;
    }
}
