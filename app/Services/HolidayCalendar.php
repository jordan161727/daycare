<?php

namespace App\Services;

use App\Models\ClosureDay;
use App\Models\HolidayRule;
use App\Models\ScheduleWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns annual holiday rules into the actual closed days on the calendar.
 *
 * The rules are the short list a director maintains; closure_days is what every
 * other part of the app reads. Writing the years out ahead of time rather than
 * matching on month-and-day at read time is the whole design: the attendance
 * board, the projection, the staff roster and leave all keep working unchanged,
 * because an annual holiday reaches them as an ordinary closure.
 *
 * A rule remembers the last year it has been written out for, and a top-up only
 * ever fills the years after it. That is what makes reopening a single day
 * stick — nothing goes back over a year already done and puts it back.
 */
class HolidayCalendar
{
    /** How far ahead a rule is written. Weeks are planned months out, not years. */
    public const HORIZON_YEARS = 5;

    public function __construct(private WeekSchedule $weeks) {}

    /**
     * Write out every rule up to the horizon, and report what that closed.
     *
     * Cheap and idempotent once the years are done — a rule already written
     * through the horizon does no work at all — so it is safe to call on every
     * visit to the holidays page, which is what keeps the horizon rolling
     * forward without a scheduled job.
     */
    public function materialise(?User $user = null): array
    {
        $through = (int) now()->year + self::HORIZON_YEARS;
        $closed = 0;
        $cleared = 0;

        foreach (HolidayRule::all() as $rule) {
            $result = $this->materialiseRule($rule, $through, $user);
            $closed += $result['closed'];
            $cleared += $result['cleared'];
        }

        return ['closed' => $closed, 'cleared' => $cleared];
    }

    /** One rule, from the year after its last to the horizon. */
    public function materialiseRule(HolidayRule $rule, ?int $through = null, ?User $user = null): array
    {
        $through ??= (int) now()->year + self::HORIZON_YEARS;
        $from = $rule->materialised_through ? $rule->materialised_through + 1 : (int) now()->year;
        $closed = 0;
        $cleared = 0;

        if ($from > $through) {
            return ['closed' => 0, 'cleared' => 0];
        }

        DB::transaction(function () use ($rule, $from, $through, $user, &$closed, &$cleared) {
            for ($year = $from; $year <= $through; $year++) {
                $date = $this->observedDate($rule, $year);

                // 29 February in a common year gives no date at all, and a week
                // already finished is a record rather than a plan. Both mean
                // there is no school day here to close.
                if (! $date || $this->weeks->isFrozen(ScheduleWeek::startOf($date))) {
                    continue;
                }

                $cleared += $this->weeks->setClosure($date, true, $rule->reason, $user, $rule->id);
                $closed++;
            }

            // Recorded even for the years that produced nothing, so a date the
            // rule cannot place is not reconsidered on every page load.
            $rule->forceFill(['materialised_through' => $through])->save();
        });

        return ['closed' => $closed, 'cleared' => $cleared];
    }

    /**
     * Where a rule's holiday is actually taken in a given year.
     *
     * A holiday at the weekend is observed on the next working day — Christmas
     * on a Saturday is kept on the Monday, not skipped. A day already closed is
     * stepped over, which is what settles the collision when Christmas and
     * Boxing Day fall on the Saturday and Sunday together: one takes the
     * Monday, the other the Tuesday.
     *
     * Rules that do not shift simply return nothing for a weekend year. Nothing
     * built on "first Monday in September" ever needs this.
     */
    private function observedDate(HolidayRule $rule, int $year): ?string
    {
        $date = $rule->dateIn($year);

        if (! $date) {
            return null;
        }

        $date = Carbon::parse($date);

        // A weekday is simply the day. If something else already closed it, the
        // centre is shut either way — shifting would close a second day for a
        // holiday that already has one.
        if (! $date->isWeekend()) {
            return $date->toDateString();
        }

        if (! $rule->observed) {
            return null;
        }

        // Walking forward from the weekend, stepping over days already spoken
        // for. Bounded, so a run of closures cannot walk it into the next week.
        for ($step = 0; $step < 4; $step++) {
            $date->addDay();

            if (! $date->isWeekend() && ! $this->takenByAnother($date, $rule)) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /** Is this day already closed by a different holiday? */
    private function takenByAnother(Carbon $date, HolidayRule $rule): bool
    {
        return ClosureDay::where('closed_on', $date->toDateString())
            ->where(fn ($query) => $query->whereNull('holiday_rule_id')->orWhere('holiday_rule_id', '!=', $rule->id))
            ->exists();
    }

    /**
     * Drop a rule and the days it wrote that have not happened yet.
     *
     * Past closures stay. The centre really was shut on them, and attendance
     * was billed against that — deleting a rule is a decision about next year,
     * not a correction of last year.
     */
    public function forget(HolidayRule $rule): int
    {
        $removed = $this->releaseFuture($rule);

        $rule->delete();

        return $removed;
    }

    /**
     * Reopen the days a rule has written that have not happened yet.
     *
     * Past closures stay. The centre really was shut on them, and attendance
     * was billed against that — changing or dropping a rule is a decision about
     * next year, not a correction of last year.
     */
    private function releaseFuture(HolidayRule $rule): int
    {
        $removed = 0;

        foreach ($rule->days()->where('closed_on', '>=', today()->toDateString())->get() as $day) {
            $date = $day->closed_on->toDateString();

            if ($this->weeks->isFrozen(ScheduleWeek::startOf($date))) {
                continue;
            }

            // Through setClosure, so the ticks the closure took off come back
            // exactly as they would if the day were reopened by hand.
            $this->weeks->setClosure($date, false);
            $removed++;
        }

        return $removed;
    }

    /**
     * Move a rule to a different date and write it out again.
     *
     * The old days are released first and the horizon reset, because a rule
     * that has already been written through 2031 would otherwise write nothing
     * — and the centre would go on closing the date the rule no longer names.
     */
    public function rewrite(HolidayRule $rule, ?User $user = null): array
    {
        $released = $this->releaseFuture($rule);

        $rule->forceFill(['materialised_through' => null])->save();

        return $this->materialiseRule($rule, null, $user) + ['released' => $released];
    }

    /**
     * Give a rule a new name without disturbing a single tick.
     *
     * A renamed holiday falls on exactly the same days, so releasing and
     * rewriting them would clear and restore the whole roster to change a piece
     * of text. The days it already wrote just take the new name.
     */
    public function rename(HolidayRule $rule, string $reason): int
    {
        $rule->forceFill(['reason' => $reason])->save();

        return $rule->days()
            ->where('closed_on', '>=', today()->toDateString())
            ->update(['reason' => $reason]);
    }
}
