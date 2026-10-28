<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Director Attendance — Phase 3 of docs/work.md, as DirectorAttendance.dc.html
 * draws it: the week as a grid, one cell per child per day (two for School
 * Age), each cell tapped through not scheduled → absent → coming, and the
 * times typed straight into it.
 *
 * It reads what the register already keeps — the schedule ticks, the
 * attendance rows the kiosk and Teacher Attendance write, the amendments —
 * and the DSS page's absent mark, so a day marked here is the same day
 * everywhere. A time typed here goes to the same row Teacher Attendance
 * reads, with an amendment for a day already gone.
 */
class AttendanceWeek
{
    private string $tz;

    public function __construct(private WeekSchedule $weeks, private DssAttendance $dss)
    {
        $this->tz = config('app.timezone');
    }

    /* ---------------------------------------------------------------
     * The week
     * --------------------------------------------------------------- */

    public function build(User $user, Carbon $anyDay, ?string $room = null, string $search = ''): array
    {
        $monday = $anyDay->copy()->startOfWeek(Carbon::MONDAY);
        $dates = collect(range(0, 4))->map(fn ($n) => $monday->copy()->addDays($n));
        $from = $monday->toDateString();
        $to = $dates->last()->toDateString();
        $today = today()->toDateString();
        $weekStart = ScheduleWeek::startOf($from);

        ClassroomAssignment::syncAll();

        $children = Child::visibleTo($user)->onRollDuring($from, $to)->orderBy('last_name')->orderBy('first_name')->get();
        $ids = $children->pluck('id');

        $records = Attendance::whereBetween('attendance_date', [$from, $to])->whereIn('child_id', $ids)->get()
            ->groupBy([fn ($a) => $a->child_id, fn ($a) => $a->attendance_date->toDateString(), fn ($a) => $a->session ?: 'FULL']);
        $slots = ScheduleSlot::where('week_start', $weekStart)->whereIn('child_id', $ids)->get()
            ->keyBy(fn ($s) => $s->child_id.'|'.$s->slot_date->toDateString().'|'.$s->session);
        $marks = DssDayMark::whereBetween('marked_on', [$from, $to])->whereIn('child_id', $ids)->get()
            ->keyBy(fn ($m) => $m->child_id.'|'.$m->marked_on->toDateString());
        $amendments = AttendanceAmendment::mapForRange($from, $to);
        $closed = ClosureDay::betweenDates($dates->first(), $dates->last())->get()
            ->mapWithKeys(fn ($d) => [$d->closed_on->toDateString() => $d->reason ?: 'Centre closed'])->all();

        $rows = [];
        foreach ($children as $child) {
            $sessions = $child->sessions();
            $cells = [];
            foreach ($dates as $date) {
                $iso = $date->toDateString();
                $parts = [];
                foreach ($sessions as $session) {
                    $parts[] = $this->part($child, $iso, $session, $sessions, $records, $slots, $marks, $amendments, $closed, $today);
                }
                $cells[] = ['date' => $iso, 'today' => $iso === $today, 'parts' => $parts];
            }

            $todayParts = $cells[array_search($today, $dates->map->toDateString()->all(), true) ?: 0]['parts'];
            $onToday = $dates->contains(fn ($d) => $d->toDateString() === $today);
            $expected = $onToday && collect($todayParts)->contains(fn ($p) => $p['st'] === 'pres');
            $came = $onToday && collect($todayParts)->contains(fn ($p) => $p['in'] !== null);
            $inNow = $onToday && collect($todayParts)->contains(fn ($p) => $p['in'] !== null && $p['out'] === null);

            $rows[] = [
                'id' => $child->id,
                'lan' => $child->lan,
                'first' => $child->first_name,
                'last' => $child->last_name,
                'name' => $child->last_name.', '.$child->first_name,
                'ini' => strtoupper(mb_substr($child->first_name, 0, 1).mb_substr($child->last_name, 0, 1)),
                'room' => $child->classroom ?: 'Unassigned',
                'roomColor' => ScheduleEditor::colour($child->classroom)[1],
                'dob' => $child->birthDate()?->format('m/d/y') ?? '—',
                'age' => $child->ageInWords() ?? '',
                'hours' => $this->hoursLabel($child),
                'url' => route('children.show', $child),
                'cells' => $cells,
                'expected' => $expected,
                'came' => $came,
                'inNow' => $inNow,
                'search' => strtolower($child->first_name.' '.$child->last_name.' '.$child->lan),
            ];
        }

        $all = collect($rows);
        $shown = $all
            ->filter(fn ($r) => $room === null || $r['room'] === $room)
            ->filter(fn ($r) => $search === '' || str_contains($r['search'], strtolower($search)))
            ->values();

        $chips = [['name' => 'All', 'color' => '#c3cbe0', 'n' => $all->count(), 'inN' => $all->where('inNow', true)->count(), 'on' => $room === null]];
        foreach (ClassroomAssignment::inOrder($all->pluck('room')) as $r) {
            $g = $all->where('room', $r);
            $chips[] = ['name' => $r, 'color' => ScheduleEditor::colour($r)[1], 'n' => $g->count(), 'inN' => $g->where('inNow', true)->count(), 'on' => $room === $r];
        }

        $inN = $all->where('inNow', true)->count();

        return [
            'weekStart' => $weekStart,
            'label' => $monday->format('M j').' – '.$dates->last()->format($monday->isSameMonth($dates->last()) ? 'j' : 'M j'),
            'prev' => $monday->copy()->subWeek()->toDateString(),
            'next' => $monday->copy()->addWeek()->toDateString(),
            'isThisWeek' => $weekStart === ScheduleWeek::startOf($today),
            'today' => $today,
            'days' => $dates->map(fn ($d) => ['date' => $d->toDateString(), 'dow' => $d->format('D'), 'label' => $d->format('M j'), 'today' => $d->toDateString() === $today, 'closed' => $closed[$d->toDateString()] ?? null])->all(),
            'chips' => $chips,
            'sum' => [
                'enr' => $all->count(),
                'exp' => $all->where('expected', true)->count(),
                'inN' => $inN,
                'come' => $all->filter(fn ($r) => $r['expected'] && ! $r['came'])->count(),
                'notIn' => $all->count() - $inN,
            ],
            'rows' => $shown->all(),
            'total' => $all->count(),
        ];
    }

    /**
     * One session of one child's day, as the cell draws it.
     *
     * st is the tap state: none (not one of their days), abs (absent), pres
     * (coming / came). The times ride on top of it: a day with times on it
     * that is not one of their days is still billable, and says so.
     */
    private function part(Child $child, string $iso, string $session, array $sessions, Collection $records, Collection $slots, Collection $marks, array $amendments, array $closed, string $today): array
    {
        $slot = $slots->get($child->id.'|'.$iso.'|'.$session);
        $mark = $marks->get($child->id.'|'.$iso);
        $rows = $records[$child->id][$iso][$session] ?? collect();
        $row = $rows->first();
        $in = $row?->signed_in_at ? Child::timeShort($row->signed_in_at->timezone($this->tz)) : null;
        $out = $row?->signed_out_at ? Child::timeShort($row->signed_out_at->timezone($this->tz)) : null;
        $future = $iso > $today;
        $isToday = $iso === $today;
        $enrolled = $child->isEnrolledOn($iso);
        $closedDay = isset($closed[$iso]);

        // A "not scheduled" mark is how a day already gone is taken off the
        // plan (the week's ticks freeze once the week is over); it is read
        // ahead of the tick, as the DSS page reads it.
        $st = match (true) {
            $mark?->mark === DssDayMark::ABSENT => 'abs',
            $mark?->mark === DssDayMark::NONE => 'none',
            (bool) $slot?->is_scheduled => 'pres',
            default => 'none',
        };

        $sched = $st === 'pres';
        $planned = $this->plannedStart($child, $session, $sessions, $slot);

        [$cls, $text] = match (true) {
            $closedDay => ['s-closed', $closed[$iso]],
            ! $enrolled => ['s-gone', '—'],
            $st === 'abs' => ['s-abs', 'Absent'],
            $in !== null => [$sched ? 's-in' : 's-bill', $out ? $in.'–'.$out : 'In '.$in],
            $st === 'pres' => [$sched ? 's-exp' : 's-bill', $isToday ? ($planned ?? '') : ''],
            default => ['s-none', ''],
        };

        $names = ['none' => 'not scheduled', 'abs' => 'absent', 'pres' => 'coming'];
        $next = ['none' => 'abs', 'abs' => 'pres', 'pres' => 'none'][$st];

        // An Absent day that remembers its times says so: the next tap puts them back.
        $back = null;
        if ($st === 'abs' && ! empty($mark?->kept)) {
            $was = collect($mark->kept)->firstWhere('session', $session) ?? $mark->kept[0];
            $back = Child::timeShort(Carbon::parse($was['in'])->timezone($this->tz)).(isset($was['out']) ? '–'.Child::timeShort(Carbon::parse($was['out'])->timezone($this->tz)) : '');
        }

        return [
            'session' => $session,
            'lbl' => count($sessions) > 1 ? $session : '',
            'st' => $st,
            'cls' => $cls,
            'text' => $text,
            'in' => $in,
            'out' => $out,
            'inV' => $row?->signed_in_at?->timezone($this->tz)->format('H:i'),
            'outV' => $row?->signed_out_at?->timezone($this->tz)->format('H:i'),
            'edited' => isset($amendments[$child->id.'|'.$iso.'|'.$session]) && $in !== null,
            'editedBy' => $amendments[$child->id.'|'.$iso.'|'.$session]['by'] ?? null,
            'tappable' => $enrolled && ! $closedDay,
            'editable' => $enrolled && ! $closedDay && ! $future,
            'next' => $next,
            'back' => $back,
            'tip' => $closedDay ? $closed[$iso] : (! $enrolled ? 'Not enrolled' : 'Tap: '.$names[$st].' → '.$names[$next].($back ? ' ('.$back.' back)' : '')),
            'placeholder' => $planned,
        ];
    }

    /** "7:30a" — the hour the day is booked for, else the agreed start. */
    private function plannedStart(Child $child, string $session, array $sessions, ?ScheduleSlot $slot): ?string
    {
        if ($slot?->planned_time) {
            return Child::timeShort(Carbon::parse($slot->planned_time));
        }

        $segments = $this->dss->agreedSegments($child);
        if ($segments === []) {
            return null;
        }

        $index = count($sessions) > 1 && $session === 'PM' ? count($segments) - 1 : 0;

        return DssAttendance::clock($segments[$index][0]);
    }

    /** "7:30a – 5:00p", or "7:15–8:30a · 3–6p" for School Age. */
    private function hoursLabel(Child $child): string
    {
        $segments = $this->dss->agreedSegments($child);

        if ($segments === []) {
            return 'Hours not agreed';
        }

        return implode(' · ', array_map(fn ($g) => DssAttendance::clock($g[0]).' – '.DssAttendance::clock($g[1]), $segments));
    }

    /* ---------------------------------------------------------------
     * A tap, or times typed in
     * --------------------------------------------------------------- */

    /**
     * Put one state on one cell, and any times typed with it.
     *
     * none   the tick off, no mark; any times stay (still billable).
     * abs    the absent mark; the day's rows come off, with amendments.
     * pres   the tick on, no mark; times, if given, go on the register row.
     *
     * The week is opened for the person if it is not yet, the way the
     * classic register opens it before a tick can be set.
     */
    public function apply(User $user, Child $child, string $date, string $session, string $state, ?string $in, ?string $out): void
    {
        abort_unless($user->canAccessClassroom($child->classroom), 403);

        if (! $child->isEnrolledOn($date)) {
            throw new \InvalidArgumentException($child->displayName().' is not enrolled on that day.');
        }
        if (in_array($date, ClosureDay::inWeek(ScheduleWeek::startOf($date)), true)) {
            throw new \InvalidArgumentException('The centre is closed that day.');
        }

        $weekStart = ScheduleWeek::startOf($date);
        $this->weeks->open($weekStart, $user);

        if ($state === 'abs') {
            $this->dss->apply($child, $date, ['st' => 'abs'], $user);
            $this->tick($child, $date, $session, false);

            return;
        }

        $mark = DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $date)->first();
        $mark?->delete();
        $this->tick($child, $date, $session, $state === 'pres');

        // Absent → Coming with nothing typed: the times the Absent mark took
        // off go back as they were (Jordan, 27 Oct 2026: "back to the time
        // before, not really to remove").
        if ($state === 'pres' && blank($in) && blank($out) && $mark?->mark === DssDayMark::ABSENT && ! empty($mark->kept)) {
            $this->putBack($user, $child, $date, $mark->kept);
        }

        // Once a week is over its ticks are frozen, so "not scheduled" on a
        // day gone by is kept as the same mark the DSS page writes. Times
        // already on the day stay (the day is still billable) — 26 Oct 2026.
        if ($state === 'none' && $this->weeks->isFrozen($weekStart)) {
            DssDayMark::create(['child_id' => $child->id, 'marked_on' => $date, 'mark' => DssDayMark::NONE, 'created_by' => $user->id]);
        }

        if (filled($in) || filled($out)) {
            $this->times($user, $child, $date, $session, $in, $out);
        }
    }

    /**
     * One tap on Director Attendance, on today or a day gone by.
     *
     * Jordan, 28 Oct 2026: "when I click the time it changes to a dot; when
     * I click it again it changes to Absent; if I click it once more it
     * should return to the previous time." So:
     *
     *   time    → dot      the rows come off, remembered on a "none" mark
     *   dot     → Absent   the mark turns absent, still remembering them
     *   Absent  → time     the mark goes and the remembered rows come back
     *
     * A day that never had a time goes dot → Absent → booked; the register
     * signs a booked day in itself, as it always has. The marks are a day's,
     * as on the DSS sheet, so a School Age morning and afternoon step
     * together. The next state is read off the record, never the screen,
     * so a double tap or a second screen cannot skip a step.
     *
     * @return array{mark: ?string, sessions: array<string, array{in: ?string, out: ?string, scheduled: bool}>}
     */
    public function step(User $user, Child $child, string $date, string $session): array
    {
        abort_unless($user->canAccessClassroom($child->classroom), 403);

        if ($date > today()->toDateString()) {
            throw new \InvalidArgumentException('A day still to come is booked, not marked.');
        }
        if (! $child->isEnrolledOn($date)) {
            throw new \InvalidArgumentException($child->displayName().' is not enrolled on that day.');
        }
        if (in_array($date, ClosureDay::inWeek(ScheduleWeek::startOf($date)), true)) {
            throw new \InvalidArgumentException('The centre is closed that day.');
        }

        $this->weeks->open(ScheduleWeek::startOf($date), $user);

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $child, $date, $session) {
            $rows = Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->whereNotNull('signed_in_at')->get();
            $mark = DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $date)->first();

            if ($rows->isNotEmpty()) {
                // time → dot, remembering every session's times.
                $kept = $rows->map(fn (Attendance $row) => [
                    'session' => $row->session ?? 'FULL',
                    'in' => $row->signed_in_at->toIso8601String(),
                    'out' => $row->signed_out_at?->toIso8601String(),
                ])->values()->all();

                foreach ($rows as $row) {
                    AttendanceAmendment::record(AttendanceAmendment::REMOVED, $row, $user, $row->signed_in_at);
                    $row->delete();
                }
                $mark?->delete();
                DssDayMark::create(['child_id' => $child->id, 'marked_on' => $date, 'mark' => DssDayMark::NONE, 'kept' => $kept, 'created_by' => $user->id]);
                foreach ($child->sessions() as $s) {
                    $this->tick($child, $date, $s, false);
                }

                return;
            }

            if ($mark?->mark === DssDayMark::ABSENT) {
                // Absent → the times it remembered, or booked if it has none.
                $mark->delete();
                if (! empty($mark->kept)) {
                    $this->putBack($user, $child, $date, $mark->kept);
                    foreach (collect($mark->kept)->pluck('session')->unique() as $s) {
                        $this->tick($child, $date, $s, true);
                    }
                } else {
                    $this->tick($child, $date, $session, true);
                }

                return;
            }

            // dot → Absent, carrying any times a "none" mark remembered.
            $mark?->delete();
            DssDayMark::create(['child_id' => $child->id, 'marked_on' => $date, 'mark' => DssDayMark::ABSENT, 'kept' => $mark?->kept ?: null, 'created_by' => $user->id]);
            foreach ($child->sessions() as $s) {
                $this->tick($child, $date, $s, false);
            }
        });

        return $this->dayFor($child, $date);
    }

    /**
     * The day as the register keeps it in the browser: the mark, and per
     * session the arrival, the departure and the tick.
     *
     * @return array{mark: ?string, sessions: array<string, array{in: ?string, out: ?string, scheduled: bool}>}
     */
    public function dayFor(Child $child, string $date): array
    {
        $rows = Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->whereNotNull('signed_in_at')->get()->keyBy(fn ($r) => $r->session ?? 'FULL');
        $ticks = ScheduleSlot::where('child_id', $child->id)->whereDate('slot_date', $date)->pluck('is_scheduled', 'session');
        $sessions = [];

        foreach ($child->sessions() as $s) {
            $row = $rows->get($s);
            $sessions[$s] = [
                'in' => $row ? Child::timeShort($row->signed_in_at->timezone($this->tz)) : null,
                'out' => $row?->signed_out_at ? Child::timeShort($row->signed_out_at->timezone($this->tz)) : null,
                'scheduled' => (bool) ($ticks[$s] ?? false),
            ];
        }

        return [
            'mark' => DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $date)->value('mark'),
            'sessions' => $sessions,
        ];
    }

    /**
     * The rows an Absent mark remembered, written again — only where the
     * day has nothing on it now, and with an amendment for a day gone by.
     *
     * @param  list<array{session: string, in: string, out: ?string}>  $kept
     */
    private function putBack(User $user, Child $child, string $date, array $kept): void
    {
        if (Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->whereNotNull('signed_in_at')->exists()) {
            return;
        }

        foreach ($kept as $was) {
            $row = Attendance::create([
                'child_id' => $child->id,
                'attendance_date' => $date,
                'session' => $was['session'] ?? 'FULL',
                'signed_in_at' => Carbon::parse($was['in']),
                'signed_out_at' => isset($was['out']) ? Carbon::parse($was['out']) : null,
            ]);
            AttendanceAmendment::record(AttendanceAmendment::ADDED, $row, $user, $row->signed_in_at);
        }
    }

    private function tick(Child $child, string $date, string $session, bool $on): void
    {
        if ($this->weeks->isFrozen(ScheduleWeek::startOf($date))) {
            return;
        }

        ScheduleSlot::where('child_id', $child->id)->whereDate('slot_date', $date)->where('session', $session)->update(['is_scheduled' => $on]);
    }

    /**
     * The times on one session: moved on the row that is there, or written
     * as a new row — with an amendment either way for a day already gone.
     */
    private function times(User $user, Child $child, string $date, string $session, ?string $in, ?string $out): void
    {
        if ($date > today()->toDateString()) {
            throw new \InvalidArgumentException('Times can only be put on a day that has happened.');
        }

        $inM = DssAttendance::minutes($in);
        $outM = DssAttendance::minutes($out);

        if (filled($in) && $inM === null) {
            throw new \InvalidArgumentException('“'.$in.'” is not a time — try 8:05a.');
        }
        if (filled($out) && $outM === null) {
            throw new \InvalidArgumentException('“'.$out.'” is not a time — try 5:00p.');
        }

        $row = Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->where('session', $session)->first();

        if ($inM === null && $row === null) {
            throw new \InvalidArgumentException('Type the time in first.');
        }

        $inM ??= $this->minutesOf($row->signed_in_at);

        if ($outM !== null && $outM <= $inM) {
            throw new \InvalidArgumentException('The time out has to be after the time in.');
        }

        $was = $row?->signed_in_at;
        $row ??= new Attendance(['child_id' => $child->id, 'attendance_date' => $date, 'session' => $session]);
        $isNew = ! $row->exists;
        $row->signed_in_at = Carbon::parse($date, $this->tz)->startOfDay()->addMinutes($inM);
        $row->signed_out_at = $outM === null ? ($out === '' || $out === null ? $row->signed_out_at : null) : Carbon::parse($date, $this->tz)->startOfDay()->addMinutes($outM);
        $row->save();

        AttendanceAmendment::record($isNew ? AttendanceAmendment::ADDED : AttendanceAmendment::RETIMED, $row, $user, $was ?? $row->signed_in_at);
    }

    private function minutesOf(Carbon $at): int
    {
        $local = $at->copy()->timezone($this->tz);

        return $local->hour * 60 + $local->minute;
    }
}
