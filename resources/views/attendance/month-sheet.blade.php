@extends('layouts.app')
@section('title', 'Month sheet')
@section('content')
{{--
    The month on one page, laid out like the paper form it replaces.

    Four lines per child — in, the check taken then, out, the check taken then
    — and a column per day. The layout is not a design choice: the people
    reading this have read that form for years, and a printout's whole value is
    that it can be handed to somebody who was not there.

    Read-only. Corrections are made on the screens that record who made them.
--}}
@php
    $cell = 'border border-slate-200 dark:border-white/10';
@endphp

<div x-data="monthSheet()">

    {{-- Which of the register's two readings this is. The month is not a
         separate screen from the week — it is the same register read a month
         at a time — so the way back to it sits where a tab would. --}}
    <div class="no-print mb-3 inline-flex items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
        <a href="{{ route('attendance.index') }}" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100">Week</a>
        <span class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-slate-900 shadow-sm dark:bg-night-700 dark:text-night-950" aria-current="page">Month</span>
    </div>

    {{-- Search, print, and the codes. The same three things the register's
         toolbar carries, in the same order. --}}
    <div class="no-print flex flex-wrap items-center gap-2">
        <div class="relative">
            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
            <label class="sr-only" for="month-search">Search</label>
            <input id="month-search" x-model="search" placeholder="Search name or LAN"
                   class="w-48 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:ring-2 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800">
        </div>

        <button type="button" onclick="window.print()"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">
            🖨 Print
        </button>

        <button type="button" @click="legendOpen = ! legendOpen" :aria-expanded="legendOpen"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">
            <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
            Symptom codes
        </button>

        <form method="GET" action="{{ route('attendance.month-sheet') }}" class="ml-auto flex items-center gap-2">
            <label class="sr-only" for="month-select">Month</label>
            <select id="month-select" name="month" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                @foreach(range(1, 12) as $number)
                    <option value="{{ $number }}" @selected($month === $number)>{{ \Illuminate\Support\Carbon::create(null, $number, 1)->format('F') }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="year-select">Year</label>
            <select id="year-select" name="year" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                @foreach(range(now()->year - 3, now()->year + 1) as $option)
                    <option value="{{ $option }}" @selected($year === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- The code list, read while the sheet is being read. Amber, because
         every code but 0 is the sheet's warning colour. --}}
    <div x-show="legendOpen" x-cloak class="glass-card no-print mt-3 rounded-2xl p-4">
        <ul class="grid gap-x-6 gap-y-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($codes as $code)
                <li class="flex items-center gap-2 text-sm">
                    <span class="att-chip {{ $code->code === 0 ? 'att-chip-ok' : 'att-chip-sick' }}">{{ $code->code }}</span>
                    <span>{{ $code->label }}{{ $code->requires_note ? ' (specify)' : '' }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- A chip per room. "1/6" is how many of that room's children are in
         today out of how many are on its roll — the number somebody is
         actually asking for when they glance at a room. --}}
    <div class="no-print mt-3 flex flex-wrap items-center gap-2">
        <button type="button" @click="room = ''"
                :class="room === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'"
                class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
            All {{ $stats['in'] }}/{{ $stats['enrolled'] }}
        </button>

        @foreach($roomChips as $chip)
            <button type="button" @click="room = @js($chip['room'])"
                    :class="room === @js($chip['room']) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'"
                    class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
                <span aria-hidden="true">{{ $chip['animal'] }}</span>
                {{ $chip['room'] }}
                <span class="font-normal text-slate-500 dark:text-slate-400">{{ $chip['in'] }}/{{ $chip['total'] }}</span>
            </button>
        @endforeach

        <p class="ml-auto flex flex-wrap items-center gap-3 text-sm">
            <span><b>{{ $stats['enrolled'] }}</b> enrolled</span>
            <span class="text-emerald-600 dark:text-emerald-400"><b>{{ $stats['in'] }}</b> in</span>
            @if($stats['sick'] > 0)
                <span class="text-amber-600 dark:text-amber-400"><b>{{ $stats['sick'] }}</b> sick</span>
            @endif
            <span class="text-rose-600 dark:text-rose-400"><b>{{ $stats['not_in'] }}</b> not in</span>
        </p>
    </div>

    {{-- The heading line off the paper form, in the same order. --}}
    <div class="mt-4 flex flex-wrap items-baseline justify-between gap-x-8 gap-y-2 text-base">
        <div class="flex flex-wrap gap-x-8">
            <span class="text-slate-500 dark:text-slate-400">Month: <b class="text-slate-900 dark:text-white">{{ $start->format('F') }}</b></span>
            <span class="text-slate-500 dark:text-slate-400">Year: <b class="text-slate-900 dark:text-white">{{ $year }}</b></span>
            <span class="text-slate-500 dark:text-slate-400">Room: <b class="text-slate-900 dark:text-white" x-text="room || 'All rooms'"></b></span>
        </div>
        {{-- The sum of the daily totals: child-days, which is what the row of
             numbers along the bottom adds up to. --}}
        <span class="text-slate-500 dark:text-slate-400">Total kids for month: <b class="text-slate-900 dark:text-white">{{ $monthTotal }}</b></span>
    </div>

    <div class="month-sheet glass-card mt-2 overflow-x-auto rounded-2xl">
        <table class="att-grid-table w-max table-fixed border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="{{ $cell }} att-name-col sticky left-0 z-10 bg-white text-left text-[0.7333rem] font-semibold uppercase tracking-wide text-slate-500 dark:bg-night-900 dark:text-slate-400">Student</th>
                    <th scope="col" class="{{ $cell }} att-line-col bg-slate-50 py-3 dark:bg-white/5"><span class="sr-only">Line</span></th>
                    @foreach($days as $day)
                        {{-- Weekends shaded, as on the form: the centre does not
                             open, and an empty column nobody has to wonder about
                             is worth the grey. --}}
                        <th scope="col" @class([
                            $cell,
                            'att-day-col px-1 py-2 text-center font-semibold',
                            'bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-200' => $day->isToday(),
                            'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500' => $day->isWeekend(),
                            'text-slate-600 dark:text-slate-300' => ! $day->isWeekend(),
                        ])>
                            <span class="block text-[0.6667rem] uppercase">{{ strtoupper(substr($day->format('D'), 0, 2)) }}</span>
                            <span class="block text-sm">{{ $day->day }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>

            {{-- A tbody per child, so the room chips and the search can hide a
                 whole four-line block with one binding rather than four. --}}
            @foreach($rows as $row)
                <tbody x-show="shows(@js($row['room']), @js($row['name'].' '.$row['lan']))">
                    @php($lines = [['IN', 'in', false], ['health', 'in_code', true], ['OUT', 'out', false], ['health', 'out_code', true]])

                    @foreach($lines as $index => [$label, $key, $isCode])
                        <tr>
                            @if($index === 0)
                                {{-- The name block spans its four lines, with the
                                     room, the number and the hours under it — the
                                     same facts the form carries in that column. --}}
                                <th scope="rowgroup" rowspan="4" class="{{ $cell }} att-name-col sticky left-0 z-10 bg-white text-left align-middle dark:bg-night-900">
                                    <span class="block text-[0.7333rem] font-bold leading-tight">{{ $row['name'] }}</span>
                                    <span class="mt-0.5 block text-[0.6667rem] font-normal text-slate-500 dark:text-slate-400">
                                        <span aria-hidden="true">{{ $row['animal'] }}</span>
                                        {{ $row['room'] }} · {{ $row['lan'] }}
                                    </span>
                                </th>
                            @endif

                            <td class="{{ $cell }} att-line-col bg-slate-50 py-1 text-center text-[0.6rem] font-semibold tracking-wide text-slate-400 dark:bg-white/5">{{ $label }}</td>

                            @foreach($days as $day)
                                @php($entry = $row['byDay'][$day->toDateString()] ?? null)
                                @php($value = $entry[$key] ?? null)

                                <td @class([
                                    $cell,
                                    'px-2 py-1.5 text-center tabular-nums',
                                    'bg-sky-50/50 dark:bg-sky-500/5' => $day->isToday(),
                                    'bg-slate-100 dark:bg-slate-800' => $day->isWeekend(),
                                ])>
                                    {{-- A health line holds a code, which may be 0
                                         — so it is tested against null rather than
                                         for truthiness, or every healthy child
                                         would print an empty box. --}}
                                    @if($isCode && $value !== null)
                                        <span class="att-chip {{ $value === 0 ? 'att-chip-ok' : 'att-chip-sick' }}">{{ $value }}</span>
                                    @elseif(! $isCode && $value !== null)
                                        <span class="text-slate-600 dark:text-slate-300">{{ $value }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            @endforeach

            <tfoot>
                <tr class="bg-slate-50 dark:bg-white/5">
                    <th scope="row" colspan="2" class="{{ $cell }} sticky left-0 z-10 bg-slate-50 px-4 py-2.5 text-left text-[0.7333rem] font-bold dark:bg-night-800">Total kids per day</th>
                    @foreach($days as $day)
                        @php($total = $perDay[$day->toDateString()] ?? 0)
                        <td @class([
                            $cell,
                            'px-2 py-2.5 text-center text-sm font-bold tabular-nums',
                            'bg-slate-100 dark:bg-slate-800' => $day->isWeekend(),
                        ])>{{ $total ?: '' }}</td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>

    <p x-show="hiddenAll" x-cloak class="mt-3 text-center text-sm text-slate-500 dark:text-slate-400">No children match this search.</p>

    {{-- The legend again, printed. The panel above is a toggle nobody will
         have open when they press Print, and a sheet handed to somebody who
         was not there has to explain its own numbers. --}}
    <p class="print-only mt-2 text-[0.6rem] leading-snug">
        <b>Symptom codes:</b>
        @foreach($codes as $code)
            {{ $code->code }}={{ strtolower($code->label) }}{{ $code->requires_note ? '(specify)' : '' }}@if(! $loop->last) &nbsp;@endif
        @endforeach
    </p>
</div>

<script>
function monthSheet() { return {
    search: '',
    room: '',
    legendOpen: false,

    /** Whether one child's four-line block is on screen. */
    shows(room, haystack) {
        if (this.room && room !== this.room) return false;

        const needle = this.search.trim().toLowerCase();

        return ! needle || haystack.toLowerCase().includes(needle);
    },

    get hiddenAll() {
        return document.querySelectorAll('.month-sheet tbody:not([style*="display: none"])').length === 0;
    },
}}
</script>

{{-- Landscape, and edge to edge: thirty-one columns of four lines each does
     not fit any other way. The greys are forced to print because on this sheet
     the shading is information — it says the centre was shut. --}}
<style>
    .print-only { display: none; }

    @media print {
        @page { size: 11in 8.5in; margin: 0.3in; }
        .no-print { display: none !important; }
        .print-only { display: block; }
        .month-sheet { overflow: visible !important; border: 0 !important; border-radius: 0 !important; }
        .month-sheet table { font-size: 5.5pt; }
        .month-sheet th, .month-sheet td { padding: 1px 2px !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
@endsection
