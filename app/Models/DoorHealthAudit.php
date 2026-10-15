<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A health check written down on the door's record — see HealthAudit. */
class DoorHealthAudit extends HealthAudit
{
    protected $table = 'health_audits';

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(DoorAttendance::class, 'attendance_id');
    }
}
