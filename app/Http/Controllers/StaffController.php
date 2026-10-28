<?php

namespace App\Http\Controllers;

use App\Mail\TeacherWelcomeMail;
use App\Models\LoginEvent;
use App\Models\StaffRule;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\PaidHours;
use App\Services\StaffSchedule;
use App\Services\TemporaryPassword;
use App\Services\TimeClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Staff page — Phase 5 of docs/work.md, as Staff.dc.html draws it.
 *
 * Everyone on the team down the left with where they stand this minute, and
 * one person on the right under four tabs: Details, Rules, Attendance, Time
 * off. Director only, like every staff screen: it carries pay, a card and a
 * PIN that is somebody's identity at the clock.
 *
 * Rules keep their own controller (StaffRuleController) and routes; this
 * page is only another place they are read from and posted to.
 */
class StaffController extends Controller
{
    public const TABS = ['details', 'rules', 'attendance', 'off'];

    public function __construct(private TimeClock $clock) {}

    /** The page with the first person on the list open. */
    public function index(Request $request)
    {
        $people = $this->people();
        $first = $people->first(fn (User $u) => ! $u->isOffSchedule()) ?? $people->first();

        return $this->page($request, $first);
    }

    public function show(Request $request, User $staff)
    {
        abort_unless(in_array($staff->role, ['teacher', 'admin'], true), 404);

        return $this->page($request, $staff);
    }

    /** Everyone on the list as a spreadsheet. */
    public function export()
    {
        $rows = [['Staff ID', 'Name', 'Legal name', 'Job', 'Employment', 'Home room', 'Mobile', 'Email', 'Start date', 'ASPIRE ID', 'Status today']];
        $status = $this->statusesFor($this->people());

        foreach ($this->people() as $person) {
            $rows[] = [
                $person->staffId(), $person->name, $person->legal_name, $person->jobRole(), $person->employmentLabel(),
                $person->classroom, $person->phone, $person->email, $person->start_date?->toDateString(), $person->aspire_id,
                $status[$person->id]['text'],
            ];
        }

        return $this->csv($rows, 'staff-'.today()->toDateString().'.csv');
    }

    /** The Details tab, saved. */
    public function update(Request $request, User $staff)
    {
        abort_unless(in_array($staff->role, ['teacher', 'admin'], true), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($staff)],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'transport' => ['nullable', 'string', 'max:255'],
            'employment' => ['nullable', Rule::in(StaffRule::EMPLOYMENT)],
            'job_role' => ['nullable', Rule::in(User::JOB_ROLES)],
            'classroom' => ['nullable', Rule::in(ClassroomAssignment::rooms())],
            'start_date' => ['nullable', 'date'],
            'aspire_id' => ['nullable', 'string', 'max:40'],
            'evaluation_score' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'pay_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'direct_deposit' => ['nullable', 'boolean'],
        ]);

        // The home room is also the first assigned classroom: the roster,
        // the check-in screen and the schedule all read that list.
        $rooms = array_values(array_filter(array_unique(array_merge(
            array_filter([$data['classroom'] ?? null]),
            $staff->assignedClassrooms(),
        ))));
        if (($data['classroom'] ?? null) === null && $rooms !== []) {
            $data['classroom'] = $rooms[0];
        }
        $data['classrooms'] = $rooms;
        $data['direct_deposit'] = $request->boolean('direct_deposit');

        // Pay is the director's to see and so the director's to change. Not
        // refused — the rest of the form is fine — just left as it was.
        if (! $request->user()->canSeePay()) {
            unset($data['pay_rate'], $data['direct_deposit']);
        }

        $staff->update($data);

        return redirect()->route('staff.show', [$staff, 'tab' => 'details'])->with('success', $staff->firstName().'’s details saved.');
    }

    /**
     * A new temporary password, mailed. The one they had stops working the
     * moment this is pressed, and the new one has to be replaced at the
     * next sign-in — the same path a new account takes.
     */
    public function sendPassword(User $staff)
    {
        abort_unless(in_array($staff->role, ['teacher', 'admin'], true), 404);

        $temporary = TemporaryPassword::generate();
        $staff->forceFill(['password' => Hash::make($temporary), 'must_change_password' => true])->save();

        try {
            Mail::to($staff->email)->send(new TeacherWelcomeMail($staff, $temporary));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('staff.show', [$staff, 'tab' => 'details'])
                ->with('error', "The email to {$staff->email} could not be sent. Temporary password: {$temporary}");
        }

        return redirect()->route('staff.show', [$staff, 'tab' => 'details'])
            ->with('success', 'A temporary password has been emailed to '.$staff->email.'. They choose their own at the next sign-in.');
    }

    /** The Attendance tab's rows as a spreadsheet. */
    public function attendanceExport(Request $request, User $staff)
    {
        abort_unless(in_array($staff->role, ['teacher', 'admin'], true), 404);

        $attendance = $this->attendance($staff, $request);
        $rows = [['Day', 'Room', 'Scheduled', 'In', 'Out', 'Hours', 'Status']];

        foreach ($attendance['rows'] as $row) {
            $rows[] = [$row['day'], $row['room'], $row['sched'], $row['in'], $row['out'], $row['hrs'], $row['status']];
        }

        return $this->csv($rows, str($staff->name)->slug().'-attendance-'.$attendance['from'].'-to-'.$attendance['to'].'.csv');
    }

    public function storeTimeOff(Request $request, User $staff)
    {
        abort_unless(in_array($staff->role, ['teacher', 'admin'], true), 404);

        $staff->timeOff()->create($this->timeOffData($request) + ['created_by' => $request->user()->id]);

        return redirect()->route('staff.show', [$staff, 'tab' => 'off'])->with('success', 'Saved. The Schedule treats it as a Must for that date.');
    }

    public function updateTimeOff(Request $request, User $staff, StaffTimeOff $timeOff)
    {
        abort_unless($timeOff->user_id === $staff->id, 404);

        $timeOff->update($this->timeOffData($request));

        return redirect()->route('staff.show', [$staff, 'tab' => 'off'])->with('success', 'Updated.');
    }

    public function destroyTimeOff(User $staff, StaffTimeOff $timeOff)
    {
        abort_unless($timeOff->user_id === $staff->id, 404);

        $timeOff->delete();

        return redirect()->route('staff.show', [$staff, 'tab' => 'off'])->with('success', 'Removed.');
    }

    /* ---- the page ---- */

    private function page(Request $request, ?User $selected)
    {
        $tab = $request->input('tab', 'details');
        abort_unless(in_array($tab, self::TABS, true), 404);

        $people = $this->people();
        $statuses = $this->statusesFor($people);

        $data = [
            'people' => $people->filter(fn (User $u) => ! $u->isOffSchedule())->values(),
            'offSchedule' => $people->filter(fn (User $u) => $u->isOffSchedule())->values(),
            'statuses' => $statuses,
            'selected' => $selected,
            'tab' => $tab,
            'rooms' => ClassroomAssignment::rooms(),
        ];

        if ($selected === null) {
            return view('staff.index', $data);
        }

        $selected->load('staffRules');

        $data += [
            'status' => $statuses[$selected->id] ?? ['key' => 'off', 'dot' => '#e7e9f0', 'text' => 'Off today', 'tone' => 'mut'],
            'rules' => $selected->staffRules->sortByDesc(fn ($rule) => $rule->isHard())->values(),
            'colleagues' => User::teachers()->whereKeyNot($selected->getKey())->pluck('name'),
            'clockTimes' => $this->clockTimes(),
            'cardReady' => StaffCardController::pendingFor($selected),
            'lastSignIn' => LoginEvent::where('user_id', $selected->id)->where('outcome', LoginEvent::SUCCESS)->latest('created_at')->value('created_at'),
            'attendance' => $tab === 'attendance' ? $this->attendance($selected, $request) : null,
            'timeOff' => $tab === 'off' ? $this->timeOff($selected) : null,
        ];

        return view('staff.index', $data);
    }

    /** Everybody on the team, the teachers first and the office after. */
    private function people(): Collection
    {
        return User::whereIn('role', ['teacher', 'admin'])
            ->orderByRaw("case when role = 'teacher' then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * Where each person stands this minute, for the dot beside their name.
     *
     * In, worked earlier, late (the shift has started and they have not),
     * due later, or off — read from the clock and today's shift.
     *
     * @return array<int, array{key:string,dot:string,text:string,tone:string}>
     */
    private function statusesFor(Collection $people): array
    {
        $today = today()->toDateString();
        $now = now()->hour * 60 + now()->minute;
        $shifts = StaffShift::whereDate('shift_date', $today)->orderBy('starts_at')->get()->groupBy('user_id');
        $off = StaffTimeOff::onDate($today)->orderByDesc('id')->get()->keyBy('user_id');

        $out = [];

        foreach ($people as $person) {
            $day = $this->clock->day($person->id, $today);
            $shift = $shifts->get($person->id)?->first();
            $change = $off->get($person->id);

            // Clocked out with a later shift still to come is due back, not
            // done — the same reading as Staff attendance's Live list.
            $later = $day['first_in'] !== null && ! $day['open']
                ? $shifts->get($person->id)?->first(fn (StaffShift $s) => $s->starts_at > (int) $day['last_out'])
                : null;

            $out[$person->id] = match (true) {
                $day['open'] => ['key' => 'in', 'dot' => '#10b981', 'text' => 'In since '.Carbon::today()->addMinutes((int) $day['first_in'])->format('g:i A'), 'tone' => 'ok', 'since' => Carbon::today()->addMinutes((int) $day['first_in'])->format('g:i A')],
                $later !== null && $now > $later->starts_at + PaidHours::lateGrace() => ['key' => 'late', 'dot' => '#f43f5e', 'text' => 'Late · not back yet', 'tone' => 'bad'],
                $later !== null => ['key' => 'due', 'dot' => '#cfd3de', 'text' => 'Due back '.StaffRule::formatTime($later->starts_at), 'tone' => 'mut'],
                $day['first_in'] !== null => ['key' => 'done', 'dot' => '#5b8fd6', 'text' => 'Done for today', 'tone' => 'info'],
                $change?->kind === 'all' => ['key' => 'off', 'dot' => '#e7e9f0', 'text' => 'Off today · '.strtolower($change->reasonLabel()), 'tone' => 'mut'],
                $shift === null => ['key' => 'off', 'dot' => '#e7e9f0', 'text' => $person->isOffSchedule() ? 'Not on the schedule' : 'Off today', 'tone' => 'mut'],
                default => (function () use ($shift, $change, $now) {
                    $start = $shift->starts_at;
                    if ($change && in_array($change->kind, ['until', 'window'], true) && $change->from_minute !== null) {
                        $start = max($start, $change->from_minute);
                    }

                    return $now > $start + PaidHours::lateGrace()
                        ? ['key' => 'late', 'dot' => '#f43f5e', 'text' => 'Late · not in yet', 'tone' => 'bad']
                        : ['key' => 'due', 'dot' => '#cfd3de', 'text' => 'Due '.StaffRule::formatTime($start), 'tone' => 'mut'];
                })(),
            };
        }

        return $out;
    }

    /* ---- Attendance tab ---- */

    /**
     * A range of days, one row each: what was scheduled, what the clock
     * recorded, and whether the two agree.
     *
     * @return array{range:string,from:string,to:string,rows:array,days:int,hours:string,sched:string,late:int,fix:int}
     */
    private function attendance(User $staff, Request $request): array
    {
        $range = $request->input('range', 'this');
        $today = today();
        $monday = $today->copy()->startOfWeek(Carbon::MONDAY);

        [$from, $to] = match ($range) {
            'last' => [$monday->copy()->subWeek(), $monday->copy()->subDay()],
            'two' => [$monday->copy()->subWeek(), $monday->copy()->addDays(6)],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'custom' => [Carbon::parse($request->input('from', $monday->toDateString())), Carbon::parse($request->input('to', $today->toDateString()))],
            default => [$monday, $monday->copy()->addDays(6)],
        };
        $range = in_array($range, ['last', 'this', 'two', 'month', 'custom'], true) ? $range : 'this';

        $days = config('daycare.days');
        $shifts = StaffShift::where('user_id', $staff->id)->whereBetween('shift_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('starts_at')->get()->groupBy(fn (StaffShift $s) => $s->shift_date->toDateString());
        $changes = StaffTimeOff::byUserAndDate($from->toDateString(), $to->toDateString())[$staff->id] ?? [];

        $rows = [];
        $worked = 0;
        $minutes = 0;
        $scheduled = 0;
        $late = 0;
        $fix = 0;
        $now = now()->hour * 60 + now()->minute;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! in_array(strtoupper($date->format('D')), $days, true)) {
                continue;
            }

            $key = $date->toDateString();
            $shift = $shifts->get($key)?->first();
            $change = $changes[$key] ?? null;
            $window = $shift ? ['start' => $shift->starts_at, 'end' => $shift->ends_at] : null;
            if ($change && $shift) {
                $window = $change->applyTo($window);
            }
            $day = $this->clock->day($staff->id, $key);
            $hasPunches = $day['first_in'] !== null || $day['punches']->isNotEmpty();

            $row = [
                'date' => $key,
                'day' => $date->format('D M j'),
                'room' => $shift?->classroom ?: '—',
                'sched' => $window ? StaffRule::formatTime($window['start']).' – '.StaffRule::formatTime($window['end']) : ($change?->kind === 'all' ? 'Off · '.strtolower($change->reasonLabel()) : 'Not scheduled'),
                'in' => '—', 'out' => '—', 'hrs' => '—', 'status' => 'Off', 'tone' => 'mut', 'action' => null,
            ];

            if ($window !== null && $date->lte($today)) {
                $scheduled += $window['end'] - $window['start'];
            }

            if ($date->gt($today)) {
                $row['status'] = $window ? ($date->isTomorrow() ? 'Tomorrow' : 'Upcoming') : 'Off';
                $rows[] = $row;

                continue;
            }

            if ($date->isSameDay($today) && ($day['open'] || ! $hasPunches)) {
                if ($day['open']) {
                    $row['in'] = Carbon::today()->addMinutes((int) $day['first_in'])->format('g:i A');
                    $row['status'] = 'Today · on shift';
                    $row['tone'] = 'info';
                } elseif ($window && $now > $window['start'] + PaidHours::lateGrace()) {
                    $row['status'] = 'Late · not in';
                    $row['tone'] = 'bad';
                } else {
                    $row['status'] = $window ? 'Today' : 'Off';
                    $row['tone'] = $window ? 'info' : 'mut';
                }
                $rows[] = $row;

                continue;
            }

            if (! $hasPunches) {
                if ($window) {
                    $row['status'] = 'Forgot to clock in';
                    $row['tone'] = 'bad';
                    $row['action'] = 'fix';
                    $fix++;
                }
                $rows[] = $row;

                continue;
            }

            $worked++;
            $row['in'] = $day['first_in'] !== null ? Carbon::today()->addMinutes((int) $day['first_in'])->format('g:i A') : 'Missing';

            if ($day['open'] || $day['broken']) {
                $row['out'] = $day['last_out'] !== null ? Carbon::today()->addMinutes((int) $day['last_out'])->format('g:i A') : 'Missing';
                $row['status'] = $day['first_in'] === null ? 'Forgot to clock in' : 'No clock-out';
                $row['tone'] = 'bad';
                $row['action'] = 'fix';
                $fix++;
                $rows[] = $row;

                continue;
            }

            $row['out'] = $day['last_out'] !== null ? Carbon::today()->addMinutes((int) $day['last_out'])->format('g:i A') : '—';
            $row['hrs'] = $this->duration((int) $day['worked']);
            $minutes += (int) $day['worked'];

            $lateBy = $window && $day['first_in'] !== null ? $day['first_in'] - $window['start'] : 0;
            if ($lateBy > PaidHours::lateGrace()) {
                $late++;
                $row['status'] = 'Late '.$lateBy.' min';
                $row['tone'] = 'warn';
                $row['action'] = 'edit';
            } else {
                $row['status'] = $window ? 'On time' : 'Worked';
                $row['tone'] = 'ok';
            }

            $rows[] = $row;
        }

        return [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'days' => $worked,
            'hours' => $this->duration($minutes),
            'sched' => round($scheduled / 60).'h',
            'late' => $late,
            'fix' => $fix,
        ];
    }

    /** The link that puts a day right: the timesheet's day page. */
    public static function fixUrl(User $staff, string $date): string
    {
        return route('timesheets.day', [TimesheetPeriod::forDate($date), $staff, $date]);
    }

    /* ---- Time off tab ---- */

    /**
     * @return array{upcoming:Collection,past:Collection,pastTotals:array,shifts:array,year:int}
     */
    private function timeOff(User $staff): array
    {
        $today = today();
        $all = $staff->timeOff()->orderBy('date')->orderBy('id')->get();

        $upcoming = $all->filter(fn (StaffTimeOff $o) => $o->isUpcoming())->values();
        $past = $all->filter(fn (StaffTimeOff $o) => ! $o->isUpcoming() && $o->date->year === $today->year)->sortByDesc('date')->values();

        $totals = [];
        foreach ($past as $entry) {
            $totals[$entry->reason] = ($totals[$entry->reason] ?? 0) + 1;
        }

        // The shift behind each upcoming entry, for its impact line, and the
        // next six weeks of them for the form's preview.
        $shifts = [];
        for ($date = $today->copy(); $date->lte($today->copy()->addWeeks(6)); $date->addDay()) {
            $shifts[$date->toDateString()] = $this->shiftOn($staff, $date);
        }

        $upcoming->each(function (StaffTimeOff $entry) use ($staff, $shifts) {
            $shift = $shifts[$entry->date->toDateString()] ?? $this->shiftOn($staff, $entry->date);
            $entry->impact = $entry->impactOn($shift, $staff->firstName());
        });

        return [
            'upcoming' => $upcoming,
            'past' => $past,
            'pastTotals' => $totals,
            'shifts' => $shifts,
            'year' => $today->year,
        ];
    }

    /**
     * The shift a person has on a date: the one the Schedule gave them, or,
     * when that week is not built yet, what their rules would give them.
     *
     * @return array{start:int,end:int,room:?string}|null
     */
    private function shiftOn(User $staff, Carbon $date): ?array
    {
        $shift = StaffShift::where('user_id', $staff->id)->whereDate('shift_date', $date->toDateString())->orderBy('starts_at')->first();

        if ($shift) {
            return ['start' => $shift->starts_at, 'end' => $shift->ends_at, 'room' => $shift->classroom];
        }

        if ($staff->isOffSchedule() || ! in_array(strtoupper($date->format('D')), config('daycare.days'), true)) {
            return null;
        }

        $staff->loadMissing('staffRules');
        $window = app(StaffSchedule::class)->windowFor($staff, strtoupper($date->format('D')));

        if ($window === null) {
            return null;
        }

        // The window is when they could work; the shift is what they are
        // owed of it, from the start.
        $daily = (int) round($staff->weeklyHours() * 60 / max(1, count(config('daycare.days'))));
        $end = $window['fixed'] ? $window['end'] : min($window['end'], $window['start'] + max(60, $daily));

        return ['start' => $window['start'], 'end' => $end, 'room' => $staff->classroom];
    }

    private function timeOffData(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(StaffTimeOff::KINDS))],
            'date' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:date'],
            'from' => ['nullable', 'date_format:H:i'],
            'to' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', Rule::in(array_keys(StaffTimeOff::REASONS))],
            'paid' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $kind = $data['kind'];
        $from = in_array($kind, ['until', 'window', 'extra'], true) ? StaffRule::toMinutes($data['from'] ?? null) : null;
        $to = in_array($kind, ['leave', 'window', 'extra'], true) ? StaffRule::toMinutes($data['to'] ?? null) : null;

        $errors = [];
        if (in_array($kind, ['until', 'window', 'extra'], true) && $from === null) {
            $errors['from'] = ['Say when.'];
        }
        if (in_array($kind, ['leave', 'window', 'extra'], true) && $to === null) {
            $errors['to'] = ['Say when.'];
        }
        if ($from !== null && $to !== null && $to <= $from) {
            $errors['to'] = ['The end has to be after the start.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'kind' => $kind,
            'date' => Carbon::parse($data['date'])->toDateString(),
            'until' => filled($data['until'] ?? null) && ! Carbon::parse($data['until'])->isSameDay(Carbon::parse($data['date'])) ? Carbon::parse($data['until'])->toDateString() : null,
            'from_minute' => $from,
            'to_minute' => $to,
            'reason' => $kind === 'extra' ? 'extra' : $data['reason'],
            'paid' => $kind !== 'extra' && $request->boolean('paid'),
            'note' => $data['note'] ?? null,
        ];
    }

    /* ---- helpers ---- */

    /** The times a rule may be set to, as H:i => "7:00 AM" (see teachers.show). */
    private function clockTimes(): Collection
    {
        return collect(range(config('daycare.open'), config('daycare.close'), config('daycare.time_step', 30)))
            ->mapWithKeys(fn (int $minutes) => [
                sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) => Carbon::today()->addMinutes($minutes)->format('g:i A'),
            ]);
    }

    private function duration(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    private function csv(array $rows, string $name)
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $body = stream_get_contents($out);
        fclose($out);

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
