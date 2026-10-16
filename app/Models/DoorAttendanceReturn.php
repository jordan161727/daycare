<?php

namespace App\Models;

/** A trip out and back on the door's record — see AttendanceReturn. */
class DoorAttendanceReturn extends AttendanceReturn
{
    protected $table = 'door_attendance_returns';

    public function attendance()
    {
        return $this->belongsTo(DoorAttendance::class, 'attendance_id');
    }
}
