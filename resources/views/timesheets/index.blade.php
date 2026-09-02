@extends('layouts.app')

@section('title', 'Payroll preparation')

@section('content')
<x-page-header title="Payroll preparation" subtitle="Turn the roster and the corrections made to it into a per-period hours summary ready to hand to payroll." />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

{{-- What approving the period earned everybody. Printed rather than done
     silently: leave that appears on a balance with no explanation is a number
     staff have to take on faith, and the one line saying "3.2h sick earned on
     96h worked" is what makes it checkable. --}}
@if(session('accrual'))
    <div class="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900">
        <p class="font-semibold">Leave earned</p>
        <ul class="mt-1 space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
            @foreach(session('accrual') as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <a href="{{ route('leave.balances') }}" class="mt-2 inline-block text-xs font-semibold text-indigo-600 hover:underline">See every balance &rarr;</a>
    </div>
@endif

{{-- Period navigation. Semi-monthly, so stepping is by half-month, and the
     date box is for jumping somewhere far off. --}}
<section class="glass-card mt-7 rounded-2xl p-5">
    <div class="flex flex-wrap items-center gap-3">
        <div>
            <h2 class="text-lg font-bold">{{ $range->label() }}</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Semi-monthly period · {{ count($dates) }} days ·
                @if($period->isApproved())
                    approved {{ $period->approved_at?->diffForHumans() }}@if($period->approver) by {{ $period->approver->name }}@endif
                @elseif($period->seeded_at)
                    draft, last filled from the roster {{ $period->seeded_at->diffForHumans() }}
                @else
                    draft, nothing brought in yet
                @endif
            </p>
        </div>

        @if($period->isApproved())
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">Approved</span>
        @else
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">Draft</span>
        @endif

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <a href="{{ route('timesheets.index', ['date' => $range->previous()->key()]) }}" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300" title="{{ $range->previous()->label() }}">‹ Previous</a>
            <a href="{{ route('timesheets.index', ['date' => $range->next()->key()]) }}" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300" title="{{ $range->next()->label() }}">Next ›</a>
            <form method="GET" action="{{ route('timesheets.index') }}" class="flex items-center gap-2">
                <label class="sr-only" for="period-date">Jump to a date</label>
                <input id="period-date" type="date" name="date" value="{{ $range->start->toDateString() }}" class="rounded-lg border-0 bg-slate-100 px-2 py-1.5 text-xs dark:bg-slate-800">
                <button class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Go</button>
            </form>
        </div>
    </div>

    {{-- The three acts, in order, each saying plainly what it will do. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200/70 pt-4 dark:border-white/10">
        @unless($period->isApproved())
            <form method="POST" action="{{ route('timesheets.seed', $period) }}">
                @csrf
                <button class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    Fill from the roster
                </button>
            </form>
            <form method="POST" action="{{ route('timesheets.approve', $period) }}" onsubmit="return confirm('Approve {{ $range->label() }}? The period is frozen after this.')">
                @csrf
                <button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    Approve period
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('timesheets.reopen', $period) }}" onsubmit="return confirm('Reopen this period? If the hours have already gone to payroll you will need to tell them what changed.')">
                @csrf
                <button class="rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-50 dark:border-amber-500/40 dark:bg-transparent dark:text-amber-200">
                    Reopen
                </button>
            </form>
        @endunless

        <a href="{{ route('timesheets.export', $period) }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
            Download CSV for payroll
        </a>

        @if($totals['unconfirmed_days'] > 0)
            <span class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                {{ $totals['unconfirmed_days'] }} day(s) still as the roster left them
            </span>
        @endif

        {{-- A different refusal from the amber one: not "nobody has said yet"
             but "what was said cannot be true". --}}
        @if($totals['clock_exceptions'] > 0)
            <span class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                {{ $totals['clock_exceptions'] }} day(s) of punches that do not add up
            </span>
        @endif
    </div>
</section>

{{-- The totals payroll is being handed. --}}
<section class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <x-stat-card compact icon="rooms" title="Employees with hours" :value="$totals['employees']" />
    <x-stat-card compact icon="hours" title="Regular hours" :value="number_format($totals['regular_hours'], 2)" />
    <x-stat-card compact icon="hours" title="Overtime hours" :value="number_format($totals['overtime_hours'], 2)" :color="$totals['overtime_hours'] > 0 ? 'amber' : 'indigo'" />
    <x-stat-card compact icon="check" title="Total paid hours" :value="number_format($totals['paid_hours'], 2)" color="emerald" />
</section>

@if($totals['missing_rates'] > 0)
    <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        {{ $totals['missing_rates'] }} employee(s) have no pay rate on file, so the estimated gross below leaves them out. The hours are still correct — only the money is missing.
    </p>
@endif

<section class="glass-card mt-5 overflow-hidden rounded-2xl">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-night-800/60">
                <tr>
                    <th class="sticky left-0 z-10 bg-slate-50 px-4 py-3 font-semibold dark:bg-night-800/60">Employee</th>
                    @foreach($dates as $date)
                        <th class="px-1.5 py-3 text-center font-semibold {{ $date->isWeekend() ? 'text-slate-300 dark:text-slate-600' : '' }}">
                            <span class="block text-[10px]">{{ $date->format('D') }}</span>
                            <span class="block">{{ $date->format('j') }}</span>
                        </th>
                    @endforeach
                    <th class="px-3 py-3 text-right font-semibold">Reg</th>
                    <th class="px-3 py-3 text-right font-semibold">OT</th>
                    <th class="px-3 py-3 text-right font-semibold">Leave</th>
                    <th class="px-3 py-3 text-right font-semibold">Paid</th>
                    <th class="px-4 py-3 text-right font-semibold">Gross</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @forelse($rows as $line)
                    @php($staff = $line['user'])
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-white/5">
                        <td class="sticky left-0 z-10 bg-white px-4 py-2.5 dark:bg-slate-900">
                            <a href="{{ route('timesheets.edit', ['period' => $period, 'user' => $staff]) }}" class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300">{{ $staff->name }}</a>
                            <span class="block text-[11px] text-slate-500">{{ $staff->employment ?? '—' }}</span>
                        </td>

                        @foreach($dates as $date)
                            @php($entry = $grid[$staff->id][$date->toDateString()] ?? null)
                            @php($broken = $exceptions[$staff->id][$date->toDateString()] ?? null)
                            <td class="px-1.5 py-2.5 text-center text-[11px] tabular-nums">
                                @if($broken)
                                    {{-- A day whose punches do not add up. It is worth nothing
                                         and it stops the period being approved, so it is a link
                                         to the place where somebody can say what happened. --}}
                                    <a href="{{ route('timesheets.day', ['period' => $period, 'user' => $staff, 'date' => $date->toDateString()]) }}"
                                       class="rounded bg-rose-100 px-1 font-bold text-rose-700 hover:bg-rose-200 dark:bg-rose-500/20 dark:text-rose-200"
                                       title="{{ ucfirst(implode('; ', $broken)) }}">!</a>
                                @elseif($entry === null || $entry->isEmpty())
                                    <span class="text-slate-300 dark:text-slate-600">·</span>
                                @elseif($entry->leave_code)
                                    {{-- Leave says which kind, because PTO and an unpaid absence
                                         are the same number of hours and very different facts. --}}
                                    <span class="rounded px-1 font-semibold {{ $entry->paidLeaveMinutes() > 0 ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}" title="{{ $leaveCodes[$entry->leave_code] ?? $entry->leave_code }}">
                                        {{ $entry->leave_code === 'HOLIDAY' ? 'HOL' : substr($entry->leave_code, 0, 4) }}
                                    </span>
                                @elseif($entry->isFromClock())
                                    {{-- The clock's own word. Not amber: a punch is the
                                         employee's account of the day, not a guess waiting
                                         for one. It links to the punches behind it. --}}
                                    <a href="{{ route('timesheets.day', ['period' => $period, 'user' => $staff, 'date' => $date->toDateString()]) }}"
                                       class="text-indigo-600 underline decoration-dotted underline-offset-2 hover:text-indigo-800 dark:text-indigo-300"
                                       title="Punched on the clock — open the punches">
                                        {{ \App\Models\TimesheetEntry::formatHours($entry->workedMinutes()) }}
                                    </a>
                                @else
                                    <span class="{{ $entry->isConfirmed() ? 'font-semibold' : 'text-amber-600 dark:text-amber-300' }}" title="{{ $entry->isConfirmed() ? 'Confirmed' : 'Still as the roster left it' }}">
                                        {{ \App\Models\TimesheetEntry::formatHours($entry->workedMinutes()) }}
                                    </span>
                                @endif
                            </td>
                        @endforeach

                        <td class="px-3 py-2.5 text-right tabular-nums">{{ number_format($line['regular_hours'], 2) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums {{ $line['overtime_hours'] > 0 ? 'font-bold text-amber-600 dark:text-amber-300' : 'text-slate-400' }}">{{ number_format($line['overtime_hours'], 2) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-500">{{ number_format($line['paid_leave_hours'], 2) }}</td>
                        <td class="px-3 py-2.5 text-right font-bold tabular-nums">{{ number_format($line['paid_hours'], 2) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            @if($line['estimated_gross'] === null)
                                <span class="text-amber-600 dark:text-amber-300" title="No pay rate on this record">no rate</span>
                            @else
                                ${{ number_format($line['estimated_gross'], 2) }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($dates) + 6 }}" class="px-6 py-12 text-center text-sm text-slate-500">
                            Nothing in this period yet. Press <b>Fill from the roster</b> to bring in the published schedule, then confirm each person's days.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
    Amber hours are still the roster's word rather than anybody's, underlined ones came off the time clock, and a red
    <b class="text-rose-600 dark:text-rose-300">!</b> is a day whose punches do not add up — it pays nothing until somebody
    opens it and says what happened. Overtime is worked out per Monday–Sunday week
    at over {{ config('daycare.timesheet.overtime_after') }} hours worked — paid leave never counts towards it — and only then
    collected into this period, so a week split across the 15th is not counted twice or lost.
</p>
@endsection
