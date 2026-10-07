@extends('layouts.app')
@section('title', 'Staff timesheets')
@section('content')
{{--
    The week, read every morning.

    Five days across and one row per person, because the question this answers
    is "is everybody here, and did yesterday go in properly" — which is read
    across a row and down a column, not out of a list.

    It reads and never writes. Every correction goes through the punch screens,
    which record who changed what and why; a grid somebody can type into is a
    grid with no audit behind it.
--}}
@php
    use App\Http\Controllers\StaffTimesheetController as Grid;

    $fmt = fn ($date) => $date->toDateString();
    /** Minutes as "7h 45m". */
    $dur = fn (int $minutes) => intdiv(max(0, $minutes), 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    /** Hours as a whole number where they are one — "40h", "37.5h". */
    $hrs = fn (float $hours) => rtrim(rtrim(number_format($hours, 1), '0'), '.').'h';

    $attention = $rows->where('fix', '>', 0);
    $onShift = $rows->where('on_shift', true);
    $notIn = $rows->where('not_in', true);
    $totalMinutes = $rows->sum('minutes');
    $problemDays = $rows->sum('fix');

    $chip = 'inline-flex min-h-[44px] items-center gap-2 rounded-full px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent focus-visible:ring-offset-2';
    $weekBack = ['from' => $start->copy()->subWeek()->toDateString(), 'to' => $end->copy()->subWeek()->toDateString()];
    $weekOn = ['from' => $start->copy()->addWeek()->toDateString(), 'to' => $end->copy()->addWeek()->toDateString()];
@endphp

{{-- The grid reads; the panel writes — through the punch endpoints, which
     record who changed what and why. See timesheetGrid() at the foot. --}}
<div x-data="timesheetGrid()" @keydown.escape.window="close()">

    {{-- relative z-20 keeps the date picker on top of the grid: .glass-card
         carries backdrop-blur, which starts a stacking context, so the panel's
         own z-index only ever competed inside this card. --}}
    <section class="glass-card relative z-20 rounded-2xl p-6 sm:p-8">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight text-la-ink dark:text-white">Staff Timesheets</h1>

            {{-- A week either way, around the range picker. The role filter
                 rides along so choosing a period does not quietly drop it. --}}
            <div class="flex items-center gap-1">
                <a href="{{ route('staff.timesheets', array_filter(['role' => $role]) + $weekBack) }}" aria-label="Previous week"
                   class="grid h-11 w-11 place-items-center rounded-full text-la-muted hover:bg-la-well dark:text-la-faint dark:hover:bg-white/10">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <x-date-range
                    :from="$start->toDateString()"
                    :to="$end->toDateString()"
                    :action="route('staff.timesheets')"
                    :keep="array_filter(['role' => $role])" />
                <a href="{{ route('staff.timesheets', array_filter(['role' => $role]) + $weekOn) }}" aria-label="Next week"
                   class="grid h-11 w-11 place-items-center rounded-full text-la-muted hover:bg-la-well dark:text-la-faint dark:hover:bg-white/10">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>

            {{-- Back to the ordinary answer in one press. --}}
            <a href="{{ route('staff.timesheets', array_filter(['role' => $role])) }}"
               class="{{ $chip }} bg-la-accent-soft text-la-accent hover:bg-[#cfe2f7] dark:bg-la-accent/20 dark:text-indigo-200 dark:hover:bg-la-accent/30">This week</a>

            {{-- The export is the range on screen: same dates, same role. --}}
            <a href="{{ route('staff.timesheets.export', request()->only('from', 'to', 'role') + ['t' => now()->timestamp]) }}"
               class="{{ $chip }} ml-auto border border-la-border-strong text-la-ink hover:bg-la-well dark:border-white/15 dark:text-white dark:hover:bg-white/10">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                Export
            </a>
        </div>

        @if($truncated)
            <p class="mt-4 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-la-warn-dot/10 dark:text-amber-200">
                That range is longer than {{ Grid::MAX_DAYS }} days. Showing the first {{ Grid::MAX_DAYS }}, because a column per day past that is a table nobody can read.
            </p>
        @endif

        {{-- Four tiles: who is here, the week's paid time, what needs a fix,
             and the day itself. Each is a card with its own mark on the left,
             and the three that describe people are buttons that narrow the
             grid below to those people — a number you can act on. --}}
        @php
            $expected = $rows->sum('target') * 60;
            $tile = 'glass-card flex items-start gap-3 rounded-2xl px-4 py-3 text-left transition';
            $clickable = 'hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent focus-visible:ring-offset-2';
            $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0;
        @endphp
        <div class="mt-5 grid gap-2.5 sm:grid-cols-2 xl:grid-cols-4">

            <button type="button" @click="$dispatch('filter-status', 'onshift')" class="{{ $tile }} {{ $clickable }}" title="Show who is on shift now">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-la-accent-soft text-la-accent dark:bg-indigo-500/20 dark:text-indigo-200" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.25"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V12l2.75 1.75"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-la-muted dark:text-slate-400">On shift now</span>
                    <span class="mt-0.5 block text-[22px] font-bold leading-none tabular-nums text-la-accent dark:text-indigo-300">{{ $counts['in'] }}<span class="text-sm font-semibold text-la-muted"> of {{ $counts['staff'] }}</span></span>
                    {{-- Everyone on staff as one bar: in, gone home, not yet here. --}}
                    <span class="mt-2 flex h-1 w-full overflow-hidden rounded-full bg-la-border dark:bg-white/10" aria-hidden="true">
                        <span class="h-full bg-la-accent" style="width:{{ $pct($counts['in'], $counts['staff']) }}%"></span>
                        <span class="h-full bg-la-faint" style="width:{{ $pct($counts['out'], $counts['staff']) }}%"></span>
                    </span>
                    <span class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-la-muted dark:text-slate-400">
                        <span><span class="font-semibold text-la-ink dark:text-slate-200">{{ $counts['in'] }}</span> clocked in</span>
                        <span><span class="font-semibold text-la-ink dark:text-slate-200">{{ $counts['out'] }}</span> clocked out</span>
                        <span><span class="font-semibold text-la-ink dark:text-slate-200">{{ $counts['not_in'] }}</span> not in</span>
                    </span>
                </span>
            </button>

            <div class="{{ $tile }}">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-la-well text-la-ink dark:bg-white/10 dark:text-slate-200" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V6a.75.75 0 01.75-.75z"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-la-muted dark:text-slate-400">Hours this week</span>
                    <span class="mt-0.5 block text-[22px] font-bold leading-none tabular-nums text-la-ink dark:text-white">{{ $dur($totalMinutes) }}</span>
                    {{-- Against what every rota adds up to, so a thin bar says
                         how far through the week's hours the centre is. --}}
                    <span class="mt-2 block h-1 w-full overflow-hidden rounded-full bg-la-border dark:bg-white/10" aria-hidden="true">
                        <span class="block h-full rounded-full bg-la-bar" style="width:{{ min(100, $pct($totalMinutes, (int) $expected)) }}%"></span>
                    </span>
                    <span class="mt-1.5 block text-xs text-la-muted dark:text-slate-400">Paid time, lunch excluded{{ $expected > 0 ? ' · of '.$hrs($expected / 60).' expected' : '' }}</span>
                </span>
            </div>

            <button type="button" @click="$dispatch('filter-status', 'attention')"
                    class="{{ $tile }} {{ $clickable }} {{ $problemDays > 0 ? '!border-la-warn-border !bg-la-warn-soft dark:!border-amber-500/40 dark:!bg-amber-500/10' : '' }}"
                    title="Show the staff with days to fix">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full {{ $problemDays > 0 ? 'bg-la-warn-dot/20 text-la-warn dark:bg-amber-500/20 dark:text-amber-200' : 'bg-la-well text-la-ok dark:bg-white/10 dark:text-emerald-300' }}" aria-hidden="true">
                    @if($problemDays > 0)
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3h.008M10.3 4.2L2.8 17.3A1.9 1.9 0 004.5 20h15a1.9 1.9 0 001.7-2.7L13.7 4.2a1.9 1.9 0 00-3.4 0z"/></svg>
                    @else
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @endif
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.06em] {{ $problemDays > 0 ? 'text-la-warn dark:text-amber-300' : 'text-la-muted dark:text-slate-400' }}">Needs attention</span>
                    <span class="mt-0.5 block text-[22px] font-bold leading-none tabular-nums {{ $problemDays > 0 ? 'text-la-warn dark:text-amber-200' : 'text-la-ink dark:text-white' }}">{{ $problemDays }}<span class="text-sm font-semibold {{ $problemDays > 0 ? 'text-la-warn/80 dark:text-amber-200/80' : 'text-la-muted' }}"> {{ \Illuminate\Support\Str::plural('day', $problemDays) }}</span></span>
                    <span class="mt-2 block text-xs {{ $problemDays > 0 ? 'text-la-warn/90 dark:text-amber-200/80' : 'text-la-muted dark:text-slate-400' }}">
                        @if($problemDays > 0)
                            <span class="font-semibold">{{ $attention->count() }} staff</span> · missing outs and late ins
                        @else
                            Every day so far adds up
                        @endif
                    </span>
                </span>
            </button>

            <div class="{{ $tile }}" x-data="{ now: @js(now()->format('g:i A')), tick() { const d = new Date(); let h = d.getHours(); const m = String(d.getMinutes()).padStart(2, '0'); this.now = ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM') } }" x-init="setInterval(() => tick(), 30000)">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-la-today text-la-accent dark:bg-indigo-500/20 dark:text-indigo-200" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5M12 19.5V21M4.2 4.2l1.06 1.06M18.74 18.74l1.06 1.06M3 12h1.5M19.5 12H21M4.2 19.8l1.06-1.06M18.74 5.26l1.06-1.06M12 8.25a3.75 3.75 0 100 7.5 3.75 3.75 0 000-7.5z"/></svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-la-muted dark:text-slate-400">Today</span>
                    <span class="mt-0.5 block text-[22px] font-bold leading-none text-la-ink dark:text-white">{{ today()->format('D, M j') }}</span>
                    <span class="mt-2 block text-xs text-la-muted dark:text-slate-400">Live as of <span class="font-semibold tabular-nums text-la-ink dark:text-slate-200" x-text="now">{{ now()->format('g:i A') }}</span></span>
                </span>
            </div>
        </div>
    </section>

    {{-- The table card: filters above, the week, the legend below. The
         filters narrow what is on screen without a round trip — every row
         is already here, and the three combine. --}}
    <section class="glass-card mt-5 overflow-hidden rounded-2xl"
             @filter-status.window="status = $event.detail; $el.scrollIntoView({ behavior: 'smooth', block: 'start' })"
             x-data="{ status: 'all', search: '', room: '',
                       matches(row) {
                           const text = (row.dataset.search || '');
                           if (this.search && ! text.includes(this.search.toLowerCase())) return false;
                           if (this.room && row.dataset.room !== this.room) return false;
                           if (this.status === 'attention' && row.dataset.fix === '0') return false;
                           if (this.status === 'onshift' && row.dataset.onshift !== '1') return false;
                           if (this.status === 'notin' && row.dataset.notin !== '1') return false;
                           return true;
                       },
                       get shown() { return Array.from($el.querySelectorAll('tbody tr[data-search]')).filter(row => this.matches(row)).length } }">

        <div class="flex flex-wrap items-center gap-2 border-b border-la-border px-5 py-4 dark:border-white/10">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter by status">
                @foreach([
                    'all' => ['All staff', $rows->count(), null],
                    'attention' => ['Needs attention', $attention->count(), 'bg-la-warn-dot'],
                    'onshift' => ['On shift now', $onShift->count(), 'bg-la-accent'],
                    'notin' => ['Not in today', $notIn->count(), 'bg-la-faint'],
                ] as $key => [$label, $count, $dot])
                    <button type="button" @click="status = '{{ $key }}'" :aria-pressed="status === '{{ $key }}'"
                            :class="status === '{{ $key }}' ? 'bg-la-accent-soft text-la-accent dark:bg-la-accent/20 dark:text-indigo-200' : 'text-la-muted hover:bg-la-well dark:text-la-faint dark:hover:bg-white/10'"
                            class="{{ $chip }}">
                        @if($dot)<span class="h-2 w-2 rounded-full {{ $dot }}" aria-hidden="true"></span>@endif
                        {{ $label }}
                        <span class="rounded-full bg-white/70 px-2 py-0.5 text-xs font-semibold tabular-nums text-la-muted ring-1 ring-la-border dark:bg-white/10 dark:text-la-faint dark:ring-white/10">{{ $count }}</span>
                    </button>
                @endforeach
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <label class="relative block">
                    <span class="sr-only">Search name or ID</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-la-faint" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
                    <input type="search" x-model.trim="search" placeholder="Search name or ID"
                           class="min-h-[44px] w-[220px] rounded-lg border border-la-border-strong bg-white pl-10 pr-3 text-sm text-la-ink placeholder:text-la-faint focus:border-la-accent focus:ring-2 focus:ring-la-accent/30 dark:border-white/15 dark:bg-slate-950 dark:text-white">
                </label>
                <label class="flex items-center gap-2 text-sm text-la-muted dark:text-la-faint">
                    <span>Room</span>
                    <select x-model="room" class="min-h-[44px] rounded-lg border border-la-border-strong bg-white py-1.5 pl-3 pr-9 text-sm text-la-ink focus:border-la-accent focus:ring-2 focus:ring-la-accent/30 dark:border-white/15 dark:bg-slate-950 dark:text-white">
                        <option value="">All rooms</option>
                        @foreach($rooms as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[66rem] text-left text-sm">
                <thead>
                    <tr class="border-b border-la-border text-xs font-semibold uppercase tracking-[0.06em] text-la-muted dark:border-white/10 dark:text-la-faint">
                        <th class="w-[248px] px-5 py-3">Staff</th>
                        <th class="w-[140px] px-4 py-3">Week</th>
                        @foreach($dates as $date)
                            {{-- Today's column is tinted the whole way down: it
                                 is the one being punched into. --}}
                            <th class="px-2 py-3 text-center {{ $date->isToday() ? 'bg-la-today text-la-accent dark:bg-la-accent/10 dark:text-indigo-300' : '' }}">
                                <span class="block">{{ $date->format('D') }}</span>
                                <span class="block text-[13px] font-bold normal-case tracking-normal {{ $date->isToday() ? 'text-la-accent dark:text-indigo-100' : 'text-la-ink dark:text-slate-200' }}">{{ $date->format('M j') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-la-border dark:divide-white/5">
                    @forelse($rows as $row)
                        <tr class="transition hover:bg-la-well/60 dark:hover:bg-white/5"
                            data-search="{{ strtolower($row['name'].' '.$row['staff_id']) }}"
                            data-room="{{ $row['room'] }}" data-fix="{{ $row['fix'] }}"
                            data-onshift="{{ $row['on_shift'] ? 1 : 0 }}" data-notin="{{ $row['not_in'] ? 1 : 0 }}"
                            x-show="matches($el)">
                            <td class="px-5 py-3 align-middle">
                                <div class="flex items-center gap-3">
                                    @if($row['avatar'])
                                        <img src="{{ $row['avatar'] }}" alt="" class="h-10 w-10 shrink-0 rounded-full bg-la-navpill object-cover" loading="lazy" decoding="async">
                                    @else
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-la-navpill text-xs font-bold text-la-link dark:bg-la-accent/20 dark:text-indigo-300" aria-hidden="true">{{ $row['initials'] }}</span>
                                    @endif
                                    <span class="min-w-0">
                                        <a href="{{ route('teachers.show', $row['id']) }}" class="block truncate text-[15px] font-semibold text-la-link hover:underline dark:text-indigo-400">{{ $row['name'] }}</a>
                                        <span class="block truncate text-[13px] text-la-muted dark:text-la-faint"><span class="tabular-nums">{{ $row['staff_id'] }}</span> &middot; {{ $row['role'] }}</span>
                                    </span>
                                </div>
                            </td>

                            {{-- Paid hours against what they are owed, as a bar, with
                                 the days that need a fix counted beside it. --}}
                            <td class="px-4 py-3 align-middle">
                                <span class="block text-sm font-bold tabular-nums text-la-ink dark:text-white"><span data-hours="{{ $row['id'] }}">{{ $dur($row['minutes']) }}</span><span class="font-medium text-la-faint"> / {{ $hrs($row['target']) }}</span></span>
                                <span class="mt-1.5 block h-1.5 w-full overflow-hidden rounded-full bg-la-border dark:bg-white/10" aria-hidden="true">
                                    <span class="block h-full rounded-full bg-la-accent" style="width:{{ $row['target'] > 0 ? min(100, round($row['minutes'] / ($row['target'] * 60) * 100)) : 0 }}%"></span>
                                </span>
                                @if($row['fix'] > 0)
                                    <span class="mt-1.5 inline-flex rounded-full bg-la-warn-soft px-2 py-0.5 text-xs font-semibold text-la-warn dark:bg-la-warn-dot/20 dark:text-amber-200">{{ $row['fix'] }} {{ \Illuminate\Support\Str::plural('day', $row['fix']) }} to fix</span>
                                @endif
                            </td>

                            @foreach($dates as $date)
                                @php
                                    $day = $row['days'][$fmt($date)];
                                    $late = $day['status'] === Grid::LATE;
                                    $missing = $day['status'] === Grid::MISSING_OUT;
                                    $onShiftNow = $day['today'] && $day['open'] && ! $day['empty'];
                                    $fine = in_array($day['status'], [Grid::ON_TIME, Grid::EDITED], true) && ! $onShiftNow;
                                    $cellClass = match (true) {
                                        $late => 'border-la-danger-border bg-la-danger-soft text-la-danger hover:shadow-md dark:border-rose-500/40 dark:bg-la-danger-dot/10 dark:text-rose-100',
                                        $missing => 'border-la-warn-border bg-la-warn-soft text-la-warn hover:shadow-md dark:border-amber-500/40 dark:bg-la-warn-dot/10 dark:text-amber-100',
                                        $onShiftNow => 'border-la-accent-border bg-la-accent-soft text-la-accent hover:shadow-md dark:border-indigo-500/40 dark:bg-la-accent/10 dark:text-indigo-100',
                                        default => 'border-la-border bg-la-card text-la-ink hover:shadow-md dark:border-white/10 dark:bg-slate-900 dark:text-white',
                                    };
                                    $dotClass = match (true) {
                                        $late => 'bg-la-danger-dot ring-4 ring-la-danger-soft dark:ring-rose-500/30',
                                        $missing => 'bg-la-warn-dot ring-4 ring-la-warn-soft dark:ring-amber-500/30',
                                        $onShiftNow => 'bg-la-accent',
                                        $day['status'] === Grid::EDITED => 'border-[1.5px] border-la-ok-dot bg-transparent',
                                        default => 'bg-la-ok-dot',
                                    };
                                    $tag = $late ? 'Late' : ($onShiftNow ? 'On shift' : null);
                                    $spoken = match (true) {
                                        $missing => 'Missing clock-out, needs a fix',
                                        $late && $onShiftNow => 'On shift, clocked in late',
                                        $late => 'Clocked in late, needs a fix',
                                        $onShiftNow => 'On shift since '.$day['in'],
                                        $day['status'] === Grid::EDITED => 'Corrected, adds up now',
                                        default => 'On time',
                                    };
                                @endphp
                                <td class="px-1.5 py-2 align-top {{ $date->isToday() ? 'bg-la-today dark:bg-la-accent/5' : '' }}"
                                    data-cell="{{ $row['id'] }}|{{ $fmt($date) }}">
                                    @if($day['empty'] && $day['today'])
                                        {{-- Nobody has punched yet. Not a judgement, a fact
                                             about the morning so far. --}}
                                        <span class="flex min-h-[56px] flex-col justify-center rounded-xl border border-dashed border-la-border-strong px-2.5 py-2 text-left dark:border-white/20">
                                            <span class="text-sm font-semibold text-la-muted dark:text-la-faint">Not in</span>
                                            <span class="text-[13px] text-la-faint">No punch yet</span>
                                        </span>
                                    @elseif($day['empty'])
                                        {{-- A day off, or a day still to come. A dot here
                                             would be a judgement about either. --}}
                                        <span class="flex min-h-[56px] items-center justify-center text-la-faint dark:text-la-muted" aria-hidden="true">—</span>
                                    @else
                                        {{-- Every punched day opens the panel. A link, not a
                                             button, so a page with no script still leads to
                                             the screen that puts the day right. Painted again
                                             by cellHtml() below after a save, so the two have
                                             to agree. --}}
                                        <a href="{{ route('timesheets.fix', ['user' => $row['id'], 'date' => $fmt($date)]) }}"
                                           @click.prevent="open({{ $row['id'] }}, '{{ $fmt($date) }}')"
                                           :class="isOpen({{ $row['id'] }}, '{{ $fmt($date) }}') ? 'border-la-accent ring-[3px] ring-[rgba(27,94,145,.25)]' : ''"
                                           class="flex min-h-[56px] w-full flex-col justify-center rounded-xl border px-2.5 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent focus-visible:ring-offset-2 {{ $cellClass }}"
                                           title="{{ $day['label'] }} — open {{ $row['name'] }}’s day{{ $fine ? ' to review' : '' }}"
                                           aria-label="{{ $row['name'] }}, {{ $date->format('D M j') }}: {{ $spoken }}. Edit day">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-2 w-2 shrink-0 rounded-full {{ $dotClass }}" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1 truncate text-sm font-bold tabular-nums">{{ $missing ? $day['label'] : $dur($day['worked']) }}</span>
                                                @if($tag)<span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide opacity-80">{{ $tag }}</span>@endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-[13px] tabular-nums {{ $late || $missing ? 'opacity-90' : 'text-la-muted dark:text-la-faint' }}">
                                                @if($missing) In {{ $day['in'] }} &middot; no out
                                                @elseif($onShiftNow) In {{ $day['in'] }}
                                                @else {{ $day['in'] }} &ndash; {{ $day['out'] ?? '—' }}
                                                @endif
                                            </span>
                                        </a>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                    @endforelse
                    <tr x-show="shown === 0" x-cloak>
                        <td colspan="{{ $dates->count() + 2 }}" class="px-5 py-12 text-center text-la-muted dark:text-la-faint">No staff match these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-la-border bg-la-well px-5 py-3 text-[13px] text-la-muted dark:border-white/10 dark:bg-white/5 dark:text-la-faint">
            <span>
                Showing <span class="font-semibold text-la-ink dark:text-slate-200" x-text="shown">{{ $rows->count() }}</span> of {{ $counts['staff'] }} staff
                &middot; Click any day to edit it. Amber and red days need a fix; every change records who made it and why.
            </span>
            <span class="flex flex-wrap items-center gap-4">
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-la-ok-dot"></span> On time</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-la-accent"></span> On shift</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-la-warn-dot"></span> Missing out</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-la-danger-dot"></span> Late</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border-[1.5px] border-la-ok-dot"></span> Edited</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border border-dashed border-slate-400"></span> Not in</span>
            </span>
        </div>
    </section>

    {{-- ============================================================
         The edit panel.

         One day of one person, opened from its dot. Fix the punches — move,
         fill, add a pair, take a pair off — and the panel checks the day as
         it is typed, the way the clock will check it when it is saved. Then
         one reason, one save. Every change lands on the record as a void
         and a replacement; the panel is a door onto that trail, not a new
         one.

         The day stays lit on the grid behind, and is repainted from the
         save's answer so the dot and the hours change without a reload.
         ============================================================ --}}
    <div x-show="panel" x-cloak class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-[1px]" @click="close()" aria-hidden="true"></div>

    <aside x-show="panel" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-la-border bg-white shadow-2xl dark:border-white/10 dark:bg-night-900"
           role="dialog" aria-modal="true" :aria-label="p ? p.staff.name + ', ' + p.date_label : 'Edit day'">

        <template x-if="loading">
            <p class="p-6 text-sm text-la-muted">Opening the day…</p>
        </template>

        <template x-if="! loading && p">
            <div class="flex min-h-0 flex-1 flex-col">
                {{-- Who, when, and how the day stands. --}}
                <header class="border-b border-la-border px-5 py-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold" x-text="p.staff.name"></h2>
                            <p class="mt-0.5 text-xs text-la-muted dark:text-la-faint">
                                <span x-text="p.staff.staff_id"></span> · <span x-text="p.staff.role"></span> · <span x-text="p.date_label"></span>
                            </p>
                        </div>
                        <button type="button" @click="close()" class="rounded-lg px-2 py-1 text-sm text-la-muted hover:bg-la-well dark:hover:bg-white/10" aria-label="Close">✕</button>
                    </div>
                    <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" :class="statusClass()" x-text="statusLabel()"></span>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <template x-if="p.locked">
                        <div class="mb-4 rounded-xl border border-la-border bg-slate-50 px-3 py-2 text-xs text-la-muted dark:border-white/10 dark:bg-slate-800 dark:text-la-faint">
                            🔒 This period has been approved, so the punches are a record now. Reopen the period first if something genuinely has to change.
                        </div>
                    </template>

                    {{-- The totals, live: they follow the rows as they are typed. --}}
                    <dl class="grid grid-cols-4 gap-2 text-center">
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-la-faint">Paid</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" :class="check().errors.size ? 'text-rose-600' : ''" x-text="paidLabel()"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-la-faint">Breaks</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="check().totals.paidBreak + 'm'"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-la-faint">Lunch</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="check().totals.unpaidBreak + 'm'"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-la-faint">Scheduled</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="hours(p.totals.scheduled)"></dd>
                        </div>
                    </dl>

                    {{-- The day as a bar: green is on the clock, amber a break, grey lunch. --}}
                    <div class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10" aria-hidden="true">
                        <template x-for="segment in timeline()" :key="segment.key">
                            <span :style="'width:' + segment.width + '%'" :class="{working: 'bg-emerald-400', break: 'bg-amber-400', lunch: 'bg-la-faint'}[segment.kind]"></span>
                        </template>
                    </div>

                    {{-- The punches. One row each; a missing one is a row with no time. --}}
                    <ol class="mt-4 space-y-1.5">
                        <template x-for="row in rows" :key="row.key">
                            <li class="rounded-xl border px-3 py-2" :class="rowClass(row)">
                                <div class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="row.label"></span>
                                    <input type="time" step="60" x-model="row.at" :disabled="p.locked"
                                           class="w-[7.2rem] rounded-lg border border-la-border bg-white px-2 py-1 text-sm tabular-nums dark:border-white/10 dark:bg-slate-800"
                                           :aria-label="row.label + ' time'">
                                    {{-- Only a break or a lunch comes off. The clock-in can be
                                         moved, never removed; the clock-out likewise. --}}
                                    <button type="button" x-show="canRemove(row)" @click="remove(row)" :disabled="p.locked" class="rounded-lg border border-la-border px-2 py-1 text-xs font-bold text-la-muted hover:border-rose-300 hover:text-rose-600 dark:border-white/10" :aria-label="'Remove ' + row.label">−</button>
                                </div>
                                <p class="mt-1 text-[11px]" :class="row.error ? 'text-rose-700 dark:text-rose-300' : 'text-la-muted dark:text-la-faint'">
                                    <template x-if="row.error"><span x-text="row.error"></span></template>
                                    <template x-if="! row.error && row.missing && ! row.at">
                                        <span>
                                            <span x-text="p.today && row.type === 'OUT' ? 'Still on the clock' : 'Not punched — enter a time'"></span>
                                            {{-- The one-click fixes: the rostered end for a missing
                                                 clock-out; the usual length for a break or lunch
                                                 nobody came back off — the paid cap for a break,
                                                 half an hour for lunch. --}}
                                            <template x-if="! p.today && p.shift && row.type === 'OUT'">
                                                <button type="button" @click="row.at = p.shift.ends_at" class="ml-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Use scheduled <span x-text="clock(p.shift.ends_at)"></span></button>
                                            </template>
                                            <template x-if="row.type === 'BREAK_END' || row.type === 'LUNCH_END'">
                                                <button type="button" @click="row.at = afterStart(row)" class="ml-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Use <span x-text="usualLength(row)"></span> min · <span x-text="clock(afterStart(row))"></span></button>
                                            </template>
                                        </span>
                                    </template>
                                    <template x-if="! row.error && row.id && row.at !== row.original">
                                        <span>was <span x-text="clock(row.original)"></span> · <button type="button" @click="row.at = row.original" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-300">restore</button></span>
                                    </template>
                                    <template x-if="! row.error && row.id && row.at === row.original && row.edited">
                                        <span>corrected earlier</span>
                                    </template>
                                </p>
                            </li>
                        </template>
                    </ol>

                    <div class="mt-2 flex gap-2" x-show="! p.locked">
                        <button type="button" @click="addPair('BREAK')" class="rounded-lg border border-la-border px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">+ Break</button>
                        <button type="button" @click="addPair('LUNCH')" class="rounded-lg border border-la-border px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">+ Lunch</button>
                    </div>

                    {{-- Why. Appears once anything has changed, and is required. --}}
                    <div class="mt-5" x-show="changes().length > 0" x-cloak>
                        <p class="text-xs font-bold uppercase tracking-wide text-la-muted dark:text-la-faint">Reason</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <template x-for="(label, code) in p.reasons" :key="code">
                                <button type="button" @click="reason = code" :class="reason === code ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-la-accent/20 dark:text-indigo-200' : 'border-la-border text-la-muted hover:bg-slate-50 dark:border-white/10 dark:text-la-faint dark:hover:bg-white/10'" class="rounded-full border px-2.5 py-1 text-xs font-semibold" x-text="label"></button>
                            </template>
                        </div>
                        <input type="text" x-model="note" maxlength="120" :placeholder="reason === 'other' ? 'What happened — required' : 'Note (optional)'"
                               class="mt-2 w-full rounded-lg border border-la-border bg-white px-3 py-1.5 text-sm dark:border-white/10 dark:bg-slate-800"
                               :class="reason === 'other' && ! note.trim() ? 'border-amber-400' : ''">
                    </div>

                    {{-- What went before. Newest first; the entry just written is the one being looked for. --}}
                    <div class="mt-5">
                        <p class="text-xs font-bold uppercase tracking-wide text-la-muted dark:text-la-faint">Change history</p>
                        <template x-if="p.history.length === 0"><p class="mt-1 text-xs text-la-faint">Nothing has been changed on this day.</p></template>
                        <ul class="mt-1.5 space-y-1.5">
                            <template x-for="(entry, index) in p.history" :key="index">
                                <li class="rounded-lg bg-slate-50 px-3 py-1.5 text-xs dark:bg-white/5">
                                    <span class="font-semibold" x-text="entry.who || 'Someone'"></span>
                                    <span class="text-la-faint"> · <span x-text="entry.when"></span></span>
                                    <span class="text-la-muted dark:text-la-faint"> · <span x-text="entry.reason"></span></span>
                                    <span class="block tabular-nums text-la-ink dark:text-slate-200" x-text="entry.what"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <footer class="border-t border-la-border px-5 py-3 dark:border-white/10">
                    <p x-show="error" x-cloak class="mb-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs text-rose-800 dark:bg-la-danger-dot/10 dark:text-rose-200" x-text="error"></p>
                    <p x-show="flash" x-cloak class="mb-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs text-emerald-800 dark:bg-la-ok-dot/10 dark:text-emerald-200" x-text="flash"></p>
                    <div class="flex items-center justify-between gap-2">
                        <button type="button" @click="undoAll()" x-show="changes().length > 0" class="text-xs font-semibold text-la-muted hover:text-slate-800 dark:hover:text-white">Undo all</button>
                        <span class="flex-1"></span>
                        <button type="button" @click="close()" class="rounded-lg border border-la-border px-3 py-1.5 text-sm font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">Cancel</button>
                        <button type="button" @click="save()" :disabled="! canSave()" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40" x-text="saving ? 'Saving…' : 'Save changes'"></button>
                    </div>
                </footer>
            </div>
        </template>
    </aside>
</div>

<script>
/*
 * The grid and its panel.
 *
 * The rules a row is checked against are the clock's own — TimeClock::NEXT
 * and walk() — written out once more here so the row turns red as it is
 * typed rather than after Save is pressed. The server walks the day again on
 * save and is the one that decides; this is the affordance, that is the rule.
 */
function timesheetGrid() { return {
    panel: false,
    loading: false,
    saving: false,
    error: '',
    flash: '',
    p: null,          // the day as the server handed it over
    rows: [],
    removed: [],      // ids of punches taken off since the panel opened
    reason: '',
    note: '',
    from: @js($start->toDateString()),
    to: @js($end->toDateString()),

    /**
     * Arriving with a day named: ?open=user|date opens that day's panel at
     * once. It is how a report row, or any link that names a day, lands here
     * with the right thing already on screen rather than on a grid somebody
     * then has to find the dot on.
     */
    init() {
        const wanted = new URLSearchParams(window.location.search).get('open');
        if (! wanted) return;

        const [userId, date] = wanted.split('|');
        if (/^\d+$/.test(userId) && /^\d{4}-\d{2}-\d{2}$/.test(date)) this.open(Number(userId), date);
    },

    // What each state allows next, and what a day left in it is missing.
    NEXT: {off: ['IN'], working: ['LUNCH_START', 'BREAK_START', 'OUT'], lunch: ['LUNCH_END'], break: ['BREAK_END']},
    MISSING: {working: 'OUT', lunch: 'LUNCH_END', break: 'BREAK_END'},
    AFTER: {IN: 'working', OUT: 'off', LUNCH_START: 'lunch', LUNCH_END: 'working', BREAK_START: 'break', BREAK_END: 'working'},

    isOpen(userId, date) {
        return this.panel && this.p && this.p.staff.id === userId && this.p.date === date;
    },

    url(userId, date) {
        return '/timesheets/panel/' + userId + '/' + date + '?from=' + this.from + '&to=' + this.to;
    },

    async open(userId, date) {
        this.panel = true;
        this.loading = true;
        this.error = '';
        this.flash = '';

        try {
            const response = await fetch(this.url(userId, date), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not open that day.');

            this.load(await response.json());
        } catch (error) {
            this.error = error.message;
            this.p = null;
        } finally {
            this.loading = false;
        }
    },

    close() {
        this.panel = false;
    },

    /** The panel from the server's answer, with nothing carried over. */
    load(payload) {
        this.p = payload;
        this.removed = [];
        this.reason = '';
        this.note = '';
        this.rows = this.build(payload);
    },

    build(p) {
        const rows = p.punches.map(punch => ({
            key: 'p' + punch.id, id: punch.id, type: punch.type, label: punch.label,
            at: punch.at, original: punch.at, edited: punch.edited, missing: false, pair: null, error: '',
        }));

        // A break or a lunch is a pair, and comes off as one. A start with no
        // end — somebody went on a break and forgot to come back off it — gets
        // its end drawn in as an empty row right after it, so the fix is a
        // time typed into the gap rather than a red clock-out further down
        // with no way to put a punch in front of it.
        let pair = 0;
        for (let index = 0; index < rows.length; index++) {
            const row = rows[index];
            if (row.type !== 'BREAK_START' && row.type !== 'LUNCH_START') continue;

            const endType = row.type.replace('START', 'END');
            const end = rows.slice(index + 1).find(other => other.type === endType && other.pair === null);
            row.pair = 'x' + (++pair);

            if (end) {
                end.pair = row.pair;
            } else {
                rows.splice(index + 1, 0, {key: row.pair + 'end', id: null, type: endType, label: p.labels[endType], at: '', original: '', edited: false, missing: true, pair: row.pair, error: ''});
            }
        }

        // Still working at the end of the day and never clocked out: the
        // missing clock-out, as an empty row. (A day left on a break or at
        // lunch has already had its end drawn in above.)
        if (p.state === 'working') {
            rows.push({key: 'missing', id: null, type: 'OUT', label: p.labels.OUT, at: '', original: '', edited: false, missing: true, pair: null, error: ''});
        }

        return rows;
    },

    /* ---- checking the day as it is typed ---- */

    minutes(at) {
        if (! at) return null;
        const [h, m] = at.split(':').map(Number);
        return h * 60 + m;
    },

    /**
     * Walk the rows in the order they are shown. A time earlier than the row
     * before it, or a punch the state does not allow, marks the row; the
     * totals come from the same pass so they always agree with the marks.
     */
    check() {
        const errors = new Set();
        let state = 'off', since = null, worked = 0, paidBreak = 0, unpaidBreak = 0, previous = null;
        const cap = this.p ? this.p.paid_break_cap : 15;

        this.rows.forEach(row => {
            row.error = '';
            const at = this.minutes(row.at);
            if (at === null) return;

            if (previous !== null && at < previous) {
                row.error = 'Earlier than the punch before it';
            } else if (! this.NEXT[state].includes(row.type)) {
                row.error = {off: 'The day is not on the clock here', working: 'Already on the clock', lunch: 'Still on lunch', break: 'Still on a break'}[state];
            }

            if (row.error) { errors.add(row.key); previous = at; return; }

            if (row.type === 'OUT' || row.type === 'LUNCH_START' || row.type === 'BREAK_START') worked += Math.max(0, at - since);
            if (row.type === 'LUNCH_END') unpaidBreak += Math.max(0, at - since);
            if (row.type === 'BREAK_END') { const length = Math.max(0, at - since); paidBreak += Math.min(length, cap); unpaidBreak += Math.max(0, length - cap); }

            state = this.AFTER[row.type];
            since = at;
            previous = at;
        });

        return {errors, totals: {worked: worked + paidBreak, paidBreak, unpaidBreak, open: state !== 'off'}};
    },

    rowClass(row) {
        if (row.error) return 'border-rose-300 bg-rose-50 dark:border-rose-500/40 dark:bg-rose-500/10';
        if (row.missing && ! row.at) return 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10';
        if (row.id && row.at !== row.original) return 'border-indigo-300 bg-indigo-50/60 dark:border-indigo-500/40 dark:bg-indigo-500/10';
        if (! row.id) return 'border-indigo-300 bg-indigo-50/60 dark:border-indigo-500/40 dark:bg-indigo-500/10';
        return 'border-slate-200 dark:border-white/10';
    },

    paidLabel() {
        const totals = this.check().totals;
        return this.hours(totals.worked) + (totals.open ? '+' : '');
    },

    hours(minutes) {
        return (Math.round((minutes || 0) / 6) / 10).toFixed(1) + 'h';
    },

    clock(at) {
        const m = this.minutes(at);
        if (m === null) return '';
        const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, '0');
        return ((h % 12) || 12) + ':' + mm + ' ' + (h < 12 ? 'AM' : 'PM');
    },

    timeline() {
        const timed = this.rows.filter(row => this.minutes(row.at) !== null && ! row.error);
        if (timed.length < 2) return [];
        const start = this.minutes(timed[0].at), end = this.minutes(timed[timed.length - 1].at), span = Math.max(1, end - start);
        const segments = [];
        let state = 'off';
        timed.forEach((row, index) => {
            const next = timed[index + 1];
            state = this.AFTER[row.type];
            if (! next || state === 'off') return;
            segments.push({key: row.key, kind: state, width: (this.minutes(next.at) - this.minutes(row.at)) / span * 100});
        });
        return segments;
    },

    /* ---- editing ---- */

    /** The usual length of a break or a lunch, for the one-click end. */
    usualLength(row) {
        return row.type === 'LUNCH_END' ? 30 : (this.p ? this.p.paid_break_cap : 15);
    },

    /** The end this row's start would usually have: the start plus the usual length. */
    afterStart(row) {
        const start = this.rows.find(other => other.pair === row.pair && other !== row);
        const from = start ? this.minutes(start.at) : null;
        if (from === null) return '';
        const m = from + this.usualLength(row);
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    },

    canRemove(row) {
        return row.pair !== null && ! this.p.locked;
    },

    remove(row) {
        this.rows.filter(other => other.pair === row.pair).forEach(other => { if (other.id) this.removed.push(other.id); });
        this.rows = this.rows.filter(other => other.pair !== row.pair);
    },

    /** A start and an end, just before the clock-out. */
    addPair(kind) {
        const length = kind === 'LUNCH' ? 30 : 15;
        const outIndex = this.rows.findIndex(row => row.type === 'OUT');
        const at = outIndex === -1 ? this.rows.length : outIndex;
        const before = this.rows[at - 1], out = this.rows[at];

        const endAt = out && out.at ? this.minutes(out.at) : (before && before.at ? this.minutes(before.at) + length : null);
        const floor = before && before.at ? this.minutes(before.at) : 0;
        const startAt = endAt === null ? null : Math.max(floor, endAt - length);

        const hm = m => m === null ? '' : String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
        const pair = 'n' + Date.now();

        this.rows.splice(at, 0,
            {key: pair + 's', id: null, type: kind + '_START', label: this.p.labels[kind + '_START'], at: hm(startAt), original: '', edited: false, missing: false, pair, error: ''},
            {key: pair + 'e', id: null, type: kind + '_END', label: this.p.labels[kind + '_END'], at: hm(endAt), original: '', edited: false, missing: false, pair, error: ''},
        );
    },

    /** What would be sent: moves, additions, removals. */
    changes() {
        const list = [];
        this.rows.forEach(row => {
            if (row.id && row.at && row.at !== row.original) list.push({op: 'move', id: row.id, at: row.at});
            if (! row.id && row.at) list.push({op: 'add', type: row.type, at: row.at});
        });
        this.removed.forEach(id => list.push({op: 'remove', id}));
        return list;
    },

    canSave() {
        if (! this.p || this.p.locked || this.saving) return false;
        if (this.changes().length === 0 || this.check().errors.size > 0) return false;
        if (! this.reason) return false;
        return this.reason !== 'other' || this.note.trim() !== '';
    },

    undoAll() {
        this.rows = this.build(this.p);
        this.removed = [];
        this.reason = '';
        this.note = '';
        this.error = '';
    },

    async save() {
        if (! this.canSave()) return;
        this.saving = true;
        this.error = '';

        try {
            const response = await window.postJson(this.url(this.p.staff.id, this.p.date), {
                reason: this.reason, note: this.note.trim() || null, changes: this.changes(),
            });
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                const firstError = Object.values(data.errors ?? {}).flat()[0];
                throw new Error(firstError || data.message || 'Could not save the day.');
            }

            this.load(data);
            this.repaint(data);
            this.flash = 'Saved · logged to change history';
            setTimeout(() => { this.flash = ''; }, 4000);
        } catch (error) {
            this.error = error.message;
        } finally {
            this.saving = false;
        }
    },

    /* ---- the grid behind, painted from the save's answer ---- */

    repaint(p) {
        const cell = document.querySelector('[data-cell="' + p.staff.id + '|' + p.date + '"]');
        if (cell) cell.innerHTML = this.cellHtml(p);

        const hours = document.querySelector('[data-hours="' + p.staff.id + '"]');
        if (hours) hours.textContent = this.duration(p.hours * 60);
    },

    esc(value) {
        return String(value ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
    },

    /** Minutes as "7h 45m", the way the Blade's $dur writes it. */
    duration(minutes) {
        const m = Math.max(0, Math.round(minutes || 0));
        return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm';
    },

    /** The same cell the Blade draws — see the day cells in the table above. */
    cellHtml(p) {
        const c = p.cell, name = this.esc(p.staff.name), day = this.esc(p.date_label), id = p.staff.id, date = p.date;

        if (c.empty && c.today) {
            return '<span class="flex min-h-[56px] flex-col justify-center rounded-xl border border-dashed border-la-border-strong px-2.5 py-2 text-left dark:border-white/20">'
                + '<span class="text-sm font-semibold text-la-muted dark:text-slate-400">Not in</span><span class="text-[13px] text-la-faint">No punch yet</span></span>';
        }
        if (c.empty) return '<span class="flex min-h-[56px] items-center justify-center text-la-faint dark:text-slate-600" aria-hidden="true">—</span>';

        // The words come from the server with the cell — see cell() on the
        // controller — so this script names no state of its own.
        const late = c.status === 'late', missing = c.status === 'missing_out';
        const onShift = c.today && c.open;
        const label = this.esc(c.label);

        const cell = late ? 'border-la-danger-border bg-la-danger-soft text-la-danger hover:shadow-md dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-100'
            : missing ? 'border-la-warn-border bg-la-warn-soft text-la-warn hover:shadow-md dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100'
            : onShift ? 'border-la-accent-border bg-la-accent-soft text-la-accent hover:shadow-md dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-100'
            : 'border-la-border bg-la-card text-la-ink hover:shadow-md dark:border-white/10 dark:bg-slate-900 dark:text-white';
        const dot = late ? 'bg-la-danger-dot ring-4 ring-la-danger-soft dark:ring-rose-500/30'
            : missing ? 'bg-la-warn-dot ring-4 ring-la-warn-soft dark:ring-amber-500/30'
            : onShift ? 'bg-la-accent'
            : c.status === 'edited' ? 'border-[1.5px] border-la-ok-dot bg-transparent'
            : 'bg-la-ok-dot';
        const tag = late ? 'Late' : (onShift ? 'On shift' : '');
        const spoken = missing ? 'Missing clock-out, needs a fix'
            : (late && onShift) ? 'On shift, clocked in late'
            : late ? 'Clocked in late, needs a fix'
            : onShift ? 'On shift since ' + this.esc(c.in)
            : c.status === 'edited' ? 'Corrected, adds up now'
            : 'On time';
        const main = missing ? label : this.duration(c.worked);
        const detail = missing ? 'In ' + this.esc(c.in) + ' · no out'
            : onShift ? 'In ' + this.esc(c.in)
            : this.esc(c.in) + ' – ' + this.esc(c.out ?? '—');

        return '<a href="/timesheets/fix/' + id + '/' + date + '" @click.prevent="open(' + id + ', \'' + date + '\')" '
            + ':class="isOpen(' + id + ', \'' + date + '\') ? \'border-indigo-500 ring-[3px] ring-indigo-500/25\' : \'\'" '
            + 'class="flex min-h-[56px] w-full flex-col justify-center rounded-xl border px-2.5 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent focus-visible:ring-offset-2 ' + cell + '" '
            + 'title="' + label + ' — open ' + name + '’s day' + (late || missing || onShift ? '' : ' to review') + '" aria-label="' + name + ', ' + day + ': ' + spoken + '. Edit day">'
            + '<span class="flex items-center gap-1.5"><span class="h-2 w-2 shrink-0 rounded-full ' + dot + '" aria-hidden="true"></span>'
            + '<span class="min-w-0 flex-1 truncate text-sm font-bold tabular-nums">' + this.esc(main) + '</span>'
            + (tag ? '<span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide opacity-80">' + tag + '</span>' : '')
            + '</span><span class="mt-0.5 block truncate text-[13px] tabular-nums ' + (late || missing ? 'opacity-90' : 'text-la-muted dark:text-slate-400') + '">' + detail + '</span></a>';
    },

    statusLabel() {
        return {missing_out: 'Missing punch', late: 'Late in', edited: 'Edited', on_time: 'On time'}[this.p?.cell?.status] || 'No punches';
    },

    statusClass() {
        return {
            missing_out: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
            late: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
            edited: 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-200',
            on_time: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
        }[this.p?.cell?.status] || 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300';
    },
}; }
</script>

@endsection
