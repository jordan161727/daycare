<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A holiday that comes back every year.
 *
 * Entered once; the closures it produces are ordinary rows in closure_days,
 * written years ahead by HolidayCalendar. Nothing that reads a closure has to
 * know this table exists.
 *
 * Four kinds, because a statutory calendar needs all four:
 *
 *  - FIXED       the same date every year. Christmas, Canada Day.
 *  - NTH_WEEKDAY the nth weekday of a month, or the last. Labour Day is the
 *                first Monday in September; Thanksgiving the second in October.
 *  - ON_OR_BEFORE the last given weekday up to a date. Victoria Day is the
 *                Monday on or before 24 May, which is not the last Monday in
 *                May and not the third one either.
 *  - EASTER      an offset from Easter Sunday, which moves by up to a month.
 *                Good Friday is -2.
 */
class HolidayRule extends Model
{
    public const FIXED = 'fixed';

    public const NTH_WEEKDAY = 'nth_weekday';

    public const ON_OR_BEFORE = 'on_or_before';

    public const EASTER = 'easter';

    protected $fillable = [
        'type', 'month', 'day', 'weekday', 'nth', 'offset_days', 'observed',
        'reason', 'key', 'materialised_through', 'created_by',
    ];

    protected $casts = [
        'month' => 'integer',
        'day' => 'integer',
        'weekday' => 'integer',
        'nth' => 'integer',
        'offset_days' => 'integer',
        'observed' => 'boolean',
        'materialised_through' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The days this rule has written out. */
    public function days()
    {
        return $this->hasMany(ClosureDay::class, 'holiday_rule_id');
    }

    /**
     * This rule's date in a given year, or null where it does not fall.
     *
     * 29 February is the only fixed date that can be missing — in a common year
     * the rule produces nothing, which is the right answer for a holiday that
     * genuinely does not occur that year.
     */
    public function dateIn(int $year): ?string
    {
        return match ($this->type) {
            self::NTH_WEEKDAY => $this->nthWeekdayIn($year),
            self::ON_OR_BEFORE => $this->onOrBeforeIn($year),
            self::EASTER => self::easter($year)->addDays($this->offset_days ?? 0)->toDateString(),
            default => checkdate($this->month, $this->day, $year)
                ? Carbon::create($year, $this->month, $this->day)->toDateString()
                : null,
        };
    }

    /** The nth given weekday of the month, or the last one when nth is -1. */
    private function nthWeekdayIn(int $year): ?string
    {
        $first = Carbon::create($year, $this->month, 1);

        if ($this->nth < 0) {
            $last = $first->copy()->endOfMonth();

            return $last->subDays(($last->dayOfWeekIso - $this->weekday + 7) % 7)->toDateString();
        }

        // Step forward to the first matching weekday, then on by whole weeks.
        $date = $first->addDays(($this->weekday - $first->dayOfWeekIso + 7) % 7)
            ->addWeeks($this->nth - 1);

        // A fifth Monday that the month does not have is not a date. Better to
        // produce nothing than to spill into the next month.
        return $date->month === $this->month ? $date->toDateString() : null;
    }

    /** The given weekday falling on or before month/day — Victoria Day's shape. */
    private function onOrBeforeIn(int $year): ?string
    {
        if (! checkdate($this->month, $this->day, $year)) {
            return null;
        }

        $date = Carbon::create($year, $this->month, $this->day);

        return $date->subDays(($date->dayOfWeekIso - $this->weekday + 7) % 7)->toDateString();
    }

    /**
     * Easter Sunday, by the anonymous Gregorian algorithm.
     *
     * Written out rather than taken from easter_date(), which needs the calendar
     * extension — an optional build flag is a poor thing for the holiday
     * calendar to depend on.
     */
    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day);
    }

    /** How the rule reads on the list — "2nd Monday in October". */
    public function label(): string
    {
        $weekday = fn () => Carbon::create(2024, 1, 1)->addDays($this->weekday - 1)->format('l');
        $month = fn () => Carbon::create(2000, $this->month, 1)->format('F');

        return match ($this->type) {
            self::NTH_WEEKDAY => $this->nth < 0
                ? 'Last '.$weekday().' in '.$month()
                : self::ordinal($this->nth).' '.$weekday().' in '.$month(),
            self::ON_OR_BEFORE => $weekday().' on or before '.$this->day.' '.$month(),
            self::EASTER => match ($this->offset_days) {
                0 => 'Easter Sunday',
                -2 => 'Good Friday',
                1 => 'Easter Monday',
                default => abs($this->offset_days).' days '.($this->offset_days < 0 ? 'before' : 'after').' Easter',
            },
            default => Carbon::create(2000, $this->month, min($this->day, 29))->format('j F'),
        };
    }

    /** Whether this rule lands on a different date each year. */
    public function moves(): bool
    {
        return $this->type !== self::FIXED;
    }

    private static function ordinal(int $n): string
    {
        return $n.match ($n) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
    }
}
