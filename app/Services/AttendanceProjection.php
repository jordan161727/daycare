<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a week is expected to look like before it happens.
 *
 * The schedule (WeekSchedule) is the director's plan — what they ticked. This
 * is the forecast the plan can be checked against: who is likely to be here,
 * on which days, for how many hours. The two are deliberately separate. A
 * projection that quietly rewrote the ticks would turn one sick day into a
 * child's new schedule, which is exactly what the schedule refuses to do.
 *
 * Nothing here is stored. Every read recomputes from the child records, the
 * enrolment dates, the closures and last week's sign-ins, so a profile edited
 * this morning is in the projection this afternoon with nothing to rebuild.
 *
 * Three inputs, in the order they bite:
 *
 *  1. **Last week's actual attendance** decides *which days*. A child who came
 *     Monday, Wednesday and Friday is expected Monday, Wednesday and Friday.
 *  2. **Enrolment dates** and closures decide whether a day can be expected at
 *     all — nobody is projected before they start, after they leave, or onto a
 *     day the centre is shut.
 *  3. **Expected hours** decide *how much* is owed, and so what the projected
 *     days are worth measuring against. They deliberately do not add or remove
 *     days: which days is a matter of evidence, and inventing a Tuesday to make
 *     the hours add up would put a child in a room nobody staffed for them.
 *     The gap is reported instead, which is the exception list a director wants.
 */
class AttendanceProjection
{
    /**
     * The centre's day, in hours. A half-day session is worth half of it, so a
     * School Age child in both sessions comes to the same full day as everyone
     * else.
     */
    public const FULL_DAY_HOURS = 9.0;

    /** Where a child's projected days came from, weakest last. */
    public const BASIS_LABELS = [
        'attendance' => "Last week's attendance",
        'schedule' => 'This week\'s ticked days',
        'contract' => 'Expected hours only — no pattern yet',
        'none' => 'Nothing expected',
    ];

    public static function hoursFor(string $session): float
    {
        return $session === 'FULL' ? self::FULL_DAY_HOURS : self::FULL_DAY_HOURS / 2;
    }

    public static function basisLabel(string $basis): string
    {
        return self::BASIS_LABELS[$basis] ?? self::BASIS_LABELS['none'];
    }

    /**
     * Project one Mon–Fri week.
     *
     * Pass $children to limit the projection to what the reader may see — a
     * teacher's own rooms — so the totals on their screen add up to their
     * roster rather than the centre's.
     *
     * @return array{
     *     week_start: string,
     *     source_week_start: string,
     *     expected: array<int, array<string, array<string, bool>>>,
     *     children: array<int, array<string, mixed>>,
     *     day_totals: array<string, int>,
     *     totals: array<string, float|int>
     * }
     */
    public function forWeek(string $weekStart, ?Collection $children = null): array
    {
        $dates = ScheduleWeek::datesOf($weekStart);
        $sourceWeekStart = Carbon::parse($weekStart)->subWeek()->toDateString();

        $children ??= Child::where('status', 'Active')->orderBy('last_name')->orderBy('first_name')->get();

        $prior = $this->priorAttendance($sourceWeekStart, $children);
        $noEvidence = $this->daysWithNothingToGoOn($sourceWeekStart);
        $ticked = $this->tickedDays($weekStart, $children);
        $closed = array_flip(ClosureDay::inWeek($weekStart));

        $expected = [];
        $summaries = [];
        $dayTotals = array_fill_keys(array_map(fn ($date) => $date->toDateString(), $dates), 0);

        foreach ($children as $child) {
            $contract = $child->expected_hours_per_week;
            $basis = $this->basisFor($child, $prior, $ticked, $contract);
            $hours = 0.0;
            $sessionCount = 0;

            foreach ($dates as $offset => $date) {
                $iso = $date->toDateString();

                // Enrolment dates and closures decide whether the day exists for
                // this child at all, whatever last week says.
                if (! $child->isEnrolledOn($iso) || isset($closed[$iso])) {
                    continue;
                }

                $counted = false;

                foreach ($child->sessions() as $session) {
                    if (! $this->expects($child, $basis, $session, $offset, $iso, $prior, $noEvidence, $ticked)) {
                        continue;
                    }

                    $expected[$child->id][$iso][$session] = true;
                    $hours += self::hoursFor($session);
                    $sessionCount++;
                    $counted = true;
                }

                // A child counts once towards the day's head count however many
                // sessions they are in — it is a body in a room, not a booking.
                $dayTotals[$iso] += (int) $counted;
            }

            $summaries[$child->id] = [
                'basis' => $basis,
                'sessions' => $sessionCount,
                'days' => count($expected[$child->id] ?? []),
                'projected_hours' => round($hours, 2),
                'contract_hours' => $contract,
                // Positive means the projection runs over what was contracted,
                // negative means it falls short. Null when nobody has said.
                'variance' => $contract === null ? null : round($hours - $contract, 2),
            ];
        }

        return [
            'week_start' => $weekStart,
            'source_week_start' => $sourceWeekStart,
            'expected' => $expected,
            'children' => $summaries,
            'day_totals' => $dayTotals,
            'totals' => [
                'children' => collect($summaries)->filter(fn ($row) => $row['sessions'] > 0)->count(),
                'sessions' => collect($summaries)->sum('sessions'),
                'projected_hours' => round(collect($summaries)->sum('projected_hours'), 2),
                'contract_hours' => round(collect($summaries)->sum(fn ($row) => $row['contract_hours'] ?? 0), 2),
                // Children whose hours are on file but whose days are not — the
                // ones a director has to set a pattern for by hand.
                'without_pattern' => collect($summaries)->where('basis', 'contract')->count(),
            ],
        ];
    }

    /**
     * Which of the three inputs is deciding this child's days.
     *
     * Attendance first, because it is what actually happened. Failing that the
     * week's own ticks, which covers a child enrolled since last week and one
     * who was away for all of it. Failing both, the contract is all that is
     * left, and it says how many hours without saying which days — so it names
     * the child for the director rather than guessing a pattern.
     */
    private function basisFor(Child $child, array $prior, array $ticked, ?float $contract): string
    {
        // Contracted for nothing is a statement, not a blank: the child is on the
        // roster but not coming, so last week's attendance does not resurrect them.
        if ($contract !== null && $contract <= 0) {
            return 'none';
        }

        if (isset($prior[$child->id])) {
            return 'attendance';
        }

        if (isset($ticked[$child->id])) {
            return 'schedule';
        }

        return $contract > 0 ? 'contract' : 'none';
    }

    /** Whether one session on one day is expected. */
    private function expects(Child $child, string $basis, string $session, int $offset, string $iso, array $prior, array $noEvidence, array $ticked): bool
    {
        if ($basis === 'none' || $basis === 'contract') {
            return false;
        }

        // A weekday last week that says nothing either way falls back to whatever
        // has been ticked for the same day here.
        if ($basis === 'schedule' || isset($noEvidence[$offset])) {
            return ($ticked[$child->id][$iso][$session] ?? false) === true;
        }

        return $this->attendedPrior($prior, $child->id, $offset, $session);
    }

    /**
     * Weekdays of the source week that are no evidence of anything, as offsets
     * from its Monday.
     *
     * Two of them, for the same reason. A day the centre was **shut** has no
     * absences to read — take it at face value and one snow day on a Thursday
     * erases every Thursday after it. And a day that **has not happened yet** is
     * the same: planning next week on a Wednesday would otherwise count this
     * Thursday and Friday as everybody staying home.
     *
     * Today counts as not yet happened. Children arrive through the morning, so
     * a sheet read at nine o'clock is not a record of who came.
     */
    private function daysWithNothingToGoOn(string $sourceWeekStart): array
    {
        $monday = Carbon::parse($sourceWeekStart);
        $today = Carbon::today();
        $offsets = [];

        foreach (ScheduleWeek::datesOf($sourceWeekStart) as $offset => $date) {
            if ($date->gte($today)) {
                $offsets[$offset] = true;
            }
        }

        foreach (ClosureDay::inWeek($sourceWeekStart) as $date) {
            $offsets[(int) $monday->diffInDays($date, false)] = true;
        }

        return $offsets;
    }

    /**
     * Whether the child was signed in on the same weekday last week.
     *
     * A full-day stamp covers both halves, and any attendance at all covers a
     * full day — which is what makes the answer survive a child moving in or
     * out of School Age between the two weeks.
     */
    private function attendedPrior(array $prior, int $childId, int $offset, string $session): bool
    {
        $sessions = $prior[$childId][$offset] ?? [];

        return isset($sessions['FULL'])
            || isset($sessions[$session])
            || ($session === 'FULL' && $sessions !== []);
    }

    /** [child_id][weekday offset][session] => true, for the week before. */
    private function priorAttendance(string $sourceWeekStart, Collection $children): array
    {
        $monday = Carbon::parse($sourceWeekStart);
        $dates = ScheduleWeek::datesOf($sourceWeekStart);

        $records = Attendance::whereBetween('attendance_date', [$dates[0]->toDateString(), $dates[4]->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get();

        $prior = [];

        foreach ($records as $record) {
            $offset = (int) $monday->diffInDays($record->attendance_date, false);

            if ($offset >= 0 && $offset <= 4) {
                $prior[$record->child_id][$offset][$record->session ?: 'FULL'] = true;
            }
        }

        return $prior;
    }

    /** [child_id][date][session] => true for the days ticked in the week itself. */
    private function tickedDays(string $weekStart, Collection $children): array
    {
        $ticked = [];

        $slots = ScheduleSlot::where('week_start', $weekStart)
            ->where('is_scheduled', true)
            ->whereIn('child_id', $children->pluck('id'))
            ->get();

        foreach ($slots as $slot) {
            $ticked[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] = true;
        }

        return $ticked;
    }
}
