<?php

namespace App\Models;

/**
 * A trip out and back as the door reads it — the register's own table, since
 * the two records were rejoined on 20 Oct 2026. See AttendanceReturn.
 */
class DoorAttendanceReturn extends AttendanceReturn
{
    protected $table = 'attendance_returns';

    public function attendance()
    {
        return $this->belongsTo(DoorAttendance::class, 'attendance_id');
    }
}
