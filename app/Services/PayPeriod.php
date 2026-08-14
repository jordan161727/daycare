<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Semi-monthly pay periods: the 1st to the 15th, and the 16th to the last day
 * of the month. Twenty-four a year.
 *
 * Kept as a value object rather than a table because a period is arithmetic on
 * a date, not a record — there is no version of "the first half of August" that
 * could be wrong, and nothing to keep in step.
 *
 * The awkward part is that a semi-monthly period never lines up with a week. A
 * period boundary falls mid-week eleven times out of twelve, so a week's work
 * routinely belongs to two of them. Overtime is a property of the week and pay
 * is a property of the period, and Timesheet is where the two are reconciled.
 */
class PayPeriod
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /** The period a date falls in. */
    public static function containing(string|Carbon $date): self
    {
        $day = Carbon::parse($date)->startOfDay();

        return $day->day <= 15
            ? new self($day->copy()->startOfMonth(), $day->copy()->startOfMonth()->addDays(14))
            : new self($day->copy()->setDay(16), $day->copy()->endOfMonth()->startOfDay());
    }

    public function previous(): self
    {
        return self::containing($this->start->copy()->subDay());
    }

    public function next(): self
    {
        return self::containing($this->end->copy()->addDay());
    }

    /** The identifying date. One period, one row, however often it is opened. */
    public function key(): string
    {
        return $this->start->toDateString();
    }

    /** "Aug 1 – 15, 2026" — the same month is not worth saying twice. */
    public function label(): string
    {
        return $this->start->format('M j').' – '
            .($this->start->month === $this->end->month ? $this->end->format('j') : $this->end->format('M j'))
            .', '.$this->end->format('Y');
    }

    /** Every date in the period, in order. Includes weekends: staff work them. */
    public function dates(): array
    {
        $dates = [];

        for ($date = $this->start->copy(); $date->lte($this->end); $date->addDay()) {
            $dates[] = $date->copy();
        }

        return $dates;
    }

    public function contains(string|Carbon $date): bool
    {
        return Carbon::parse($date)->startOfDay()->between($this->start, $this->end);
    }

    /**
     * Has the period finished?
     *
     * Only a finished period holds the whole story. Approving one that is still
     * running would send payroll hours for days that have not happened.
     */
    public function hasEnded(): bool
    {
        return $this->end->copy()->endOfDay()->isPast();
    }

    /**
     * The Monday of the FLSA workweek a date belongs to.
     *
     * Overtime is worked out per week, and a week is not a period — this is how
     * a day gets grouped with the rest of its week, including the days on the
     * far side of the period boundary.
     */
    public static function workweekOf(string|Carbon $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
    }
}
