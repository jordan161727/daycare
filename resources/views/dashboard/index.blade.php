@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <x-page-header title="Dashboard" subtitle="Welcome back! Here’s what’s happening at your daycare today." />

    @php($absentToday = max($totalChildren - $presentToday, 0))
    @php($percentage = $totalChildren ? round(($presentToday / $totalChildren) * 100) : 0)
    <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card icon="children" title="Total Children" :value="$totalChildren" color="indigo" />
        <x-stat-card icon="check" title="Present Today" :value="$presentToday" color="emerald" />
        <x-stat-card icon="close" title="Absent" :value="$absentToday" color="rose" />
        <x-stat-card icon="rooms" title="Rooms" :value="$totalRooms" color="amber" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-5">
        <section class="glass-card overflow-hidden rounded-2xl p-6 xl:col-span-3">
            <div class="flex items-start justify-between"><div><p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">TODAY'S ATTENDANCE</p><h2 class="mt-1 text-xl font-bold">Attendance progress</h2></div><span class="rounded-xl bg-indigo-50 px-3 py-2 text-sm font-bold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{{ $percentage }}%</span></div>
            <div class="mt-8 h-4 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-violet-500 transition-all duration-1000" style="width: {{ $percentage }}%"></div></div>
            <div class="mt-4 flex justify-between text-sm"><span class="font-semibold">{{ $presentToday }} / {{ $totalChildren }} children present</span><span class="text-slate-500 dark:text-slate-400">{{ $absentToday }} still to arrive</span></div>
            <a href="{{ route('attendance.index') }}" class="mt-7 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:-translate-y-0.5 hover:bg-indigo-700">Take attendance <span>→</span></a>
        </section>
        <section class="glass-card rounded-2xl p-6 xl:col-span-2"><div class="flex items-center justify-between"><h2 class="text-lg font-bold">Recent attendance</h2><a href="{{ route('attendance.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View all</a></div><div class="mt-4 divide-y divide-slate-100 dark:divide-white/10">@forelse($recentAttendance as $attendance)<div class="flex items-center gap-3 py-3"><span class="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-indigo-100 to-violet-100 text-sm font-bold text-indigo-700">{{ strtoupper(substr($attendance->child->first_name, 0, 1)) }}</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ $attendance->child->first_name }} {{ $attendance->child->last_name }}</p><p class="text-xs text-slate-500">{{ $attendance->child->classroom }}</p></div><span class="text-xs font-semibold text-emerald-600">{{ $attendance->signed_in_at->format('g:i A') }}</span></div>@empty <p class="py-8 text-center text-sm text-slate-500">No sign-ins yet today.</p>@endforelse</div></section>
    </div>

    <section class="mt-6"><h2 class="text-lg font-bold">Quick actions</h2><div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@if(auth()->user()->isAdmin())<x-quick-action href="{{ route('children.import.form') }}" title="Import Children" text="Upload a class roster" icon="upload" />@endif <x-quick-action href="{{ route('attendance.index') }}" title="Attendance" text="Mark today's attendance" icon="check" />@if(auth()->user()->isAdmin())<x-quick-action href="{{ route('reports.index') }}" title="Reports" text="View attendance trends" icon="chart" />@endif <x-quick-action href="{{ route('attendance.index') }}" title="History" text="Review sign-in records" icon="clock" /></div></section>
@endsection
