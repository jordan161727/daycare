<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\RoomDemand;
use App\Services\StaffSchedule;
use Illuminate\Http\Request;

class StaffScheduleController extends Controller
{
    public function __construct(
        private StaffSchedule $scheduler,
        private RoomDemand $demand,
    ) {}

    public function index(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->query('week', now()->toDateString()));

        $week = StaffScheduleWeek::firstWhere('week_start', $weekStart);

        $shifts = StaffShift::with('user:id,name,employment,title')
            ->where('week_start', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $dates = StaffScheduleWeek::datesOf($weekStart);

        return view('staff-schedule.index', [
            'weekStart' => $weekStart,
            'dates' => $dates,
            // Approved leave is overlaid rather than generated in: a request
            // granted after the week was built takes the shifts off but cannot
            // put a marker into a table of shifts, and a blank cell where
            // somebody normally works reads as an oversight.
            'leave' => LeaveRequest::byUserAndDate($weekStart, end($dates)->toDateString()),
            'week' => $week,
            'shifts' => $shifts,
            'byTeacher' => $shifts->groupBy('user_id'),
            'staff' => User::teachers()->get(['id', 'name', 'employment', 'title']),
            'coverage' => $week ? $this->scheduler->coverage($weekStart) : [],
            'demand' => $this->demand->forWeek($weekStart),
            'rooms' => ClassroomAssignment::rooms(),
            'mode' => $request->query('mode') === 'room' ? 'room' : 'teacher',
            'day' => in_array($request->query('day'), config('daycare.days'), true)
                ? $request->query('day')
                : config('daycare.days')[0],
        ]);
    }

    /**
     * One person's own week, and nothing else on the floor.
     *
     * The full roster answers "who is in on Wednesday", which is a question
     * about the centre. This answers "when am I in", which is a question about
     * you — and it is the one a teacher actually opens on a phone in a
     * corridor. Same shifts, no gantt, no colleagues, no ratio bars.
     */
    public function mine(Request $request)
    {
        $user = $request->user();
        $weekStart = StaffScheduleWeek::startOf($request->query('week', now()->toDateString()));

        $shifts = StaffShift::where('user_id', $user->id)
            ->where('week_start', $weekStart)
            ->orderBy('starts_at')
            ->get();

        $dates = StaffScheduleWeek::datesOf($weekStart);

        return view('staff-schedule.mine', [
            'staff' => $user,
            'weekStart' => $weekStart,
            'dates' => $dates,
            // Their own approved leave, so the day they booked off shows as
            // booked off rather than as a day they are simply not needed.
            'leave' => LeaveRequest::byUserAndDate($weekStart, end($dates)->toDateString())[$user->id] ?? [],
            'week' => StaffScheduleWeek::firstWhere('week_start', $weekStart),
            'byDay' => $shifts->groupBy('day'),
            'minutes' => $shifts->sum(fn (StaffShift $shift) => $shift->minutes()),
            // What the rules say they are owed, so a short week is visible as a
            // short week rather than as a number with nothing to compare it to.
            'expected' => $user->loadMissing('staffRules')->weeklyHours(),
        ]);
    }

    /**
     * Re-solve the week from the current rules.
     *
     * Destructive by design — see StaffSchedule::generate(). The button says so.
     */
    public function generate(Request $request)
    {
        $weekStart = StaffScheduleWeek::startOf($request->input('week', now()->toDateString()));

        $week = $this->scheduler->generate($weekStart, $request->user());

        $warnings = count($week->warnings ?? []);

        return redirect()
            ->route('staff-schedule.index', ['week' => $weekStart, 'mode' => $request->input('mode', 'teacher')])
            ->with('success', $warnings === 0
                ? 'Schedule generated with no conflicts.'
                : "Schedule generated with {$warnings} thing".($warnings === 1 ? '' : 's').' to look at.');
    }
}
