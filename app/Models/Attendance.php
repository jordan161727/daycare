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
        'attendance_date' => 'date',
        'signed_in_at' => 'datetime',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }
    
}
