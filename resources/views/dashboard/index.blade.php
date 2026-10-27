@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- The day as it stands — Phase 4.1 of docs/work.md. Every number here
         is read from what the other screens wrote (the roll, the week's
         ticks, today's arrivals, the staff clock, the roster) by
         App\Services\DashboardSnapshot, so none of them can disagree with
         the screen it came from. --}}
    @php($percentage = $snapshot['scheduled'] ? (int) round($snapshot['arrived'] / $snapshot['scheduled'] * 100) : 0)

    {{-- The heading at the mock's size, and one welcome line that carries the
         date and the time — the time keeps going, since it is the first thing
         looked at by somebody about to write an hour down. Server-rendered
         first, then ticked by the browser. --}}
    <div>
        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Daycare management</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight sm:text-4xl">Dashboard</h1>
        <p class="mt-2 text-base text-slate-500 dark:text-slate-400" data-dashboard-clock
           x-data="{ at: '' }"
           x-init="const tick = () => { at = new Date().toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' }) + ', ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }; tick(); setInterval(tick, 15000);">
            Welcome back! Here’s what’s happening at your daycare today · <span x-text="at">{{ $snapshot['now']->timezone(config('app.timezone'))->format('l, M j, g:i A') }}</span>
        </p>
    </div>

    {{-- The seven cards: where every child on today's floor is, and how many
         adults are with them. --}}
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7" data-dashboard-cards>
        @foreach([
            ['Scheduled today', $snapshot['scheduled'], 'of '.$snapshot['enrolled'].' enrolled', 'scheduled'],
            ['Checked in', $snapshot['in'], 'In the building now', 'in'],
            ['Checked out · returning', $snapshot['returning'], 'At school, back later today', 'returning'],
            ['Checked out', $snapshot['out'], 'Picked up for the day', 'out'],
            ['Expected', $snapshot['expected'], 'Not arrived yet', 'expected'],
            ['Absent', $snapshot['absent'], 'Not arrived · an hour past their time', 'absent'],
            ['Staff on the floor', $snapshot['staffOnFloor'], 'Clocked in, in a room', 'staff'],
        ] as [$title, $value, $note, $key])
            <div class="glass-card flex min-h-[9.75rem] flex-col rounded-2xl p-5" data-card="{{ $key }}">
                <p class="text-[15px] font-semibold leading-snug text-slate-800 dark:text-slate-100">{{ $title }}</p>
                <p class="mt-1.5 text-[2.25rem] font-bold tabular-nums leading-none" data-card-value>{{ $value }}</p>
                <p class="mt-auto pt-3 text-sm text-slate-500 dark:text-slate-400">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    {{-- Ratios right now: each room, children against staff against what
         the ratio asks for, and a word on where that leaves it. --}}
    <section class="glass-card mt-5 rounded-2xl p-6" data-ratios>
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-bold">Ratios right now</h2>
            @if(Route::has('floor-plan.index'))
                <a href="{{ route('floor-plan.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Open the floor plan →</a>
            @else
                <a href="{{ route('attendance.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Open the register →</a>
            @endif
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            @foreach($snapshot['ratios'] as $room)
                @php($tone = [
                    'covered' => ['border-slate-200 bg-white dark:border-white/10 dark:bg-night-800', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200'],
                    'short' => ['border-rose-200 bg-rose-50/70 dark:border-rose-500/30 dark:bg-rose-500/10', 'bg-rose-600 text-white'],
                    'spare' => ['border-slate-200 bg-white dark:border-white/10 dark:bg-night-800', 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200'],
                    'empty' => ['border-slate-200 bg-white dark:border-white/10 dark:bg-night-800', 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300'],
                ][$room['tone']])
                <div class="rounded-xl border p-4 {{ $tone[0] }}" data-ratio-room="{{ $room['room'] }}" data-ratio-tone="{{ $room['tone'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-base font-bold">{{ $room['room'] }}</h3>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $tone[1] }}">{{ $room['status'] }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-700 dark:text-slate-200">{{ $room['children'] }} {{ Str::plural('child', $room['children']) }} · {{ $room['staff'] }} staff</p>
                    @if($room['note'] !== '')
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $room['note'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <div class="mt-5 grid gap-5 xl:grid-cols-5">
        <section class="glass-card overflow-hidden rounded-2xl p-6 xl:col-span-3" data-progress>
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Today's attendance</p>
                    <h2 class="mt-1 text-xl font-bold">Attendance progress</h2>
                </div>
                <span class="rounded-xl bg-indigo-50 px-3 py-2 text-sm font-bold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">{{ $percentage }}%</span>
            </div>
            <div class="mt-6 h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-indigo-500 transition-all duration-1000" style="width: {{ $percentage }}%"></div></div>
            <div class="mt-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                <span><b class="font-semibold">Arrived: {{ $snapshot['arrived'] }} of {{ $snapshot['scheduled'] }} scheduled</b> <span class="text-slate-500 dark:text-slate-400">· {{ $snapshot['in'] }} checked in, {{ $snapshot['returning'] }} checked out · returning</span></span>
                <span class="text-slate-500 dark:text-slate-400">{{ $snapshot['expected'] }} expected · {{ $snapshot['absent'] }} absent</span>
            </div>
            <a href="{{ route('attendance.index') }}" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:-translate-y-0.5 hover:bg-indigo-700">Take attendance <span>→</span></a>
        </section>

        {{-- The days the centre is shut, on the screen everybody opens. A
             teacher has no way into the holidays page, so without this they
             find out about Labour Day from the greyed-out column on the
             attendance board — on the day, which is too late. --}}
        <section class="glass-card overflow-hidden rounded-2xl xl:col-span-2">
            <header class="flex items-center justify-between gap-4 px-6 py-5">
                <h2 class="text-xl font-bold">Upcoming closures</h2>
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('holidays.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">Manage holidays</a>
                @endif
            </header>

            @if($upcomingClosures->isEmpty())
                <p class="px-6 pb-6 text-sm text-slate-500 dark:text-slate-400">Nothing scheduled — the centre is open every weekday from here on.</p>
            @else
                <ul class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-white/10 dark:border-white/10">
                    @foreach($upcomingClosures as $closure)
                        @php($days = (int) today()->diffInDays($closure->closed_on, false))
                        @php($soon = $days <= 7)
                        <li>
                            <a href="{{ route('attendance.index', ['date' => $closure->closed_on->toDateString()]) }}"
                               class="flex items-center gap-4 px-6 py-3 transition hover:bg-slate-50 dark:hover:bg-white/5">
                                <span class="w-28 shrink-0 text-sm font-semibold tabular-nums {{ $soon ? 'text-rose-600 dark:text-rose-300' : '' }}">{{ $closure->closed_on->format('D j M') }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-slate-600 dark:text-slate-300">{{ $closure->label() }}</span>
                                <span class="shrink-0 text-xs {{ $soon ? 'font-semibold text-rose-600 dark:text-rose-300' : 'text-slate-400' }}">
                                    @if($days === 0) Today @elseif($days === 1) Tomorrow @else in {{ $days }} days @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <section class="glass-card mt-5 rounded-2xl p-6">
        <div class="flex items-center justify-between"><h2 class="text-lg font-bold">Recent attendance</h2><a href="{{ route('attendance.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View all</a></div>
        <div class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
            @forelse($recentAttendance as $attendance)
                <div class="flex items-center gap-3 py-3"><x-child-avatar :child="$attendance->child" /><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ $attendance->child->first_name }} {{ $attendance->child->last_name }}</p><p class="text-xs text-slate-500">{{ $attendance->child->classroom }}</p></div><span class="text-xs font-semibold text-emerald-600">{{ $attendance->signed_in_at->timezone(config('app.timezone'))->format('g:i A') }}</span></div>
            @empty
                <p class="py-8 text-center text-sm text-slate-500">No sign-ins yet today.</p>
            @endforelse
        </div>
    </section>

    <section class="mt-6"><h2 class="text-lg font-bold">Quick actions</h2><div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@if(auth()->user()->isAdmin())<x-quick-action href="{{ route('children.import.form') }}" title="Import Children" text="Upload a class roster" icon="upload" />@endif <x-quick-action href="{{ route('attendance.index') }}" title="Attendance" text="Mark today's attendance" icon="check" />@if(auth()->user()->isAdmin())<x-quick-action href="{{ route('reports.index') }}" title="Reports" text="View attendance trends" icon="chart" />@endif <x-quick-action href="{{ route('attendance.index') }}" title="History" text="Review sign-in records" icon="clock" /></div></section>
@endsection
