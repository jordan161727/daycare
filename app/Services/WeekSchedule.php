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
    /** How many sign-ins the last copyFrom() brought across. */
    public int $copiedSignIns = 0;

    /** Days ticked in the target week after the last copyFrom(), and the change. */
    public int $tickedDays = 0;

    public int $tickChange = 0;

    public function __construct(private AttendanceProjection $projection) {}

    /**
     * Return the week, building it from the previous one the first time it is opened.
     * Center-wide by design: it must not matter which teacher happened to open it.
     */
    public function open(string $weekStart, ?User $user = null): ScheduleWeek
    {
        $existing = ScheduleWeek::firstWhere('week_start', $weekStart);

        if ($existing) {
            $this->addMissingChildren($weekStart);
            $this->pruneUnenrolled($weekStart);

            return $existing;
        }

        return DB::transaction(function () use ($weekStart, $user) {
            // Another teacher may have opened the same week a moment ago.
            $week = ScheduleWeek::lockForUpdate()->firstWhere('week_start', $weekStart);

            if ($week) {
                return $week;
            }

            $source = $this->sourceFor($weekStart);

            $week = ScheduleWeek::create([
                'week_start' => $weekStart,
                'copied_from_week_start' => $source,
                'created_by' => $user?->id,
            ]);

            $this->fill($weekStart, $source);

            return $week;
        });
    }

    /**
     * Bring another week's pattern into this one.
     *
     * 'replace' rebuilds the week from the source, dropping the ticks it has now.
     * 'add' only ever turns days on: everything already ticked here stays ticked,
     * and the source week's days join it. Nothing a teacher set up by hand is
     * lost, which is the difference that matters when a week has been edited.
     *
     * With $withSignIns the attendance of the source week is reproduced on the
     * matching weekdays too. That writes a record of a child attending on a day
     * they were never signed in for, so it is off unless asked for explicitly —
     * it is for filling out sample data, not for running a real roster.
     */
    public function copyFrom(string $weekStart, string $sourceWeekStart, bool $withSignIns = false, string $mode = 'replace'): ScheduleWeek
    {
        return DB::transaction(function () use ($weekStart, $sourceWeekStart, $withSignIns, $mode) {
            $week = ScheduleWeek::firstOrCreate(['week_start' => $weekStart]);
            $week->update(['copied_from_week_start' => $sourceWeekStart]);

            $before = $this->tickedIn($weekStart);

            if ($mode !== 'add') {
                ScheduleSlot::where('week_start', $weekStart)->delete();
            }

            $this->fill($weekStart, $sourceWeekStart, $mode === 'add');

            $this->tickedDays = $this->tickedIn($weekStart);
            $this->tickChange = $this->tickedDays - $before;
            $this->copiedSignIns = $withSignIns ? $this->copySignIns($weekStart, $sourceWeekStart) : 0;

            return $week->refresh();
        });
    }

    /**
     * Reproduce the source week's sign-ins on the same weekday of the target
     * week, at the same time of day.
     *
     * Days a child is not enrolled on are skipped, and a day that is already
     * signed in is left exactly as it is — a real arrival time is never
     * overwritten by a copied one.
     */
    private function copySignIns(string $weekStart, string $sourceWeekStart): int
    {
        $monday = Carbon::parse($weekStart);
        $sourceMonday = Carbon::parse($sourceWeekStart);
        $sourceDates = ScheduleWeek::datesOf($sourceWeekStart);

        $records = Attendance::whereBetween('attendance_date', [$sourceDates[0], $sourceDates[4]])->get();
        $children = Child::whereIn('id', $records->pluck('child_id')->unique())->get()->keyBy('id');
        $copied = 0;

        foreach ($records as $record) {
            $offset = (int) $sourceMonday->diffInDays($record->attendance_date, false);
            $child = $children->get($record->child_id);

            if ($offset < 0 || $offset > 4 || ! $child) {
                continue;
            }

            $date = $monday->copy()->addDays($offset)->toDateString();

            if (! $child->isEnrolledOn($date)) {
                continue;
            }

            $attendance = Attendance::firstOrCreate(
                [
                    'child_id' => $record->child_id,
                    'attendance_date' => $date,
                    'session' => $record->session,
                ],
                ['signed_in_at' => $record->signed_in_at->copy()->setDateFrom($monday->copy()->addDays($offset))],
            );

            $copied += (int) $attendance->wasRecentlyCreated;
        }

        return $copied;
    }

    /**
     * Tick this week's days from the projection — the pattern last week's actual
     * attendance, the enrolment dates and the contracted hours add up to.
     *
     * This is the one place the forecast is allowed to touch the plan, and only
     * because the director asked for it in as many words. Left to itself the
     * projection stays a second opinion: see AttendanceProjection for why a sick
     * day must never become a schedule on its own.
     *
     * 'add' only ever turns days on, so nothing set up by hand is lost. 'replace'
     * makes the week an exact match of the forecast. A child the projection has
     * no pattern for — hours on file but no history and no ticks — is left
     * exactly as they are either way: there is nothing to write, and clearing
     * their days would read as a decision nobody made.
     */
    public function applyProjection(string $weekStart, string $mode = 'replace'): int
    {
        if ($this->isFrozen($weekStart)) {
            $this->tickedDays = $this->tickedIn($weekStart);
            $this->tickChange = 0;

            return 0;
        }

        return DB::transaction(function () use ($weekStart, $mode) {
            $before = $this->tickedIn($weekStart);
            $expected = $this->projection->forWeek($weekStart)['expected'];

            $on = [];
            $off = [];

            foreach (ScheduleSlot::where('week_start', $weekStart)->get() as $slot) {
                $wanted = ($expected[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] ?? false) === true;

                // "Add" never clears, and neither mode rewrites a box that
                // already says what the projection says.
                if ((! $wanted && $mode === 'add') || (bool) $slot->is_scheduled === $wanted) {
                    continue;
                }

                $wanted ? $on[] = $slot->id : $off[] = $slot->id;
            }

            $changed = $this->setScheduled($on, true) + $this->setScheduled($off, false);

            $this->tickedDays = $this->tickedIn($weekStart);
            $this->tickChange = $this->tickedDays - $before;
            $this->copiedSignIns = 0;

            return $changed;
        });
    }

    /** @param  array<int>  $ids */
    private function setScheduled(array $ids, bool $value): int
    {
        $changed = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $changed += ScheduleSlot::whereIn('id', $chunk)->update(['is_scheduled' => $value]);
        }

        return $changed;
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
     */
    public function setClosure(string $date, bool $closed, ?string $reason = null, ?User $user = null): int
    {
        return DB::transaction(function () use ($date, $closed, $reason, $user) {
            if (! $closed) {
                ClosureDay::where('closed_on', $date)->delete();

                return 0;
            }

            ClosureDay::updateOrCreate(
                ['closed_on' => $date],
                ['reason' => $reason, 'created_by' => $user?->id],
            );

            return ScheduleSlot::where('slot_date', $date)
                ->where('is_scheduled', true)
                ->update(['is_scheduled' => false]);
        });
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
    public function tickedIn(string $weekStart): int
    {
        return ScheduleSlot::where('week_start', $weekStart)->where('is_scheduled', true)->count();
    }

    /** Weeks that can be used as a copy source, newest first. */
    public function availableSources(string $exceptWeekStart)
    {
        return ScheduleWeek::where('week_start', '!=', $exceptWeekStart)
            ->orderByDesc('week_start')
            ->pluck('week_start')
            ->map(fn ($date) => $date->toDateString());
    }

    /**
     * Write one slot row per child, per weekday, per session.
     *
     * A child outside their enrolment window gets no row at all — that is the
     * "not enrolled" state, and it is why the grid can show an empty box rather
     * than pretending the child was simply unscheduled.
     */
    private function fill(string $weekStart, ?string $sourceWeekStart, bool $keepExisting = false): void
    {
        $children = Child::where('status', 'Active')->get();
        $pattern = $sourceWeekStart ? $this->patternOf($sourceWeekStart) : [];
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
                        // Same weekday of the source week. A child with nothing to
                        // inherit (newly enrolled) starts unticked rather than
                        // silently scheduled.
                        'is_scheduled' => ! in_array($slotDate, $closed, true)
                            && (($pattern[$child->id][$offset][$session] ?? false)
                                || ($existing[$child->id][$offset][$session] ?? false)),
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
     * New boxes always arrive unticked. A day nobody has asked for is never
     * silently scheduled.
     *
     * A finished week is left alone, the same as when enrolment shrinks. Without
     * that, adding a child today puts boxes into weeks that ended before they
     * were on the roster — writing into the record DSS bills against.
     */
    private function addMissingChildren(string $weekStart): void
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

        foreach (Child::where('status', 'Active')->get() as $child) {
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
                        'is_scheduled' => false,
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
    private function patternOf(string $weekStart): array
    {
        $monday = Carbon::parse($weekStart);
        $pattern = [];

        foreach (ScheduleSlot::where('week_start', $weekStart)->get() as $slot) {
            $offset = $monday->diffInDays($slot->slot_date, false);
            $pattern[$slot->child_id][(int) $offset][$slot->session] = (bool) $slot->is_scheduled;
        }

        return $pattern;
    }
}
