<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $selectedDate = $request->input('date', today()->toDateString());

        validator(['date' => $selectedDate], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $startOfWeek = Carbon::parse($selectedDate)->startOfWeek(Carbon::MONDAY);
        $endOfWeek = $startOfWeek->copy()->addDays(4);

        $dates = collect();
        for ($day = 0; $day < 5; $day++) {
            $dates->push($startOfWeek->copy()->addDays($day));
        }

        $classrooms = $user->isAdmin()
            ? Child::visibleTo($user)->where('status', 'Active')->distinct()->orderBy('classroom')->pluck('classroom')
            : collect($user->assignedClassrooms());

        $selectedClassroom = $request->input('classroom', '');

        $childrenQuery = Child::visibleTo($user)
            ->where('status', 'Active');

        if (! $user->isAdmin()) {
            if ($selectedClassroom === '' && $classrooms->isNotEmpty()) {
                $selectedClassroom = $classrooms->first();
            }
        }

        if ($selectedClassroom !== '') {
            $childrenQuery->where('classroom', $selectedClassroom);
        }

        $children = $childrenQuery
            ->orderBy('classroom')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $attendanceRecords = Attendance::whereBetween('attendance_date', [$startOfWeek->toDateString(), $endOfWeek->toDateString()])
            ->whereIn('child_id', $children->pluck('id'))
            ->get();

        $attendanceMap = $attendanceRecords
            ->groupBy('child_id')
            ->mapWithKeys(function ($records, $childId) {
                return [$childId => $records->keyBy(fn ($record) => $record->attendance_date->toDateString())];
            });

        $dailyTotals = $attendanceRecords
            ->groupBy(fn ($record) => $record->attendance_date->toDateString())
            ->map(fn ($records) => $records->count());

        return view('reports.index', compact(
            'children',
            'classrooms',
            'selectedClassroom',
            'dates',
            'attendanceMap',
            'dailyTotals',
            'selectedDate'
        ));
    }
}
