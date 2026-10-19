<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A health check as the door reads it — the register's own audit table,
 * since the two records were rejoined on 20 Oct 2026. See HealthAudit.
 */
class DoorHealthAudit extends HealthAudit
{
    protected $table = 'health_audits';

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(DoorAttendance::class, 'attendance_id');
    }
}
