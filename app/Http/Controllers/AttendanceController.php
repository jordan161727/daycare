<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Attendance;
use App\Models\AttendanceAmendment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\AttendanceProjection;
use App\Services\AttendanceSheet;
use App\Services\RoomCover;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function index(WeekSchedule $weeks, AttendanceProjection $projector)
    {
            $user = request()->user();
            // A cleared date field arrives as null, not '', so fall back to today.
            $selectedDate = trim((string) request('date')) ?: today()->toDateString();
            validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

            // Rooms are worked out from an age, so a birthday moves a child
            // without anyone touching the record. Catch up before reading the
            // roster, in case the nightly sweep is not running.
            ClassroomAssignment::syncAll();

            $selectedCarbon = Carbon::parse($selectedDate);
            $weekStart = $selectedCarbon->startOfWeek(Carbon::MONDAY);
            $weekDates = collect(range(0, 4))->map(fn ($offset) => $weekStart->copy()->addDays($offset));

            // Whoever this week belongs to, which on a week gone by includes
            // children who have since left.
            $children = Child::visibleTo($user)
                ->onRollDuring($weekStart->toDateString(), $weekDates->last()->toDateString())
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            // The room chips are drawn from the same set, so a room that this
            // week holds only a leaver still has one to filter by.
            $classrooms = $children->pluck('classroom')->filter()->unique()->values();

            $attendanceRecords = Attendance::with('child')
                // Date strings, not Carbon instances. attendance_date is a DATE
                // column; a Carbon binds as 'Y-m-d H:i:s', and SQLite compares
                // the two as text — so '2026-09-14' sorts before
                // '2026-09-14 00:00:00' and a sign-in on the Monday of the week
                // was silently dropped from the sheet. The same trap
                // ClosureDay::scopeBetweenDates documents, and Monday is not a
                // rare day to be missing. Every other range in the app already
                // binds strings; this one had been left behind.
                ->whereBetween('attendance_date', [
                    $weekDates->first()->toDateString(),
                    $weekDates->last()->toDateString(),
                ])
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->get();

            $attendanceMap = [];
            $timezone = config('app.timezone');
            foreach ($attendanceRecords as $attendance) {
                $date = $attendance->attendance_date->toDateString();
                $session = $attendance->session ?? 'FULL';
                $attendanceMap[$attendance->child_id][$date][$session] = Child::timeShort($attendance->signed_in_at->timezone($timezone));
            }

            // Dashboard Statistics
            $totalChildren = $children->count();

            $presentToday = Attendance::whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->distinct()
                ->count('child_id');

            $absentToday = $totalChildren - $presentToday;

            $totalRooms = $classrooms->count();

            // Recent Sign-ins
            $recentAttendance = Attendance::with('child')
                ->whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->latest('signed_in_at')
                ->take(10)
                ->get();

            // Opening a week for the first time snapshots it from the week before,
            // so it must be asked for rather than happen by itself: browsing ahead
            // to look at next week would otherwise fill it in, and a week nobody
            // has planned would come back covered in ticks copied from this one.
            //
            // The week we are standing in is the exception. The centre always has
            // today's sheet, and making a teacher press a button before they can
            // sign a child in would be a door where there was none.
            $weekStartDate = $weekStart->toDateString();
            $isCurrentWeek = $weekStartDate === ScheduleWeek::startOf(today()->toDateString());

            // "Open" means open to this reader: their own rooms having boxes in
            // it. Another teacher having been here first builds their rooms, not
            // everybody's, so the week's row existing is no longer the question.
            $scheduleWeek = $weeks->isOpenFor($weekStartDate, $user) || $isCurrentWeek
                ? $weeks->open($weekStartDate, $user)
                : null;

            $weekIsOpen = $scheduleWeek !== null && $weeks->isOpenFor($weekStartDate, $user);

            $scheduleMap = [];
            foreach (ScheduleSlot::where('week_start', $weekStartDate)->get() as $slot) {
                $scheduleMap[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] = (bool) $slot->is_scheduled;
            }

            // Once a week has ended its pattern is a record, so the checklist and
            // every copy control drop away rather than sitting there inert.
            $weekIsFrozen = $weeks->isFrozen($weekStartDate);
            $closedDays = ClosureDay::betweenDates($weekDates->first(), $weekDates->last())
                ->get()
                ->keyBy(fn ($day) => $day->closed_on->toDateString())
                ->map(fn ($day) => $day->reason ?: 'Centre closed');

            // Nothing to tick in a week that has not been built, so the schedule
            // view and every copy control wait until it has.
            $canEditSchedule = ($user->isAdmin() || $user->role === 'teacher') && ! $weekIsFrozen && $weekIsOpen;

            // A finished week is a record of what did not happen. Offering to
            // build one now would write a plan into a week that is already over.
            $canOpenWeek = ($user->isAdmin() || $user->role === 'teacher') && ! $weekIsOpen && ! $weekIsFrozen;

            /*
             * Correcting a day already gone.
             *
             * A room teacher is the one who knows who actually turned up, so it
             * is not a director-only job. Any week that exists and has already
             * begun, finished ones included — those are exactly the weeks a
             * missing day is noticed in, when the month is being reconciled.
             *
             * Deliberately not $canEditSchedule: that one closes on a finished
             * week, and rightly so. The plan for a week that is over is over;
             * the record of what happened in it is still correctable, and every
             * correction is written to attendance_amendments.
             */
            $canAmendAttendance = ($user->isAdmin() || $user->role === 'teacher')
                && $weekIsOpen
                && $weekStartDate <= ScheduleWeek::startOf(today()->toDateString());

            // What has already been corrected in this week, so a row that was
            // not a live sign-in is visibly not one.
            $amendmentMap = AttendanceAmendment::mapForRange(
                $weekDates->first()->toDateString(),
                $weekDates->last()->toDateString()
            );

            // What the week is expected to look like: last week's actual
            // attendance, bounded by the enrolment dates and closures, measured
            // against the contracted hours. Worked out on every read from the
            // records as they stand, so a profile edited this morning is in the
            // forecast this afternoon. The ticks are untouched — see
            // AttendanceProjection for why the two are kept apart.
            $projection = $projector->forWeek($weekStartDate, $children);
            $projectionMap = $projection['expected'];
            $projectionChildren = $projection['children'];
            $projectionTotals = $projection['totals'];
            $projectionDayTotals = $projection['day_totals'];
            $projectionSource = $projection['source_week_start'];
            $projectionBasisLabels = AttendanceProjection::BASIS_LABELS;

            // Who is on the floor with each child: their room's rostered
            // teachers, over the hours the child is contracted for, on the days
            // they are ticked. Empty until a staff week has been generated —
            // the roster is the source, and there is nothing to claim without
            // one. See RoomCover, which reads the relationship RoomDemand built.
            $roomCover = app(RoomCover::class)->forWeek($weekStartDate, $children);

            // What the "open this week" prompt names, so the offer says which
            // week it is about to copy forward before it is accepted.
            $previousWeekStart = $canEditSchedule || $canOpenWeek ? $weeks->sourceFor($weekStartDate) : null;

            return view('attendance.index', compact(
                'children',
                'classrooms',
                'weekDates',
                'attendanceMap',
                'totalChildren',
                'presentToday',
                'absentToday',
                'totalRooms',
                'recentAttendance',
                'selectedDate',
                'scheduleWeek',
                'scheduleMap',
                'canEditSchedule',
                'canOpenWeek',
                'weekIsOpen',
                'previousWeekStart',
                'weekIsFrozen',
                'closedDays',
                'weekStartDate',
                'projectionMap',
                'projectionChildren',
                'projectionTotals',
                'projectionDayTotals',
                'projectionSource',
                'projectionBasisLabels',
                'roomCover',
                'canAmendAttendance',
                'amendmentMap'
            ));
    }

    /**
     * Take an arrival back off the register.
     *
     * The other half of a correction: a cell tapped by mistake, or the wrong
     * child in a room of two Levis. Without it Edit could only ever add, which
     * would make the sheet drift further from the truth rather than closer.
     *
     * Bounded exactly as signing in is — this week, never the future, and only
     * a child this person may see — because deleting a day is the more
     * consequential half of the pair, not the lesser one.
     */
    public function removeSignIn(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => [
                'required',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    $today = now()->toDateString();

                    if ($value > $today) {
                        $fail(Carbon::parse($value)->format('l, M j').' has not happened yet, '
                            .'so there is nothing on it to take off.');
                    }
                },
            ],
            'session' => 'nullable|in:AM,PM,FULL',
        ]);

        $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);

        $session = $validated['session'] ?? 'FULL';

        $attendance = Attendance::where('child_id', $child->id)
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('session', $session)
            ->first();

        // Written down before it goes, and with the time that was on it: a
        // deletion leaves nothing in `attendances` to ask about afterwards, so
        // this is the only place the old value can survive.
        if ($attendance) {
            AttendanceAmendment::record(
                AttendanceAmendment::REMOVED,
                $attendance,
                $request->user(),
                $attendance->signed_in_at
            );

            $attendance->delete();
        }

        return response()->json(['success' => true, 'removed' => $attendance ? 1 : 0]);
    }

    /**
     * Change the hour on an arrival already recorded.
     *
     * The commonest correction there is — the child was here, the tap came
     * late — and until now the only way to make it was to take the arrival off
     * and put it back, which wrote two amendments to say one thing. The row
     * keeps its identity; the time on it moves; and for a day already gone
     * the move is written down with who made it.
     *
     * Today included. An arrival tapped at 9:20 for a child who walked in at
     * 8:45 is wrong today as much as it will be next month, and the person who
     * knows is standing at the sheet now.
     */
    public function retime(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => [
                'required',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    if ($value > now()->toDateString()) {
                        $fail(Carbon::parse($value)->format('l, M j').' has not happened yet, '
                            .'so there is no arrival on it to move.');
                    }
                },
            ],
            'session' => 'nullable|in:AM,PM,FULL',
            'signed_in_time' => ['required', 'date_format:H:i'],
        ]);

        $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);
        $session = $validated['session'] ?? 'FULL';

        $attendance = Attendance::where('child_id', $child->id)
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->where('session', $session)
            ->first();

        if (! $attendance) {
            return response()->json([
                'message' => $child->displayName().' has no arrival recorded on '
                    .Carbon::parse($validated['attendance_date'])->format('l, M j').' to move.',
            ], 422);
        }

        $attendance->signed_in_at = Carbon::parse(
            $validated['attendance_date'].' '.$validated['signed_in_time'],
            config('app.timezone')
        );
        $attendance->save();

        // The new hour is what the register now says; the old one survives
        // only here. record() writes nothing for today, deliberately — see it.
        $amendment = AttendanceAmendment::record(
            AttendanceAmendment::RETIMED,
            $attendance,
            $request->user(),
            $attendance->signed_in_at
        );

        return response()->json([
            'success' => true,
            'time' => Child::timeShort($attendance->signed_in_at->timezone(config('app.timezone'))),
            'amendment' => $amendment ? [
                'action' => $amendment->action,
                'by' => $request->user()->name,
                'on' => $amendment->created_at->format('M j'),
            ] : null,
        ]);
    }
    /**
     * The week as a page for the clipboard.
     *
     * The same children and the same slots the sign-in grid shows, arranged
     * for landscape Letter by AttendanceSheet. Nothing is written by looking:
     * a week nobody has opened prints as an empty page rather than being built
     * on the way to the printer.
     */
    public function print(AttendanceSheet $sheet)
    {
        $user = request()->user();
        $selectedDate = trim((string) request('date')) ?: today()->toDateString();
        validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        ClassroomAssignment::syncAll();

        /*
         * One week, or the month that week falls in.
         *
         * A month is the same sheet several times over rather than a different
         * one: twenty-two weekday columns will not fit across a page, and a
         * register nobody can write on is not a register. So each week keeps
         * the layout the centre already knows and takes a page of its own.
         *
         * Whole weeks, including the days either side of the month boundary.
         * The alternative is a two-column page in the first week of November,
         * which reads as a printing fault rather than as a month starting on a
         * Wednesday — and those days have to be signed for by somebody too.
         */
        $range = request('range') === 'month' ? 'month' : 'week';
        $selected = Carbon::parse($selectedDate);
        // The same roster the screen shows for this range, so a printed week
        // gone by carries the children who were in it.
        $printFrom = $range === 'month'
            ? $selected->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY)
            : $selected->copy()->startOfWeek(Carbon::MONDAY);
        $printTo = $range === 'month'
            ? $selected->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)
            : $printFrom->copy()->addDays(4);

        $children = Child::visibleTo($user)
            ->onRollDuring($printFrom->toDateString(), $printTo->toDateString())
            ->get();

        // Where "back to attendance" and the other range's link should land.
        $shared = [
            'range' => $range,
            'weekStart' => $selected->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'selectedDate' => $selectedDate,
        ];

        // The month is its own page rather than five weekly ones stapled
        // together � see AttendanceSheet::buildMonth for why the geometry has
        // to change rather than repeat.
        if ($range === 'month') {
            return view('attendance.print-month', $sheet->buildMonth($selectedDate, $children) + $shared);
        }

        return view('attendance.print', [
            'sheets' => collect([$sheet->build($shared['weekStart'], $children)]),
            'rangeLabel' => null,
        ] + $shared);
    }


    /**
     * Build a week, because somebody said so.
     *
     * The only way a week that is not the current one comes into being. It is a
     * button rather than a side effect of looking, so "next week is empty" stays
     * true until the centre has actually planned it — and so the copy forward
     * happens at a moment someone chose.
     */
    public function openWeek(Request $request, WeekSchedule $weeks)
    {
        $validated = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
        ]);

        $weekStart = ScheduleWeek::startOf($validated['week_start']);

        if ($weeks->isFrozen($weekStart)) {
            return back()->with('warning', 'That week has already ended — a finished week cannot be planned.');
        }

        if ($weeks->isOpen($weekStart)) {
            return redirect()->route('attendance.index', ['date' => $weekStart]);
        }

        $week = $weeks->open($weekStart, $request->user());
        $source = $week->copied_from_week_start;

        return redirect()
            ->route('attendance.index', ['date' => $weekStart])
            ->with('success', $source
                ? 'Week opened from '.$source->format('M j').' copy.'
                : 'Week opened. Nothing came before it, so the days start from each child\'s record.');
    }

    public function signIn(Request $request)
{
    
    $validated = $request->validate([
        'child_id' => 'required|exists:children,id',
        'attendance_date' => [
            'required',
            'date_format:Y-m-d',
            /*
             * Today, or a day already gone inside the week on screen.
             *
             * Today is the ordinary case and needs no explaining. The days
             * behind it are the correction: a child was here on Monday and
             * nobody tapped the cell, and the register has to be able to say
             * so. The sheet only offers those cells once somebody has pressed
             * Edit — but that is an affordance against accidents, not an
             * authorisation, so the boundary is drawn here where it counts.
             *
             * One thing stays refused: a date ahead of today, because an
             * arrival that has not happened is not a correction but a guess.
             *
             * Earlier weeks are open too, including weeks the centre has
             * already reported and billed from. That is a deliberate loosening
             * of the frozen-week rule and it is why every such change is
             * written to attendance_amendments — the row can move, but not
             * quietly. The ticked schedule of a finished week stays locked; it
             * is the plan, and the plan is over.
             */
            function ($attribute, $value, $fail) {
                $today = now()->toDateString();

                if ($value > $today) {
                    // Named dates, because the sheet shows a whole week at once
                    // and "today" alone does not say which column to use.
                    $fail(Carbon::parse($value)->format('l, M j').' has not happened yet — '
                        .now()->format('l, M j').' is the last day that can be signed in.');

                    return;
                }
            },

            /*
             * On the roll that day.
             *
             * The sheet draws no cell for a day outside a child's enrolment
             * dates — it draws a dash — so this is never reached by tapping.
             * It is reached by a stale tab: a page opened on Friday, a leaving
             * date entered on Monday, and the Friday tab still offering cells
             * that no longer exist. Billing a day a child was not enrolled for
             * is the kind of error nobody finds until an audit.
             */
            function ($attribute, $value, $fail) use ($request) {
                $child = Child::find($request->input('child_id'));

                if ($child && ! $child->isEnrolledOn($value)) {
                    $fail($child->displayName().' was not on the roll on '
                        .Carbon::parse($value)->format('l, M j').'.');
                }
            },
        ],

        /*
         * The halves of the day a child's room actually uses.
         *
         * School Age is signed in by morning and afternoon; every other room is
         * signed in once for the whole day. The list is not a matter of
         * preference — an AM row for a Toddler is a half day of attendance
         * against a child who has no half days, which is a billing figure that
         * cannot be reconciled against anything.
         *
         * The sheet builds its cells from the child's own session list, so this
         * too is a boundary check rather than a thing a person can trip over.
         */
        'session' => [
            'nullable',
            'in:AM,PM,FULL',
            function ($attribute, $value, $fail) use ($request) {
                $child = Child::find($request->input('child_id'));

                if (! $child) {
                    return;
                }

                $sessions = $child->sessions();

                if (! in_array($value ?? 'FULL', $sessions, true)) {
                    $fail(count($sessions) > 1
                        ? $child->classroom.' is signed in by half day — choose AM or PM.'
                        : $child->classroom.' is signed in once for the whole day, not by half.');
                }
            },
        ],

        /*
         * The hour they arrived, when somebody is entering it rather than
         * witnessing it. Optional: at the door the tap is the moment, and the
         * sheet sends nothing here. In Edit mode the time is typed, and a
         * typed time is the correction — so it is taken as given, on today as
         * on any other day.
         */
        'signed_in_time' => ['nullable', 'date_format:H:i'],
    ]);

    $validated['session'] = $validated['session'] ?? 'FULL';

    $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);

    /*
     * When to say they arrived.
     *
     * Today it is the moment the cell was tapped, which is the whole point of
     * a sign-in sheet. A day already gone has no such moment — nobody is
     * standing at the door — so it takes the hours that day was agreed for:
     * the child's own drop-off time, or the hour the centre opens if none has
     * been agreed. Stamping now() would write this afternoon onto Monday.
     */
    $signedInAt = match (true) {
        // Typed: that is the fact being recorded, whichever day it is.
        filled($validated['signed_in_time'] ?? null)
            => Carbon::parse($validated['attendance_date'].' '.$validated['signed_in_time'], config('app.timezone')),
        $validated['attendance_date'] === now()->toDateString() => now(),
        default => Carbon::parse($validated['attendance_date'].' '.Child::timeInputValue($child->drop_off_time ?: Child::DAY_OPENS_AT)),
    };

    $attendance = Attendance::firstOrCreate(
        [
            'child_id' => $child->id,
            'attendance_date' => $validated['attendance_date'],
            'session' => $validated['session'],
        ],
        [
            'signed_in_at' => $signedInAt,
        ]
    );

    // A day already gone did not get this row by somebody tapping a cell with
    // the child in front of them, so the register should not pretend it did.
    $amendment = $attendance->wasRecentlyCreated
        ? AttendanceAmendment::record(AttendanceAmendment::ADDED, $attendance, $request->user())
        : null;

    $attendance->load('child');

    return response()->json([
        'success' => true,
        'created' => $attendance->wasRecentlyCreated,
        'time' => Child::timeShort($attendance->signed_in_at->timezone(config('app.timezone'))),
        'amendment' => $amendment ? [
            'action' => $amendment->action,
            'by' => $request->user()->name,
            'on' => $amendment->created_at->format('M j'),
        ] : null,
        'child' => [
            // The reader's own format, so a row that arrives from a sign-in
            // matches the sixty already on the sheet above it.
            'name' => $attendance->child->displayName(),
            'classroom' => $attendance->child->classroom,
        ],
        'session' => $attendance->session,
    ]);
}

}
