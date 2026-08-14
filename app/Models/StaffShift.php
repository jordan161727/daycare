<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, in one room, for one stretch of one day.
 *
 * Times are minutes past midnight and the range is half-open — a shift ending
 * at 12:00 and one starting at 12:00 do not overlap, which is what stops a
 * handover reading as double cover in the ratio check.
 */
class StaffShift extends Model
{
    /** The person's own shift, worked out from their rules and hours. */
    public const ROLE_STAFF = 'STAFF';

    /** A regular member pulled off their room to plug a ratio gap. */
    public const ROLE_FLOAT = 'FLOAT';

    /** A substitute brought in for the same reason. */
    public const ROLE_PATCH = 'PATCH';

    protected $fillable = [
        'week_start', 'user_id', 'shift_date', 'day',
        'starts_at', 'ends_at', 'classroom', 'role',
    ];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'shift_date' => 'date:Y-m-d',
        'starts_at' => 'integer',
        'ends_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutes(): int
    {
        return max(0, $this->ends_at - $this->starts_at);
    }

    public function hours(): float
    {
        return round($this->minutes() / 60, 2);
    }

    /** Is this shift running at the given minute past midnight? */
    public function covers(int $minute): bool
    {
        return $this->starts_at <= $minute && $this->ends_at > $minute;
    }

    /** True when the two shifts share any minute at all. */
    public function overlaps(self $other): bool
    {
        return $this->starts_at < $other->ends_at && $other->starts_at < $this->ends_at;
    }

    public function isCover(): bool
    {
        return $this->role !== self::ROLE_STAFF;
    }

    public function label(): string
    {
        return StaffRule::formatTime($this->starts_at).' – '.StaffRule::formatTime($this->ends_at);
    }
}
