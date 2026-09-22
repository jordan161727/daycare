@extends('layouts.app')
@section('title', 'Staff reports')
@section('content')
{{--
    Staff reports: choose a report, give it a period, get a table.

    One table for every report, because the controller builds them all to the
    same shape. A blade per report would be sixteen near-identical tables that
    drift apart the first time one of them gets a fix.

    The filters sit in a panel that collapses, because they are set once and
    then the table below is what gets read — a fortnight is already wider than
    the screen without a form above it.
--}}
@php
    $period = $meta['period'];
    $query = array_filter([
        'report' => $report,
        'start' => $start?->toDateString(),
        'end' => $period === 'range' ? $end?->toDateString() : null,
        'role' => $role,
        'department' => $department,
        'pay' => $showPay ? 1 : null,
    ]);
    $align = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
@endphp

<div x-data="{ filters: true }">
    <h1 class="text-xl font-bold tracking-tight">Reports</h1>

    <section class="glass-card relative z-20 mt-3 rounded-2xl">
        <button type="button" @click="filters = !filters"
                class="flex w-full items-center gap-2 px-5 py-3 text-sm font-semibold">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18l-7 8v6l-4 2v-8L3 4z"/></svg>
            Filters
            <svg :class="filters ? '' : 'rotate-180'" class="ml-auto h-4 w-4 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
        </button>

        <form x-show="filters" x-cloak method="GET" action="{{ route('staff.reports') }}" class="border-t border-slate-200/70 px-5 py-4 dark:border-white/10">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    {{-- Changing the report reloads the page rather than waiting
                         for Show, because the other filters depend on it: a
                         report about right now has no dates to set, and the pay
                         box does nothing to a report with no rates in it. --}}
                    <label for="report" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Select Report <span class="text-rose-500">*</span></label>
                    <select id="report" name="report" onchange="this.form.submit()" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800">
                        @foreach($reports as $key => $option)
                            <option value="{{ $key }}" @selected($report === $key)>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="role" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Select Role</label>
                    <select id="role" name="role" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800">
                        <option value="">All roles</option>
                        @foreach($roles as $option)
                            <option value="{{ $option }}" @selected($role === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                @if($departments->isNotEmpty())
                    {{-- Only where departments have been set up. An empty
                         dropdown is a question with no answer, and a centre
                         that does not run departments should never see it. --}}
                    <div>
                        <label for="department" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Select Department</label>
                        <select id="department" name="department" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800">
                            <option value="">All departments</option>
                            @foreach($departments as $option)
                                <option value="{{ $option->id }}" @selected($department === (string) $option->id)>{{ $option->name }}</option>
                            @endforeach
                            {{-- The question somebody asks the week after
                                 setting departments up. --}}
                            <option value="none" @selected($department === 'none')>Unassigned</option>
                        </select>
                    </div>
                @endif

                @if($period !== 'none')
                    <div>
                        <label for="start" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Start Date <span class="text-rose-500">*</span></label>
                        <input id="start" type="date" name="start" value="{{ $start->toDateString() }}" required
                               class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800">
                        @if(is_int($period))
                            {{-- A fixed report runs forward from whatever day is
                                 given, so a fortnight can start on a Saturday if
                                 that is how the centre's pay period falls. --}}
                            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Covers {{ $period }} days from this date.</p>
                        @endif
                    </div>

                    @if($period === 'range')
                        <div>
                            <label for="end" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">End Date</label>
                            <input id="end" type="date" name="end" value="{{ $end->toDateString() }}"
                                   class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800">
                        </div>
                    @endif
                @else
                    <div class="sm:col-span-2 flex items-end">
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">This report is about the roster as it stands now, so it takes no dates.</p>
                    </div>
                @endif
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if($meta['pay'])
                    {{-- A rate on screen by default is payroll left open on a
                         desk. It is a column somebody turns on when they are
                         working on pay, and a report without rates in it does
                         not offer the box at all. --}}
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="pay" value="1" @checked($showPay) class="rounded border-slate-300 dark:border-white/20">
                        Show pay
                    </label>
                @endif

                <div class="ml-auto flex gap-2">
                    <button class="rounded-full bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Show</button>
                    <a href="{{ route('staff.reports', ['report' => $report]) }}" class="rounded-full border border-slate-200 px-5 py-2 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Reset</a>
                </div>
            </div>
        </form>
    </section>

    @if($truncated)
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            That range is longer than {{ \App\Http\Controllers\StaffReportController::MAX_DAYS }} days. Showing the first {{ \App\Http\Controllers\StaffReportController::MAX_DAYS }}, because past that it is a table nobody reads to the end of.
        </p>
    @endif

    <section class="glass-card mt-3 rounded-2xl p-5">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <h2 class="text-base font-bold tracking-tight">Report Summary</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ $meta['label'] }}
                    @if($start)· {{ $start->format('M j, Y') }} – {{ $end->format('M j, Y') }}@endif
                    · Generated {{ $generatedAt->format('M j, Y g:i A') }}
                </p>
            </div>

            <div class="ml-auto flex gap-2">
                <a href="{{ route('staff.reports.export', $query) }}"
                   class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Export Excel</a>
                <a href="{{ route('staff.reports.export', $query + ['format' => 'csv']) }}"
                   class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Export CSV</a>
            </div>
        </div>

        @if($rows->isEmpty())
            <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">Nothing to report for these filters.</p>
        @else
            {{-- The table scrolls sideways on its own. A fortnight is seventeen
                 columns and the page around it should not move to read them. --}}
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-max border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500 dark:border-white/10 dark:text-slate-400">
                            @foreach($columns as $column)
                                <th scope="col" class="px-3 py-2 font-semibold {{ $align[$column['align']] }}">{{ $column['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr class="border-b border-slate-100 last:border-0 dark:border-white/5">
                                @foreach(array_values($row) as $index => $value)
                                    @php($column = $columns[$index] ?? ['align' => 'left'])
                                    {{-- A worked cell is worth reading; a nought
                                         is only there to keep the column
                                         straight, and an empty field is not a
                                         gap somebody should have to squint at. --}}
                                    @php($muted = $value === null || $value === '' || $value === '00:00' || $value === '—')
                                    <td class="px-3 py-2 tabular-nums {{ $align[$column['align']] }} {{ $muted ? 'text-slate-300 dark:text-slate-600' : '' }} {{ $index === 0 ? 'text-slate-500 dark:text-slate-400' : '' }}">
                                        {{ $value === null || $value === '' ? '—' : $value }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Showing {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('row', $rows->count()) }}.</p>
        @endif
    </section>
</div>
@endsection
