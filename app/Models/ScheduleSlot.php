<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSlot extends Model
{
    protected $fillable = ['week_start', 'child_id', 'slot_date', 'session', 'is_scheduled'];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'slot_date' => 'date:Y-m-d',
        'is_scheduled' => 'boolean',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
}
