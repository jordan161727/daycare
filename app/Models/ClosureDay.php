<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day the centre is shut. Nobody is scheduled on a closed day, but a sign-in
 * is still accepted — if a child turns up, DSS bills the attendance that
 * happened, not the attendance we planned for.
 */
class ClosureDay extends Model
{
    protected $fillable = ['closed_on', 'reason', 'cleared_slots', 'holiday_rule_id', 'created_by'];

    protected $casts = [
        'closed_on' => 'date:Y-m-d',
        'cleared_slots' => 'array',
    ];

    /** Who entered the closure — the audit line on the holidays page. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The annual holiday that produced this day, if it was not entered by hand. */
    public function rule()
    {
        return $this->belongsTo(HolidayRule::class, 'holiday_rule_id');
    }

    /** What the greyed-out column on the attendance board says. */
    public function label(): string
    {
        return $this->reason ?: 'Centre closed';
    }

    /** How many ticks reopening this day would put back. */
    public function clearedCount(): int
    {
        return count($this->cleared_slots ?? []);
    }

    /**
     * Closures between two dates, inclusive of both ends.
     *
     * The bounds are cut to Y-m-d strings first, and that is the whole point of
     * this scope. closed_on is a DATE; a Carbon bound binds as 'Y-m-d H:i:s',
     * and SQLite compares the two as text — so '2028-12-25' sorts *before*
     * '2028-12-25 00:00:00' and a closure on the first day of the range is
     * silently dropped. Mondays are where public holidays live, so that miss is
     * not a rare one.
     */
    public function scopeBetweenDates($query, $from, $to)
    {
        return $query->whereBetween('closed_on', [
            Carbon::parse($from)->toDateString(),
            Carbon::parse($to)->toDateString(),
        ]);
    }

    /** The closed dates inside one Mon–Fri week, as Y-m-d strings. */
    public static function inWeek(string $weekStart): array
    {
        $dates = ScheduleWeek::datesOf($weekStart);

        return static::betweenDates($dates[0], $dates[4])
            ->pluck('closed_on')
            ->map(fn ($date) => $date->toDateString())
            ->all();
    }
}
