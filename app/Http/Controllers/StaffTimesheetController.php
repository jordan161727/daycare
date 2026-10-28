<?php

namespace App\Http\Controllers;

use App\Exports\StaffTimesheetExport;
use App\Models\StaffRule;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\PaidHours;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Staff attendance: who is in right now, and the week as the clock recorded it.
 *
 * Two views of one page (Phase 6 of docs/work.md, drawn by Main.dc.html).
 * Live is the morning question — is everyone here who should be — answered
 * from today's punches against today's shifts. History is the week: five
 * days across, one row per person, and a coloured day wherever something
 * does not add up, with a panel beside it that puts the day right.
 *
 * Deliberately not the pay-period screen next to it. That one exists to be
 * approved — two weeks, leave codes, an approval that locks it — and it is
 * opened once a fortnight by whoever runs payroll. This is opened every
 * morning, which is a different question with a different shape.
 *
 * The grid reads and the panel writes — through the punch endpoints, which
 * record who changed what and why. The original punch is always kept; a
 * correction is a void and a replacement, never an edit. A grid somebody
 * can type into is a grid with no audit trail behind it.
 */
class StaffTimesheetController extends Controller
{
    /** What a day can be, in the order a reader cares about it. */
    public const ON_TIME = 'on_time';

    public const LATE = 'late';

    public const MISSING_OUT = 'missing_out';

    /**
     * Scheduled, the day has gone, and the clock never saw them arrive —
     * or saw them leave without ever seeing them come in.
     */
    public const MISSING_IN = 'missing_in';

    /**
     * Somebody corrected this day and it now adds up.
     *
     * Drawn in blue rather than left plain, because a day that was put right
     * by hand is a different kind of fact from one the clock got right on its
     * own, and the person reconciling the month is the one who needs to tell
     * them apart. A corrected day that is still late or still open keeps its
     * amber or red: "corrected" says "fine now", and it is not.
     */
    public const EDITED = 'edited';

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

    /** Today's shifts and changes, read once for everyone — see preload(). */
    private array $shifts = [];

    private array $changes = [];

    private array $loaded = [];

    public function __construct(private TimeClock $clock) {}

    /**
     * The grid, with one person's day already open in the edit panel.
     *
     * Where a report row leads. The week around the day is the range shown,
     * so the cell being edited is on screen with its neighbours, and the panel
     * opens from ?open= on arrival — see staffAttendance().init().
     */
    public function openDay(User $user, string $date)
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $day = Carbon::parse($date);

        return redirect()->route('staff.timesheets', [
            'from' => $day->copy()->startOfWeek()->toDateString(),
            'to' => $day->copy()->endOfWeek()->toDateString(),
            'open' => $user->id.'|'.$date,
        ]);
    }

    public function index(Request $request)
    {
        [$start, $end, $truncated] = $this->range($request);

        $dates = $this->days($start, $end);
        $staff = $this->staff();
        $role = trim((string) $request->input('role'));

        $this->preload($staff, $start, $end);
        $rows = $this->rows($staff, $dates, $role);

        // Live unless the address names a week or a day: a report row landing
        // here with ?open= wants the grid, not this morning's list.
        $view = $request->input('view') === 'hist' || $request->hasAny(['from', 'to', 'open']) ? 'hist' : 'live';

        // The role filter narrows both views: the page is under one filter,
        // not one per view.
        $shown = $staff->when($role !== '', fn ($all) => $all->filter(fn (User $person) => $person->jobRole() === $role))->values();

        return view('timesheets.week', [
            'view' => $view,
            'start' => $start,
            'end' => $end,
            'dates' => $dates,
            'truncated' => $truncated,
            'rows' => $rows,
            'role' => $role,
            'roles' => User::jobRolesAmong($staff),
            'live' => $this->live($shown),
            'log' => $this->log($staff, $start, $end),
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
        $staff = $this->staff();

        $this->preload($staff, $start, $end);
        $rows = $this->rows($staff, $dates, $role);

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
            ->with('staffRules')
            ->whereIn('role', ['admin', 'teacher'])
            ->orderBy('name')
            ->get();
    }

    /** The rows themselves, narrowed to one job where one was asked for. */
    private function rows($staff, $dates, string $role)
    {
        return $staff
            ->when($role !== '', fn ($all) => $all->filter(fn (User $person) => $person->jobRole() === $role))
            ->reject(fn (User $person) => $this->idle($person, $dates))
            ->map(fn (User $person) => $this->row($person, $dates))
            ->values();
    }

    /**
     * A director with nothing on these days — no punch, no shift, no time
     * off — is not a row of "Off" cells. Teachers always have a row: a week
     * of Off is something to notice for them, as the Staff page says.
     */
    private function idle(User $person, $dates): bool
    {
        if ($person->role !== 'admin') {
            return false;
        }

        foreach ($dates as $date) {
            $date = $date instanceof Carbon ? $date->toDateString() : (string) $date;
            $day = $this->clock->day($person->id, $date);

            if ($day['first_in'] !== null || $day['last_out'] !== null || $this->windowOn($person->id, $date) || $this->changeOn($person->id, $date)) {
                return false;
            }
        }

        return true;
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

    /* ---------------------------------------------------------------
     * Shifts and time off
     * --------------------------------------------------------------- */

    /**
     * Everyone's shifts and changes across the range, in two queries.
     *
     * The grid asks about every person on every day; asking the database
     * each time would be a query per cell. Read once and remembered; cell()
     * falls back to a single lookup for a day nobody preloaded, which is how
     * the panel's save repaints one cell.
     */
    private function preload(Collection $staff, Carbon $start, Carbon $end): void
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $ids = $staff->pluck('id')->all();

        foreach (StaffShift::whereIn('user_id', $ids)->whereBetween('shift_date', [$from, $to])->orderBy('starts_at')->get() as $shift) {
            $this->shifts[$shift->user_id.'|'.$shift->shift_date->toDateString()][] = $shift;
        }

        foreach (StaffTimeOff::byUserAndDate($from, $to) as $userId => $byDate) {
            foreach ($byDate as $date => $change) {
                $this->changes[$userId.'|'.$date] = $change;
            }
        }

        foreach ($ids as $id) {
            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $this->loaded[$id.'|'.$day->toDateString()] = true;
            }
        }
    }

    /** One person's shifts on one day, earliest first. */
    private function shiftsOn(int $userId, string $date): Collection
    {
        $key = $userId.'|'.$date;

        if (! isset($this->loaded[$key])) {
            $this->shifts[$key] = StaffShift::where('user_id', $userId)->whereDate('shift_date', $date)->orderBy('starts_at')->get()->all();
            $this->changes[$key] = StaffTimeOff::where('user_id', $userId)->onDate($date)->orderByDesc('id')->first();
            $this->loaded[$key] = true;
        }

        return collect($this->shifts[$key] ?? []);
    }

    /** The Staff page's change for one person on one day, if any. */
    private function changeOn(int $userId, string $date): ?StaffTimeOff
    {
        $this->shiftsOn($userId, $date);

        return $this->changes[$userId.'|'.$date] ?? null;
    }

    /**
     * When one person was due in and due out on one day.
     *
     * The Schedule's shift, with the Staff page's change for the date laid
     * over it: "out until 3" opens the day at three, "out all day" closes
     * it, an extra day opens one the Schedule did not. Null is a day they
     * were not expected.
     *
     * @return array{start: int, end: int}|null
     */
    private function windowOn(int $userId, string $date): ?array
    {
        $shifts = $this->shiftsOn($userId, $date);
        $change = $this->changeOn($userId, $date);

        $window = $shifts->isEmpty() ? null : ['start' => (int) $shifts->min('starts_at'), 'end' => (int) $shifts->max('ends_at')];

        return $change ? $change->applyTo($window) : $window;
    }

    /** The room a person is scheduled in on a day, or their usual one. */
    private function roomOn(User $person, string $date, ?int $minute = null): ?string
    {
        $shifts = $this->shiftsOn($person->id, $date);

        $now = $minute === null ? null : $shifts->first(fn (StaffShift $shift) => $shift->covers($minute));

        return ($now ?? $shifts->first())?->classroom ?: ($person->classroom ?: null);
    }

    /* ---------------------------------------------------------------
     * History: the week
     * --------------------------------------------------------------- */

    /** One person's week: their rate, their days, and their total. */
    private function row(User $person, $dates): array
    {
        $days = [];
        $worked = 0;

        foreach ($dates as $date) {
            $iso = $date->toDateString();
            $day = $this->clock->day($person->id, $iso);

            $worked += $day['worked'];

            $days[$iso] = $this->cell($person, $iso, $day);
        }

        $today = $days[today()->toDateString()] ?? null;
        $with = fn (string $flag) => count(array_filter($days, fn ($day) => $day['flag'] === $flag));

        // The payroll week (Monday to Sunday), not just the days on screen:
        // overtime is owed per workweek, and a Saturday shift off the grid
        // still counts towards it. A range across weeks flags any of them.
        $weeks = collect($dates)
            ->map(fn ($date) => PaidHours::weekOf($date->toDateString())[0]->toDateString())
            ->unique()
            ->map(fn (string $monday) => app(PaidHours::class)->week($person->id, $monday))
            ->values();

        return [
            'id' => $person->id,
            'staff_id' => $person->staffId(),
            'name' => $person->name,
            'email' => $person->email,
            'initials' => $person->initials,
            // Their photograph where they have one; the initials stand in otherwise.
            'avatar' => $person->avatar_url,
            'role' => $person->jobRole(),
            // The room they work, for the filter: their title, failing that
            // the room on their account, failing that the office.
            'room' => $person->title ?: ($person->classroom ?: 'Office'),
            // Pay is the director's alone; anyone else gets no number at all.
            'rate' => auth()->user()?->canSeePay() ? $person->pay_rate : null,
            'hours' => round($worked / 60, 1),
            'minutes' => $worked,
            'total' => $this->duration($worked),
            'week_minutes' => (int) $weeks->sum('minutes'),
            'week_total' => $this->duration((int) $weeks->sum('minutes')),
            'overtime' => $weeks->contains('overtime', true),
            'overtime_by' => $this->duration((int) $weeks->sum('over')),
            // What the week is measured against — the hours their rules say
            // they are owed, or the default for their employment type.
            'target' => $person->weeklyHours(),
            // Days that need a fix — a missing in or out — and the two the
            // week's header counts beside them.
            'fix' => $with('fix'),
            'late' => $with('late'),
            'edited' => $with('edit'),
            'on_shift' => $today !== null && ! $today['empty'] && $today['open'],
            'not_in' => $today !== null && $today['empty'],
            'days' => $days,
        ];
    }

    /**
     * One day of one person's row, as the grid draws it.
     *
     * Public because the edit panel's save hands it back: the grid updates
     * the cell it opened from without reloading, and it has to be painted
     * by the same code that painted it in the first place or the two will
     * disagree the moment a rule changes. The words on the cell — top line,
     * second line, colour — are decided here, once, so the Blade and the
     * script that repaints after a save draw from the same answer.
     */
    public function cell(User $person, string $date, ?array $day = null): array
    {
        $day ??= $this->clock->day($person->id, $date);
        $window = $this->windowOn($person->id, $date);
        $change = $this->changeOn($person->id, $date);
        $status = $this->statusOf($date, $day, $window);

        $today = $date === today()->toDateString();
        $future = $date > today()->toDateString();
        $empty = $day['first_in'] === null && $day['last_out'] === null;
        $onShiftNow = $today && $day['open'] && ! $empty;
        $now = now()->hour * 60 + now()->minute;

        $in = $this->short($day['first_in']);
        $out = $this->short($day['last_out']);
        $lateBy = $window && $day['first_in'] !== null ? $day['first_in'] - $window['start'] : 0;

        // The cell's two lines and its colour, by what the day is.
        [$cls, $top, $sub, $tone, $flag] = match (true) {
            $status === self::MISSING_IN => ['c-bad', $out ? '— – '.$out : '—', 'Forgot to clock in', 'bad', 'fix'],
            $status === self::MISSING_OUT => ['c-bad', $in.' – —', 'No clock-out', 'bad', 'fix'],
            $onShiftNow => ['c-now', $in.' – …', 'On shift · '.$this->duration((int) $day['so_far']), 'info', null],
            $status === self::LATE => ['c-warn', $in.' – '.($out ?: '—'), 'Late '.$lateBy.' min · '.$this->duration((int) $day['worked']), 'warn', 'late'],
            $status === self::EDITED => ['c-edit', $in.' – '.($out ?: '—'), 'Corrected · '.$this->duration((int) $day['worked']), 'info', 'edit'],
            $status === self::ON_TIME => ['', $in.' – '.($out ?: '—'), $this->duration((int) $day['worked']), 'mut', null],
            // Nothing punched. Today that is a fact about the morning so far;
            // ahead it is the plan; behind, with nobody expected, a day off.
            $today && $window && $now > $window['start'] + PaidHours::lateGrace() => ['c-bad', 'Not in', 'Late · due '.StaffRule::formatTime($window['start']), 'bad', null],
            $today && $window => ['c-wait', 'Not in', 'Due '.StaffRule::formatTime($window['start']), 'mut', null],
            $future && $window => ['c-off', $this->short($window['start']).' – '.$this->short($window['end']), 'Scheduled', 'mut', null],
            default => ['c-off', 'Off', $change?->kind === 'all' ? $change->reasonLabel() : '', 'mut', null],
        };

        $label = match ($status) {
            self::LATE => 'Late',
            self::MISSING_OUT => 'Missing time out',
            self::MISSING_IN => 'Missing clock-in',
            self::EDITED => 'Edited',
            self::ON_TIME => 'On time',
            default => null,
        };

        $spoken = match (true) {
            $status === self::MISSING_IN => 'Forgot to clock in, needs a fix',
            $status === self::MISSING_OUT => 'Missing clock-out, needs a fix',
            $onShiftNow && $status === self::LATE => 'On shift, clocked in late',
            $onShiftNow => 'On shift since '.$this->clockTime($day['first_in']),
            $status === self::LATE => 'Clocked in late, needs a fix',
            $status === self::EDITED => 'Corrected, adds up now',
            $status === self::ON_TIME => 'In '.$this->clockTime($day['first_in']).', out '.($this->clockTime($day['last_out']) ?? '—').'. On time',
            default => $top.($sub !== '' ? ', '.$sub : ''),
        };

        return [
            'in' => $this->clockTime($day['first_in']),
            'out' => $this->clockTime($day['last_out']),
            'status' => $status,
            'label' => $label,
            // Nothing at all, which is not the same as a day that went
            // wrong: a day off has no dot and no dash to read.
            'empty' => $empty,
            // Paid minutes so far — the clock counts an open day up to now —
            // and whether it is still open, so today's cell can say "On shift".
            'worked' => (int) $day['worked'],
            'so_far' => (int) $day['so_far'],
            'open' => (bool) $day['open'],
            'today' => $today,
            'future' => $future,
            'sched' => $window ? StaffRule::formatTime($window['start']).' – '.StaffRule::formatTime($window['end']) : null,
            'room' => $this->roomOn($person, $date),
            'cls' => $cls,
            'top' => $top,
            'sub' => $sub,
            'tone' => $tone,
            'flag' => $flag,
            'spoken' => $spoken,
            // A day with punches opens the panel; a day with none is opened
            // too, so a forgotten clock-in can be typed in — but a day off or
            // a day still to come has nothing to correct.
            'fixable' => ! $empty || $status === self::MISSING_IN || ($today && $window !== null),
            'fine' => in_array($status, [self::ON_TIME, self::EDITED], true) && ! $onShiftNow,
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
    private function statusOf(string $date, array $day, ?array $window): ?string
    {
        $past = $date < today()->toDateString();

        if ($day['first_in'] === null && $day['last_out'] === null) {
            // Expected, the day has gone, and the clock never saw them.
            return $past && $window !== null && SettingController::tracksTime() ? self::MISSING_IN : null;
        }

        // Attendance Only: the centre takes an arrival and nothing else, so
        // every day is open by design. Flagging them all would put an amber
        // dot against the whole centre every day, and a warning everybody sees
        // daily is one nobody reads.
        if (! SettingController::tracksTime()) {
            return self::ON_TIME;
        }

        // Left without ever arriving, as far as the clock knows.
        if ($day['first_in'] === null) {
            return self::MISSING_IN;
        }

        // Open and the day is over: somebody went home without clocking out.
        // Today's open day is just somebody still at work.
        if ($day['open'] && $past) {
            return self::MISSING_OUT;
        }

        if ($day['broken']) {
            return self::MISSING_OUT;
        }

        if ($window !== null && $day['first_in'] > $window['start'] + PaidHours::lateGrace()) {
            return self::LATE;
        }

        // Fine now — but was it fine on its own? walk() hands back the live
        // punches, and any one of them a supervisor wrote is the answer.
        if ($day['punches']->contains(fn ($punch) => $punch->isCorrection())) {
            return self::EDITED;
        }

        return self::ON_TIME;
    }

    /** Paid minutes across a run of days, for the total at the end of a row. */
    public function minutesBetween(User $person, string $from, string $to): int
    {
        $worked = 0;

        foreach (Carbon::parse($from)->daysUntil($to) as $date) {
            $worked += $this->clock->day($person->id, $date->toDateString())['worked'];
        }

        return (int) $worked;
    }

    /** The same, as hours to one place — what the export prints. */
    public function hoursBetween(User $person, string $from, string $to): float
    {
        return round($this->minutesBetween($person, $from, $to) / 60, 1);
    }

    /* ---------------------------------------------------------------
     * Live: today
     * --------------------------------------------------------------- */

    /**
     * Everyone today, and the five numbers over them.
     *
     * Read from today's punches against today's shifts and the Staff page's
     * changes for the date — the same reckoning as the dot beside each name
     * on the Staff page, so the two screens never disagree about who is in.
     *
     * @return array{rows: array, counts: array}
     */
    private function live(Collection $staff): array
    {
        $today = today()->toDateString();
        $now = now()->hour * 60 + now()->minute;
        $rows = [];
        $counts = ['all' => 0, 'in' => 0, 'done' => 0, 'late' => 0, 'due' => 0, 'off' => 0, 'minutes' => 0];

        foreach ($staff as $person) {
            $day = $this->clock->day($person->id, $today);
            $window = $this->windowOn($person->id, $today);
            $change = $this->changeOn($person->id, $today);
            $arrived = $day['first_in'] !== null;

            // Clocked out with another shift still to come — a split shift
            // — is due back, not done.
            $later = $arrived && ! $day['open']
                ? $this->shiftsOn($person->id, $today)->first(fn (StaffShift $shift) => $shift->starts_at > (int) $day['last_out'])
                : null;

            // The director with nothing today is not a row of dashes.
            if ($person->role === 'admin' && ! $arrived && $day['last_out'] === null && $window === null && $change === null) {
                continue;
            }

            [$key, $status, $tone] = match (true) {
                $day['open'] && $day['state'] === TimeClock::BREAK => ['in', 'On break', 'ok'],
                $day['open'] && $day['state'] === TimeClock::LUNCH => ['in', 'At lunch', 'ok'],
                $day['open'] => ['in', 'On shift', 'ok'],
                $later !== null && $now > $later->starts_at + PaidHours::lateGrace() => ['late', 'Not back · due '.StaffRule::formatTime($later->starts_at), 'bad'],
                $later !== null => ['due', 'Due back '.StaffRule::formatTime($later->starts_at), 'mut'],
                $arrived => ['done', 'Done for today', 'mut'],
                $change?->kind === 'all' => ['off', 'Off · '.strtolower($change->reasonLabel()), 'mut'],
                $window === null => ['off', 'Off', 'mut'],
                $now > $window['start'] + PaidHours::lateGrace() => ['late', 'Not in · due '.StaffRule::formatTime($window['start']), 'bad'],
                default => ['due', 'Due '.StaffRule::formatTime($window['start']), 'mut'],
            };

            $counts['all']++;
            $counts[$key]++;
            $counts['minutes'] += (int) $day['so_far'];

            $rows[] = [
                'id' => $person->id,
                'name' => $person->name,
                'initials' => $person->initials,
                'avatar' => $person->avatar_url,
                'role' => $this->roleLine($person),
                'room' => $key === 'off' ? '—' : ($this->roomOn($person, $today, $now) ?? '—'),
                'sched' => $window
                    ? $this->short($window['start']).' – '.$this->short($window['end'])
                    : ($change?->kind === 'all' ? 'Off · '.strtolower($change->reasonLabel()) : 'Off today'),
                'in' => $this->short($day['first_in']) ?? '—',
                'out' => $day['open'] ? '—' : ($this->short($day['last_out']) ?? '—'),
                'key' => $key,
                'status' => $status,
                'tone' => $tone,
                'hours' => $arrived ? $this->duration((int) $day['so_far']) : '—',
                'search' => strtolower($person->name.' '.$person->staffId()),
                // The order of the morning: who is here, earliest first;
                // then who is finished; then who is late; then who is due,
                // soonest first; then who is off.
                'order' => [
                    ['in' => 0, 'done' => 1, 'late' => 2, 'due' => 3, 'off' => 4][$key],
                    $key === 'in' || $key === 'done' ? (int) $day['first_in'] : (int) ($window['start'] ?? 0),
                    $person->name,
                ],
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['order'] <=> $b['order']);
        $rows = array_map(function (array $row) {
            unset($row['order']);

            return $row;
        }, $rows);

        // Due by now: everyone whose shift has started, in or not.
        $counts['due_now'] = $counts['in'] + $counts['done'] + $counts['late'];
        $counts['hours'] = $this->duration($counts['minutes']);

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** "FT · opens", "PT flex", "Type not set" — under the name on the live list. */
    private function roleLine(User $person): string
    {
        $type = $person->employmentLabel();
        $opens = $person->staffRules->contains(fn (StaffRule $rule) => $rule->rule_type === 'CAN_OPEN');

        return ($type === 'Not set' ? 'Type not set' : $type).($opens ? ' · opens' : '');
    }

    /* ---------------------------------------------------------------
     * Changes this week
     * --------------------------------------------------------------- */

    /**
     * Every correction made to a day in the range, newest first.
     *
     * The panel's own history is one day; this is the week's, under the
     * panel, so the director sees what has already been put right before
     * opening anything. Worded by the clock — see TimeClock::changes().
     */
    private function log(Collection $staff, Carbon $start, Carbon $end): array
    {
        $names = $staff->pluck('name', 'id');

        $punches = TimePunch::with(['recorder', 'voider', 'corrects'])
            ->whereIn('user_id', $names->keys())
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($query) => $query->where('source', TimePunch::SOURCE_SUPERVISOR)->orWhereNotNull('voided_at'))
            ->get();

        return $this->clock->changes($punches)
            ->take(30)
            ->map(fn (array $line) => [
                'who' => $line['who'] ?? 'Someone',
                'name' => $names[$line['user_id'] ?? null] ?? null,
                'day' => Carbon::parse($line['date'])->format('D M j'),
                'what' => $line['what'],
                'reason' => $line['reason'],
                'when' => $line['when'],
            ])
            ->values()
            ->all();
    }

    /* ---------------------------------------------------------------
     * Words for times
     * --------------------------------------------------------------- */

    /** Minutes as "7h 45m". */
    public function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    /** Minutes past midnight as the clock reads them — "7:52 AM" — or null. */
    private function clockTime(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return Carbon::today()->startOfDay()->addMinutes($minutes)->format('g:i A');
    }

    /** The short form a cell has room for: "7:52a", "12p", "3:45p". */
    private function short(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return $twelve.($minute === 0 ? '' : ':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT)).($hour >= 12 ? 'p' : 'a');
    }
}
