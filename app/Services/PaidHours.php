<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * The one place a worked day turns into paid time.
 *
 * TimeClock::walk() calls it for every day it adds up, the correction panel
 * asks the server for its numbers rather than doing the sums in the browser,
 * and the week's total and its overtime flag come from here too — so a
 * corrected day, the grid and the pay slip can only ever agree.
 */
class PaidHours
{
    /** Minutes worked before the unpaid break starts coming off. */
    public static function threshold(): int
    {
        return (int) config('daycare.timesheet.clock.auto_break.after_minutes', 360);
    }

    /** The most the unpaid break can take. */
    public static function maxBreak(): int
    {
        return (int) config('daycare.timesheet.clock.auto_break.max_minutes', 30);
    }

    /** The first day the rule applies, or null for every day. */
    public static function from(): ?string
    {
        return config('daycare.timesheet.clock.auto_break.from') ?: null;
    }

    /** Whether the rule applies on a date. Days before it are paid as they were. */
    public static function appliesOn(string $date): bool
    {
        $from = self::from();

        return $from === null || Carbon::parse($date)->toDateString() >= Carbon::parse($from)->toDateString();
    }

    /**
     * The break taken off a day: clamp(worked − threshold, 0, max).
     *
     * Zero on a day with a punched lunch — that lunch is already unpaid — and
     * on a day before the rule started.
     */
    public static function deduction(int $worked, ?string $date = null, bool $lunchPunched = false): int
    {
        if ($lunchPunched || ($date !== null && ! self::appliesOn($date))) {
            return 0;
        }

        return max(0, min($worked - self::threshold(), self::maxBreak()));
    }

    /** Worked minutes less the break. */
    public static function paid(int $worked, ?string $date = null, bool $lunchPunched = false): int
    {
        return $worked - self::deduction($worked, $date, $lunchPunched);
    }

    /** Minutes after the scheduled start before a clock-in is late. */
    public static function lateGrace(): int
    {
        return (int) config('daycare.timesheet.clock.late_grace', 5);
    }

    /** Paid minutes in a week before overtime. */
    public static function overtimeAfter(): int
    {
        return (int) config('daycare.timesheet.overtime_after', 40) * 60;
    }

    /**
     * The payroll week a date falls in: Monday to Sunday, as config says.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function weekOf(string $date): array
    {
        $monday = Carbon::parse($date)->startOfWeek(Carbon::MONDAY);

        return [$monday, $monday->copy()->addDays(6)];
    }

    /**
     * One person's paid minutes for the payroll week a date falls in.
     *
     * @return array{from: string, to: string, minutes: int, overtime: bool, over: int}
     */
    public function week(int $userId, string $date): array
    {
        [$from, $to] = self::weekOf($date);
        $clock = app(TimeClock::class);
        $minutes = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $minutes += (int) $clock->day($userId, $day->toDateString())['worked'];
        }

        $over = max(0, $minutes - self::overtimeAfter());

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'minutes' => $minutes,
            'overtime' => $over > 0,
            'over' => $over,
        ];
    }
}
