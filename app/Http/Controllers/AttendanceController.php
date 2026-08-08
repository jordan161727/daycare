<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\Attendance;
use App\Models\Child;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function index()
    {
            $user = request()->user();
            // A cleared date field arrives as null, not '', so fall back to today.
            $selectedDate = trim((string) request('date')) ?: today()->toDateString();
            validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

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
                'selectedDate'
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
                    $fail('Attendance can only be signed in for today.');
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
