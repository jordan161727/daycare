<?php

namespace App\Services;

use App\Models\ClosureDay;
use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a week of staff shifts from the rules on each staff record.
 *
 * The shape of the problem: every HARD rule bounds what is legal, every SOFT
 * rule is a preference, and on top of both sits the licensing ratio, which is
 * the only constraint that can force a shift into existence rather than merely
 * restrict one.
 *
 * It solves greedily rather than optimally, and that is deliberate. A director
 * regenerates this several times while nudging rules, so a fast answer they can
 * read and argue with beats a slow one they have to trust. Everything it could
 * not satisfy comes back as a warning instead of being quietly smoothed over —
 * an unexplained gap in a roster reads as a bug and destroys confidence in the
 * whole screen.
 */
class StaffSchedule
{
    private int $open;

    private int $close;

    private int $step;

    /** @var array<string, int> Minutes already assigned this week, by user id. */
    private array $assigned = [];

    /**
     * @var array<string, int> Of those minutes, the ones spent covering another
     *                         room. Tracked so an overtime warning can say why.
     */
    private array $cover = [];

    /** @var array<string, array<string, list<string>>> Soft rules broken, by user then rule type. */
    private array $broken = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private RoomDemand $demand)
    {
        $this->open = (int) config('daycare.open');
        $this->close = (int) config('daycare.close');
        $this->step = (int) config('daycare.coverage_step');
    }

    /**
     * Solve the week and replace whatever was there before.
     *
     * Replacing rather than merging is the point: the previous roster was the
     * answer to the old rules, and keeping any of it would leave a schedule
     * that matches neither rule set.
     */
    public function generate(string $weekStart, ?User $actor = null): StaffScheduleWeek
    {
        $this->assigned = [];
        $this->cover = [];
        $this->broken = [];
        $this->warnings = [];

        $staff = User::teachers()->with('staffRules')->get();

        if ($staff->isEmpty()) {
            $this->warnings[] = 'No teacher records to schedule. Add staff before generating.';
        }

        // Said once, up front. Somebody expecting to see their own name on the
        // chart needs to know why it is missing, and "no employment type" is a
        // two-second fix on their record.
        $unrostered = $staff->filter(fn (User $person) => $person->weeklyHours() <= 0);

        if ($unrostered->isNotEmpty()) {
            $this->warnings[] = $unrostered->count().' staff have no employment type and were left off the roster: '
                .$unrostered->pluck('name')->join(', ', ' and ').'.';
        }

        foreach (RoomDemand::roomsMissingRatios() as $room) {
            $this->warnings[] = "Room \"{$room}\" has no staff ratio in config/daycare.php — it is being skipped, so any shortfall in it will not be reported.";
        }

        $demand = $this->demand->forWeek($weekStart);
        $dates = StaffScheduleWeek::datesOf($weekStart);
        $closed = array_flip(ClosureDay::inWeek($weekStart));

        $noPair = $this->noPairIndex($staff);
        $targets = $staff->mapWithKeys(fn (User $person) => [$person->id => $person->weeklyHours() * 60])->all();

        /** @var list<array> $rows */
        $rows = [];

        foreach ($dates as $day => $date) {
            if (isset($closed[$date->toDateString()])) {
                continue;
            }

            $shifts = $this->shiftsForDay($staff, $day, $targets);

            $this->checkPreferences($shifts, $staff, $day);
            $this->checkPairs($shifts, $staff, $noPair, $day);
            $this->checkSupervision($shifts, $staff, $day);
            $this->checkOpener($shifts, $staff, $day);

            $this->fillRatioGaps($shifts, $staff, $demand[$day] ?? [], $day, $targets);

            foreach ($shifts as $shift) {
                $minutes = $shift['ends_at'] - $shift['starts_at'];

                $this->assigned[$shift['user_id']] = ($this->assigned[$shift['user_id']] ?? 0) + $minutes;

                if ($shift['role'] !== StaffShift::ROLE_STAFF) {
                    $this->cover[$shift['user_id']] = ($this->cover[$shift['user_id']] ?? 0) + $minutes;
                }

                $rows[] = $shift + [
                    'week_start' => $weekStart,
                    'shift_date' => $date->toDateString(),
                    'day' => $day,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        $this->reportPreferences($staff);
        $this->checkHours($staff, $targets);

        return DB::transaction(function () use ($weekStart, $rows, $actor) {
            StaffShift::where('week_start', $weekStart)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                StaffShift::insert($chunk);
            }

            return StaffScheduleWeek::updateOrCreate(
                ['week_start' => $weekStart],
                [
                    'generated_at' => now(),
                    'generated_by' => $actor?->id,
                    'warnings' => array_values(array_unique($this->warnings)),
                ]
            );
        });
    }

    /**
     * Everyone's own shift for one day, before cover is added.
     *
     * @param  array<int, float>  $targets
     * @return list<array>
     */
    private function shiftsForDay(Collection $staff, string $day, array $targets): array
    {
        $shifts = [];

        foreach ($staff as $person) {
            // Nobody is rostered without a contract to roster them against.
            // A record with no employment type is an account, not a shift — a
            // placeholder login, or somebody half set up — and giving them the
            // minimum shift anyway silently doubles a room's cover and hides
            // the shortfall the chart exists to show.
            if ($targets[$person->id] <= 0) {
                continue;
            }

            // Substitutes are cover, not staff. Giving one a shift before a gap
            // exists is how a roster ends up over-staffed on Monday and short
            // on Thursday, so they are left out here and picked up by
            // fillRatioGaps() only where the ratio actually needs them.
            if ($person->isSubstitute()) {
                continue;
            }

            $window = $this->windowFor($person, $day);

            if (! $window) {
                continue;
            }

            $remaining = $targets[$person->id] - ($this->assigned[$person->id] ?? 0);

            $shifts[] = [
                'user_id' => $person->id,
                'starts_at' => $window['start'],
                'ends_at' => $window['start'] + $this->shiftLength($window, $targets[$person->id], $remaining),
                'classroom' => $this->roomFor($person),
                'role' => StaffShift::ROLE_STAFF,
            ];
        }

        return $shifts;
    }

    /**
     * How long a shift should run, given the window and what they are still owed.
     *
     * A fixed shift is taken whole. Otherwise the week's target is spread
     * evenly across the operating days, then clipped to the window and to what
     * is left — spreading is what stops someone burning forty hours by
     * Wednesday and being unavailable when Friday is short.
     */
    private function shiftLength(array $window, float $weekTarget, float $remaining): int
    {
        $available = $window['end'] - $window['start'];

        if ($window['fixed']) {
            return $available;
        }

        $minimum = (int) config('daycare.minimum_shift');
        $perDay = (int) (round(($weekTarget / count(config('daycare.days'))) / 15) * 15);

        $want = min($available, max($minimum, (int) $remaining));

        if ($perDay >= $minimum) {
            $want = min($want, $perDay);
        }

        // Never below the minimum, unless the window itself is shorter — a
        // two-hour availability window is a two-hour shift, not a rule to break.
        return max(min($available, $minimum), min($available, $want));
    }

    /**
     * The legal start and end for one person on one day, from HARD rules only.
     *
     * Returns null when the day is closed to them entirely, either by an
     * UNAVAILABLE_DAY or because the constraints have collapsed the window to
     * nothing.
     */
    public function windowFor(User $person, string $day): ?array
    {
        $rules = $person->staffRules->filter(fn (StaffRule $rule) => $rule->isHard());

        // A PREFERRED_DAY_OFF marked HARD is a director saying the preference
        // is not negotiable after all, so it closes the day exactly as an
        // UNAVAILABLE_DAY does. Left as a preference it only ever gets
        // reported — see checkPreferences().
        $blocked = $rules->contains(
            fn (StaffRule $rule) => in_array($rule->rule_type, ['UNAVAILABLE_DAY', 'PREFERRED_DAY_OFF'], true)
                && $rule->appliesOn($day)
        );

        if ($blocked) {
            return null;
        }

        $start = $this->open;
        $end = $this->close;

        // Only keyholders can be first through the door.
        if (! $person->staffRules->contains(fn (StaffRule $rule) => $rule->rule_type === 'CAN_OPEN')) {
            $start = max($start, (int) config('daycare.default_earliest'));
        }

        foreach ($rules as $rule) {
            if (! $rule->appliesOn($day) && filled($rule->day)) {
                continue;
            }

            match ($rule->rule_type) {
                'AVAILABLE_WINDOW' => [$start, $end] = [max($start, (int) $rule->time_1), min($end, (int) $rule->time_2)],
                'AVAILABLE_AFTER' => $start = max($start, (int) $rule->time_1),
                default => null,
            };
        }

        // A fixed shift replaces the window rather than narrowing it — it is a
        // contract, so it wins even over the centre's own opening time.
        $fixed = $rules->first(fn (StaffRule $rule) => $rule->rule_type === 'FIXED_SHIFT' && $rule->appliesOn($day));

        if ($fixed) {
            [$start, $end] = [(int) $fixed->time_1, (int) $fixed->time_2];
        }

        $fixedEnd = $rules->first(fn (StaffRule $rule) => $rule->rule_type === 'FIXED_END' && $rule->appliesOn($day));

        if ($fixedEnd) {
            $end = (int) $fixedEnd->time_1;
        }

        return $end > $start
            ? ['start' => $start, 'end' => $end, 'fixed' => (bool) $fixed]
            : null;
    }

    /** The room this person works, honouring preference then title. */
    private function roomFor(User $person): string
    {
        $forbidden = $person->staffRules
            ->where('rule_type', 'ROOM_FORBIDDEN')
            ->pluck('value_text')
            ->filter()
            ->all();

        $preferred = $person->staffRules->firstWhere('rule_type', 'ROOM_PREFERENCE')?->value_text;
        $rooms = ClassroomAssignment::rooms();

        foreach ([$preferred, $person->title, $person->classroom] as $candidate) {
            if (filled($candidate) && in_array($candidate, $rooms, true) && ! in_array($candidate, $forbidden, true)) {
                return $candidate;
            }
        }

        return collect($rooms)->reject(fn ($room) => in_array($room, $forbidden, true))->first() ?? $rooms[0];
    }

    /**
     * Add cover wherever a room falls below its ratio.
     *
     * Walks each room in coverage_step slices and, on the first slice that is
     * short, pulls in whoever is free. Anyone brought in shows as a FLOAT or a
     * PATCH so the director can see their own room lost them for an hour.
     *
     * Slices that stay short are merged before being reported: one teacher
     * missing from a room all afternoon is one problem, and printing it once
     * per half hour buries the other five things worth reading.
     *
     * @param  list<array>  $shifts  Modified in place.
     * @param  array<int, float>  $targets
     */
    private function fillRatioGaps(array &$shifts, Collection $staff, array $dayDemand, string $day, array $targets): void
    {
        foreach ($dayDemand as $room => $steps) {
            /** @var list<array> $gaps */
            $gaps = [];

            for ($minute = $this->open; $minute < $this->close; $minute += $this->step) {
                $needed = $this->demand->staffNeeded($steps, $room, $minute);

                if ($needed === 0) {
                    continue;
                }

                $have = collect($shifts)
                    ->where('classroom', $room)
                    ->filter(fn ($shift) => $shift['starts_at'] <= $minute && $shift['ends_at'] > $minute)
                    ->count();

                if ($have >= $needed) {
                    continue;
                }

                $cover = $this->findCover($shifts, $staff, $room, $day, $minute, $targets);

                if ($cover) {
                    $shifts[] = $cover;

                    continue;
                }

                $slice = [
                    'from' => $minute,
                    'to' => $minute + $this->step,
                    'children' => $this->demand->childrenAt($steps, $minute),
                    'needed' => $needed,
                    'have' => $have,
                ];

                $last = $gaps ? $gaps[count($gaps) - 1] : null;

                // Extend the run only while it stays the same shortfall — a
                // room going from one short to two short is a new fact.
                if ($last && $last['to'] === $minute
                    && $last['needed'] === $needed
                    && $last['have'] === $have
                    && $last['children'] === $slice['children']) {
                    $gaps[count($gaps) - 1]['to'] = $slice['to'];

                    continue;
                }

                $gaps[] = $slice;
            }

            foreach ($gaps as $gap) {
                $this->warnings[] = sprintf(
                    '%s %s, %s–%s: %d %s need %d staff, %s scheduled — short by %d.',
                    $day,
                    $room,
                    StaffRule::formatTime($gap['from']),
                    StaffRule::formatTime($gap['to']),
                    $gap['children'],
                    $gap['children'] === 1 ? 'child' : 'children',
                    $gap['needed'],
                    $gap['have'] === 0 ? 'none' : $gap['have'],
                    $gap['needed'] - $gap['have']
                );
            }
        }
    }

    /**
     * Whoever can legally stand in that room at that minute.
     *
     * Ordered by hours left first, then substitutes, then by who has most
     * outstanding. Hours before employment type because pulling a full-timer
     * who is already at forty is how a roster quietly books overtime; a
     * substitute with nothing left is no cheaper than anyone else.
     *
     * Somebody already at their target is still used when nobody else can
     * stand there. A ratio breach costs the licence and an hour of overtime
     * does not, so the shift is filled and checkHours() reports the cost.
     *
     * @param  array<int, float>  $targets
     */
    private function findCover(array $shifts, Collection $staff, string $room, string $day, int $minute, array $targets): ?array
    {
        $booked = collect($shifts)
            ->groupBy('user_id')
            ->map(fn ($theirs) => collect($theirs)->sum(fn ($shift) => $shift['ends_at'] - $shift['starts_at']));

        // Measured against the day's share of the week, not the week itself.
        // The weekly remainder is useless here: on Monday nothing but Monday
        // has been placed, so every full-timer looks like they have thirty-two
        // spare hours and picks up cover they will owe back on Friday. A day's
        // share is the same figure shiftLength() builds the base shift from,
        // so anything beyond it is overtime by definition.
        $perDay = fn (User $person) => ($targets[$person->id] ?? 0) / max(1, count(config('daycare.days')));

        $remaining = fn (User $person) => $perDay($person) - ($booked[$person->id] ?? 0);

        $candidates = $staff->sortBy([
            fn (User $a, User $b) => ($remaining($b) > 0 ? 1 : 0) <=> ($remaining($a) > 0 ? 1 : 0),
            fn (User $a, User $b) => ($a->isSubstitute() ? 0 : 1) <=> ($b->isSubstitute() ? 0 : 1),
            fn (User $a, User $b) => $remaining($b) <=> $remaining($a),
        ]);

        foreach ($candidates as $person) {
            $forbidden = $person->staffRules
                ->where('rule_type', 'ROOM_FORBIDDEN')
                ->contains(fn (StaffRule $rule) => $rule->value_text === $room);

            if ($forbidden) {
                continue;
            }

            // Already working somewhere at this minute — moving them would only
            // move the gap.
            $busy = collect($shifts)->contains(
                fn ($shift) => $shift['user_id'] === $person->id
                    && $shift['starts_at'] <= $minute
                    && $shift['ends_at'] > $minute
            );

            if ($busy) {
                continue;
            }

            // Same rule as the base roster: no contract, no shift. Cover is
            // still work, and pulling in a placeholder account to close a gap
            // would report the gap as solved by nobody.
            if (($targets[$person->id] ?? 0) <= 0) {
                continue;
            }

            $window = $this->windowFor($person, $day);

            if (! $window || $window['start'] > $minute || $window['end'] <= $minute) {
                continue;
            }

            // Being free *now* is not enough — the cover shift has a length, and
            // it must end before whatever they are already down for later that
            // day. Without this, somebody free at noon picks up a three-hour
            // patch that runs straight through the shift they start at two, and
            // the chart shows one person in two rooms at once.
            $nextStart = collect($shifts)
                ->where('user_id', $person->id)
                ->where('starts_at', '>', $minute)
                ->min('starts_at');

            $latest = $nextStart === null
                ? $window['end']
                : min($window['end'], $nextStart);

            // Too small a window to be worth anything: it would not close the
            // slice that asked for cover, and the next pass would ask again.
            if ($latest - $minute < $this->step) {
                continue;
            }

            // Trim the cover shift to the hours they have left, so a three-hour
            // patch handed to somebody with one hour outstanding does not book
            // two hours of overtime to close a half-hour gap. Never below one
            // slice, for the same reason as above.
            $left = (int) $remaining($person);
            $length = (int) config('daycare.patch_length');

            if ($left > 0) {
                $length = max($this->step, min($length, $left));
            }

            return [
                'user_id' => $person->id,
                'starts_at' => $minute,
                'ends_at' => min($latest, $minute + $length),
                'classroom' => $room,
                'role' => $person->isSubstitute() ? StaffShift::ROLE_PATCH : StaffShift::ROLE_FLOAT,
            ];
        }

        return null;
    }

    /**
     * NO_PAIR as a symmetric lookup.
     *
     * The rule is entered on one person but means something about both, so it
     * is indexed both ways — otherwise which of the two the director happened
     * to open would decide whether the rule was enforced.
     *
     * @return array<int, list<int>>
     */
    private function noPairIndex(Collection $staff): array
    {
        $byName = $staff->keyBy('name');
        $index = [];

        foreach ($staff as $person) {
            foreach ($person->staffRules->where('rule_type', 'NO_PAIR') as $rule) {
                $other = $byName->get($rule->value_text);

                if (! $other) {
                    $this->warnings[] = "{$person->name} has a NO_PAIR rule naming \"{$rule->value_text}\", who is not on staff. The rule is being ignored.";

                    continue;
                }

                $index[$person->id][] = $other->id;
                $index[$other->id][] = $person->id;
            }
        }

        return $index;
    }

    /**
     * Note the soft preferences this day broke.
     *
     * Collected rather than reported here, then summarised once per person at
     * the end of the week. A preference broken on all five days is one thing a
     * director wants to know, not five lines pushing the ratio gaps off screen.
     *
     * This is the entire practical difference between SOFT and HARD: a hard
     * rule bounds the search, a soft one is honoured where it can be and
     * accounted for where it cannot. A soft rule that produced no output at all
     * would be a preference the director believes was considered.
     */
    private function checkPreferences(array $shifts, Collection $staff, string $day): void
    {
        foreach ($staff as $person) {
            $mine = collect($shifts)->where('user_id', $person->id);

            if ($mine->isEmpty()) {
                continue;
            }

            $soft = $person->staffRules->reject(fn (StaffRule $rule) => $rule->isHard());

            foreach ($soft as $rule) {
                if ($rule->rule_type === 'PREFERRED_DAY_OFF' && $rule->appliesOn($day)) {
                    $this->broken[$person->id]['PREFERRED_DAY_OFF'][] = $day;
                }

                if ($rule->rule_type === 'PREFERRED_START' && $rule->time_1 !== null
                    && $mine->min('starts_at') !== (int) $rule->time_1) {
                    $this->broken[$person->id]['PREFERRED_START'][] = $day;
                }
            }
        }
    }

    /** Summarise the week's broken preferences, one line per person per rule. */
    private function reportPreferences(Collection $staff): void
    {
        foreach ($staff as $person) {
            foreach ($this->broken[$person->id] ?? [] as $type => $days) {
                $when = count($days) === count(config('daycare.days'))
                    ? 'every day'
                    : implode(', ', $days);

                $this->warnings[] = match ($type) {
                    'PREFERRED_DAY_OFF' => "{$person->name} prefers ".implode(', ', array_unique($days))." off but is scheduled then. Mark the rule HARD to enforce it.",
                    'PREFERRED_START' => "{$person->name}'s preferred start time was not met on {$when}.",
                    default => "{$person->name}: {$type} not honoured on {$when}.",
                };
            }
        }
    }

    private function checkPairs(array $shifts, Collection $staff, array $noPair, string $day): void
    {
        $names = $staff->pluck('name', 'id');

        foreach ($shifts as $i => $a) {
            foreach (array_slice($shifts, $i + 1) as $b) {
                $conflicts = in_array($b['user_id'], $noPair[$a['user_id']] ?? [], true);
                $together = $a['starts_at'] < $b['ends_at'] && $b['starts_at'] < $a['ends_at'];

                if ($conflicts && $together) {
                    $this->warnings[] = "{$day}: {$names[$a['user_id']]} and {$names[$b['user_id']]} overlap but are marked NO_PAIR.";
                }
            }
        }
    }

    /** Nobody who needs supervision may be the only person on site. */
    private function checkSupervision(array $shifts, Collection $staff, string $day): void
    {
        $needs = $staff->filter(
            fn (User $person) => $person->staffRules->contains(fn (StaffRule $rule) => $rule->rule_type === 'NEEDS_SUPERVISION')
        )->keyBy('id');

        foreach ($shifts as $shift) {
            if (! $needs->has($shift['user_id'])) {
                continue;
            }

            $alone = ! collect($shifts)->contains(
                fn ($other) => $other['user_id'] !== $shift['user_id']
                    && $other['starts_at'] < $shift['ends_at']
                    && $shift['starts_at'] < $other['ends_at']
            );

            if ($alone) {
                $this->warnings[] = "{$day}: {$needs[$shift['user_id']]->name} needs supervision but is scheduled with nobody else present.";
            }
        }
    }

    /** Someone has to unlock the door. */
    private function checkOpener(array $shifts, Collection $staff, string $day): void
    {
        $keyholders = $staff->filter(
            fn (User $person) => $person->staffRules->contains(fn (StaffRule $rule) => $rule->rule_type === 'CAN_OPEN')
        )->pluck('id')->flip();

        $covered = collect($shifts)->contains(
            fn ($shift) => $keyholders->has($shift['user_id']) && $shift['starts_at'] <= $this->open
        );

        if (! $covered) {
            $this->warnings[] = "{$day}: no keyholder is scheduled at opening (".StaffRule::formatTime($this->open).').';
        }
    }

    /**
     * Report anyone the week left short of, or over, their contracted hours.
     *
     * A quarter-hour of slack either way: reporting someone 5 minutes under
     * their forty would bury the person who is genuinely eight hours short.
     */
    private function checkHours(Collection $staff, array $targets): void
    {
        foreach ($staff as $person) {
            $target = $targets[$person->id];

            if ($target <= 0) {
                continue;
            }

            $got = $this->assigned[$person->id] ?? 0;

            if ($got + 15 < $target) {
                $this->warnings[] = sprintf(
                    '%s: scheduled %sh of a %sh target.',
                    $person->name, round($got / 60, 1), round($target / 60, 1)
                );
            }

            if ($got > $target + 60) {
                $cover = $this->cover[$person->id] ?? 0;

                // Naming the cover is the difference between a number that
                // reads as a bug and one that reads as a decision: the hours
                // are overtime the centre chose over a ratio breach, and the
                // fix is another pair of hands, not a smaller roster.
                $this->warnings[] = sprintf(
                    '%s: %sh against a %sh target%s.',
                    $person->name,
                    round($got / 60, 1),
                    round($target / 60, 1),
                    $cover > 0 ? sprintf(' — %sh of it covering other rooms', round($cover / 60, 1)) : ''
                );
            }
        }
    }

    /**
     * Coverage as have-versus-need bands, for the room view.
     *
     * Recomputed from the saved shifts rather than kept from the solve, so the
     * bar on screen always describes the roster actually stored — including
     * after a director edits a shift by hand.
     */
    public function coverage(string $weekStart): array
    {
        $demand = $this->demand->forWeek($weekStart);
        $shifts = StaffShift::where('week_start', $weekStart)->get();
        $coverage = [];

        foreach ($demand as $day => $rooms) {
            foreach ($rooms as $room => $steps) {
                $bands = [];
                $current = null;

                for ($minute = $this->open; $minute < $this->close; $minute += $this->step) {
                    $have = $shifts->filter(
                        fn (StaffShift $shift) => $shift->day === $day
                            && $shift->classroom === $room
                            && $shift->covers($minute)
                    )->count();

                    $need = $this->demand->staffNeeded($steps, $room, $minute);

                    if ($current && $current['have'] === $have && $current['need'] === $need) {
                        $current['to'] = $minute + $this->step;

                        continue;
                    }

                    if ($current) {
                        $bands[] = $current;
                    }

                    $current = ['from' => $minute, 'to' => $minute + $this->step, 'have' => $have, 'need' => $need];
                }

                if ($current) {
                    $bands[] = $current;
                }

                $coverage[$day][$room] = $bands;
            }
        }

        return $coverage;
    }
}
