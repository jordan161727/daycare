<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Child;

class Attendance extends Model
{
    protected $fillable = [
        'child_id',
        'attendance_date',
        'signed_in_at',
        'signed_out_at',
        'session',
    ];

    protected $casts = [
        // Pin the format: without it the value is written as a full timestamp, which
        // only matches on MySQL because a DATE column truncates it. firstOrCreate()
        // lookups miss on any driver that stores what it is given.
        'attendance_date' => 'date:Y-m-d',
        'signed_in_at' => 'datetime',
        // Written by the door kiosk when a child is collected. The arrival
        // stands either way: leaving at three does not make the morning not
        // have happened, and DSS bills against the day, not the departure.
        'signed_out_at' => 'datetime',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
    
}
