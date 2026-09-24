<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSlot extends Model
{
    protected $fillable = ['week_start', 'child_id', 'slot_date', 'session', 'is_scheduled', 'planned_time'];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'slot_date' => 'date:Y-m-d',
        'is_scheduled' => 'boolean',
    ];

    /**
     * The hour this day is booked for, as the browser wants it: "08:00".
     *
     * Stored as a TIME, which comes back with seconds on it. Nothing on the
     * sheet has ever shown a second, and a field pre-filled with "08:00:00"
     * invites somebody to correct it to the value it already held.
     */
    public function plannedTimeValue(): ?string
    {
        return $this->planned_time ? substr((string) $this->planned_time, 0, 5) : null;
    }

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
