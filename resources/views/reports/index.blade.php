@extends('layouts.app')
@section('title', 'Reports')
@section('content')
@php
    $cell = 'border border-slate-300 dark:border-slate-600';
    $mark = fn ($present) => $present ? '1' : '';
@endphp

<div x-data="{ view: 'sheet' }">
<div class="no-print glass-card rounded-2xl px-3 py-2.5">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="text-base font-bold tracking-tight sm:text-lg" x-text="view === 'sheet' ? 'Attendance sheet' : 'Class report'"></h1>
        <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
            <button type="button" @click="view = 'sheet'" :class="view === 'sheet' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'" class="rounded-md px-2.5 py-1 text-xs font-semibold transition">Daily sheet</button>
            <button type="button" @click="view = 'summary'" :class="view === 'summary' ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400'" class="rounded-md px-2.5 py-1 text-xs font-semibold transition">Class report</button>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span class="font-bold text-slate-900 dark:text-white">{{ $children->count() }}</span> children</span>
        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $dates->first()->format('F j') }} – {{ $dates->last()->format('F j, Y') }}</span>
        <form method="GET" action="{{ route('reports.index') }}" class="ml-auto flex flex-wrap items-center gap-1.5">
            <label class="sr-only" for="report-date">Week of</label>
            <input id="report-date" type="date" name="date" value="{{ $selectedDate }}" class="rounded-lg border-0 bg-slate-100 px-2 py-1.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-100">
            <label class="sr-only" for="report-classroom">Classroom</label>
            <select id="report-classroom" name="classroom" class="rounded-lg border-0 bg-slate-100 px-2 py-1.5 text-xs text-slate-800 dark:bg-slate-800 dark:text-slate-100">
                @if(auth()->user()->isAdmin())<option value="">All classrooms</option>@endif
                @foreach($classrooms as $classroom)
                    <option value="{{ $classroom }}" @selected($selectedClassroom === $classroom)>{{ $classroom }}</option>
                @endforeach
            </select>
            <button class="rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">View</button>
            <button type="button" onclick="window.print()" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">Print</button>
        </form>
    </div>
</div>

@if($blocks->isEmpty())
    <div class="glass-card mt-3 rounded-2xl p-8 text-center text-sm text-slate-500">No active children found for this week or selected classroom.</div>
@else
    {{-- Only shown on the printout, where the toolbar above is hidden. --}}
    <h1 class="hidden print:mb-2 print:block print:text-base print:font-bold" x-text="(view === 'sheet' ? 'Attendance' : 'Class report') + ' — week of {{ $dates->first()->format('F j, Y') }}'">Attendance — week of {{ $dates->first()->format('F j, Y') }}</h1>

    {{-- Class report: one row per room, one column per weekday. --}}
    <div x-show="view === 'summary'" x-cloak class="mt-3 max-w-2xl">
        <table class="w-full border-collapse text-xs leading-tight">
            <thead>
                <tr>
                    <th scope="col" class="{{ $cell }} bg-blue-100 px-2 py-1.5 text-left font-semibold text-slate-700 dark:bg-blue-500/20 dark:text-blue-50">Classroom</th>
                    @foreach($dates as $date)
                        <th scope="col" class="{{ $cell }} w-16 bg-blue-100 px-2 py-1.5 text-center font-semibold text-slate-700 dark:bg-blue-500/20 dark:text-blue-50">{{ $date->format('D') }}<span class="block text-[10px] font-normal text-slate-500 dark:text-blue-100/70">{{ $date->format('n/j') }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($blocks as $block)
                    <tr class="odd:bg-white even:bg-slate-50 dark:odd:bg-slate-900 dark:even:bg-slate-800/60">
                        <th scope="row" class="{{ $cell }} px-2 py-1.5 text-left font-medium">{{ $block['room'] }}</th>
                        @foreach($dates as $date)
                            <td class="{{ $cell }} px-2 py-1.5 text-center">{{ $block['totals'][$date->toDateString()] ?? 0 }}</td>
                        @endforeach
                    </tr>
                @endforeach
                <tr class="bg-amber-100 font-bold dark:bg-amber-500/20">
                    <th scope="row" class="{{ $cell }} px-2 py-1.5 text-left">Total</th>
                    @foreach($dates as $date)
                        <td class="{{ $cell }} px-2 py-1.5 text-center">{{ $centerTotals[$date->toDateString()] }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>

    <div x-show="view === 'sheet'" class="mt-3 gap-4 xl:columns-2">
        @foreach($blocks as $block)
            @php($splits = $block['splitsSessions'])
            <div class="mb-4 inline-block w-full break-inside-avoid align-top">
                <table class="w-full border-collapse text-[11px] leading-tight">
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $cell }} bg-amber-200/80 px-1.5 py-1 text-left font-bold text-slate-900 dark:bg-amber-500/30 dark:text-amber-50">{{ $block['room'] }}</th>
                            <th scope="col" class="{{ $cell }} bg-amber-200/80 px-1 py-1 text-center font-bold text-slate-900 dark:bg-amber-500/30 dark:text-amber-50">DOB</th>
                            @foreach($dates as $date)
                                <th scope="col" colspan="{{ $splits ? 2 : 1 }}" class="{{ $cell }} bg-amber-200/80 px-1 py-1 text-center font-bold text-slate-900 dark:bg-amber-500/30 dark:text-amber-50">{{ $date->format('j') }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="col" class="{{ $cell }} bg-blue-100 px-1.5 py-0.5 text-left font-semibold text-slate-700 dark:bg-blue-500/20 dark:text-blue-50">{{ $dates->first()->format('F') }}</th>
                            <th scope="col" class="{{ $cell }} bg-blue-100 px-1 py-0.5 dark:bg-blue-500/20"></th>
                            @foreach($dates as $date)
                                <th scope="col" class="{{ $cell }} w-6 bg-blue-100 px-1 py-0.5 text-center font-semibold text-slate-700 dark:bg-blue-500/20 dark:text-blue-50">{{ substr($date->format('D'), 0, 1) }}</th>
                                @if($splits)
                                    <th scope="col" class="{{ $cell }} w-6 bg-blue-100 px-1 py-0.5 text-center font-semibold text-slate-700 dark:bg-blue-500/20 dark:text-blue-50">pm</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($block['children'] as $child)
                            <tr class="odd:bg-white even:bg-slate-50 dark:odd:bg-slate-900 dark:even:bg-slate-800/60">
                                <td class="{{ $cell }} whitespace-nowrap px-1.5 py-0.5 font-medium">{{ $child->last_name }}, {{ $child->first_name }}</td>
                                <td class="{{ $cell }} whitespace-nowrap px-1 py-0.5 text-center text-slate-500 dark:text-slate-400">{{ optional($child->dob ?? $child->birth_date)->format('n/j/y') }}</td>
                                @foreach($dates as $date)
                                    @php($sessions = $presence[$child->id][$date->toDateString()] ?? [])
                                    @php($full = isset($sessions['FULL']))
                                    <td class="{{ $cell }} px-1 py-0.5 text-center font-semibold text-blue-700 dark:text-blue-300">{{ $mark($splits ? ($full || isset($sessions['AM'])) : filled($sessions)) }}</td>
                                    @if($splits)
                                        <td class="{{ $cell }} px-1 py-0.5 text-center font-semibold text-blue-700 dark:text-blue-300">{{ $mark($full || isset($sessions['PM'])) }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="bg-amber-100 font-bold dark:bg-amber-500/20">
                            <td colspan="2" class="{{ $cell }} px-1.5 py-0.5">{{ $block['room'] }} total</td>
                            @foreach($dates as $date)
                                @php($key = $date->toDateString())
                                <td class="{{ $cell }} px-1 py-0.5 text-center">{{ $splits ? ($block['sessionTotals'][$key]['AM'] ?? 0) : ($block['totals'][$key] ?? 0) }}</td>
                                @if($splits)
                                    <td class="{{ $cell }} px-1 py-0.5 text-center">{{ $block['sessionTotals'][$key]['PM'] ?? 0 }}</td>
                                @endif
                            @endforeach
                        </tr>
                        @if($block['combined'])
                            <tr class="bg-emerald-100 font-bold dark:bg-emerald-500/20">
                                <td colspan="2" class="{{ $cell }} px-1.5 py-0.5">{{ $block['combined']['label'] }}</td>
                                @foreach($dates as $date)
                                    <td colspan="{{ $splits ? 2 : 1 }}" class="{{ $cell }} px-1 py-0.5 text-center">{{ $block['combined']['totals'][$date->toDateString()] }}</td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <table x-show="view === 'sheet'" class="mt-1 w-full border-collapse text-[11px] leading-tight xl:w-1/2">
        <tbody>
            <tr class="bg-blue-200/70 font-bold dark:bg-blue-500/25">
                <td class="{{ $cell }} px-1.5 py-1">Center total</td>
                @foreach($dates as $date)
                    <td class="{{ $cell }} w-8 px-1 py-1 text-center">{{ $centerTotals[$date->toDateString()] }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
@endif
</div>
@endsection
