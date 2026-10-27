<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of somebody standing in one room.
 *
 * Opened by a room pick at the door or a PIN at a room tablet, closed by the
 * next move, a break, a clock-out or closing time. A row with no room is the
 * office: on the clock, in nobody's ratio. See the migration for why this is
 * a history and not a column.
 */
class StaffRoomCheckin extends Model
{
    public const VIA_DOOR = 'door';

    public const VIA_TABLET = 'tablet';

    /** A room tablet clocked them in because they had not been. */
    public const VIA_AUTO = 'auto';

    /** End Break put them back where they were. */
    public const VIA_RETURN = 'return';

    public const ENDED_MOVE = 'move';

    public const ENDED_BREAK = 'break';

    public const ENDED_CLOCK_OUT = 'clock_out';

    /** Closed by the nightly safety net, not by the person. */
    public const ENDED_CLOSING = 'closing';

    protected $fillable = [
        'user_id', 'classroom', 'started_at', 'ended_at', 'started_via',
        'ended_reason', 'started_device_id', 'ended_device_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function startedDevice(): BelongsTo
    {
        return $this->belongsTo(StaffDevice::class, 'started_device_id');
    }

    /** Still standing there. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /** In a room, as opposed to the office. */
    public function scopeInARoom(Builder $query): Builder
    {
        return $query->whereNotNull('classroom');
    }

    public function isOffice(): bool
    {
        return $this->classroom === null;
    }

    /** "PreK", or "the office" — the words every sentence about it uses. */
    public function placeName(): string
    {
        return $this->classroom ?? 'the office';
    }

    /** Close it, saying why and at which screen. */
    public function close(string $reason, ?StaffDevice $device = null): self
    {
        $this->forceFill([
            'ended_at' => now(),
            'ended_reason' => $reason,
            'ended_device_id' => $device?->id,
        ])->save();

        return $this;
    }
}
