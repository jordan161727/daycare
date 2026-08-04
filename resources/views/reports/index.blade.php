@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<x-page-header title="Reports" subtitle="Attendance reports for your classroom or the whole center." />
<section class="glass-card mt-7 rounded-2xl p-6">
    <form method="GET" action="{{ route('reports.index') }}" class="grid gap-4 md:grid-cols-3">
        <label class="block">
            <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">Week of</span>
            <input type="date" name="date" value="{{ $selectedDate }}" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200 dark:bg-slate-900 dark:text-slate-100" />
        </label>

        @if(auth()->user()->isAdmin())
            <label class="block">
                <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">Classroom</span>
                <select name="classroom" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200 dark:bg-slate-900 dark:text-slate-100">
                    <option value="">All classrooms</option>
                    @foreach($classrooms as $classroom)
                        <option value="{{ $classroom }}" @selected($selectedClassroom === $classroom)>{{ $classroom }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex items-end">
            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Generate report</button>
        </div>
    </form>
</section>

<section class="glass-card mt-6 rounded-2xl p-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">ATTENDANCE REPORT</p>
            <h2 class="mt-1 text-xl font-bold">Weekly attendance stamps</h2>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-900">
                <p class="text-sm text-slate-500">Active children</p>
                <p class="mt-2 text-3xl font-bold text-slate-900 dark:text-white">{{ $children->count() }}</p>
            </div>
            <div class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-900">
                <p class="text-sm text-slate-500">Total present stamps</p>
                <p class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ $attendanceMap->flatten(1)->count() }}</p>
            </div>
        </div>
    </div>
</section>

<section class="glass-card mt-6 overflow-hidden rounded-2xl">
    @if($children->isEmpty())
        <div class="p-8 text-center text-sm text-slate-500">No active children found for this week or selected classroom.</div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-700">
                <thead class="bg-slate-100 text-slate-500 dark:bg-slate-900 dark:text-slate-300">
                    <tr>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">Child</th>
                        <th class="whitespace-nowrap px-4 py-3 font-semibold">Classroom</th>
                        @foreach($dates as $date)
                            <th class="whitespace-nowrap px-4 py-3 font-semibold text-center">{{ $date->format('D') }}<span class="block text-xs text-slate-400">{{ $date->format('m/d') }}</span></th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    @foreach($children->groupBy('classroom') as $classroom => $group)
                        <tr class="bg-slate-50 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
                            <td colspan="{{ $dates->count() + 2 }}" class="px-4 py-3 font-semibold">{{ $classroom ?: 'Unassigned classroom' }}</td>
                        </tr>
                        @foreach($group as $child)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">{{ $child->first_name }} {{ $child->last_name }}</td>
                                <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $child->classroom }}</td>
                                @foreach($dates as $date)
                                    @php
                                        $dateKey = $date->toDateString();
                                        $present = isset($attendanceMap[$child->id][$dateKey]);
                                    @endphp
                                    <td class="border-l border-slate-200 px-4 py-3 text-center text-sm dark:border-slate-700">
                                        @if($present)
                                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">✓</span>
                                        @else
                                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-900 dark:text-slate-500">-</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-100 text-slate-700 dark:bg-slate-950 dark:text-slate-200">
                    <tr>
                        <td colspan="2" class="px-4 py-3 font-semibold">Daily totals</td>
                        @foreach($dates as $date)
                            <td class="border-l border-slate-200 px-4 py-3 text-center font-semibold dark:border-slate-700">{{ $dailyTotals[$date->toDateString()] ?? 0 }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>
@endsection
