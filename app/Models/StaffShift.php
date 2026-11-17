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
        'starts_at', 'ends_at', 'classroom', 'role', 'covers_user_id', 'moved_by',
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

    /**
     * Shifts the floor runs by: in a published week, or in a week that has
     * no generated roster at all (shifts entered by hand, or a cover made
     * from the floor plan). A draft — generated, not yet published — is the
     * director's plan on the Schedule page and nothing else: it never makes
     * anybody late, due, "on today's schedule" or counts toward a ratio
     * (QA, 17 Nov 2026: a fresh draft marked six teachers "Late · due 7:00").
     */
    public function scopeLive($query)
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staff_schedule_weeks')
            ->whereRaw('date(staff_schedule_weeks.week_start) = date(staff_shifts.week_start)')
            ->whereNotNull('staff_schedule_weeks.generated_at')
            ->whereNull('staff_schedule_weeks.published_at'));
    }

    /** A generated week not yet published — the one thing live() leaves out. */
    public function inDraft(): bool
    {
        $week = $this->scheduleWeek();

        return $week !== null && $week->generated_at !== null && $week->published_at === null;
    }

    private ?StaffScheduleWeek $weekRow = null;

    private bool $weekLoaded = false;

    /** The generated week this shift belongs to, if there is one. */
    public function scheduleWeek(): ?StaffScheduleWeek
    {
        if (! $this->weekLoaded) {
            $this->weekRow = StaffScheduleWeek::whereDate('week_start', $this->week_start->toDateString())->first();
            $this->weekLoaded = true;
        }

        return $this->weekRow;
    }

    /**
     * The minute of shift_date from which somebody not in can count as late,
     * or null when they never can. Late runs from the moment the schedule was
     * published, never back to a start time that had already gone by: a week
     * published at 9:30 does not make the 7:00 opener late (QA, 17 Nov 2026).
     */
    public function lateFrom(): ?int
    {
        $published = $this->scheduleWeek()?->published_at;

        if ($published === null) {
            // A week entered by hand (never generated) has always been the
            // plan; a generated draft is not one yet.
            return $this->inDraft() ? null : $this->starts_at;
        }

        $published = $published->copy()->timezone(config('app.timezone'));
        $day = $this->shift_date->toDateString();

        return match (true) {
            $published->toDateString() < $day => $this->starts_at,
            $published->toDateString() === $day => max($this->starts_at, $published->hour * 60 + $published->minute),
            default => null,
        };
    }

    /** Late by now (minute of the day), allowing the grace, if they are not in. */
    public function isLateAt(int $minute, int $grace = 0): bool
    {
        $from = $this->lateFrom();

        return $from !== null && $minute > $from + $grace;
    }

    /** The person this cover stands in for, when it was made from the floor plan. */
    public function coversFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'covers_user_id');
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
