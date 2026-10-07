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
        'pay' => $showPay ? 1 : null,
    ]);
    $align = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
@endphp

<div>
    @php
        // The catalogue in four groups, so fifteen names read as a menu
        // rather than a list. Keys not named here fall into the last group.
        $groups = [
            'Attendance' => ['attendance-counter', 'attendance-only', 'daily-absence', 'current-status'],
            'Summaries' => ['daily-summary-1w', 'daily-summary-2w', 'employee-summary', 'date-wise-summary', 'weekday-summary', 'role-summary'],
            'People' => ['employee-list', 'employee-details', 'role-members'],
            'Activity' => ['employee-activity', 'manual-adjustments'],
        ];
        $grouped = collect($groups)->map(fn ($keys) => collect($keys)->filter(fn ($k) => isset($reports[$k]))->mapWithKeys(fn ($k) => [$k => $reports[$k]]));
        $rest = collect($reports)->except(collect($groups)->flatten());
        if ($rest->isNotEmpty()) $grouped['Other'] = $rest;

        $periodLabel = match (true) {
            $period === 'none' => 'The roster as it stands, so no dates apply',
            $period === 'day' => 'One day — today unless another is chosen',
            is_int($period) => $period.' days from the start date',
            default => 'Any date range, up to '.\App\Http\Controllers\StaffReportController::MAX_DAYS.' days',
        };
        $chip = 'inline-flex min-h-[44px] items-center gap-2 rounded-full px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent focus-visible:ring-offset-2';
        $preset = 'inline-flex min-h-[36px] items-center rounded-full border border-la-border px-3 text-xs font-semibold text-la-muted transition hover:border-la-accent-border hover:bg-la-accent-soft hover:text-la-accent dark:border-white/10 dark:text-slate-300 dark:hover:bg-indigo-500/20';
        $field = 'min-h-[44px] rounded-lg border border-la-border-strong bg-white px-3 text-sm text-la-ink focus:border-la-accent focus:ring-2 focus:ring-la-accent/30 dark:border-white/15 dark:bg-slate-950 dark:text-white';
        $monday = today()->startOfWeek(\Illuminate\Support\Carbon::MONDAY);
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:text-slate-400">Employee</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-la-ink dark:text-white">Reports</h1>
        </div>
    </div>

    {{-- The filters as one card: which report, then who and when, then Show.
         Changing the report reloads at once because the other controls depend
         on it — a report about right now has no dates, and the pay switch
         does nothing to a report with no rates in it. --}}
    <form method="GET" action="{{ route('staff.reports') }}"
          x-data="{
              group: @js(collect($groups)->search(fn ($keys) => in_array($report, $keys, true)) ?: array_key_first($groups)),
              start: @js($start?->toDateString() ?? $monday->toDateString()),
              end: @js($end?->toDateString() ?? $monday->copy()->addDays(6)->toDateString()),
              period: @js($period),
              // Local calendar date, not toISOString(): that is UTC, and in the
              // evening here it is already tomorrow there, so presets drifted a day.
              iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') },
              shift(d, days) { const c = new Date(d + 'T00:00:00'); c.setDate(c.getDate() + days); return this.iso(c) },
              monday(offsetWeeks) { const d = new Date(); const day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day + offsetWeeks * 7); return this.iso(d) },
              // The date fields belong to the date picker (see dates.js), so a
              // preset writes into the field itself and the picker follows;
              // Alpine only listens to the field's change event. Binding the
              // field two ways left the picker and Alpine overwriting each other.
              set(which, value) { this[which] = value; const field = document.getElementById(which); if (field) field.value = value },
              preset(name) {
                  let start = this.start, end = this.end;
                  if (name === 'this-week') { start = this.monday(0); end = this.shift(start, 6) }
                  if (name === 'last-week') { start = this.monday(-1); end = this.shift(start, 6) }
                  if (name === 'this-month') { const d = new Date(); start = this.iso(new Date(d.getFullYear(), d.getMonth(), 1, 12)); end = this.iso(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12)) }
                  if (name === 'last-30') { end = this.iso(new Date()); start = this.shift(end, -29) }
                  this.set('start', start); this.set('end', end);
              },
              get endsOn() { return Number.isInteger(this.period) && this.start ? new Date(this.shift(this.start, this.period - 1) + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) : '' }
          }"
          class="glass-card relative z-20 mt-4 overflow-hidden rounded-2xl">

        {{-- Step one: which report. The four groups as pills, and the reports
             in the chosen group as chips — pressing one runs it, because the
             rest of the form depends on which report it is. --}}
        <div class="px-5 pt-3.5 sm:px-6">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:text-slate-400">Report</span>
                <div class="flex flex-wrap gap-1" role="tablist" aria-label="Report groups">
                    @foreach($grouped as $groupName => $options)
                        <button type="button" role="tab" @click="group = @js($groupName)" :aria-selected="group === @js($groupName)"
                                :class="group === @js($groupName) ? 'bg-la-ink text-white dark:bg-white dark:text-slate-900' : 'text-la-muted hover:bg-la-well dark:text-slate-300 dark:hover:bg-white/10'"
                                class="inline-flex min-h-[30px] items-center gap-1.5 rounded-full px-3 text-[13px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent">
                            {{ $groupName }}
                            @if($options->has($report))<span class="h-1.5 w-1.5 rounded-full bg-la-accent" :class="group === @js($groupName) ? 'bg-white dark:bg-slate-900' : ''" aria-hidden="true"></span>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach($grouped as $groupName => $options)
                <div x-show="group === @js($groupName)" @if(! $options->has($report)) x-cloak @endif role="tabpanel" class="mt-2.5 flex flex-wrap gap-1.5 pb-3.5">
                    @foreach($options as $key => $option)
                        <label class="group/chip relative cursor-pointer">
                            <input type="radio" name="report" value="{{ $key }}" @checked($report === $key) onchange="this.form.submit()" class="peer sr-only" id="report-{{ $key }}">
                            <span class="inline-flex min-h-[36px] items-center gap-1.5 rounded-lg border border-la-border bg-white px-3 text-[13px] font-semibold text-la-ink transition group-hover/chip:border-la-accent-border group-hover/chip:bg-la-accent-soft/60 peer-checked:border-la-accent peer-checked:bg-la-accent-soft peer-checked:text-la-accent peer-focus-visible:ring-2 peer-focus-visible:ring-la-accent peer-focus-visible:ring-offset-2 dark:border-white/10 dark:bg-slate-950 dark:text-white dark:peer-checked:bg-indigo-500/20 dark:peer-checked:text-indigo-200">
                                @if($report === $key)
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                @endif
                                {{ $option['label'] }}
                                @if($option['pay'])<span class="rounded-full bg-la-well px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-la-muted peer-checked:bg-white/70 dark:bg-white/10 dark:text-slate-300" title="Can show pay">Pay</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Step two: who and when, on one line, then Show. --}}
        <div class="flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-la-border bg-la-well/60 px-5 py-3.5 dark:border-white/10 dark:bg-white/5 sm:px-6">
            <label class="inline-flex items-center gap-2 text-sm text-la-muted dark:text-slate-300">
                <span class="font-semibold">Role</span>
                <select id="role" name="role" class="{{ $field }} min-w-[150px] pr-9">
                    <option value="">All roles</option>
                    @foreach($roles as $option)
                        <option value="{{ $option }}" @selected($role === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            @if($period === 'range')
                {{-- From and to as one joined pill, with the ranges people
                     actually ask for one press away. --}}
                <div class="inline-flex items-center gap-2 text-sm text-la-muted dark:text-slate-300">
                    <span class="font-semibold">Dates</span>
                    <span class="inline-flex min-h-[44px] items-center overflow-hidden rounded-lg border border-la-border-strong bg-white dark:border-white/15 dark:bg-slate-950">
                        <input id="start" type="date" name="start" value="{{ $start->toDateString() }}" @change="start = $event.target.value" required aria-label="From"
                               class="min-h-[42px] border-0 bg-transparent px-3 text-sm text-la-ink focus:ring-0 dark:text-white">
                        <svg class="h-4 w-4 shrink-0 text-la-faint" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5l5 5-5 5"/></svg>
                        <input id="end" type="date" name="end" value="{{ $end->toDateString() }}" @change="end = $event.target.value" aria-label="To"
                               class="min-h-[42px] border-0 bg-transparent px-3 text-sm text-la-ink focus:ring-0 dark:text-white">
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Quick ranges">
                    @foreach(['this-week' => 'This week', 'last-week' => 'Last week', 'this-month' => 'This month', 'last-30' => 'Last 30 days'] as $key => $label)
                        <button type="button" @click="preset(@js($key))" class="inline-flex min-h-[36px] items-center rounded-full px-3 text-xs font-semibold text-la-accent transition hover:bg-la-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent dark:text-indigo-300 dark:hover:bg-indigo-500/20">{{ $label }}</button>
                    @endforeach
                </div>
            @elseif($period === 'day')
                {{-- One day. Today unless another is picked, with yesterday and
                     today one press away. --}}
                <label class="inline-flex items-center gap-2 text-sm text-la-muted dark:text-slate-300">
                    <span class="font-semibold">On</span>
                    <input id="start" type="date" name="start" value="{{ $start->toDateString() }}" @change="start = $event.target.value" required class="{{ $field }}">
                </label>
                <div class="flex flex-wrap items-center gap-1" role="group" aria-label="Quick days">
                    <button type="button" @click="set('start', iso(new Date()))" class="inline-flex min-h-[36px] items-center rounded-full px-3 text-xs font-semibold text-la-accent transition hover:bg-la-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent dark:text-indigo-300 dark:hover:bg-indigo-500/20">Today</button>
                    <button type="button" @click="set('start', shift(iso(new Date()), -1))" class="inline-flex min-h-[36px] items-center rounded-full px-3 text-xs font-semibold text-la-accent transition hover:bg-la-accent-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-la-accent dark:text-indigo-300 dark:hover:bg-indigo-500/20">Yesterday</button>
                </div>
            @elseif($period !== 'none')
                <label class="inline-flex items-center gap-2 text-sm text-la-muted dark:text-slate-300">
                    <span class="font-semibold">Starts</span>
                    <input id="start" type="date" name="start" value="{{ $start->toDateString() }}" @change="start = $event.target.value" required class="{{ $field }}">
                </label>
                {{-- A fixed report runs forward from whatever day is given, so a
                     fortnight can start on a Saturday if that is how the pay
                     period falls. The end follows the start as it is typed. --}}
                <span class="text-sm text-la-muted dark:text-slate-400">{{ $period }} days, ending <span class="font-semibold text-la-ink dark:text-white" x-text="endsOn">{{ $end->format('D, M j') }}</span></span>
            @else
                {{-- A roster report has no dates to read. The control stays where
                     it always is, switched off, so the row does not jump about
                     between reports and the reason is written on it. --}}
                <div class="inline-flex items-center gap-2 text-sm text-la-muted dark:text-slate-300" aria-disabled="true">
                    <span class="font-semibold">Dates</span>
                    <span class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-dashed border-la-border-strong px-3 text-sm text-la-faint dark:border-white/15">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 8.25h16.5M4.5 5.25h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V6a.75.75 0 01.75-.75z"/></svg>
                        Not used — this report lists the roster as it stands
                    </span>
                </div>
            @endif

            <div class="ml-auto flex flex-wrap items-center gap-3">
                @if($meta['pay'])
                    {{-- A rate on screen by default is payroll left open on a desk.
                         It is switched on when somebody is working on pay. --}}
                    <label class="inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-sm font-semibold text-la-ink dark:text-slate-200">
                        <input type="checkbox" name="pay" value="1" @checked($showPay) class="h-4 w-4 rounded border-la-border-strong text-la-accent focus:ring-la-accent">
                        Show pay
                    </label>
                @endif
                <button class="{{ $chip }} bg-la-accent text-white shadow-sm hover:bg-[#174f7a]">Show</button>
                <a href="{{ route('staff.reports', ['report' => $report]) }}" class="inline-flex min-h-[44px] items-center px-2 text-sm font-semibold text-la-muted hover:text-la-ink dark:text-slate-400 dark:hover:text-white">Reset</a>
            </div>
        </div>
    </form>

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
                <a href="{{ route('staff.reports.export', $query + ['t' => now()->timestamp]) }}"
                   class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Export Excel</a>
                <a href="{{ route('staff.reports.export', $query + ['format' => 'csv', 't' => now()->timestamp]) }}"
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
                                    @if($column['link'] ?? false)
                                        {{-- A way through, not a value: the day this row is
                                             about, opened in the edit panel on the grid. --}}
                                        <td class="px-3 py-2 {{ $align[$column['align']] }}">
                                            <a href="{{ $value }}" class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2.5 py-0.5 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-50 dark:border-white/10 dark:text-indigo-300 dark:hover:bg-indigo-500/10">Open day <span aria-hidden="true">→</span></a>
                                        </td>
                                    @else
                                        <td class="px-3 py-2 tabular-nums {{ $align[$column['align']] }} {{ $muted ? 'text-slate-300 dark:text-slate-600' : '' }} {{ $index === 0 ? 'text-slate-500 dark:text-slate-400' : '' }}">
                                            {{ $value === null || $value === '' ? '—' : $value }}
                                        </td>
                                    @endif
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
