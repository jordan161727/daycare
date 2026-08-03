<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\Child;

class AttendanceController extends Controller
{
    public function index()
    {
            $user = request()->user();
            $selectedDate = request('date', today()->toDateString());
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
                ->orderBy('classroom')
                ->pluck('classroom');

            // Today's attendance
            $todayAttendance = Attendance::whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->get()
                ->keyBy('child_id');

            // Dashboard Statistics
            $totalChildren = Child::visibleTo($user)->where('status', 'Active')->count();

            $presentToday = Attendance::whereDate('attendance_date', $selectedDate)
                ->whereHas('child', fn ($query) => $query->visibleTo($user))
                ->count();

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
                'todayAttendance',
                'totalChildren',
                'presentToday',
                'absentToday',
                'totalRooms',
                'recentAttendance',
                'selectedDate'
            ));
    }

    public function signIn(Request $request)
    {
        $validated = $request->validate([
            'child_id' => 'required|exists:children,id',
            'attendance_date' => 'required|date_format:Y-m-d',
        ]);

        $child = Child::visibleTo($request->user())->findOrFail($validated['child_id']);

        $attendance = Attendance::firstOrCreate(
            [
                'child_id' => $child->id,
                'attendance_date' => $validated['attendance_date'],
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
        ]);
    }
}
