<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weeks copy forward. Opening a week for the first time snapshots the scheduled
 * pattern of the newest week before it; from then on the two are independent.
 *
 * Only the pattern travels — never who actually attended, or one sick day would
 * quietly become a child's new schedule.
 */
class WeekSchedule
{


    /**
     * Return the week, building it the first time it is opened.
     *
     * Opening copies the week before it forward. That is the whole of how a
     * week gets built — there is one button, and it brings last week's shape
     * with it, because a centre's weeks are the same week over and over with
     * exceptions, and typing the exceptions is less work than typing the rule.
     *
     * What travels is the pattern: every day ticked in the source week, and
     * every day somebody actually arrived on. A drop-in counts — see
     * patternOf() — and a child the source week says nothing about falls back
     * to the days on their record, which is how somebody enrolled last
     * Thursday arrives with a week already shaped.
     *
     * What never travels is the attendance itself. Next week is a plan; who
     * was here is a fact about the week it happened in.
     */
    public function open(string $weekStart, ?User $user = null): ScheduleWeek
    {
        /*
         * A week is opened room by room, by whoever holds those rooms.
         *
         * It used to be one centre-wide act: the first person to look at a week
         * built it for every child in the building. That made the Infant
         * teacher's schedule something the Toddler teacher created by opening a
         * page — days ticked, or not ticked, by somebody who cannot see them
         * and did not decide them.
         *
         * Now opening builds only the opener's own rooms, and a week reads as
         * open to somebody when their own children have boxes in it. A teacher
         * whose rooms are not in a week still sees "not set up", with the
         * button to build their part of it, whoever else has been in already.
         *
         * An admin holds the whole centre, so for them this is unchanged.
         */
        $childIds = $this->childIdsFor($user);
        $existing = ScheduleWeek::firstWhere('week_start', $weekStart);

        if ($existing) {
            // Somebody else built this week first and this opener's rooms have
            // no boxes in it. They get the same copy-forward the first opener
            // got, rather than a blank week nobody chose — addMissingChildren
            // would hand them empty boxes and lose the pattern.
            if (! $this->hasSlotsFor($weekStart, $childIds)) {
                $this->fill(
                    $weekStart,
                    $existing->copied_from_week_start?->toDateString() ?? $this->sourceFor($weekStart),
                    false,
                    $childIds
                );
            } else {
                $this->addMissingChildren($weekStart, $childIds);
            }

            $this->pruneUnenrolled($weekStart);

            return $existing;
        }

        return DB::transaction(function () use ($weekStart, $user, $childIds) {
            // Another teacher may have opened the same week a moment ago.
            $week = ScheduleWeek::lockForUpdate()->firstWhere('week_start', $weekStart);

            if ($week) {
                // Their rooms, into the week that appeared underneath us.
                $this->fill($weekStart, $week->copied_from_week_start?->toDateString(), false, $childIds);

                return $week;
            }

            $source = $this->sourceFor($weekStart);

            $week = ScheduleWeek::create([
                'week_start' => $weekStart,
                'copied_from_week_start' => $source,
                'created_by' => $user?->id,
            ]);

            $this->fill($weekStart, $source, false, $childIds);

            return $week;
        });
    }

    /** Whether a given set of children has any box at all in a week. */
    private function hasSlotsFor(string $weekStart, ?array $childIds): bool
    {
        return ScheduleSlot::where('week_start', $weekStart)
            ->when($childIds !== null, fn ($query) => $query->whereIn('child_id', $childIds))
            ->exists();
    }

    /**
     * Whether this week is built as far as this person is concerned.
     *
     * Judged on their own children having boxes, not on the week's row
     * existing: the row is centre-wide and says only that somebody has been
     * here, which is not the same as this reader having a sheet to work from.
     */
    public function isOpenFor(string $weekStart, ?User $user): bool
    {
        return $this->hasSlotsFor($weekStart, $this->childIdsFor($user));
    }


    /**
     * The children a given person's bulk action may touch, or null for all.
     *
     * Null rather than "every id" on purpose: it is the difference between a
     * query with no constraint and one carrying a list of every child in the
     * centre, and it keeps the centre-wide case obviously centre-wide.
     *
     * @return array<int, int>|null
     */
    private function childIdsFor(?User $user): ?array
    {
        if ($user === null || $user->isAdmin()) {
            return null;
        }

        return Child::visibleTo($user)->pluck('id')->all();
    }

    /**
     * Has this week been built yet?
     *
     * Asked before open(), so a screen can show a week as it really is —
     * untouched — instead of creating it just by being looked at.
     */
    public function isOpen(string $weekStart): bool
    {
        return ScheduleWeek::where('week_start', $weekStart)->exists();
    }

    /** The newest week already built before this one, or null for the very first week. */
    public function sourceFor(string $weekStart): ?string
    {
        return ScheduleWeek::where('week_start', '<', $weekStart)
            ->orderByDesc('week_start')
            ->value('week_start')
            ?->toDateString();
    }

    /**
     * A week is frozen once its Friday has passed. What happened, happened —
     * the schedule of a finished week is a record, not a plan, and DSS bills
     * against it.
     */
    public function isFrozen(string $weekStart): bool
    {
        return Carbon::parse($weekStart)->addDays(4)->endOfDay()->isPast();
    }

    /**
     * Shut the centre for a day, or open it again.
     *
     * Closing grays out every box on that date in one go. Sign-ins already
     * recorded are left alone — if a child was here, that is still true, and it
     * is still billable.
     *
     * The ticks it takes off are written onto the closure first, so reopening
     * the day puts back exactly the day that was called off rather than an
     * empty column. Returns the number of boxes moved, either way.
     */
    public function setClosure(string $date, bool $closed, ?string $reason = null, ?User $user = null, ?int $ruleId = null): int
    {
        return DB::transaction(function () use ($date, $closed, $reason, $user, $ruleId) {
            if (! $closed) {
                return $this->reopen($date);
            }

            $cleared = ScheduleSlot::where('slot_date', $date)
                ->where('is_scheduled', true)
                ->get(['child_id', 'session']);

            ClosureDay::updateOrCreate(
                ['closed_on' => $date],
                [
                    'reason' => $reason,
                    'created_by' => $user?->id,
                    'holiday_rule_id' => $ruleId,
                    // Read before the update below, or this records the state
                    // the clearing left behind — which is nothing.
                    'cleared_slots' => $cleared->map(fn ($slot) => [
                        'child_id' => $slot->child_id,
                        'session' => $slot->session,
                    ])->all(),
                ],
            );

            return ScheduleSlot::where('slot_date', $date)
                ->where('is_scheduled', true)
                ->update(['is_scheduled' => false]);
        });
    }

    /**
     * Open a closed day back up, re-ticking what the closure took off.
     *
     * Only boxes that still exist and still match are restored. A child who has
     * left, or whose room now splits the day into AM and PM, has no box to put
     * a tick back into — so the count returned is what was actually restored,
     * not what was once cleared.
     */
    private function reopen(string $date): int
    {
        $closure = ClosureDay::firstWhere('closed_on', $date);

        if (! $closure) {
            return 0;
        }

        $restored = 0;

        foreach (collect($closure->cleared_slots ?? [])->groupBy('session') as $session => $rows) {
            $restored += ScheduleSlot::where('slot_date', $date)
                ->where('session', $session)
                ->whereIn('child_id', collect($rows)->pluck('child_id'))
                ->update(['is_scheduled' => true]);
        }

        $closure->delete();

        return $restored;
    }

    /**
     * Reshape a child's boxes after they change room.
     *
     * Most rooms sign in once for the day; School Age splits into AM and PM. A
     * move across that line leaves the wrong boxes behind — a stale full-day box
     * sitting beside a new AM/PM pair — so the week has to be brought into line
     * with the room.
     *
     * The day's tick survives the reshape: the director said the child comes on
     * Tuesday, and splitting Tuesday into two halves is not them changing their
     * mind. Finished weeks and days already signed in are left exactly as they
     * are, as everywhere else.
     */
    public function resyncSessions(Child $child): void
    {
        $sessions = $child->sessions();
        $slots = ScheduleSlot::where('child_id', $child->id)->get();

        $weeks = $slots->pluck('week_start')
            ->map(fn ($week) => $week->toDateString())
            ->unique()
            ->reject(fn ($week) => $this->isFrozen($week));

        if ($weeks->isEmpty()) {
            return;
        }

        $live = $slots->filter(fn ($slot) => $weeks->contains($slot->week_start->toDateString()));

        if ($live->every(fn ($slot) => in_array($slot->session, $sessions, true))
            && $live->groupBy(fn ($slot) => $slot->slot_date->toDateString())->every(fn ($day) => $day->count() === count($sessions))) {
            return;
        }

        $scheduled = $live->filter->is_scheduled
            ->map(fn ($slot) => $slot->slot_date->toDateString())
            ->flip();

        $signedIn = Attendance::where('child_id', $child->id)
            ->get()
            ->map(fn ($record) => $record->attendance_date->toDateString().'|'.$record->session)
            ->flip();

        $stale = $live->filter(fn ($slot) => ! in_array($slot->session, $sessions, true)
            && ! $signedIn->has($slot->slot_date->toDateString().'|'.$slot->session));

        if ($stale->isNotEmpty()) {
            ScheduleSlot::whereIn('id', $stale->pluck('id'))->delete();
        }

        $closed = $weeks->flatMap(fn ($week) => ClosureDay::inWeek($week))->flip();
        $have = $live->diff($stale)->map(fn ($slot) => $slot->slot_date->toDateString().'|'.$slot->session)->flip();
        $now = now();
        $rows = [];

        foreach ($weeks as $week) {
            foreach (ScheduleWeek::datesOf($week) as $date) {
                $slotDate = $date->toDateString();

                if (! $child->isEnrolledOn($slotDate)) {
                    continue;
                }

                foreach ($sessions as $session) {
                    if ($have->has($slotDate.'|'.$session)) {
                        continue;
                    }

                    $rows[] = [
                        'week_start' => $week,
                        'child_id' => $child->id,
                        'slot_date' => $slotDate,
                        'session' => $session,
                        'is_scheduled' => $scheduled->has($slotDate) && ! $closed->has($slotDate),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ScheduleSlot::upsert($chunk, ['child_id', 'slot_date', 'session'], ['week_start', 'is_scheduled', 'updated_at']);
        }
    }

    /** Days ticked in a week — what the teacher counts when they look at the grid. */
    /**
     * Days ticked in a week, optionally only for a given set of children.
     *
     * The scoped count is what a copy reports back: a teacher told "8 days
     * added" when four of them were another room's is being told about work
     * they did not do and cannot see.
     *
     * @param  array<int, int>|null  $childIds  null counts the whole centre
     */
    public function tickedIn(string $weekStart, ?array $childIds = null): int
    {
        return ScheduleSlot::where('week_start', $weekStart)
            ->where('is_scheduled', true)
            ->when($childIds !== null, fn ($query) => $query->whereIn('child_id', $childIds))
            ->count();
    }


    /**
     * Write one slot row per child, per weekday, per session.
     *
     * A child outside their enrolment window gets no row at all — that is the
     * "not enrolled" state, and it is why the grid can show an empty box rather
     * than pretending the child was simply unscheduled.
     */
    private function fill(string $weekStart, ?string $sourceWeekStart, bool $keepExisting = false, ?array $childIds = null): void
    {
        $children = Child::where('status', 'Active')
            ->when($childIds !== null, fn ($query) => $query->whereIn('id', $childIds))
            ->get();
        // Ticks and arrivals both: see patternOf().
        $pattern = $sourceWeekStart ? $this->patternOf($sourceWeekStart, attendedCountsAsScheduled: true) : [];
        // Read before writing: these are the ticks an "add" must not lose.
        $existing = $keepExisting ? $this->patternOf($weekStart) : [];
        // A holiday here must not inherit a normal week's ticks. Closure is a
        // fact about this date, so it wins over whatever the pattern says.
        $closed = ClosureDay::inWeek($weekStart);
        $now = now();
        $rows = [];

        foreach ($children as $child) {
            foreach (ScheduleWeek::datesOf($weekStart) as $offset => $date) {
                $slotDate = $date->toDateString();

                if (! $child->isEnrolledOn($slotDate)) {
                    continue;
                }

                foreach ($child->sessions() as $session) {
                    $rows[] = [
                        'week_start' => $weekStart,
                        'child_id' => $child->id,
                        'slot_date' => $slotDate,
                        'session' => $session,
                        // Same weekday of the source week, and — only for a child
                        // the source week says nothing about — the days they are
                        // registered for.
                        //
                        // That fallback is the whole point of the registered
                        // pattern. A child enrolled last Thursday has nothing to
                        // inherit, and used to arrive with a blank week that
                        // somebody had to notice and tick by hand; a record
                        // saying Mon/Wed/Fri has already asked for those days.
                        //
                        // Per child, not per week, and that distinction is load
                        // bearing: a child who IS in the source week carries it
                        // forward untouched, including a week somebody
                        // deliberately cleared. The registration seeds a child's
                        // first week; it never overrules a later decision.
                        //
                        // A child nobody has answered the question for still
                        // starts unticked — scheduleDays() is null then, and a
                        // day nobody has asked for is never silently scheduled.
                        'is_scheduled' => ! in_array($slotDate, $closed, true)
                            && (($pattern[$child->id][$offset][$session] ?? false)
                                || ($existing[$child->id][$offset][$session] ?? false)
                                || (! isset($pattern[$child->id]) && $child->attendsOn($date))),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ScheduleSlot::upsert($chunk, ['child_id', 'slot_date', 'session'], ['week_start', 'is_scheduled', 'updated_at']);
        }
    }

    /**
     * Give every enrolled day a box it is missing.
     *
     * Covers two cases with one pass: a child enrolled after the week was opened,
     * who would otherwise have no boxes and read as "not enrolled"; and a child
     * whose leaving date moved back out again, whose later days were pruned and
     * now belong to them once more.
     *
     * A new box follows the days the child is registered for, and is unticked
     * when their record does not say. A day nobody has asked for is still never
     * silently scheduled — but a day agreed at registration has been asked for,
     * and making somebody re-tick it in every open week was the gap this fills.
     *
     * A closed day is never ticked whatever the registration says: the closure
     * is a fact about that date and the pattern is only an arrangement.
     *
     * Only missing boxes are added, so a day deliberately unticked stays
     * unticked — this can put a box back but never re-tick one.
     *
     * A finished week is left alone, the same as when enrolment shrinks. Without
     * that, adding a child today puts boxes into weeks that ended before they
     * were on the roster — writing into the record DSS bills against.
     */
    private function addMissingChildren(string $weekStart, ?array $childIds = null): void
    {
        if ($this->isFrozen($weekStart)) {
            return;
        }

        $existing = ScheduleSlot::where('week_start', $weekStart)
            ->get()
            ->map(fn ($slot) => $slot->child_id.'|'.$slot->slot_date->toDateString().'|'.$slot->session)
            ->flip();

        $now = now();
        $rows = [];
        $closed = ClosureDay::inWeek($weekStart);

        $roll = Child::where('status', 'Active')
            ->when($childIds !== null, fn ($query) => $query->whereIn('id', $childIds))
            ->get();

        foreach ($roll as $child) {
            foreach (ScheduleWeek::datesOf($weekStart) as $date) {
                $slotDate = $date->toDateString();

                if (! $child->isEnrolledOn($slotDate)) {
                    continue;
                }

                foreach ($child->sessions() as $session) {
                    if ($existing->has($child->id.'|'.$slotDate.'|'.$session)) {
                        continue;
                    }

                    $rows[] = [
                        'week_start' => $weekStart,
                        'child_id' => $child->id,
                        'slot_date' => $slotDate,
                        'session' => $session,
                        'is_scheduled' => ! in_array($slotDate, $closed, true) && $child->attendsOn($date),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ScheduleSlot::upsert($chunk, ['child_id', 'slot_date', 'session'], ['week_start', 'updated_at']);
        }
    }

    /**
     * Take away boxes a child is no longer entitled to.
     *
     * Enrolment dates decide whether a box exists at all, and they can change
     * after a week was built — "August 10th is his last day" arrives on the 6th.
     * Without this the old boxes would sit there until someone noticed.
     *
     * Two things are never touched: a finished week, which is a record rather
     * than a plan, and any day that carries a sign-in. A child who was here was
     * here, and hiding the box would hide the attendance DSS bills for.
     */
    private function pruneUnenrolled(string $weekStart): void
    {
        if ($this->isFrozen($weekStart)) {
            return;
        }

        $slots = ScheduleSlot::where('week_start', $weekStart)->get();

        if ($slots->isEmpty()) {
            return;
        }

        $children = Child::whereIn('id', $slots->pluck('child_id')->unique())->get()->keyBy('id');
        $dates = ScheduleWeek::datesOf($weekStart);

        $signedIn = Attendance::whereBetween('attendance_date', [$dates[0], $dates[4]])
            ->get()
            ->map(fn ($record) => $record->child_id.'|'.$record->attendance_date->toDateString().'|'.$record->session)
            ->flip();

        $stale = $slots->filter(function ($slot) use ($children, $signedIn) {
            $child = $children->get($slot->child_id);
            $date = $slot->slot_date->toDateString();

            if ($child && $child->status === 'Active' && $child->isEnrolledOn($date)) {
                return false;
            }

            return ! $signedIn->has($slot->child_id.'|'.$date.'|'.$slot->session);
        });

        if ($stale->isNotEmpty()) {
            ScheduleSlot::whereIn('id', $stale->pluck('id'))->delete();
        }
    }

    /** [child_id][weekday offset][session] => bool */
    private function patternOf(string $weekStart, bool $attendedCountsAsScheduled = false): array
    {
        $monday = Carbon::parse($weekStart);
        $pattern = [];

        foreach (ScheduleSlot::where('week_start', $weekStart)->get() as $slot) {
            $offset = $monday->diffInDays($slot->slot_date, false);
            $pattern[$slot->child_id][(int) $offset][$slot->session] = (bool) $slot->is_scheduled;
        }

        if (! $attendedCountsAsScheduled) {
            return $pattern;
        }

        /*
         * A day attended is a day scheduled, when this week is being read as
         * the shape of the next one.
         *
         * A child who turned up on a day nobody had booked was, in the only
         * sense next week cares about, coming on that day. Leaving the drop-in
         * out meant the staff re-ticked the same Tuesday every week and a
         * standing arrangement never became one.
         *
         * One-way: it can only turn a day on. An absence does not clear a
         * ticked day, because a sick Monday is not a change of schedule — that
         * is the whole reason the plan and the record are separate things.
         */
        // datesOf() hands back a plain array, and the bounds are bound as
        // strings: a Carbon against a DATE column compares as text and drops
        // the Monday, which is the trap this file documents elsewhere.
        $dates = ScheduleWeek::datesOf($weekStart);

        $arrivals = Attendance::whereBetween('attendance_date', [
            $dates[0]->toDateString(),
            $dates[4]->toDateString(),
        ])->get();

        foreach ($arrivals as $arrival) {
            $offset = (int) $monday->diffInDays($arrival->attendance_date, false);
            $session = $arrival->session ?? 'FULL';

            if ($offset < 0 || $offset > 4) {
                continue;
            }

            $pattern[$arrival->child_id][$offset][$session] = true;
        }

        return $pattern;
    }
}
