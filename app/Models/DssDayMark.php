<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The word on a day with no times: absent, or not scheduled after all.
 *
 * See the migration. Clearing a day deletes the row; putting times on it
 * deletes the row too, because a day with an arrival is neither.
 */
class DssDayMark extends Model
{
    public const ABSENT = 'absent';

    public const NONE = 'none';

    /**
     * 'kept': the rows an Absent mark took off the day — [{session, in, out}]
     * as ISO datetimes — so a tap back to Coming can put them back (27 Oct 2026).
     */
    protected $fillable = ['child_id', 'marked_on', 'mark', 'created_by', 'kept'];

    protected $casts = ['marked_on' => 'date:Y-m-d', 'kept' => 'array'];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
