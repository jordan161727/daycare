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
        'session',
    ];

    protected $casts = [
        // Pin the format: without it the value is written as a full timestamp, which
        // only matches on MySQL because a DATE column truncates it. firstOrCreate()
        // lookups miss on any driver that stores what it is given.
        'attendance_date' => 'date:Y-m-d',
        'signed_in_at' => 'datetime',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
    
}
