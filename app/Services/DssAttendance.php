<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The DSS attendance page — Phase 8.1 of docs/work.md, as Ray.dc.html draws it.
 *
 * The month as the county wants to see it: a row per child, a column per
 * weekday, the times in each cell, and the week's hours against the hours
 * the subsidy authorised. It reads the same `attendances` rows the register
 * and the kiosk write, and every change made here is written back to them —
 * with an amendment, for a day already gone — so there is one record of a
 * child's day, not a second one kept for the county.
 *
 * A time is a string here ("8:00a") the whole way out and back; the maths
 * is done in minutes past midnight, the way the staff clock does it.
 */
class DssAttendance
{
    private const WEEKDAYS = [1, 2, 3, 4, 5];

    private string $tz;

    public function __construct()
    {
        $this->tz = config('app.timezone');
    }

    /* ---------------------------------------------------------------
     * Words for times
     * --------------------------------------------------------------- */

    /** Minutes past midnight as "8:00a" / "3:30p". */
    public static function clock(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return (($h + 11) % 12 + 1).':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).($h >= 12 ? 'p' : 'a');
    }

    /** "8:00a", "8:00 AM", "08:00", "3p" → minutes; null when it is not a time. */
    public static function minutes(?string $text): ?int
    {
        // Anchored at both ends: "9abc" or "8:05xyz" is not a time, and was
        // read as one until 29 Oct 2026.
        if (! preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*(?:([ap])\.?m?\.?)?\s*$/i', (string) $text, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        $ap = strtolower($m[3] ?? '');

        if ($ap !== '') {
            $h = $h % 12 + ($ap === 'p' ? 12 : 0);
        } elseif ($h < 7) {
            // "3:00" with no letter at a daycare is three in the afternoon.
            $h += 12;
        }

        return $h > 23 || $min > 59 ? null : $h * 60 + $min;
    }

    private static function fromTime(?string $hhmm): ?int
    {
        return blank($hhmm) ? null : (int) Carbon::parse($hhmm)->format('G') * 60 + (int) Carbon::parse($hhmm)->format('i');
    }

    /* ---------------------------------------------------------------
     * What each child agreed to
     * --------------------------------------------------------------- */

    /**
     * The agreed stretches of a day, as [from, to] in minutes.
     *
     * One for most rooms. School Age is two: the morning before school and
     * the afternoon after it, from the room's own hours in config.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function agreedSegments(Child $child): array
    {
        $in = self::fromTime($child->drop_off_time);
        $out = self::fromTime($child->pick_up_time);

        if ($in === null || $out === null || $out <= $in) {
            return [];
        }

        if (count($child->sessions()) === 1) {
            return [[$in, $out]];
        }

        $leaves = self::fromTime(config('daycare.school_age_leaves_at', '08:30'));
        $returns = self::fromTime(config('daycare.school_age_returns_at', '15:00'));
        $segments = [];

        if ($in < $leaves) {
            $segments[] = [$in, min($out, $leaves)];
        }
        if ($out > $returns) {
            $segments[] = [max($in, $returns), $out];
        }

        return $segments ?: [[$in, $out]];
    }

    /** Weekday numbers (1 = Monday) the child is booked for; every weekday when none are set. */
    private function bookedDays(Child $child): array
    {
        $days = $child->scheduleDays();

        return $days === null || $days === [] ? self::WEEKDAYS : array_values(array_map('intval', $days));
    }

    /* ---------------------------------------------------------------
     * The month
     * --------------------------------------------------------------- */

    /**
     * Everyone the page may list in a month: active children, and anyone
     * withdrawn during it, who still has days to claim up to their last day.
     */
    public function children(Carbon $start, Carbon $end): Collection
    {
        return Child::query()
            ->where(function ($q) use ($start) {
                $q->where('status', 'Active')
                    ->orWhere(fn ($w) => $w->whereNotNull('withdrawn_on')->whereDate('withdrawn_on', '>=', $start->toDateString()));
            })
            ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $end->toDateString()))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
    }

    /**
     * The whole grid for one month.
     *
     * @param  list<string>  $rooms  Class filter; empty is every class.
     */
    public function month(int $year, int $month, array $rooms = [], bool $subsidyOnly = true, string $search = ''): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();
        $today = today()->toDateString();

        $all = $this->children($start, $end);
        $subsidyCount = $all->filter(fn (Child $c) => $c->isDss())->count();
        $roomCounts = [];
        foreach (ClassroomAssignment::rooms() as $room) {
            $roomCounts[$room] = $all->filter(fn (Child $c) => (! $subsidyOnly || $c->isDss()) && $c->classroom === $room)->count();
        }

        $children = $all
            ->filter(fn (Child $c) => ! $subsidyOnly || $c->isDss())
            ->filter(fn (Child $c) => $rooms === [] || in_array($c->classroom, $rooms, true))
            ->filter(fn (Child $c) => $search === '' || str_contains(strtolower($c->first_name.' '.$c->last_name.' '.$c->lan.' '.$c->dss_cin.' '.$c->dss_case_no), strtolower($search)))
            ->values();

        $closed = ClosureDay::betweenDates($start, $end)->get()->mapWithKeys(fn ($d) => [$d->closed_on->toDateString() => $d->label()])->all();

        $days = [];
        $weeks = [];
        $weekKey = null;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! in_array($d->dayOfWeekIso, self::WEEKDAYS, true)) {
                continue;
            }
            $iso = $d->toDateString();
            $weekKey ??= $iso;
            $days[] = ['date' => $iso, 'd' => $d->day, 'dow' => substr($d->format('D'), 0, 2), 'closed' => $closed[$iso] ?? null, 'today' => $iso === $today, 'future' => $iso > $today, 'week' => $weekKey];
            $weeks[$weekKey] = ['key' => $weekKey, 'from' => Carbon::parse($weekKey)->day, 'to' => $d->day, 'done' => $d->lt(today())];
            if ($d->dayOfWeekIso === 5) {
                $weekKey = null;
            }
        }

        $records = Attendance::whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get()
            ->groupBy([fn (Attendance $a) => $a->child_id, fn (Attendance $a) => $a->attendance_date->toDateString()]);

        $marks = DssDayMark::whereBetween('marked_on', [$start->toDateString(), $end->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get()
            ->keyBy(fn (DssDayMark $m) => $m->child_id.'|'.$m->marked_on->toDateString());

        $rows = $children->map(fn (Child $child) => $this->row($child, $days, $weeks, $records->get($child->id, collect()), $marks, $today))->values()->all();

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'prev' => $start->copy()->subMonth()->format('Y-m'),
            'next' => $start->copy()->addMonth()->format('Y-m'),
            'today' => $today,
            'days' => $days,
            'weeks' => array_values($weeks),
            'rooms' => array_map(fn ($room) => ['name' => $room, 'color' => ScheduleEditor::colour($room)[1], 'n' => $roomCounts[$room]], ClassroomAssignment::rooms()),
            'total' => $all->filter(fn (Child $c) => ! $subsidyOnly || $c->isDss())->count(),
            'subsidyCount' => $subsidyCount,
            'rows' => $rows,
        ];
    }

    /**
     * One child's month: the cell for every weekday, the week totals, and
     * the row's own numbers.
     */
    private function row(Child $child, array $days, array $weeks, Collection $records, Collection $marks, string $today): array
    {
        $agreed = $this->agreedSegments($child);
        $booked = $this->bookedDays($child);
        $cap = $child->isDss() && $child->expected_hours_per_week > 0 ? (float) $child->expected_hours_per_week : null;
        $split = count($child->sessions()) > 1;

        $cells = [];
        $wk = [];
        $minutes = 0;
        $nDays = 0;
        $agreedMinutes = 0;
        $pastBooked = 0;
        $allBooked = 0;
        $over = 0;

        foreach ($days as $day) {
            $iso = $day['date'];
            $dow = Carbon::parse($iso)->dayOfWeekIso;
            $scheduled = in_array($dow, $booked, true);
            $enrolled = $child->isEnrolledOn($iso);
            $future = $iso > $today;
            $isToday = $iso === $today;
            $mark = $marks->get($child->id.'|'.$iso);
            $onDay = $records->get($iso, collect());
            $segs = $this->segmentsOf($onDay);
            $w = &$wk[$day['week']];
            $w ??= ['act' => 0, 'plan' => 0];

            $agreedDay = array_sum(array_map(fn ($g) => $g[1] - $g[0], $agreed));
            if ($day['closed'] === null && $scheduled && $enrolled && $mark?->mark !== DssDayMark::NONE) {
                $agreedMinutes += $agreedDay;
                $allBooked++;
                if (! $future && ! $isToday) {
                    $pastBooked++;
                }
            }

            $cell = ['st' => 'none', 'cls' => 'c-none', 'lines' => [['t' => '·', 'cls' => '']], 'tip' => 'Not scheduled', 'segs' => [], 'editable' => true, 'future' => $future];

            if ($day['closed'] !== null) {
                $cell = ['st' => 'closed', 'cls' => 'c-closed', 'lines' => [['t' => 'Closed', 'cls' => '']], 'tip' => $day['closed'], 'segs' => [], 'editable' => false, 'future' => $future];
            } elseif (! $enrolled) {
                $cell = ['st' => 'gone', 'cls' => 'c-gone', 'lines' => [['t' => '—', 'cls' => '']], 'tip' => $child->withdrawn_on && $iso > $child->withdrawn_on->toDateString() ? 'Not enrolled after '.$child->withdrawn_on->format('M j') : 'Not enrolled yet', 'segs' => [], 'editable' => false, 'future' => $future];
            } elseif ($mark?->mark === DssDayMark::ABSENT) {
                $cell = ['st' => 'abs', 'cls' => 'c-abs', 'lines' => [['t' => 'Abs', 'cls' => '']], 'tip' => 'Absent', 'segs' => [], 'editable' => true, 'future' => $future];
            } elseif ($mark?->mark === DssDayMark::NONE) {
                // Marked not scheduled by hand: the default look, on purpose.
            } elseif ($segs !== []) {
                $cell = $this->timedCell($segs, $scheduled, $split, $isToday, $agreed);
                foreach ($segs as $g) {
                    if ($g[0] !== null && $g[1] !== null) {
                        $w['act'] += $g[1] - $g[0];
                        $minutes += $g[1] - $g[0];
                    } elseif ($g[0] !== null && $isToday && $agreed !== []) {
                        $w['plan'] += max(0, end($agreed)[1] - $g[0]);
                    }
                }
                $nDays++;
            } elseif ($scheduled && $future) {
                $lines = [];
                foreach ($agreed as $n => $g) {
                    $lines[] = ['t' => self::clock($g[0]), 'cls' => $n ? 'pm' : ''];
                    $lines[] = ['t' => self::clock($g[1]), 'cls' => ''];
                }
                $cell = ['st' => 'plan', 'cls' => 'c-plan', 'lines' => $lines ?: [['t' => '·', 'cls' => '']], 'tip' => 'Planned · '.$this->segText($agreed), 'segs' => $this->segStrings($agreed), 'editable' => true, 'future' => true];
                $w['plan'] += $agreedDay;
            } elseif ($scheduled) {
                $cell = ['st' => 'open', 'cls' => 'c-open', 'lines' => [['t' => 'no time', 'cls' => '']], 'tip' => 'Expected — no times yet', 'segs' => $this->segStrings($agreed), 'editable' => true, 'future' => false];
                if ($isToday) {
                    $w['plan'] += $agreedDay;
                }
            }

            $cell['tip'] = Carbon::parse($iso)->format('D M j').' · '.$cell['tip'];
            $cells[$iso] = $cell;
            unset($w);
        }

        $weekCells = [];
        foreach ($weeks as $week) {
            $t = $wk[$week['key']] ?? ['act' => 0, 'plan' => 0];
            $act = round($t['act'] / 60, 1);
            $tot = round(($t['act'] + $t['plan']) / 60, 1);
            $done = $week['done'];
            $partial = ! $done && $t['act'] > 0;
            $cls = $done ? 'okc' : 'proj';
            $small = $cap ? '/ '.self::num($cap).' h' : 'h';

            if ($cap && $act > $cap) {
                $cls = 'over';
                if ($done) {
                    $over++;
                }
            } elseif ($cap && $tot > $cap && ! $done) {
                $cls = 'risk';
            }

            $big = $done || $t['plan'] === 0 ? self::num($act) : ($partial ? self::num($act) : self::num($tot));
            if ($partial && $t['plan'] > 0) {
                $small = '→'.self::num($tot).($cap ? ' / '.self::num($cap) : '');
            }

            $weekCells[$week['key']] = [
                'cls' => $cls, 'big' => $big, 'small' => $small,
                'tip' => Carbon::parse($week['key'])->format('M j').'–'.$week['to'].': '.self::num($act).' h so far'.($t['plan'] ? ', '.self::num($tot).' h if the rest goes as planned' : '').($cap ? ' · authorized '.self::num($cap).' h'.($act > $cap ? ' · OVER by '.self::num($act - $cap).' h' : ($tot > $cap ? ' · will go over' : '')) : ''),
            ];
        }

        $hours = round($minutes / 60, 1);
        $agreedHours = round($agreedMinutes / 60);
        $pastShare = $allBooked > 0 ? $pastBooked / $allBooked : 0;
        $cin = $child->isDss()
            ? (filled($child->dss_cin) ? $child->dss_cin : 'CIN missing').($cap ? ' · '.self::num($cap).' h/wk' : '')
            : 'private pay';

        return [
            'id' => $child->id,
            'first' => $child->first_name,
            'last' => $child->last_name,
            'lan' => $child->lan,
            'room' => $child->classroom,
            'color' => ScheduleEditor::colour($child->classroom)[1],
            'split' => $split,
            'agreed' => $agreed !== [] ? $this->segText($agreed) : 'hours not agreed',
            'agreedSegs' => $this->segStrings($agreed),
            'daysTxt' => $booked === self::WEEKDAYS ? 'Mon–Fri' : implode(', ', array_map(fn ($n) => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$n], $booked)),
            'cin' => $cin,
            'cinColor' => $child->isDss() && blank($child->dss_cin) ? '#be123c' : '#8a90a0',
            'subsidy' => $child->isDss(),
            'cinMissing' => $child->isDss() && blank($child->dss_cin),
            'wd' => $child->withdrawn_on ? 'Withdrawn · last day '.$child->withdrawn_on->format('M j').(filled($child->withdrawal_reason) ? ' · '.$child->withdrawal_reason : '').(filled($child->withdrawal_note) ? ' — '.$child->withdrawal_note : '') : '',
            'note' => (string) ($child->enrollment_note ?? ''),
            'cells' => $cells,
            'weeks' => $weekCells,
            'over' => $over,
            'nDays' => $nDays,
            'hrs' => self::num($hours).' h',
            'diff' => $agreedHours.' h / mo',
            'diffCls' => $hours > 0 && $hours > $agreedHours * $pastShare * 1.08 ? 'warn' : 'mut',
        ];
    }

    /** "8:00a–5:00p, 3:00p–6:00p". */
    private function segText(array $segments): string
    {
        return implode(', ', array_map(fn ($g) => self::clock($g[0]).'–'.self::clock($g[1]), $segments));
    }

    /** @return list<array{in: ?string, out: ?string}> */
    private function segStrings(array $segments): array
    {
        return array_map(fn ($g) => ['in' => self::clock($g[0]), 'out' => self::clock($g[1])], $segments);
    }

    /**
     * The day's attendance rows as [in, out] minutes, AM before PM, out
     * null while the child is still in.
     *
     * @return list<array{0: ?int, 1: ?int}>
     */
    private function segmentsOf(Collection $onDay): array
    {
        return $onDay
            ->filter(fn (Attendance $a) => $a->signed_in_at !== null)
            ->sortBy(fn (Attendance $a) => $a->signed_in_at->timestamp)
            ->map(fn (Attendance $a) => [
                $this->minutesOf($a->signed_in_at),
                $a->signed_out_at ? $this->minutesOf($a->signed_out_at) : null,
            ])
            ->values()->all();
    }

    private function minutesOf(Carbon $at): int
    {
        $local = $at->copy()->timezone($this->tz);

        return $local->hour * 60 + $local->minute;
    }

    /** A cell with times on it, drawn the way the mock-up draws one. */
    private function timedCell(array $segs, bool $scheduled, bool $split, bool $isToday, array $agreed): array
    {
        $t = fn ($m) => $m === null ? '—' : self::clock($m);
        $lines = [];

        if ($split) {
            $am = count($segs) > 1 ? $segs[0] : ($segs[0][0] !== null && $segs[0][0] < 12 * 60 ? $segs[0] : null);
            $pm = count($segs) > 1 ? $segs[1] : ($am ? null : $segs[0]);
            if (count($agreed) > 1 || $am) {
                $lines[] = ['t' => $am ? $t($am[0]) : '—', 'cls' => ''];
                $lines[] = ['t' => $am ? $t($am[1]) : '—', 'cls' => ''];
            }
            $lines[] = ['t' => $pm ? $t($pm[0]) : '—', 'cls' => $lines ? 'pm' : ''];
            $lines[] = ['t' => $pm ? $t($pm[1]) : '—', 'cls' => ''];
        } else {
            $lines[] = ['t' => $t($segs[0][0]), 'cls' => ''];
            $lines[] = ['t' => $t($segs[0][1]), 'cls' => ''];
        }

        $text = implode(', ', array_map(fn ($g) => $t($g[0]).'–'.$t($g[1]), $segs));

        return [
            'st' => 'pres',
            'cls' => $scheduled ? 'c-in' : 'c-bill',
            'lines' => $lines,
            'tip' => $text.($scheduled ? '' : ' · not one of their days').($isToday && end($segs)[1] === null ? ' · still in' : ''),
            'segs' => array_map(fn ($g) => ['in' => self::clock($g[0]), 'out' => self::clock($g[1])], $segs),
            'editable' => true,
            'future' => false,
        ];
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1), '0'), '.');
    }

    /* ---------------------------------------------------------------
     * Changing a day
     * --------------------------------------------------------------- */

    /**
     * Put one state on one child's day.
     *
     * pres with segments writes the attendance rows — one per session, with
     * an amendment for a day already gone; absent and none take the rows off
     * and leave the word; clear takes everything off.
     *
     * @param  array{st: string, segs?: list<array{in: ?string, out: ?string}>}  $state
     */
    public function apply(Child $child, string $date, array $state, User $by): void
    {
        $state['st'] = $state['st'] ?? 'clear';

        if ($date > today()->toDateString() && $state['st'] === 'pres') {
            throw new \InvalidArgumentException('Times can only be put on a day that has happened.');
        }

        DB::transaction(function () use ($child, $date, $state, $by) {
            DssDayMark::where('child_id', $child->id)->whereDate('marked_on', $date)->delete();

            if ($state['st'] === 'abs' || $state['st'] === 'none') {
                // An Absent mark remembers the times it takes off, so the
                // register's tap back to Coming can put them back (27 Oct 2026).
                $kept = Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->whereNotNull('signed_in_at')->get()
                    ->map(fn (Attendance $row) => ['session' => $row->session ?? 'FULL', 'in' => $row->signed_in_at->toIso8601String(), 'out' => $row->signed_out_at?->toIso8601String()])
                    ->values()->all();
                $this->removeRows($child, $date, $by, []);
                DssDayMark::create([
                    'child_id' => $child->id, 'marked_on' => $date, 'created_by' => $by->id,
                    'mark' => $state['st'] === 'abs' ? DssDayMark::ABSENT : DssDayMark::NONE,
                    'kept' => $state['st'] === 'abs' && $kept !== [] ? $kept : null,
                ]);

                return;
            }

            if ($state['st'] !== 'pres') {
                $this->removeRows($child, $date, $by, []);

                return;
            }

            $wanted = $this->sessionsFor($child, $state['segs'] ?? []);
            $this->removeRows($child, $date, $by, array_keys($wanted));

            foreach ($wanted as $session => [$in, $out]) {
                $row = Attendance::firstOrNew(['child_id' => $child->id, 'attendance_date' => $date, 'session' => $session]);
                $was = $row->exists ? $row->signed_in_at : null;
                $row->signed_in_at = Carbon::parse($date, $this->tz)->startOfDay()->addMinutes($in);
                $row->signed_out_at = $out === null ? null : Carbon::parse($date, $this->tz)->startOfDay()->addMinutes($out);
                $isNew = ! $row->exists;
                $row->save();

                AttendanceAmendment::record($isNew ? AttendanceAmendment::ADDED : AttendanceAmendment::RETIMED, $row, $by, $was ?? $row->signed_in_at);
            }
        });
    }

    /**
     * Which session each typed stretch is, as session => [in, out].
     *
     * @return array<string, array{0: int, 1: ?int}>
     */
    private function sessionsFor(Child $child, array $segs): array
    {
        $segs = array_values(array_filter(array_map(fn ($g) => [self::minutes($g['in'] ?? null), self::minutes($g['out'] ?? null)], $segs), fn ($g) => $g[0] !== null));

        foreach ($segs as $g) {
            if ($g[1] !== null && $g[1] <= $g[0]) {
                throw new \InvalidArgumentException('The time out has to be after the time in.');
            }
        }

        if ($segs === []) {
            throw new \InvalidArgumentException('Type a time in first.');
        }

        if (count($child->sessions()) === 1) {
            return ['FULL' => $segs[0]];
        }

        if (count($segs) >= 2) {
            return ['AM' => $segs[0], 'PM' => $segs[1]];
        }

        return [$segs[0][0] < 12 * 60 ? 'AM' : 'PM' => $segs[0]];
    }

    /** Take the day's rows off, except the sessions about to be rewritten. */
    private function removeRows(Child $child, string $date, User $by, array $keep): void
    {
        foreach (Attendance::where('child_id', $child->id)->whereDate('attendance_date', $date)->get() as $row) {
            if (in_array($row->session, $keep, true)) {
                continue;
            }
            AttendanceAmendment::record(AttendanceAmendment::REMOVED, $row, $by, $row->signed_in_at);
            $row->delete();
        }
    }

    /* ---------------------------------------------------------------
     * Export for KinderConnect
     * --------------------------------------------------------------- */

    /**
     * The rows the county's system takes: one per visit, School Age as
     * separate AM and PM rows, days already gone only.
     *
     * @return array{range: string, from: string, to: string, header: list<string>, rows: list<list<string>>, records: list<array>, checks: list<array{tag: string, cls: string, text: string}>}
     */
    public function export(int $year, int $month, array $rooms, bool $subsidyOnly, string $range, bool $withAbsences): array
    {
        $data = $this->month($year, $month, $rooms, $subsidyOnly);
        $start = Carbon::create($year, $month, 1);
        $yesterday = today()->subDay();
        $from = $range === 'week' ? max($start, today()->startOfWeek(Carbon::MONDAY)) : $start;
        $to = min($start->copy()->endOfMonth(), $yesterday);

        $header = ['Child Last Name', 'Child First Name', 'Date of Birth', 'CIN', 'DSS Case Number', 'Class', 'Date', 'Session', 'Time In', 'Time Out', 'Absent'];
        $rows = [];
        $records = [];
        $missingCin = 0;
        $long = 0;
        $overCap = [];

        $children = Child::whereIn('id', array_column($data['rows'], 'id'))->get()->keyBy('id');

        foreach ($data['rows'] as $row) {
            if (! $row['subsidy']) {
                continue;
            }
            $child = $children[$row['id']];
            if (blank($child->dss_cin)) {
                $missingCin++;
            }
            if ($row['over'] > 0) {
                $overCap[] = $row['first'].' '.$row['last'].' ('.$row['over'].' wk)';
            }
            $dob = $child->birthDate()?->format('m/d/Y') ?? '';

            foreach ($row['cells'] as $iso => $cell) {
                if ($iso < $from->toDateString() || $iso > $to->toDateString()) {
                    continue;
                }
                $date = Carbon::parse($iso)->format('m/d/Y');
                $base = [$row['last'], $row['first'], $dob, (string) $child->dss_cin, (string) $child->dss_case_no, (string) $row['room'], $date];

                if ($cell['st'] === 'abs') {
                    if ($withAbsences) {
                        $rows[] = [...$base, '', '', '', 'Y'];
                        $records[] = ['lan' => $row['lan'], 'last' => $row['last'], 'first' => $row['first'], 'dob' => $dob, 'cin' => $child->dss_cin, 'caseNo' => $child->dss_case_no, 'class' => $row['room'], 'date' => $date, 'absent' => true];
                    }
                    continue;
                }
                if ($cell['st'] !== 'pres') {
                    continue;
                }

                foreach ($cell['segs'] as $n => $g) {
                    $in = self::minutes($g['in']);
                    $out = self::minutes($g['out']);
                    if ($in === null) {
                        continue;
                    }
                    if ($out !== null && $out - $in > 10 * 60) {
                        $long++;
                    }
                    $session = $row['split'] ? (count($cell['segs']) > 1 ? ($n === 0 ? 'AM' : 'PM') : ($in >= 12 * 60 ? 'PM' : 'AM')) : 'Full day';
                    $tin = strtoupper(str_replace(['a', 'p'], [' AM', ' PM'], $g['in']));
                    $tout = $g['out'] === null ? '' : strtoupper(str_replace(['a', 'p'], [' AM', ' PM'], $g['out']));
                    $rows[] = [...$base, $session, $tin, $tout, 'N'];
                    $records[] = ['lan' => $row['lan'], 'last' => $row['last'], 'first' => $row['first'], 'dob' => $dob, 'cin' => $child->dss_cin, 'caseNo' => $child->dss_case_no, 'class' => $row['room'], 'date' => $date, 'session' => $session, 'timeIn' => $tin, 'timeOut' => $tout, 'absent' => false];
                }
            }
        }

        $checks = [];
        if ($missingCin) {
            $checks[] = ['tag' => 'Fix first', 'cls' => 'bad', 'text' => $missingCin.' subsidy '.($missingCin > 1 ? 'children have' : 'child has').' no CIN — add it on the child’s record or KinderConnect will reject those rows.'];
        }
        if ($overCap) {
            $checks[] = ['tag' => 'Over cap', 'cls' => 'bad', 'text' => implode(', ', $overCap).' went over their authorized weekly hours — the extra time may not be paid.'];
        }
        if ($long) {
            $checks[] = ['tag' => 'Check', 'cls' => 'warn', 'text' => $long.' '.($long === 1 ? 'visit is' : 'visits are').' longer than 10 hours.'];
        }
        $checks[] = ['tag' => 'OK', 'cls' => 'ok', 'text' => 'Today ('.today()->format('M j').') and future days are left out — only days that already happened can be claimed.'];

        return [
            'range' => $from->format('M j').' – '.$to->format('M j, Y').' · '.($rooms ? implode(' + ', $rooms) : 'all classes').' · '.($subsidyOnly ? 'subsidy children' : 'all children with a CIN'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'header' => $header,
            'rows' => $rows,
            'records' => $records,
            'checks' => $checks,
        ];
    }

    /** The CSV, the way a spreadsheet and KinderConnect both read it. */
    public static function csv(array $header, array $rows): string
    {
        $line = fn (array $cells) => implode(',', array_map(fn ($v) => preg_match('/[",\n]/', (string) $v) ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v, $cells));

        return implode("\n", array_merge([$line($header)], array_map($line, $rows)))."\n";
    }
}
