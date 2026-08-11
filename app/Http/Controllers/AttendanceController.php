<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Services\AttendanceProjection;
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

            // Active children
            $children = Child::visibleTo($user)->where('status', 'Active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();

            // Classroom filters
            $classrooms = Child::visibleTo($user)->where('status', 'Active')
                ->select('classroom')
                ->distinct()
                ->pluck('classroom');

            $selectedCarbon = Carbon::parse($selectedDate);
            $weekStart = $selectedCarbon->startOfWeek(Carbon::MONDAY);
            $weekDates = collect(range(0, 4))->map(fn ($offset) => $weekStart->copy()->addDays($offset));

            $attendanceRecords = Attendance::with('child')
                ->whereBetween('attendance_date', [$weekDates->first(), $weekDates->last()])
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->get();

            $attendanceMap = [];
            $timezone = config('app.timezone');
            foreach ($attendanceRecords as $attendance) {
                $date = $attendance->attendance_date->toDateString();
                $session = $attendance->session ?? 'FULL';
                $attendanceMap[$attendance->child_id][$date][$session] = $attendance->signed_in_at->timezone($timezone)->format('g:i A');
            }

            // Dashboard Statistics
            $totalChildren = Child::visibleTo($user)->where('status', 'Active')->count();

            $presentToday = Attendance::whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->distinct()
                ->count('child_id');

            $absentToday = $totalChildren - $presentToday;

            $totalRooms = Child::visibleTo($user)->where('status', 'Active')
                ->distinct('classroom')
                ->count('classroom');

            // Recent Sign-ins
            $recentAttendance = Attendance::with('child')
                ->whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->latest('signed_in_at')
                ->take(10)
                ->get();

            // Opening a week for the first time snapshots it from the week before.
            $weekStartDate = $weekStart->toDateString();
            $scheduleWeek = $weeks->open($weekStartDate, $user);

            $scheduleMap = [];
            foreach (ScheduleSlot::where('week_start', $weekStartDate)->get() as $slot) {
                $scheduleMap[$slot->child_id][$slot->slot_date->toDateString()][$slot->session] = (bool) $slot->is_scheduled;
            }

            // Once a week has ended its pattern is a record, so the checklist and
            // every copy control drop away rather than sitting there inert.
            $weekIsFrozen = $weeks->isFrozen($weekStartDate);
            $closedDays = ClosureDay::whereBetween('closed_on', [$weekDates->first(), $weekDates->last()])
                ->get()
                ->keyBy(fn ($day) => $day->closed_on->toDateString())
                ->map(fn ($day) => $day->reason ?: 'Centre closed');

            $canEditSchedule = ($user->isAdmin() || $user->role === 'teacher') && ! $weekIsFrozen;

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

            // "Copy" almost always means "same as last week", so the week just
            // gone is offered on its own button and leads the picker.
            $previousWeekStart = $canEditSchedule ? $weeks->sourceFor($weekStartDate) : null;
            $available = $canEditSchedule ? $weeks->availableSources($weekStartDate) : collect();

            $sourceList = $available->filter(fn ($week) => $week < $weekStartDate)
                // Earlier weeks newest first; later weeks after them, nearest
                // first, for the occasional copy backwards.
                ->concat($available->filter(fn ($week) => $week > $weekStartDate)->reverse())
                ->take(12)
                ->values();

            // Rebuilding from "a normal week" means being able to spot one. A
            // week thinned out by holidays reads as a low count and a closure
            // flag, so the choice is made from the list rather than by opening
            // each week in turn.
            $sourceTicks = ScheduleSlot::whereIn('week_start', $sourceList)
                ->where('is_scheduled', true)
                ->selectRaw('week_start, count(*) as total')
                ->groupBy('week_start')
                ->pluck('total', 'week_start');

            $sourceClosures = $sourceList->mapWithKeys(fn ($week) => [$week => count(ClosureDay::inWeek($week))]);

            $sourceWeeks = $sourceList->map(fn ($week) => [
                'value' => $week,
                'label' => Carbon::parse($week)->format('M j').' – '.Carbon::parse($week)->addDays(4)->format('M j, Y'),
                'is_previous' => $week === $previousWeekStart,
                'ticked' => (int) ($sourceTicks[$week] ?? $sourceTicks[$week.' 00:00:00'] ?? 0),
                'closures' => $sourceClosures[$week],
            ]);

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
                'sourceWeeks',
                'previousWeekStart',
                'weekIsFrozen',
                'closedDays',
                'weekStartDate',
                'projectionMap',
                'projectionChildren',
                'projectionTotals',
                'projectionDayTotals',
                'projectionSource',
                'projectionBasisLabels'
            ));
    }

    // public function signIn(Request $request)
    // {
    //     $validated = $request->validate([
    //         'child_id' => 'required|exists:children,id',
    //         'attendance_date' => 'required|date_format:Y-m-d',
    //         'session' => 'nullable|in:AM,PM,FULL',
    //     ]);

    //     $validated['session'] = $validated['session'] ?? 'FULL';

    //     $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);

    //     $attendance = Attendance::firstOrCreate(
    //         [
    //             'child_id' => $child->id,
    //             'attendance_date' => $validated['attendance_date'],
    //             'session' => $validated['session'],
    //         ],
    //         [
    //             'signed_in_at' => now(),
    //         ]
    //     );

    //     $attendance->load('child');

    //     return response()->json([
    //         'success' => true,
    //         'created' => $attendance->wasRecentlyCreated,
    //         'time' => $attendance->signed_in_at->format('h:i A'),
    //         'child' => [
    //             'name' => $attendance->child->first_name.' '.$attendance->child->last_name,
    //             'classroom' => $attendance->child->classroom,
    //         ],
    //         'session' => $attendance->session,
    //     ]);
    // }
    public function signIn(Request $request)
{
    
    $validated = $request->validate([
        'child_id' => 'required|exists:children,id',
        'attendance_date' => [
            'required',
            'date_format:Y-m-d',
            function ($attribute, $value, $fail) {
                if ($value !== now()->toDateString()) {
                    // Named dates, because the sheet shows a whole week at once
                    // and "today" alone does not say which column to use.
                    $fail('Only '.now()->format('l, M j').' can be signed in. '
                        .Carbon::parse($value)->format('l, M j').' is closed — use "Copy from another week" to fill a past week.');
                }
            },
        ],
        'session' => 'nullable|in:AM,PM,FULL',
    ]);

    $validated['session'] = $validated['session'] ?? 'FULL';

    $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);

    $attendance = Attendance::firstOrCreate(
        [
            'child_id' => $child->id,
            'attendance_date' => $validated['attendance_date'],
            'session' => $validated['session'],
        ],
        [
            'signed_in_at' => now(),
        ]
    );

    $attendance->load('child');

    return response()->json([
        'success' => true,
        'created' => $attendance->wasRecentlyCreated,
        'time' => $attendance->signed_in_at->format('h:i A'),
        'child' => [
            'name' => $attendance->child->first_name.' '.$attendance->child->last_name,
            'classroom' => $attendance->child->classroom,
        ],
        'session' => $attendance->session,
    ]);
}

}
