<?php

namespace App\Services;

use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The staff time clock, and the arithmetic that turns punches into a paid day.
 *
 * Three rules run through everything here.
 *
 * **A punch is evidence; the timesheet entry is arithmetic.** Every day is
 * rebuilt from its live punches rather than adjusted in place, so voiding a
 * stray punch two weeks later produces exactly the same number as if it had
 * never been pressed. Nothing accumulates.
 *
 * **Lunch is unpaid and a short break is paid.** That is the only reason they
 * are separate punch types. The FLSA treats rest breaks of up to twenty
 * minutes as hours worked and a genuine meal period as not, so a break that
 * overruns the cap stops being a rest break for the minutes beyond it.
 *
 * **A day that does not add up is never guessed at.** Somebody who forgot to
 * clock out is worth zero hours, not hours-until-midnight and not
 * hours-until-their-rostered-end. It is raised as an exception, it blocks the
 * period from being approved, and a supervisor has to say what happened.
 */
class TimeClock
{
    public const OFF = 'off';

    public const WORKING = 'working';

    public const LUNCH = 'lunch';

    public const BREAK = 'break';

    /**
     * What somebody in each state may legally press next.
     *
     * The employee's own clock offers these and nothing else, so the ordinary
     * run of punches is correct by construction. A supervisor correction is
     * deliberately not held to it — the missing 5pm OUT has to be insertable
     * after the 6pm IN of the next day was already recorded.
     */
    public const NEXT = [
        self::OFF => [TimePunch::IN],
        self::WORKING => [TimePunch::LUNCH_START, TimePunch::BREAK_START, TimePunch::OUT],
        self::LUNCH => [TimePunch::LUNCH_END],
        self::BREAK => [TimePunch::BREAK_END],
    ];

    /** Where somebody stands right now, from the punches they have made today. */
    public function state(User $user, string $date): string
    {
        return $this->day($user->id, $date)['state'];
    }

    /** The punches on a day, live ones and voided ones, oldest first. */
    public function punches(int $userId, string $date, bool $withVoided = false): Collection
    {
        return TimePunch::with(['recorder', 'voider', 'corrects'])
            ->where('user_id', $userId)
            ->whereDate('work_date', $date)
            ->when(! $withVoided, fn ($query) => $query->live())
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Everything a day amounts to: hours, breaks, and what does not add up.
     *
     * @return array{punches: Collection, state: string, worked: int, paid_break: int, unpaid_break: int, first_in: ?int, last_out: ?int, problems: array<int, string>, broken: bool, open: bool, so_far: int}
     */
    public function day(int $userId, string $date, ?Collection $punches = null): array
    {
        return $this->walk($punches ?? $this->punches($userId, $date), $date);
    }

    /**
     * Record a punch.
     *
     * The state machine is checked by the caller rather than here, because the
     * two callers need different answers: an employee pressing an impossible
     * button is refused, and a supervisor filling in a gap is not.
     */
    public function punch(
        User $user,
        string $type,
        Carbon $at,
        string $source = TimePunch::SOURCE_CLOCK,
        ?User $by = null,
        ?string $reason = null,
        ?string $ip = null,
        ?TimePunch $corrects = null,
    ): TimePunch {
        $punch = TimePunch::create([
            'user_id' => $user->id,
            'work_date' => $at->toDateString(),
            'punched_at' => $at,
            'type' => $type,
            'source' => $source,
            'reason' => $reason,
            'recorded_by' => ($by ?? $user)->id,
            'ip_address' => $ip,
            'corrects_id' => $corrects?->id,
        ]);

        // A supervisor acting on a day is acting on it deliberately, so their
        // correction overrides an earlier hand-typed entry. An employee's own
        // punch never does — see rebuild().
        $this->rebuild($user, $at->toDateString(), force: $source === TimePunch::SOURCE_SUPERVISOR);

        return $punch;
    }

    /** Take a punch out of the reckoning without taking it out of the record. */
    public function void(TimePunch $punch, User $by, string $reason): TimePunch
    {
        if ($punch->isVoided()) {
            return $punch;
        }

        $punch->forceFill([
            'voided_at' => now(),
            'voided_by' => $by->id,
            'void_reason' => $reason,
        ])->save();

        $this->rebuild($punch->user, $punch->work_date->toDateString(), force: true);

        return $punch;
    }

    /**
     * Rewrite a day's timesheet entry from its punches.
     *
     * Refused on an approved period — those hours have been paid — and,
     * unless forced, on a day somebody has already typed by hand. A punch
     * beats the roster's guess, but it does not beat a person who looked at
     * the day and said what happened.
     */
    public function rebuild(User $user, string $date, bool $force = false): ?TimesheetEntry
    {
        $period = TimesheetPeriod::forDate($date);

        if ($period->isApproved()) {
            return null;
        }

        $entry = TimesheetEntry::firstOrNew([
            'user_id' => $user->id,
            'work_date' => $date,
        ]);

        if ($entry->exists && $entry->isConfirmed() && ! $force) {
            return $entry;
        }

        $day = $this->day($user->id, $date);

        // A day that does not add up pays nothing until somebody says what
        // happened. Leave, and any note on the entry, belong to whoever put
        // them there and are never touched by the clock.
        $usable = ! $day['broken'] && ! $day['open']
            && $day['first_in'] !== null && $day['last_out'] !== null;

        $entry->fill([
            'timesheet_period_id' => $period->id,
            'starts_at' => $usable ? $day['first_in'] : null,
            'ends_at' => $usable ? $day['last_out'] : null,
            'break_minutes' => $usable
                ? max(0, ($day['last_out'] - $day['first_in']) - $day['worked'])
                : 0,
        ]);

        // Only a day that adds up becomes the clock's word. A broken one is
        // left saying whatever it said before, so forcing a rebuild over a
        // hand-typed day does not quietly relabel it as the clock's.
        if ($usable) {
            $entry->fill([
                'source' => TimesheetEntry::SOURCE_CLOCK,
                'confirmed_by' => null,
                'confirmed_at' => null,
            ]);
        }

        // Nothing left on the day at all — every punch voided, no leave, no
        // note. Keeping an empty row would claim the clock had something to
        // say about it.
        if ($entry->isEmpty() && blank($entry->note)) {
            if ($entry->exists) {
                $entry->delete();
            }

            return null;
        }

        $entry->save();

        return $entry;
    }

    /**
     * Days in a period that do not add up, per employee.
     *
     * One query across the whole period. These block approval for the same
     * reason a day still carrying the roster's word does: nobody has said what
     * happened, and paying it would be a guess.
     *
     * @return array<int, array<string, array<int, string>>> [user_id][date] => problems
     */
    public function exceptions(PayPeriod $range, array $userIds = []): array
    {
        $punches = TimePunch::live()
            ->when($userIds !== [], fn ($query) => $query->whereIn('user_id', $userIds))
            ->whereBetween('work_date', [$range->start->toDateString(), $range->end->toDateString()])
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        $found = [];

        foreach ($punches->groupBy('user_id') as $userId => $forUser) {
            foreach ($forUser->groupBy(fn ($punch) => $punch->work_date->toDateString()) as $date => $onDay) {
                $day = $this->walk($onDay, $date);

                if ($day['problems'] !== []) {
                    $found[$userId][$date] = $day['problems'];
                }
            }
        }

        return $found;
    }

    /** How many days in a period have something wrong with them. */
    public function exceptionCount(array $exceptions, ?int $userId = null): int
    {
        if ($userId !== null) {
            return count($exceptions[$userId] ?? []);
        }

        return array_sum(array_map('count', $exceptions));
    }

    /**
     * Walk a day's punches in order, keeping a running state.
     *
     * Every transition either advances the state or is recorded as a problem —
     * there is no third option where a punch is quietly ignored, because a
     * punch that counted for nothing and said nothing is exactly how a day
     * comes out short and nobody notices.
     */
    private function walk(Collection $punches, string $date): array
    {
        $cap = (int) config('daycare.timesheet.clock.paid_break_cap');
        $punches = $punches
            ->filter(fn (TimePunch $punch) => ! $punch->isVoided())
            ->sortBy([['punched_at', 'asc'], ['id', 'asc']])
            ->values();

        $state = self::OFF;
        $since = null;
        $worked = 0;
        $paidBreak = 0;
        $unpaidBreak = 0;
        $firstIn = null;
        $lastOut = null;
        $problems = [];
        $broken = false;

        foreach ($punches as $punch) {
            $at = $punch->minutes();
            $when = $punch->time();

            switch ($punch->type) {
                case TimePunch::IN:
                    if ($state !== self::OFF) {
                        $problems[] = "clocked in again at {$when} without clocking out";
                        $broken = true;

                        break;
                    }

                    $state = self::WORKING;
                    $since = $at;
                    $firstIn ??= $at;

                    break;

                case TimePunch::OUT:
                    if ($state !== self::WORKING) {
                        $problems[] = "clocked out at {$when} while not on the clock";
                        $broken = true;

                        break;
                    }

                    $worked += max(0, $at - $since);
                    $lastOut = $at;
                    $state = self::OFF;
                    $since = null;

                    break;

                case TimePunch::LUNCH_START:
                case TimePunch::BREAK_START:
                    if ($state !== self::WORKING) {
                        $problems[] = 'went on '.($punch->type === TimePunch::LUNCH_START ? 'lunch' : 'a break')." at {$when} while not on the clock";
                        $broken = true;

                        break;
                    }

                    $worked += max(0, $at - $since);
                    $state = $punch->type === TimePunch::LUNCH_START ? self::LUNCH : self::BREAK;
                    $since = $at;

                    break;

                case TimePunch::LUNCH_END:
                    if ($state !== self::LUNCH) {
                        $problems[] = "came back from lunch at {$when} without going on it";
                        $broken = true;

                        break;
                    }

                    $unpaidBreak += max(0, $at - $since);
                    $state = self::WORKING;
                    $since = $at;

                    break;

                case TimePunch::BREAK_END:
                    if ($state !== self::BREAK) {
                        $problems[] = "came back from a break at {$when} without going on one";
                        $broken = true;

                        break;
                    }

                    // Paid to the cap, and unpaid beyond it: a rest break that
                    // runs to forty minutes has stopped being a rest break.
                    $length = max(0, $at - $since);
                    $paidBreak += min($length, $cap);
                    $unpaidBreak += max(0, $length - $cap);
                    $state = self::WORKING;
                    $since = $at;

                    break;
            }
        }

        // A short break is hours worked, so it goes back in.
        $worked += $paidBreak;

        $open = $state !== self::OFF;
        $today = Carbon::parse($date)->isToday();

        // Still on the clock is ordinary at three in the afternoon and a
        // missing punch by Thursday of the following week.
        if ($open && ! $today) {
            $problems[] = 'never clocked out';
        }

        if ($punches->isNotEmpty() && $firstIn === null) {
            $problems[] = 'punches on this day but never clocked in';
            $broken = true;
        }

        $longest = (int) config('daycare.timesheet.clock.max_day_hours') * 60;

        if ($worked > $longest) {
            $problems[] = 'longer than '.config('daycare.timesheet.clock.max_day_hours').' hours';
        }

        // What the running total says on the employee's own screen. Only
        // meaningful today; a past day's open stretch is a missing punch, not
        // time still being accrued.
        $soFar = $worked;

        if ($open && $today && $state === self::WORKING) {
            $soFar += max(0, (now()->hour * 60 + now()->minute) - $since);
        }

        return [
            'punches' => $punches,
            'state' => $state,
            'worked' => $worked,
            'paid_break' => $paidBreak,
            'unpaid_break' => $unpaidBreak,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'problems' => $problems,
            'broken' => $broken,
            'open' => $open,
            'so_far' => $soFar,
        ];
    }
}
