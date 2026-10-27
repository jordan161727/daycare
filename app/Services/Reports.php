<?php

namespace App\Services;

use App\Http\Controllers\StaffController;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\LeaveRequest;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Reports page — Phase 8.2 of docs/work.md, as Reports.dc.html draws it.
 *
 * Three reports, each a sheet on screen and a .csv: a month of children's
 * attendance by room, a stretch of staff hours from the clock, and the
 * UPK-4 attendance sheet in the school district's own layout. Each is built
 * here once, in one shape, and the page, the file and the monthly email all
 * read that shape.
 */
class Reports
{
    private const ROOM_ORDER = ['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'];

    private const DOW = ['', 'M', 'T', 'W', 'TH', 'F', 'Sa', 'Su'];

    private string $tz;

    public function __construct(private TimeClock $clock)
    {
        $this->tz = config('app.timezone');
    }

    /* ---------------------------------------------------------------
     * Child attendance: a month, by room
     * --------------------------------------------------------------- */

    /**
     * @param  ?string  $room  One room, or null for every room the viewer may see.
     * @param  string  $mode  times | marks
     */
    public function childMonth(User $viewer, int $year, int $month, ?string $room, string $mode = 'times'): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = today()->toDateString();
        $closed = $this->closed($start, $end);
        $days = $this->weekdays($start, $end);

        $children = Child::visibleTo($viewer)
            ->where(fn ($q) => $q->where('status', 'Active')->orWhere(fn ($w) => $w->whereNotNull('withdrawn_on')->whereDate('withdrawn_on', '>=', $start->toDateString())))
            ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $end->toDateString()))
            ->when($room, fn ($q) => $q->where('classroom', $room))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $records = Attendance::whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get()
            ->groupBy([fn ($a) => $a->child_id, fn ($a) => $a->attendance_date->toDateString()]);
        $marks = DssDayMark::whereBetween('marked_on', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))->get()
            ->keyBy(fn ($m) => $m->child_id.'|'.$m->marked_on->toDateString());

        $byRoom = $children->groupBy(fn (Child $c) => $c->classroom ?: 'Unassigned');
        $rooms = collect(self::ROOM_ORDER)->intersect($byRoom->keys())->merge($byRoom->keys()->diff(self::ROOM_ORDER)->sort())->values();

        $rows = [];
        $csv = [['Room', 'Last name', 'First name', 'Date', 'Status', 'In', 'Out', 'Hours']];
        $totP = 0;
        $totA = 0;

        foreach ($rooms as $r) {
            $kids = $byRoom[$r];
            if ($room === null) {
                $rows[] = ['group' => true, 'name' => $r, 'color' => ScheduleEditor::colour($r)[1], 'count' => $kids->count().' '.($kids->count() === 1 ? 'child' : 'children'), 'cells' => array_map(fn () => ['cls' => 'g', 'a' => '', 'b' => ''], $days), 'p' => '', 'ab' => '', 'h' => ''];
            }
            foreach ($kids as $child) {
                $p = 0;
                $a = 0;
                $mins = 0;
                $booked = $this->bookedDays($child);
                $cells = [];
                foreach ($days as $day) {
                    $iso = $day['date'];
                    $onDay = $records[$child->id][$iso] ?? collect();
                    $mark = $marks->get($child->id.'|'.$iso);
                    $scheduled = in_array($day['dow'], $booked, true) && $child->isEnrolledOn($iso);
                    $date = Carbon::parse($iso)->format('n/j/Y');

                    if (isset($closed[$iso])) {
                        $cells[] = ['cls' => 'closed', 'a' => '', 'b' => ''];
                    } elseif ($onDay->isNotEmpty() && $onDay->first(fn ($x) => $x->signed_in_at) ) {
                        [$in, $out, $m] = $this->firstLast($onDay);
                        $p++;
                        $mins += $m;
                        $csv[] = [$r, $child->last_name, $child->first_name, $date, 'Present', $in, $out ?? '', $m ? number_format($m / 60, 2) : ''];
                        $cells[] = $mode === 'marks' ? ['cls' => 'pr ck', 'a' => '✓', 'b' => ''] : ['cls' => 'pr', 'a' => $in, 'b' => $out ?? '—'];
                    } elseif ($iso <= $today && ($mark?->mark === DssDayMark::ABSENT || ($scheduled && $iso < $today && $mark?->mark !== DssDayMark::NONE))) {
                        $a++;
                        $csv[] = [$r, $child->last_name, $child->first_name, $date, 'Absent', '', '', ''];
                        $cells[] = ['cls' => 'ab', 'a' => 'A', 'b' => ''];
                    } else {
                        $cells[] = ['cls' => '', 'a' => '', 'b' => ''];
                    }
                }
                $totP += $p;
                $totA += $a;
                $rows[] = ['group' => false, 'name' => $child->last_name.', '.$child->first_name, 'room' => $r, 'cells' => $cells, 'p' => (string) $p, 'ab' => (string) $a, 'aCol' => $a >= 4 ? '#be123c' : '#1c2130', 'h' => $this->hm($mins)];
            }
        }

        return [
            'title' => $start->format('F Y').($room ? ' · '.$room : ' · all rooms'),
            'sub' => $totP.' days present · '.$totA.' absences'.($start->isSameMonth(today()) ? ' · up to today, '.today()->format('D M j') : ''),
            'days' => array_map(fn ($d) => ['dow' => $d['label'], 'n' => $d['n'], 'cls' => isset($closed[$d['date']]) ? 'closed' : ($d['date'] > $today ? 'fut' : '')], $days),
            'rows' => $rows,
            'csv' => $csv,
            'file' => 'child-attendance-'.strtolower($start->format('F-Y')).($room ? '-'.str_replace(' ', '-', strtolower($room)) : '').'.csv',
        ];
    }

    /** First in and last out across the day's sessions, and the minutes between each pair. */
    private function firstLast(Collection $onDay): array
    {
        $rows = $onDay->filter(fn ($x) => $x->signed_in_at)->sortBy(fn ($x) => $x->signed_in_at->timestamp);
        $first = $rows->first();
        $last = $rows->filter(fn ($x) => $x->signed_out_at)->sortBy(fn ($x) => $x->signed_out_at->timestamp)->last();
        $mins = (int) $rows->sum(fn ($x) => $x->signed_out_at ? $x->signed_in_at->diffInMinutes($x->signed_out_at) : 0);

        return [$this->clock($first->signed_in_at), $last ? $this->clock($last->signed_out_at) : null, $mins];
    }

    /* ---------------------------------------------------------------
     * Staff attendance: a stretch of days from the clock
     * --------------------------------------------------------------- */

    /** The stretches on offer: three weeks and the pay period. */
    public function staffRanges(): array
    {
        $monday = today()->startOfWeek(Carbon::MONDAY);
        $pp = PayPeriod::containing(today()->toDateString());
        $label = fn (Carbon $a, Carbon $b) => $a->format('M j').' – '.$b->format($a->isSameMonth($b) ? 'j' : 'M j');

        return [
            'before' => ['from' => $monday->copy()->subWeeks(2), 'to' => $monday->copy()->subWeeks(2)->addDays(4), 'label' => $label($monday->copy()->subWeeks(2), $monday->copy()->subWeeks(2)->addDays(4))],
            'last' => ['from' => $monday->copy()->subWeek(), 'to' => $monday->copy()->subWeek()->addDays(4), 'label' => $label($monday->copy()->subWeek(), $monday->copy()->subWeek()->addDays(4))],
            'this' => ['from' => $monday->copy(), 'to' => $monday->copy()->addDays(4), 'label' => $label($monday, $monday->copy()->addDays(4)).' (this week)'],
            'pp' => ['from' => $pp->start->copy(), 'to' => $pp->end->copy(), 'label' => 'Pay period · '.$label($pp->start, $pp->end)],
        ];
    }

    public function staffRange(Carbon $from, Carbon $to, string $title): array
    {
        $closed = $this->closed($from, $to);
        $days = $this->weekdays($from, $to);
        $today = today()->toDateString();
        $staff = User::whereIn('role', ['admin', 'teacher'])->where(fn ($q) => $q->whereNull('employment')->orWhere('employment', '!=', 'INACTIVE'))->with('staffRules')->orderBy('name')->get();
        $shifts = StaffShift::whereIn('user_id', $staff->pluck('id'))->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])->orderBy('starts_at')->get()
            ->groupBy(fn ($s) => $s->user_id.'|'.$s->shift_date->toDateString());
        $off = StaffTimeOff::byUserAndDate($from->toDateString(), $to->toDateString());
        $leave = LeaveRequest::byUserAndDate($from->toDateString(), $to->toDateString());

        $rows = [];
        $csv = [['Staff', 'Date', 'Status', 'Clock in', 'Clock out', 'Break (min)', 'Paid hours', 'Late (min)', 'Edited']];
        $allH = 0;
        $allLate = 0;
        $allEd = 0;
        $allOT = 0;

        foreach ($staff as $person) {
            $H = 0;
            $BR = 0;
            $L = 0;
            $ED = 0;
            $weeks = [];
            $cells = [];

            foreach ($days as $day) {
                $iso = $day['date'];
                $date = Carbon::parse($iso)->format('n/j/Y');

                if (isset($closed[$iso])) {
                    $csv[] = [$person->name, $date, 'Closed', '', '', '', '', '', ''];
                    $cells[] = ['cls' => 'closed', 'a' => 'Closed', 'b' => '', 'late' => '', 'ed' => false];
                    continue;
                }
                if ($iso > $today) {
                    $cells[] = ['cls' => 'fut', 'a' => '', 'b' => '', 'late' => '', 'ed' => false];
                    continue;
                }

                $d = $this->clock->day($person->id, $iso);
                $away = $leave[$person->id][$iso] ?? null;
                $change = $off[$person->id][$iso] ?? null;

                if ($d['first_in'] === null) {
                    $word = $away ? $away->label() : ($change?->kind === 'all' ? 'Time off · '.$change->reasonLabel() : ($change ? $change->describe() : null));
                    if ($word) {
                        $csv[] = [$person->name, $date, $word, '', '', '', '', '', ''];
                        $cells[] = ['cls' => 'off', 'a' => $word, 'b' => '', 'late' => '', 'ed' => false];
                    } else {
                        $cells[] = ['cls' => '', 'a' => $iso === $today ? '' : '—', 'b' => '', 'late' => '', 'ed' => false];
                    }
                    continue;
                }

                $shift = $shifts->get($person->id.'|'.$iso)?->first();
                $due = $shift?->starts_at;
                if ($change && in_array($change->kind, ['until', 'window'], true) && $change->from_minute !== null && $due !== null) {
                    $due = max($due, $change->from_minute);
                }
                $lateBy = $due !== null && $d['first_in'] > $due + StaffController::LATE_GRACE ? $d['first_in'] - $due : 0;
                $edited = $d['punches']->contains(fn ($p) => $p->isCorrection());
                $paid = (int) $d['worked'];
                $br = (int) $d['unpaid_break'];

                $H += $paid;
                $BR += $br;
                if ($lateBy) {
                    $L++;
                }
                if ($edited) {
                    $ED++;
                }
                $wk = Carbon::parse($iso)->startOfWeek(Carbon::MONDAY)->toDateString();
                $weeks[$wk] = ($weeks[$wk] ?? 0) + $paid;

                $in = DssAttendance::clock($d['first_in']);
                $out = $d['last_out'] !== null && ! $d['open'] ? DssAttendance::clock($d['last_out']) : null;
                $csv[] = [$person->name, $date, $d['open'] && $iso === $today ? 'On shift' : ($d['open'] ? 'No clock-out' : 'Worked'), $in, $out ?? '', $br, number_format($paid / 60, 2), $lateBy ?: '', $edited ? 'yes' : ''];
                $cells[] = [
                    'cls' => 'wk',
                    'a' => $in.' – '.($out ?? ($iso === $today ? '…' : '—')),
                    'b' => $this->hm($paid).($br ? ' · br '.$br.'m' : ''),
                    'late' => $lateBy ? 'late '.$lateBy.'m' : '',
                    'ed' => $edited,
                ];
            }

            $ot = array_sum(array_map(fn ($m) => max(0, $m - 40 * 60), $weeks));
            $allH += $H;
            $allLate += $L;
            $allEd += $ED;
            $allOT += $ot;

            $rows[] = [
                'name' => $person->name,
                'role' => ($person->classroom ?: $person->jobRole()).($person->staffRules->contains(fn ($r) => $r->rule_type === 'CAN_OPEN') ? ' · opens' : ''),
                'cells' => $cells,
                'h' => $this->hm($H), 'br' => $BR ? $this->hm($BR) : '—',
                'late' => $L ? (string) $L : '—', 'lateCol' => $L ? '#be123c' : '#8a90a0',
                'ed' => $ED ? (string) $ED : '—', 'edCol' => $ED ? '#c2410c' : '#8a90a0',
                'ot' => $ot ? $this->hm($ot) : '—', 'otCol' => $ot ? '#b45309' : '#8a90a0',
            ];
        }

        return [
            'title' => $title,
            'sub' => $staff->count().' staff',
            'days' => array_map(fn ($d) => ['dow' => $d['label'], 'n' => Carbon::parse($d['date'])->format('n/j'), 'cls' => isset($closed[$d['date']]) ? 'closed' : ($d['date'] > $today ? 'fut' : '')], $days),
            'rows' => $rows,
            'kpis' => [
                ['label' => 'Paid hours', 'val' => $this->hm($allH), 'col' => '#1c2130'],
                ['label' => 'Late clock-ins', 'val' => (string) $allLate, 'col' => $allLate ? '#be123c' : '#1c2130'],
                ['label' => 'Times fixed by you', 'val' => (string) $allEd, 'col' => $allEd ? '#c2410c' : '#1c2130'],
                ['label' => 'Overtime (over 40 / week)', 'val' => $allOT ? $this->hm($allOT) : 'None', 'col' => $allOT ? '#b45309' : '#15803d'],
            ],
            'csv' => $csv,
            'file' => 'staff-hours-'.$from->toDateString().'-to-'.$to->toDateString().'.csv',
        ];
    }

    /* ---------------------------------------------------------------
     * The UPK-4 attendance sheet
     * --------------------------------------------------------------- */

    /**
     * The school district's sheet: five weeks of Monday-to-Friday columns
     * from the week of the month's first open day, a tick or an A per day,
     * absences totalled, a note from the child's record, and the head count
     * per day along the bottom. Twenty-five rows, like the paper.
     *
     * The layout is the district's UPK-4 form, but the sheet is drawn for any
     * room (27 Oct 2026, Jordan: "dynamic for each classroom"): the room is
     * the Grade on the sheet and part of the file's name. UPK-4 stays the
     * default, and the one the monthly email sends.
     */
    public function upkSheet(int $year, int $month, string $room = 'UPK-4'): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = today()->toDateString();
        $closed = $this->closed($start, $end);

        $firstOpen = null;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->dayOfWeekIso <= 5 && ! isset($closed[$d->toDateString()])) {
                $firstOpen = $d->copy();
                break;
            }
        }
        $weekStart = ($firstOpen ?? $start)->copy()->startOfWeek(Carbon::MONDAY);

        $cols = [];
        for ($w = 0; $w < 5; $w++) {
            for ($i = 0; $i < 5; $i++) {
                $d = $weekStart->copy()->addDays($w * 7 + $i);
                $iso = $d->toDateString();
                $inMonth = $d->month === $month && ! isset($closed[$iso]);
                $cols[] = ['date' => $iso, 'in' => $inMonth, 'n' => $inMonth ? (string) $d->day : '', 'dow' => self::DOW[$i + 1], 'we' => $i === 4];
            }
        }

        $children = Child::where('classroom', $room)
            ->where(fn ($q) => $q->where('status', 'Active')->orWhere(fn ($w) => $w->whereNotNull('withdrawn_on')->whereDate('withdrawn_on', '>=', $start->toDateString())))
            ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $end->toDateString()))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $records = Attendance::whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))->whereNotNull('signed_in_at')->get()
            ->groupBy([fn ($a) => $a->child_id, fn ($a) => $a->attendance_date->toDateString()]);
        $marks = DssDayMark::whereBetween('marked_on', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))->get()
            ->keyBy(fn ($m) => $m->child_id.'|'.$m->marked_on->toDateString());

        $rows = [];
        $csv = [array_merge(['#', 'Last Name, First Name'], array_map(fn ($c) => $month.'/'.Carbon::parse($c['date'])->day, array_filter($cols, fn ($c) => $c['in'])), ['Total Days Absent', 'Notes'])];
        $present = array_fill(0, count($cols), 0);
        $missing = 0;

        foreach ($children->values() as $i => $child) {
            $booked = $this->bookedDays($child);
            $ab = 0;
            $any = false;
            $cells = [];
            $csvCells = [];

            foreach ($cols as $k => $col) {
                $iso = $col['date'];
                $v = '';
                if ($col['in'] && $iso < $today && $child->isEnrolledOn($iso)) {
                    $mark = $marks->get($child->id.'|'.$iso);
                    if (isset($records[$child->id][$iso])) {
                        $v = '✓';
                        $present[$k]++;
                    } elseif ($mark?->mark === DssDayMark::ABSENT) {
                        $v = 'A';
                    } elseif (in_array(Carbon::parse($iso)->dayOfWeekIso, $booked, true) && $mark?->mark !== DssDayMark::NONE) {
                        $v = 'A';
                        $missing++;
                    }
                }
                if ($v === 'A') {
                    $ab++;
                }
                if ($v !== '') {
                    $any = true;
                }
                // 'e': the cell can be pressed on the sheet — a day of this
                // month, already gone, with the child enrolled (27 Oct 2026).
                $cells[] = ['v' => $v, 'we' => $col['we'], 'd' => $iso, 'e' => $col['in'] && $iso < $today && $child->isEnrolledOn($iso)];
                if ($col['in']) {
                    $csvCells[] = $v === '✓' ? 'P' : $v;
                }
            }

            $note = (string) ($child->enrollment_note ?? '');
            if ($child->enrolled_on && $child->enrolled_on->month === $month && $child->enrolled_on->year === $year) {
                $note = '1st day '.$child->enrolled_on->format('jS');
            }
            if ($child->withdrawn_on && $child->withdrawn_on->month === $month && $child->withdrawn_on->year === $year) {
                $note = 'No longer in class'.($child->withdrawn_on ? ' · last day '.$child->withdrawn_on->format('M j') : '');
            }

            $rows[] = ['no' => $i + 1, 'child_id' => $child->id, 'name' => $child->last_name.', '.$child->first_name, 'cells' => $cells, 'tot' => $any ? (string) $ab : '', 'note' => $note];
            $csv[] = array_merge([$i + 1, $child->last_name.', '.$child->first_name], $csvCells, [$any ? $ab : '', $note]);
        }

        for ($i = count($rows); $i < 25; $i++) {
            $rows[] = ['no' => $i + 1, 'child_id' => null, 'name' => '', 'cells' => array_map(fn ($c) => ['v' => '', 'we' => $c['we'], 'd' => $c['date'], 'e' => false], $cols), 'tot' => '', 'note' => ''];
        }

        $grand = [];
        foreach ($cols as $k => $col) {
            $grand[] = ['v' => $col['in'] && $col['date'] < $today ? (string) $present[$k] : '', 'we' => $col['we']];
        }

        $schoolDays = count(array_filter($cols, fn ($c) => $c['in'] && $c['date'] < $today));

        return [
            'month' => $start->format('F'),
            'year' => $year,
            'label' => $start->format('F Y'),
            'cols' => $cols,
            'rows' => $rows,
            'grand' => $grand,
            'children' => $children->count(),
            'schoolDays' => $schoolDays,
            'missing' => $missing,
            'csv' => $csv,
            'room' => $room,
            // The Grade box: the district writes UPK4; any other room is named as it is.
            'grade' => $room === 'UPK-4' ? 'UPK4' : $room,
            'file' => ($room === 'UPK-4' ? 'UPK4' : str_replace(' ', '-', $room)).'-attendance-'.$start->format('F-Y'),
        ];
    }

    /* ---------------------------------------------------------------
     * Shared
     * --------------------------------------------------------------- */

    private function closed(Carbon $from, Carbon $to): array
    {
        return ClosureDay::betweenDates($from, $to)->get()->mapWithKeys(fn ($d) => [$d->closed_on->toDateString() => $d->label()])->all();
    }

    /** @return list<array{date: string, dow: int, n: int, label: string}> */
    private function weekdays(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($d->dayOfWeekIso <= 5) {
                $out[] = ['date' => $d->toDateString(), 'dow' => $d->dayOfWeekIso, 'n' => $d->day, 'label' => self::DOW[$d->dayOfWeekIso]];
            }
        }

        return $out;
    }

    private function bookedDays(Child $child): array
    {
        $days = $child->scheduleDays();

        return $days === null || $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    private function clock(?Carbon $at): ?string
    {
        if (! $at) {
            return null;
        }
        $local = $at->copy()->timezone($this->tz);

        return DssAttendance::clock($local->hour * 60 + $local->minute);
    }

    private function hm(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    public static function csv(array $rows): string
    {
        $line = fn (array $cells) => implode(',', array_map(fn ($v) => preg_match('/[",\n]/', (string) $v) ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v, $cells));

        return implode("\n", array_map($line, $rows))."\n";
    }
}
