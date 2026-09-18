<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One press of the door kiosk.
 *
 * Append-only. The attendance row says a child was here; this says who brought
 * them, at which minute, and by what means. A correction is another row — an
 * editable arrival time is not a record of an arrival.
 */
class ChildAttendancePunch extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'child_id', 'guardian_id', 'person_id', 'direction', 'occurred_at',
        'service_date', 'session', 'method', 'device',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'service_date' => 'date:Y-m-d',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * Who pressed the button.
     *
     * guardian_id is still beside this and still filled for the punches that
     * predate the move, so the old answer can be read next to the new one
     * until that column is dropped.
     */
    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }
}
