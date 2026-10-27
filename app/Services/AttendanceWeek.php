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
            'tip' => $closedDay ? $closed[$iso] : (! $enrolled ? 'Not enrolled' : 'Tap: '.$names[$st].' → '.$names[$next]),
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

        DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $date)->delete();
        $this->tick($child, $date, $session, $state === 'pres');

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
