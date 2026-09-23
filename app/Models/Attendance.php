<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Child;

class Attendance extends Model
{
    protected $fillable = [
        'child_id',
        'attendance_date',
        'signed_in_at',
        'signed_out_at',
        'session',
        'health_in_code',
        'health_in_note',
        'health_out_code',
        'health_out_note',
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
        // Tiny integers come back as strings on some drivers, and a chip that
        // compares \"0\" against 0 colours a healthy child amber.
        'health_in_code' => 'integer',
        'health_out_code' => 'integer',
    ];

    public function child()
    {
        return $this->belongsTo(Child::class);
    }

    public function healthAudits(): HasMany
    {
        return $this->hasMany(HealthAudit::class)->latest('id');
    }

    /** The code on one end of the day, named by direction. */
    public function healthCode(string $direction): ?int
    {
        return $direction === HealthAudit::OUT ? $this->health_out_code : $this->health_in_code;
    }

    public function healthNote(string $direction): ?string
    {
        return $direction === HealthAudit::OUT ? $this->health_out_note : $this->health_in_note;
    }

    /**
     * Whether the child was unwell that day.
     *
     * Either end counts. A child who arrived well and was sent home with a
     * fever was sick that day, and a count that only read the morning would
     * say the day was clear.
     */
    public function isSick(): bool
    {
        return SymptomCode::isSick($this->health_in_code) || SymptomCode::isSick($this->health_out_code);
    }

    /**
     * Whether an out-code can be recorded yet.
     *
     * Only once there is a departure to attach it to. A health check at
     * collection is something a person does while handing the child over; a
     * code recorded against a child still in the room is a note about a
     * moment that has not happened.
     */
    public function acceptsOutHealth(): bool
    {
        return $this->signed_out_at !== null;
    }
}
