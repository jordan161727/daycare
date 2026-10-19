<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A child's day as the door reads it — which is the register's record.
 *
 * Teacher Attendance and Director Attendance are one record (20 Oct 2026):
 * a clock-in at the door is the arrival on the register, as it was before
 * the two were split on 16 Oct. This class is kept because the door's code,
 * the comments and the seeders name it, but it is the attendances table —
 * the same rows Attendance reads — with the register's returns and audits.
 * The door_* tables are no longer read or written.
 */
class DoorAttendance extends Attendance
{
    protected $table = 'attendances';

    public function healthAudits(): HasMany
    {
        return $this->hasMany(HealthAudit::class, 'attendance_id')->latest('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AttendanceReturn::class, 'attendance_id')->orderBy('returned_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AttendanceComment::class, 'attendance_id')->latest('created_at')->latest('id');
    }
}
