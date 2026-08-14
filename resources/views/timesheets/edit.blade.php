@extends('layouts.app')

@section('title', $staff->name.' — '.$range->label())

@section('content')
<x-page-header :title="$staff->name" :subtitle="'What actually happened, day by day, for '.$range->label().'.'" />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

<div class="mt-6 flex flex-wrap items-center gap-3">
    <a href="{{ route('timesheets.index', ['date' => $range->key()]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300">‹ Back to {{ $range->label() }}</a>
    @if($summary)
        <span class="ml-auto text-sm text-slate-500 dark:text-slate-400">
            <b class="text-slate-800 dark:text-slate-100">{{ number_format($summary['paid_hours'], 2) }} h</b> paid this period
            @if($summary['overtime_hours'] > 0)
                · <b class="text-amber-600 dark:text-amber-300">{{ number_format($summary['overtime_hours'], 2) }} h overtime</b>
            @endif
            @if($summary['unconfirmed_days'] > 0)
                · {{ $summary['unconfirmed_days'] }} day(s) unconfirmed
            @endif
        </span>
    @endif
</div>

@if($period->isApproved())
    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-white/10 dark:bg-slate-800 dark:text-slate-300">
        🔒 This period has been approved. The hours have gone to payroll, so they are a record now — reopen the period on the summary page if something genuinely has to change.
    </div>
@endif

<form method="POST" action="{{ route('timesheets.update', ['period' => $period, 'user' => $staff]) }}" class="mt-5">
    @csrf @method('PUT')

    <section class="glass-card overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800/60">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Day</th>
                        <th class="px-3 py-3 font-semibold">In</th>
                        <th class="px-3 py-3 font-semibold">Out</th>
                        <th class="px-3 py-3 font-semibold">Break</th>
                        <th class="px-3 py-3 font-semibold">Leave</th>
                        <th class="px-3 py-3 font-semibold">Leave hrs</th>
                        <th class="px-3 py-3 text-right font-semibold">Worked</th>
                        <th class="px-3 py-3 font-semibold">Clock</th>
                        <th class="px-3 py-3 font-semibold">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach($dates as $date)
                        @php($iso = $date->toDateString())
                        @php($entry = $entries[$iso] ?? null)
                        @php($clock = $clockDays[$iso] ?? null)
                        <tr class="{{ $clock && $clock['problems'] !== [] ? 'bg-rose-50/60 dark:bg-rose-500/5' : ($date->isWeekend() ? 'bg-slate-50/60 dark:bg-white/[0.02]' : '') }}">
                            <td class="whitespace-nowrap px-4 py-2">
                                <span class="font-semibold">{{ $date->format('D j M') }}</span>
                                @if($entry && $entry->needsConfirming())
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-700 dark:bg-amber-500/20 dark:text-amber-200" title="Copied from the roster and not yet confirmed">roster</span>
                                @elseif($entry && $entry->isFromClock())
                                    <span class="ml-1 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200" title="Built from the punches on the clock">clock</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <input type="time" name="days[{{ $iso }}][starts_at]" value="{{ $entry && $entry->starts_at !== null ? sprintf('%02d:%02d', intdiv($entry->starts_at, 60), $entry->starts_at % 60) : '' }}" @disabled($period->isApproved()) class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                            </td>
                            <td class="px-3 py-2">
                                <input type="time" name="days[{{ $iso }}][ends_at]" value="{{ $entry && $entry->ends_at !== null ? sprintf('%02d:%02d', intdiv($entry->ends_at, 60), $entry->ends_at % 60) : '' }}" @disabled($period->isApproved()) class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" min="0" max="600" step="5" name="days[{{ $iso }}][break_minutes]" value="{{ $entry?->break_minutes ?: '' }}" placeholder="0" @disabled($period->isApproved()) class="w-20 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs tabular-nums dark:border-white/10 dark:bg-slate-800" title="Unpaid break, in minutes">
                            </td>
                            <td class="px-3 py-2">
                                <select name="days[{{ $iso }}][leave_code]" @disabled($period->isApproved()) class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                                    <option value="">—</option>
                                    @foreach($leaveCodes as $code => $label)
                                        <option value="{{ $code }}" @selected($entry?->leave_code === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" min="0" max="24" step="0.25" name="days[{{ $iso }}][leave_hours]" value="{{ $entry && $entry->leave_minutes ? rtrim(rtrim(number_format($entry->leave_minutes / 60, 2), '0'), '.') : '' }}" placeholder="{{ config('daycare.timesheet.default_leave_hours') }}" @disabled($period->isApproved()) class="w-20 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs tabular-nums dark:border-white/10 dark:bg-slate-800" title="Leave a blank to use the default">
                            </td>
                            <td class="px-3 py-2 text-right text-xs font-semibold tabular-nums">
                                {{ $entry ? \App\Models\TimesheetEntry::formatHours($entry->workedMinutes()) : '0.00' }}
                            </td>
                            {{-- What the clock says, next to what the sheet is paying. When
                                 they differ, somebody has typed over the day — which is
                                 allowed, and is exactly the thing worth being able to see. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-xs">
                                @if($clock === null)
                                    <span class="text-slate-300 dark:text-slate-600">—</span>
                                @else
                                    <a href="{{ route('timesheets.day', ['period' => $period, 'user' => $staff, 'date' => $iso]) }}"
                                       class="font-semibold {{ $clock['problems'] !== [] ? 'text-rose-600 dark:text-rose-300' : 'text-indigo-600 dark:text-indigo-300' }} hover:underline"
                                       title="{{ $clock['problems'] !== [] ? ucfirst(implode('; ', $clock['problems'])) : $clock['punches']->count().' punch(es)' }}">
                                        {{ $clock['problems'] !== [] ? '!' : \App\Models\TimesheetEntry::formatHours($clock['worked']) }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <input name="days[{{ $iso }}][note]" value="{{ $entry?->note }}" maxlength="120" placeholder="—" @disabled($period->isApproved()) class="w-full min-w-[10rem] rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @unless($period->isApproved())
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-200/70 px-5 py-4 dark:border-white/10">
                <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">
                    Confirm these days
                </button>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Saving marks every day here as somebody's word rather than the roster's or the clock's, and records that it was yours.
                    A day left completely blank stays blank. To change what a punched day is worth, correct the punches in the
                    <b>Clock</b> column instead — that leaves a trail, and typing over it does not.
                </p>
            </div>
        @endunless
    </section>
</form>
@endsection
