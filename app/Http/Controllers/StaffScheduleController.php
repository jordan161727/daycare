<?php

namespace App\Http\Controllers;

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

        return view('staff-schedule.index', [
            'weekStart' => $weekStart,
            'dates' => StaffScheduleWeek::datesOf($weekStart),
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
