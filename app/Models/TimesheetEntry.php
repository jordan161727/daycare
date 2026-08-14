<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, one day: when they came, when they left, and what they were paid
 * for that they did not work.
 *
 * Worked minutes and leave minutes are separate fields rather than one total,
 * because overtime only counts the first: somebody on PTO Monday who then works
 * forty hours is owed forty hours, not forty-eight with eight at time and a
 * half. Collapsing them would be a payroll error, not a rounding one.
 */
class TimesheetEntry extends Model
{
    /** Copied from the published roster, and not yet looked at by anybody. */
    public const SOURCE_SCHEDULE = 'schedule';

    /** Built from the punches the employee made on the clock. */
    public const SOURCE_CLOCK = 'clock';

    /** Somebody said this is what happened. */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'timesheet_period_id', 'user_id', 'work_date',
        'starts_at', 'ends_at', 'break_minutes',
        'leave_code', 'leave_minutes', 'source', 'note',
        'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'starts_at' => 'integer',
        'ends_at' => 'integer',
        'break_minutes' => 'integer',
        'leave_minutes' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(TimesheetPeriod::class, 'timesheet_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who said this is what happened. Null on a day nobody has confirmed. */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Minutes actually worked — the span, less the break.
     *
     * A break longer than the shift would otherwise pay a negative day, which
     * is a typo rather than an instruction, so it floors at zero.
     */
    public function workedMinutes(): int
    {
        if ($this->starts_at === null || $this->ends_at === null) {
            return 0;
        }

        return max(0, $this->ends_at - $this->starts_at - $this->break_minutes);
    }

    /** Paid leave only. An unpaid absence is recorded but worth nothing. */
    public function paidLeaveMinutes(): int
    {
        return in_array($this->leave_code, config('daycare.timesheet.paid_leave_codes'), true)
            ? $this->leave_minutes
            : 0;
    }

    /** Has a person actually said what happened on this day? */
    public function isConfirmed(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /** Built from what the employee punched rather than typed by anybody. */
    public function isFromClock(): bool
    {
        return $this->source === self::SOURCE_CLOCK;
    }

    /**
     * Is this day still nobody's word?
     *
     * Only a day copied from the roster is. A punched day is the employee's
     * own account of it and a corrected one is a supervisor's, and neither is
     * the guess the confirm step exists to catch — making a director retype
     * a fortnight of clean punches would turn confirming into the rubber stamp
     * that this whole flow refuses to have.
     */
    public function needsConfirming(): bool
    {
        return $this->source === self::SOURCE_SCHEDULE && ! $this->isEmpty();
    }

    /** A day with nothing on it at all — neither worked nor accounted for. */
    public function isEmpty(): bool
    {
        return $this->workedMinutes() === 0 && $this->leave_code === null;
    }

    public static function formatHours(int $minutes): string
    {
        return number_format($minutes / 60, 2);
    }
}
