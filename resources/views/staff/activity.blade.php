@extends('layouts.app')
@section('title', 'Activity')
@section('content')
{{-- Staff → Activity: what everyone did, one line each, newest first.

     Ray (28 Oct 2026): "11:00 AM = Farhana checked Saroya in @ 11:00 AM;
     11:05 AM = Patrick modified Evana check in time for Sept 15 from
     Nothing to 10:00 AM". That is the whole page: the clock down the left,
     the sentence beside it. The lines come from App\Services\ActivityLog,
     which writes one for every record anybody saves. --}}
@php($link = fn (array $extra = []) => route('staff.activity', array_filter($extra + ['date' => $day->toDateString(), 'who' => $who, 'q' => $q], fn ($v) => $v !== null && $v !== '')))
@php($prev = $day->copy()->subDay()->toDateString())
@php($next = $day->copy()->addDay()->toDateString())

<div class="mx-auto max-w-4xl" data-activity-log>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Activity</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Everything anyone did, as it happened. Check-ins, fixed times, clock punches, record changes, settings.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('staff.activity') }}" class="glass-card mt-5 flex flex-wrap items-center gap-2 rounded-2xl px-3 py-2.5" data-activity-filters>
        {{-- The day as one control, like the week on Director Attendance. --}}
        <div class="flex items-center rounded-full border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-800">
            <a href="{{ $link(['date' => $prev]) }}" class="grid h-8 w-9 place-items-center rounded-l-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Previous day">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <label class="border-x border-slate-200 px-1 dark:border-white/10">
                <span class="sr-only">Day</span>
                <input type="date" name="date" value="{{ $day->toDateString() }}" onchange="this.form.submit()" class="h-8 border-0 bg-transparent px-2 text-sm font-semibold text-slate-900 focus:ring-0 dark:text-white">
            </label>
            <a href="{{ $link(['date' => $next]) }}" class="grid h-8 w-9 place-items-center rounded-r-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Next day">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        </div>
        @unless($day->isToday())
            <a href="{{ $link(['date' => today()->toDateString()]) }}" class="rounded-full bg-indigo-100 px-3 py-1.5 text-xs font-semibold text-indigo-900 hover:bg-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-100">Today</a>
        @endunless

        <label class="ml-auto">
            <span class="sr-only">Who</span>
            <select name="who" onchange="this.form.submit()" class="h-9 rounded-xl border border-slate-200 bg-white pl-3 pr-8 text-sm dark:border-white/10 dark:bg-slate-800" data-activity-who>
                <option value="">Everyone</option>
                @foreach($people as $person)
                    <option value="{{ $person['value'] }}" @selected($who === $person['value'])>{{ $person['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="relative block w-full sm:w-56">
            <span class="sr-only">Search the day</span>
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
            <input type="search" name="q" value="{{ $q }}" placeholder="Search this day" class="h-9 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-300 dark:border-white/10 dark:bg-slate-800">
        </label>
    </form>

    <section class="glass-card mt-4 rounded-2xl">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/70 px-5 py-3 text-sm dark:border-white/10">
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ $day->isToday() ? 'Today' : $day->format('l, M j') }}</h2>
            <span class="text-xs text-slate-500 dark:text-slate-400" data-activity-count>
                @if($lines->count() === $total)
                    {{ $total }} {{ Str::plural('line', $total) }}
                @else
                    {{ $lines->count() }} of {{ $total }} {{ Str::plural('line', $total) }}
                @endif
            </span>
        </div>

        @if($lines->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                {{ $total === 0 ? 'Nothing logged '.($day->isToday() ? 'yet today.' : 'on this day.') : 'Nothing matches.' }}
            </p>
        @else
            <ol class="divide-y divide-slate-100 dark:divide-white/5">
                @foreach($lines as $line)
                    <li class="flex gap-4 px-5 py-2.5 text-sm" data-activity-line>
                        <time datetime="{{ $line->occurred_at->toIso8601String() }}" class="w-[4.6rem] shrink-0 tabular-nums text-slate-500 dark:text-slate-400">{{ $line->clock() }}</time>
                        <span class="min-w-0 flex-1 text-slate-800 dark:text-slate-100">{{ $line->text }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
</div>
@endsection
