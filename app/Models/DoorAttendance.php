<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A child's day as the door recorded it.
 *
 * The same shape as the register's Attendance, on its own table, with its
 * own returns and health audits: Teacher Attendance and Director Attendance
 * are two records that do not meet. Everything that works on an Attendance
 * works on this, which is the point of the inheritance — the door's code
 * reads and writes the door's tables without knowing it.
 */
class DoorAttendance extends Attendance
{
    protected $table = 'door_attendances';

    public function healthAudits(): HasMany
    {
        return $this->hasMany(DoorHealthAudit::class, 'attendance_id')->latest('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(DoorAttendanceReturn::class, 'attendance_id')->orderBy('returned_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AttendanceComment::class, 'attendance_id')->latest('created_at')->latest('id');
    }
}
