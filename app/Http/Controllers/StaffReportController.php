<?php

namespace App\Http\Controllers;

use App\Exports\StaffReportExport;
use App\Models\Department;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Staff reports: a report is chosen, a period is given, and a table comes back.
 *
 * Separate from the timesheet week next to it. That screen is opened every
 * morning to answer "is everybody here and did yesterday go in properly", so
 * it shows punch times and a coloured dot. These are opened to produce
 * something somebody else will use — a fortnight of hours handed to payroll, a
 * roster handed to licensing — so each one is a table and nothing else.
 *
 * Every report is built to the same shape: a list of columns and a list of
 * rows. That is what lets one blade draw all of them and one export class
 * write all of them, so adding the sixteenth report is a method rather than a
 * page, an exporter and a route.
 *
 * Read only. Nothing here writes a punch or an adjustment; the hours are the
 * ones the clock already computed, read back through the same service the
 * timesheet screens read.
 */
class StaffReportController extends Controller
{
    /**
     * Every report, in the order the dropdown offers them.
     *
     *   period — how the report is dated. A number is a fixed run of days from
     *            the start date, 'range' takes two dates, and 'none' is a
     *            report about right now, which has no period at all.
     *   pay    — whether the "show pay" box does anything to it. A report
     *            without rates in it should not pretend the box is live.
     *
     * Grouped the way somebody choosing thinks: attendance first, then hours,
     * then the roster, then the audit trail.
     */
    public const REPORTS = [
        'attendance-counter' => ['label' => 'Attendance Counter', 'period' => 'range', 'pay' => false],
        'attendance-only' => ['label' => 'Attendance-Only', 'period' => 'range', 'pay' => false],
        'daily-absence' => ['label' => 'Daily Attendance Absence', 'period' => 'range', 'pay' => false],
        'current-status' => ['label' => 'Current Employee Status', 'period' => 'none', 'pay' => false],

        'daily-summary-1w' => ['label' => 'Employee Daily Summary — One Week', 'period' => 7, 'pay' => true],
        'daily-summary-2w' => ['label' => 'Employee Daily Summary — Two Weeks', 'period' => 14, 'pay' => true],
        'employee-summary' => ['label' => 'Employee Summary', 'period' => 'range', 'pay' => true],
        'date-wise-summary' => ['label' => 'Employee Date Wise Summary', 'period' => 'range', 'pay' => false],
        'weekday-summary' => ['label' => 'Employee Weekday Summary', 'period' => 'range', 'pay' => false],
        'role-summary' => ['label' => 'Role Summary', 'period' => 'range', 'pay' => true],
        'department-summary' => ['label' => 'Department Summary', 'period' => 'range', 'pay' => true],

        'employee-list' => ['label' => 'Employee List', 'period' => 'none', 'pay' => true],
        'employee-details' => ['label' => 'Employee Details', 'period' => 'none', 'pay' => true],
        'role-members' => ['label' => 'Role Members', 'period' => 'none', 'pay' => false],
        'department-members' => ['label' => 'Department Member', 'period' => 'none', 'pay' => false],

        'employee-activity' => ['label' => 'Employee Activity', 'period' => 'range', 'pay' => false],
        'manual-adjustments' => ['label' => 'Manual Time Adjustments', 'period' => 'range', 'pay' => false],
    ];

    public const DEFAULT_REPORT = 'daily-summary-2w';

    /**
     * How long a 'range' report may run.
     *
     * A quarter is already a table nobody reads to the end of, and the day
     * reports draw a column per day on top of that. Asking for more is
     * answered with the first ninety-two days and a line saying so, rather
     * than with a refusal — the usual cause is somebody dragging past what
     * they meant.
     */
    public const MAX_DAYS = 92;

    public function __construct(private TimeClock $clock) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $table = $this->build($filters);

        return view('reports.staff', [
            'reports' => self::REPORTS,
            'meta' => self::REPORTS[$filters['report']],
            'report' => $filters['report'],
            'start' => $filters['start'],
            'end' => $filters['end'],
            'role' => $filters['role'],
            'roles' => User::jobRolesAmong($this->staff()),
            'department' => $filters['department'],
            // Only offered where departments have been set up: an empty
            // dropdown is a question with no answer.
            'departments' => Department::orderBy('name')->get(),
            'showPay' => $filters['pay'],
            'truncated' => $filters['truncated'],
            'columns' => $table['columns'],
            'rows' => $table['rows'],
            'generatedAt' => now(),
        ]);
    }

    /**
     * The same table as a file.
     *
     * Built through the same filters and the same builder as the page, so the
     * file and the screen can never disagree about a period.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $table = $this->build($filters);

        $meta = self::REPORTS[$filters['report']];

        $label = $filters['start'] === null
            ? now()->format('Y-m-d')
            : $filters['start']->format('Y-m-d').'-to-'.$filters['end']->format('Y-m-d');

        $name = $filters['report'].'-'.$label.'.'.($request->input('format') === 'csv' ? 'csv' : 'xlsx');

        return Excel::download(
            new StaffReportExport($table['columns'], $table['rows'], $meta['label'], $label),
            $name,
        );
    }

    /**
     * What was asked for, normalised.
     *
     * An unknown report falls back to the default rather than failing: the
     * usual cause is a bookmarked link from before a report was renamed, and
     * a table is a better answer to that than a 422.
     *
     * @return array{report: string, start: ?Carbon, end: ?Carbon, dates: Collection, role: string, pay: bool, truncated: bool}
     */
    private function filters(Request $request): array
    {
        $rules = ['nullable', 'date_format:Y-m-d'];

        validator($request->only('start', 'end'), ['start' => $rules, 'end' => $rules])->validate();

        $report = (string) $request->input('report');

        if (! array_key_exists($report, self::REPORTS)) {
            $report = self::DEFAULT_REPORT;
        }

        $period = self::REPORTS[$report]['period'];
        $pay = self::REPORTS[$report]['pay'] && $request->boolean('pay');

        // A report about right now has no period, and offering one would only
        // invite somebody to set it and wonder why nothing moved.
        if ($period === 'none') {
            return [
                'report' => $report, 'start' => null, 'end' => null, 'dates' => collect(),
                'role' => trim((string) $request->input('role')),
                'department' => trim((string) $request->input('department')),
                'pay' => $pay, 'truncated' => false,
            ];
        }

        $start = blank($request->input('start'))
            ? today()->startOfWeek(Carbon::MONDAY)
            : Carbon::parse($request->input('start'))->startOfDay();

        if (is_int($period)) {
            $end = $start->copy()->addDays($period - 1);
            $truncated = false;
        } else {
            $end = blank($request->input('end'))
                ? $start->copy()->addDays(6)
                : Carbon::parse($request->input('end'))->startOfDay();

            // Given backwards, they are swapped rather than refused: dragging
            // right to left across a calendar is a way of choosing a range.
            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }

            $truncated = $start->diffInDays($end) + 1 > self::MAX_DAYS;

            if ($truncated) {
                $end = $start->copy()->addDays(self::MAX_DAYS - 1);
            }
        }

        $dates = collect();

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates->push($day->copy());
        }

        return [
            'report' => $report,
            'start' => $start,
            'end' => $end,
            'dates' => $dates,
            'role' => trim((string) $request->input('role')),
            'department' => trim((string) $request->input('department')),
            'pay' => $pay,
            'truncated' => $truncated,
        ];
    }

    /**
     * Hand the filters to the report that was asked for.
     *
     * @return array{columns: array<int, array{label: string, align: string}>, rows: Collection}
     */
    private function build(array $filters): array
    {
        $staff = $this->rostered($filters['role'], $filters['department']);

        return match ($filters['report']) {
            'attendance-counter' => $this->attendanceCounter($staff, $filters),
            'attendance-only' => $this->attendanceOnly($staff, $filters),
            'daily-absence' => $this->dailyAbsence($staff, $filters),
            'current-status' => $this->currentStatus($staff),
            'daily-summary-1w', 'daily-summary-2w' => $this->dailySummary($staff, $filters),
            'employee-summary' => $this->employeeSummary($staff, $filters),
            'date-wise-summary' => $this->dateWiseSummary($staff, $filters),
            'weekday-summary' => $this->weekdaySummary($staff, $filters),
            'role-summary' => $this->groupedSummary($staff, $filters, 'Role', fn (User $p) => $p->jobRole()),
            'department-summary' => $this->groupedSummary($staff, $filters, 'Department', fn (User $p) => $p->department?->name ?? 'Unassigned'),
            'employee-list' => $this->employeeList($staff, $filters),
            'employee-details' => $this->employeeDetails($staff, $filters),
            'role-members' => $this->members($staff, 'Role', fn (User $p) => $p->jobRole()),
            'department-members' => $this->members($staff, 'Department', fn (User $p) => $p->department?->name ?? 'Unassigned'),
            'employee-activity' => $this->employeeActivity($staff, $filters, onlyManual: false),
            'manual-adjustments' => $this->employeeActivity($staff, $filters, onlyManual: true),
        };
    }

    /* ---------------------------------------------------------------- *
     |  Attendance                                                      |
     * ---------------------------------------------------------------- */

    /** How many were in and how many were not, a row per day. */
    private function attendanceCounter(Collection $staff, array $filters): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);

        $rows = $filters['dates']->map(function (Carbon $date) use ($staff, $worked) {
            $iso = $date->toDateString();
            $present = $staff->filter(fn (User $p) => ($worked[$p->id][$iso] ?? 0) > 0);
            $minutes = $present->sum(fn (User $p) => $worked[$p->id][$iso]);

            return [
                $date->format('Y-m-d'),
                $date->format('D'),
                $present->count(),
                $staff->count() - $present->count(),
                self::hhmm($minutes),
            ];
        });

        return [
            'columns' => $this->columns([
                ['Date'], ['Day'], ['Worked', 'center'], ['Absent', 'center'], ['Total hours', 'center'],
            ]),
            'rows' => $rows,
        ];
    }

    /**
     * Who was in and at what time — the times alone, no hours.
     *
     * The point of this one is the clock face, so a day nobody punched is left
     * out rather than printed as a row of dashes.
     */
    private function attendanceOnly(Collection $staff, array $filters): array
    {
        $rows = collect();

        foreach ($filters['dates'] as $date) {
            $iso = $date->toDateString();

            foreach ($staff as $person) {
                $day = $this->day($person, $iso);

                if ($day['first_in'] === null) {
                    continue;
                }

                $rows->push([
                    $iso,
                    $date->format('D'),
                    $person->staffId(),
                    $person->name,
                    $person->jobRole(),
                    $this->clockTime($day['first_in']),
                    $this->clockTime($day['last_out']) ?? '—',
                ]);
            }
        }

        return [
            'columns' => $this->columns([
                ['Date'], ['Day'], ['ID'], ['Employee Name'], ['Role'], ['Time in', 'center'], ['Time out', 'center'],
            ]),
            'rows' => $rows,
        ];
    }

    /**
     * Who was not in, a row per person per day.
     *
     * "Absent" here means no punch, which is not the same as unauthorised —
     * a day off and a no-show look identical to a clock. The column says
     * nothing more than the clock knows.
     */
    private function dailyAbsence(Collection $staff, array $filters): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);
        $rows = collect();

        foreach ($filters['dates'] as $date) {
            $iso = $date->toDateString();

            foreach ($staff as $person) {
                if (($worked[$person->id][$iso] ?? 0) > 0) {
                    continue;
                }

                $rows->push([
                    $iso,
                    $date->format('D'),
                    $person->staffId(),
                    $person->name,
                    $person->jobRole(),
                    $person->department?->name,
                ]);
            }
        }

        return [
            'columns' => $this->columns([
                ['Date'], ['Day'], ['ID'], ['Employee Name'], ['Role'], ['Department'],
            ]),
            'rows' => $rows,
        ];
    }

    /** Where everybody stands right now, which is the only period it has. */
    private function currentStatus(Collection $staff): array
    {
        $today = today()->toDateString();

        $rows = $staff->map(function (User $person) use ($today) {
            $day = $this->day($person, $today);

            $status = match (true) {
                $day['first_in'] === null => 'Not in',
                $day['open'] => 'Clocked in',
                default => 'Clocked out',
            };

            return [
                $person->staffId(),
                $person->name,
                $person->jobRole(),
                $status,
                $this->clockTime($day['first_in']) ?? '—',
                $this->clockTime($day['last_out']) ?? '—',
                self::hhmm($day['worked']),
            ];
        });

        return [
            'columns' => $this->columns([
                ['ID'], ['Employee Name'], ['Role'], ['Status'],
                ['First in', 'center'], ['Last out', 'center'], ['Today', 'center'],
            ]),
            'rows' => $rows,
        ];
    }

    /* ---------------------------------------------------------------- *
     |  Hours                                                           |
     * ---------------------------------------------------------------- */

    /** A person per row, a day per column — the fortnight payroll is handed. */
    private function dailySummary(Collection $staff, array $filters): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);

        $columns = [['ID'], ['Employee Name'], ['Role']];

        foreach ($filters['dates'] as $date) {
            $columns[] = [$date->format('D m/d'), 'center'];
        }

        $columns[] = ['Total', 'center'];

        $rows = $staff->map(function (User $person) use ($filters, $worked) {
            $row = [$person->staffId(), $person->name, $person->jobRole()];
            $total = 0;

            foreach ($filters['dates'] as $date) {
                $minutes = $worked[$person->id][$date->toDateString()] ?? 0;
                $total += $minutes;
                $row[] = self::hhmm($minutes);
            }

            $row[] = self::hhmm($total);

            return $this->withPay($row, $person, $total, $filters['pay']);
        });

        return ['columns' => $this->columns($this->payColumns($columns, $filters['pay'])), 'rows' => $rows];
    }

    /** What the period came to per person, and nothing about individual days. */
    private function employeeSummary(Collection $staff, array $filters): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);

        $columns = [
            ['ID'], ['Employee Name'], ['Role'], ['Employment'],
            ['Days worked', 'center'], ['Total', 'center'], ['Total hours', 'center'], ['Avg per day', 'center'],
        ];

        $rows = $staff->map(function (User $person) use ($worked, $filters) {
            $days = collect($filters['dates'])
                ->map(fn (Carbon $date) => $worked[$person->id][$date->toDateString()] ?? 0)
                ->filter(fn (int $minutes) => $minutes > 0);

            $total = $days->sum();

            return $this->withPay([
                $person->staffId(),
                $person->name,
                $person->jobRole(),
                $person->employment,
                $days->count(),
                self::hhmm($total),
                round($total / 60, 2),
                // An average over days nobody worked is not an average of
                // anything, so it is the days worked that divide it.
                $days->isEmpty() ? '—' : self::hhmm((int) round($total / $days->count())),
            ], $person, $total, $filters['pay']);
        });

        return ['columns' => $this->columns($this->payColumns($columns, $filters['pay'])), 'rows' => $rows];
    }

    /** A row per person per day worked, which is the shape a pivot wants. */
    private function dateWiseSummary(Collection $staff, array $filters): array
    {
        $rows = collect();

        foreach ($staff as $person) {
            foreach ($filters['dates'] as $date) {
                $iso = $date->toDateString();
                $day = $this->day($person, $iso);

                if ($day['worked'] <= 0 && $day['first_in'] === null) {
                    continue;
                }

                $rows->push([
                    $person->staffId(),
                    $person->name,
                    $person->jobRole(),
                    $iso,
                    $date->format('D'),
                    $this->clockTime($day['first_in']) ?? '—',
                    $this->clockTime($day['last_out']) ?? '—',
                    self::hhmm($day['unpaid_break']),
                    self::hhmm($day['worked']),
                    round($day['worked'] / 60, 2),
                ]);
            }
        }

        return [
            'columns' => $this->columns([
                ['ID'], ['Employee Name'], ['Role'], ['Date'], ['Day'],
                ['Time in', 'center'], ['Time out', 'center'], ['Unpaid break', 'center'],
                ['Worked', 'center'], ['Hours', 'center'],
            ]),
            'rows' => $rows,
        ];
    }

    /**
     * The same hours read by weekday instead of by date.
     *
     * What it answers is staffing rather than pay: whether Tuesdays are thin,
     * across however many weeks the range holds.
     */
    private function weekdaySummary(Collection $staff, array $filters): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);

        $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        $columns = [['ID'], ['Employee Name'], ['Role']];

        foreach ($weekdays as $weekday) {
            $columns[] = [$weekday, 'center'];
        }

        $columns[] = ['Total', 'center'];

        $rows = $staff->map(function (User $person) use ($filters, $worked, $weekdays) {
            $byWeekday = array_fill_keys($weekdays, 0);

            foreach ($filters['dates'] as $date) {
                $byWeekday[$date->format('D')] += $worked[$person->id][$date->toDateString()] ?? 0;
            }

            $row = [$person->staffId(), $person->name, $person->jobRole()];

            foreach ($weekdays as $weekday) {
                $row[] = self::hhmm($byWeekday[$weekday]);
            }

            $row[] = self::hhmm(array_sum($byWeekday));

            return $row;
        });

        return ['columns' => $this->columns($columns), 'rows' => $rows];
    }

    /**
     * The period totalled by a grouping rather than by person.
     *
     * One method for both the role and the department reports, because they
     * differ only in the callback that says which bucket somebody is in. The
     * two groupings are genuinely different — a Kitchen holds several jobs,
     * and a cook and a dishwasher are one department and two roles — but the
     * arithmetic underneath is identical.
     *
     * @param  callable(User): string  $groupBy
     */
    private function groupedSummary(Collection $staff, array $filters, string $heading, callable $groupBy): array
    {
        $worked = $this->workedMinutes($staff, $filters['dates']);

        $rows = $staff->groupBy($groupBy)
            ->sortKeys()
            ->map(function (Collection $people, string $group) use ($filters, $worked) {
                $total = 0;
                $daysWorked = 0;
                $pay = 0.0;

                foreach ($people as $person) {
                    $minutes = 0;

                    foreach ($filters['dates'] as $date) {
                        $day = $worked[$person->id][$date->toDateString()] ?? 0;
                        $minutes += $day;
                        $daysWorked += $day > 0 ? 1 : 0;
                    }

                    $total += $minutes;
                    $pay += round($minutes / 60, 2) * (float) $person->pay_rate;
                }

                $row = [
                    $group,
                    $people->count(),
                    $daysWorked,
                    self::hhmm($total),
                    round($total / 60, 2),
                    self::hhmm((int) round($total / max($people->count(), 1))),
                ];

                if ($filters['pay']) {
                    $row[] = round($pay, 2);
                }

                return $row;
            })
            ->values();

        $columns = [
            [$heading], ['Employees', 'center'], ['Days worked', 'center'],
            ['Total', 'center'], ['Total hours', 'center'], ['Avg per employee', 'center'],
        ];

        if ($filters['pay']) {
            $columns[] = ['Pay', 'right'];
        }

        return ['columns' => $this->columns($columns), 'rows' => $rows];
    }

    /* ---------------------------------------------------------------- *
     |  Roster                                                          |
     * ---------------------------------------------------------------- */

    /** The roster as it is usually asked for: who, what job, since when. */
    private function employeeList(Collection $staff, array $filters): array
    {
        $columns = [
            ['ID'], ['Employee Name'], ['Role'], ['Department'], ['Employment'], ['Title'],
            ['Start date'], ['Email'], ['Phone'],
        ];

        if ($filters['pay']) {
            $columns[] = ['Rate', 'right'];
        }

        $rows = $staff->map(function (User $person) use ($filters) {
            $row = [
                $person->staffId(),
                $person->name,
                $person->jobRole(),
                $person->department?->name,
                $person->employment,
                $person->title,
                $person->start_date?->format('Y-m-d'),
                $person->email,
                $person->phone,
            ];

            if ($filters['pay']) {
                $row[] = $person->pay_rate;
            }

            return $row;
        });

        return ['columns' => $this->columns($columns), 'rows' => $rows];
    }

    /** The fuller record, for the file a licensing visit asks to see. */
    private function employeeDetails(Collection $staff, array $filters): array
    {
        $columns = [
            ['ID'], ['Employee Name'], ['Legal name'], ['Role'], ['Department'], ['Employment'], ['Title'],
            ['Classroom'], ['Start date'], ['Date of birth'], ['Phone'],
            ['Emergency contact'], ['Emergency phone'], ['Transport'], ['Aspire ID'],
        ];

        if ($filters['pay']) {
            $columns[] = ['Rate', 'right'];
        }

        $rows = $staff->map(function (User $person) use ($filters) {
            $row = [
                $person->staffId(),
                $person->name,
                $person->legal_name,
                $person->jobRole(),
                $person->department?->name,
                $person->employment,
                $person->title,
                $person->classroom,
                $person->start_date?->format('Y-m-d'),
                $person->dob?->format('Y-m-d'),
                $person->phone,
                $person->emergency_contact,
                $person->emergency_phone,
                $person->transport,
                $person->aspire_id,
            ];

            if ($filters['pay']) {
                $row[] = $person->pay_rate;
            }

            return $row;
        });

        return ['columns' => $this->columns($columns), 'rows' => $rows];
    }

    /**
     * Everybody listed under the bucket they are in.
     *
     * Shared by the role and department versions for the same reason as
     * groupedSummary above: one list, two ways of grouping it.
     *
     * @param  callable(User): string  $groupBy
     */
    private function members(Collection $staff, string $heading, callable $groupBy): array
    {
        $rows = $staff
            ->sortBy([
                fn (User $a, User $b) => $groupBy($a) <=> $groupBy($b),
                fn (User $a, User $b) => $a->name <=> $b->name,
            ])
            ->map(fn (User $person) => [
                $groupBy($person),
                $person->staffId(),
                $person->name,
                $person->jobRole(),
                $person->employment,
                $person->title,
                $person->start_date?->format('Y-m-d'),
            ])
            ->values();

        return [
            'columns' => $this->columns([
                [$heading], ['ID'], ['Employee Name'], ['Role'], ['Employment'], ['Title'], ['Start date'],
            ]),
            'rows' => $rows,
        ];
    }

    /* ---------------------------------------------------------------- *
     |  Audit trail                                                     |
     * ---------------------------------------------------------------- */

    /**
     * Every punch in the period, or only the ones a supervisor wrote.
     *
     * One method for both because they are the same query with one clause
     * different, and the interesting columns — who recorded it, why, whether
     * it was later voided — are the same columns either way. Voided punches
     * are on it: a record that hides its own corrections is not an audit
     * trail.
     */
    private function employeeActivity(Collection $staff, array $filters, bool $onlyManual): array
    {
        $punches = TimePunch::with(['user', 'recorder'])
            ->whereIn('user_id', $staff->pluck('id'))
            ->whereBetween('work_date', [$filters['start']->toDateString(), $filters['end']->toDateString()])
            ->when($onlyManual, fn ($query) => $query->where('source', TimePunch::SOURCE_SUPERVISOR))
            ->orderBy('work_date')
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();

        $rows = $punches->map(fn (TimePunch $punch) => [
            $punch->work_date->format('Y-m-d'),
            $punch->punched_at->format('g:i A'),
            $punch->user?->staffId(),
            $punch->user?->name,
            TimePunch::LABELS[$punch->type] ?? $punch->type,
            $punch->source === TimePunch::SOURCE_SUPERVISOR ? 'Supervisor' : 'Clock',
            $punch->recorder?->name,
            $punch->reason,
            $punch->isVoided() ? 'Voided' : 'Counts',
        ]);

        return [
            'columns' => $this->columns([
                ['Date'], ['Time', 'center'], ['ID'], ['Employee Name'], ['Punch'],
                ['Source'], ['Recorded by'], ['Reason'], ['Status'],
            ]),
            'rows' => $rows,
        ];
    }

    /* ---------------------------------------------------------------- *
     |  Shared                                                          |
     * ---------------------------------------------------------------- */

    /** Everyone a report can cover, in the order it prints them. */
    private function staff(): Collection
    {
        return User::query()
            // Eager, or every report that groups or prints a department is a
            // query per person on a page built to be printed.
            ->with('department')
            ->whereIn('role', ['admin', 'teacher'])
            ->orderBy('name')
            ->get();
    }

    /**
     * The same people, narrowed to whatever was asked for.
     *
     * The department filter is an id rather than a name so that renaming a
     * department does not break every bookmarked report, and 'none' is a real
     * choice on it: "who is not in a department yet" is the question somebody
     * asks the week after setting departments up.
     */
    private function rostered(string $role, string $department): Collection
    {
        return $this->staff()
            ->when($role !== '', fn ($all) => $all->filter(fn (User $person) => $person->jobRole() === $role))
            ->when($department === 'none', fn ($all) => $all->filter(fn (User $person) => $person->department_id === null))
            ->when($department !== '' && $department !== 'none',
                fn ($all) => $all->filter(fn (User $person) => (string) $person->department_id === $department))
            ->values();
    }

    /**
     * Worked minutes for everybody, every day in the period.
     *
     * One query for the whole period rather than one per person per day. A
     * fortnight of twenty staff is 280 days, and read one at a time that is
     * 280 queries for a page somebody opens to print.
     *
     * @return array<int, array<string, int>>  Keyed by user, then by date.
     */
    private function workedMinutes(Collection $staff, Collection $dates): array
    {
        if ($staff->isEmpty() || $dates->isEmpty()) {
            return [];
        }

        $punches = TimePunch::live()
            ->whereIn('user_id', $staff->pluck('id'))
            ->whereBetween('work_date', [$dates->first()->toDateString(), $dates->last()->toDateString()])
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get()
            ->groupBy([fn (TimePunch $punch) => $punch->user_id, fn (TimePunch $punch) => $punch->work_date->toDateString()]);

        $worked = [];

        foreach ($staff as $person) {
            foreach ($dates as $date) {
                $iso = $date->toDateString();
                $onDay = $punches[$person->id][$iso] ?? collect();

                $worked[$person->id][$iso] = $this->clock->day($person->id, $iso, $onDay)['worked'];
            }
        }

        return $worked;
    }

    /** One person's day, for the reports that need more of it than the total. */
    private function day(User $person, string $date): array
    {
        return $this->clock->day($person->id, $date);
    }

    /** Rate and pay on the end of a row, where the box is ticked. */
    private function withPay(array $row, User $person, int $minutes, bool $showPay): array
    {
        if (! $showPay) {
            return $row;
        }

        $hours = round($minutes / 60, 2);

        $row[] = $person->pay_rate;
        // Null rather than nought where no rate is set: an unpaid-looking zero
        // in a pay column is a number somebody would act on.
        $row[] = $person->pay_rate === null ? null : round($hours * (float) $person->pay_rate, 2);

        return $row;
    }

    /** The two headings that go with withPay(). */
    private function payColumns(array $columns, bool $showPay): array
    {
        if (! $showPay) {
            return $columns;
        }

        $columns[] = ['Rate', 'right'];
        $columns[] = ['Pay', 'right'];

        return $columns;
    }

    /**
     * Column headings, given as [label, align] and returned as maps.
     *
     * The shorthand is for the builders above, which declare a dozen columns
     * each and would be unreadable written out; the maps are for the blade,
     * which should not be indexing into tuples.
     *
     * @return array<int, array{label: string, align: string}>
     */
    private function columns(array $columns): array
    {
        return array_map(fn (array $column) => [
            'label' => $column[0],
            'align' => $column[1] ?? 'left',
        ], $columns);
    }

    /** Minutes past midnight as the clock reads them, or null. */
    private function clockTime(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return Carbon::today()->startOfDay()->addMinutes($minutes)->format('g:i A');
    }

    /** Minutes as the grids print them — 07:30, never 7.5. */
    public static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
