<?php

namespace App\Http\Controllers;

use App\Exports\StaffTimesheetExport;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The week on the wall: who was in, when, and what does not add up.
 *
 * Deliberately not the pay-period screen next to it. That one exists to be
 * approved — two weeks, leave codes, an approval that locks it — and it is
 * opened once a fortnight by whoever runs payroll. This is opened every
 * morning, to answer "is everyone here and did yesterday go in properly",
 * which is a different question with a different shape: five days, the times
 * as they were punched, and a coloured dot where something is wrong.
 *
 * It reads and never writes. Every correction goes through the punch screens
 * that already exist, because those record who changed what and why — and a
 * grid somebody can type into is a grid with no audit trail behind it.
 */
class StaffTimesheetController extends Controller
{
    /** What a dot can mean, in the order a reader cares about it. */
    public const ON_TIME = 'on_time';

    public const LATE = 'late';

    public const MISSING_OUT = 'missing_out';

    public function __construct(private TimeClock $clock) {}

    /**
     * How many days the grid will draw.
     *
     * A column per day, so a range of a year is a table nobody can read and a
     * query per person per day that nobody wants to run. Asking for more is
     * answered with the first month of it and a line saying so, rather than
     * with a refusal — the usual cause is somebody dragging past what they
     * meant.
     */
    public const MAX_DAYS = 31;

    public function index(Request $request)
    {
        [$start, $end, $truncated] = $this->range($request);

        $dates = $this->days($start, $end);
        $staff = $this->staff();
        $role = trim((string) $request->input('role'));
        $rows = $this->rows($staff, $dates, $role);

        return view('timesheets.week', [
            'start' => $start,
            'end' => $end,
            'dates' => $dates,
            'truncated' => $truncated,
            'rows' => $rows,
            'role' => $role,
            'roles' => User::jobRolesAmong($staff),
            'counts' => $this->counts($staff),
        ]);
    }

    /**
     * The same grid as a file.
     *
     * Reads exactly what the screen reads — same range, same role filter — so
     * the file and the page can never disagree about a week. It is the rows
     * that are reshaped on the way out, not the numbers; see the export.
     */
    public function export(Request $request)
    {
        [$start, $end] = $this->range($request);

        $dates = $this->days($start, $end);
        $role = trim((string) $request->input('role'));
        $rows = $this->rows($this->staff(), $dates, $role);

        $label = $start->format('Y-m-d').($start->eq($end) ? '' : '-to-'.$end->format('Y-m-d'));

        $name = 'timesheet-'.$label.'.'.($request->input('format') === 'csv' ? 'csv' : 'xlsx');

        return Excel::download(new StaffTimesheetExport($rows, $label), $name);
    }

    /** Every day in the range, as Carbon. */
    private function days(Carbon $start, Carbon $end)
    {
        $dates = collect();

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates->push($day->copy());
        }

        return $dates;
    }

    /**
     * Everyone the grid can show, in the order it shows them.
     *
     * Also what the role filter offers: read off the staff themselves rather
     * than a list in config, so a role nobody holds does not get a chip that
     * filters to nothing.
     */
    private function staff()
    {
        return User::query()
            ->whereIn('role', ['admin', 'teacher'])
            ->orderBy('name')
            ->get();
    }

    /** The rows themselves, narrowed to one job where one was asked for. */
    private function rows($staff, $dates, string $role)
    {
        return $staff
            ->when($role !== '', fn ($all) => $all->filter(fn (User $person) => $person->jobRole() === $role))
            ->map(fn (User $person) => $this->row($person, $dates))
            ->values();
    }
    /**
     * The days being shown.
     *
     * Defaults to this week, which is what somebody opening the page each
     * morning wants, and takes any two dates otherwise. Given backwards, they
     * are swapped rather than refused: dragging right to left across a
     * calendar is a way of choosing a range, not a mistake to be told about.
     *
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function range(Request $request): array
    {
        $rules = ['nullable', 'date_format:Y-m-d'];

        validator($request->only('from', 'to'), ['from' => $rules, 'to' => $rules])->validate();

        $from = $request->input('from');
        $to = $request->input('to');

        if (blank($from) || blank($to)) {
            $monday = today()->startOfWeek(Carbon::MONDAY);

            return [$monday, $monday->copy()->addDays(4), false];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        // A column per day: a year of them is a table nobody can read. Cut to
        // the cap and say so on the page rather than refusing the request.
        $truncated = $start->diffInDays($end) + 1 > self::MAX_DAYS;

        if ($truncated) {
            $end = $start->copy()->addDays(self::MAX_DAYS - 1);
        }

        return [$start, $end, $truncated];
    }

    /** One person's week: their rate, their days, and their total. */
    private function row(User $person, $dates): array
    {
        $days = [];
        $worked = 0;

        foreach ($dates as $date) {
            $iso = $date->toDateString();
            $day = $this->clock->day($person->id, $iso);

            $worked += $day['worked'];

            $days[$iso] = [
                'in' => $this->clockTime($day['first_in']),
                'out' => $this->clockTime($day['last_out']),
                'status' => $this->statusOf($person, $iso, $day),
                // Nothing at all, which is not the same as a day that went
                // wrong: a day off has no dot and no dash to read.
                'empty' => $day['first_in'] === null && $day['last_out'] === null,
            ];
        }

        return [
            'id' => $person->id,
            'staff_id' => $person->staffId(),
            'name' => $person->name,
            'email' => $person->email,
            'initials' => $person->initials,
            'role' => $person->jobRole(),
            'rate' => $person->pay_rate,
            'hours' => round($worked / 60, 1),
            'days' => $days,
        ];
    }

    /**
     * What colour the day is.
     *
     * Missing out first, because it is the one that stops a period being
     * approved and the one somebody has to chase today while the answer is
     * still rememberable. Late second, and only where a shift says what time
     * they were due — without a roster there is no such thing as late, and a
     * dot claiming otherwise would be an accusation the data cannot support.
     */
    private function statusOf(User $person, string $date, array $day): ?string
    {
        if ($day['first_in'] === null) {
            return null;
        }

        // Attendance Only: the centre takes an arrival and nothing else, so
        // every day is open by design. Flagging them all would put an amber
        // dot against the whole centre every day, and a warning everybody sees
        // daily is one nobody reads.
        if (! SettingController::tracksTime()) {
            return self::ON_TIME;
        }

        // Open and the day is over: somebody went home without clocking out.
        // Today's open day is just somebody still at work.
        if ($day['open'] && $date < today()->toDateString()) {
            return self::MISSING_OUT;
        }

        if ($day['broken']) {
            return self::MISSING_OUT;
        }

        $due = StaffShift::where('user_id', $person->id)
            ->whereDate('shift_date', $date)
            ->min('starts_at');

        if ($due !== null && $day['first_in'] > (int) $due) {
            return self::LATE;
        }

        return self::ON_TIME;
    }

    /** The three numbers over the grid, about today and nothing else. */
    private function counts($staff): array
    {
        $today = today()->toDateString();
        $in = 0;
        $out = 0;

        foreach ($staff as $person) {
            $day = $this->clock->day($person->id, $today);

            if ($day['first_in'] === null) {
                continue;
            }

            $day['open'] ? $in++ : $out++;
        }

        return [
            'staff' => $staff->count(),
            'in' => $in,
            'out' => $out,
            // Nobody has punched at all. Worth its own number: at nine in the
            // morning it is the one a director acts on.
            'not_in' => $staff->count() - $in - $out,
        ];
    }

    /** Minutes past midnight as the clock reads them, or null. */
    private function clockTime(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return Carbon::today()->startOfDay()->addMinutes($minutes)->format('g:i A');
    }
}
