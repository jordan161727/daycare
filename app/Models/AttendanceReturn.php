<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One trip out of the room and back, on an attendance row.
 *
 * The row says first in and last out; these say what happened between. See
 * the migration for why the row is not simply reopened and the earlier
 * departure forgotten.
 */
class AttendanceReturn extends Model
{
    protected $fillable = [
        'attendance_id',
        'left_at',
        'returned_at',
        'performed_by',
    ];

    protected $casts = [
        'left_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
