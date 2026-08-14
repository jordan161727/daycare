@extends('layouts.app')

@section('title', $staff->name.' — '.$date->format('D j M Y'))

@section('content')
<x-page-header :title="$staff->name" :subtitle="$date->format('l j F Y').' — every punch on this day, and everything done to them.'" />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif
@if($errors->any())
    <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-6 flex flex-wrap items-center gap-3">
    <a href="{{ route('timesheets.edit', ['period' => $period, 'user' => $staff]) }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300">‹ Back to {{ $staff->firstName() }}'s {{ $range->label() }}</a>
    <a href="{{ route('timesheets.index', ['date' => $range->key()]) }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">Payroll preparation</a>
</div>

{{-- What the punches come to, and what the timesheet is actually paying. They
     are the same number unless somebody has typed over the day by hand, and
     when they differ that is the single most useful fact on this screen. --}}
<section class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <x-stat-card compact icon="hours" title="Punched hours"
        :value="$day['broken'] || $day['open'] ? '—' : \App\Models\TimesheetEntry::formatHours($day['worked'])"
        :color="$day['problems'] !== [] ? 'rose' : 'indigo'" />
    <x-stat-card compact icon="hours" title="Unpaid break" :value="$day['unpaid_break'].' min'" />
    <x-stat-card compact icon="check" title="Paid break" :value="$day['paid_break'].' min'" color="emerald" />
    <x-stat-card compact icon="hours" title="On the timesheet"
        :value="$entry ? \App\Models\TimesheetEntry::formatHours($entry->workedMinutes()) : '0.00'"
        :color="$entry && $entry->isConfirmed() ? 'amber' : 'indigo'" />
</section>

@if($day['problems'] !== [])
    <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
        <b>This day does not add up:</b> {{ implode('; ', $day['problems']) }}.
        It is paying nothing until the punches are put right, and the period cannot be approved while it stands.
    </div>
@endif

@if($entry && $entry->isConfirmed())
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        @php($who = $entry->confirmer ? ' by '.$entry->confirmer->name : '')
        @php($when = $entry->confirmed_at ? ', '.$entry->confirmed_at->diffForHumans() : '')
        This day was typed by hand{{ $who.$when }},
        so the timesheet says what they said rather than what the clock says. Correcting a punch below rebuilds the day from the clock again.
    </div>
@endif

@if($period->isApproved())
    <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-white/10 dark:bg-slate-800 dark:text-slate-300">
        🔒 This period has been approved, so the punches are a record now and nothing here can be changed. Reopen the period first if something genuinely has to.
    </div>
@endif

{{-- The trail. Voided punches stay, struck through, with who and why —
     the whole reason a correction is a void plus a replacement rather than
     an edit. --}}
<section class="glass-card mt-5 overflow-hidden rounded-2xl">
    <div class="border-b border-slate-200/70 px-5 py-4 dark:border-white/10">
        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">The punches</h2>
    </div>

    @if($history->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-slate-500">Nobody punched the clock on this day.</p>
    @else
        <ul class="divide-y divide-slate-100 dark:divide-white/10">
            @foreach($history as $punch)
                <li class="px-5 py-3 {{ $punch->isVoided() ? 'bg-slate-50/70 dark:bg-white/[0.02]' : '' }}">
                    <div class="flex flex-wrap items-baseline gap-2 text-sm">
                        <span class="w-20 shrink-0 font-bold tabular-nums {{ $punch->isVoided() ? 'text-slate-400 line-through dark:text-slate-500' : '' }}">{{ $punch->time() }}</span>
                        <span class="{{ $punch->isVoided() ? 'text-slate-400 line-through dark:text-slate-500' : 'font-semibold' }}">{{ $punch->label() }}</span>

                        @if($punch->isCorrection())
                            <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200">
                                {{ $punch->corrects ? 'replaces '.$punch->corrects->time() : 'added' }}
                            </span>
                        @else
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate-500 dark:bg-slate-800 dark:text-slate-400">clock</span>
                        @endif

                        @if($punch->isVoided())
                            <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rose-700 dark:bg-rose-500/20 dark:text-rose-200">voided</span>
                        @endif
                    </div>

                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Recorded {{ $punch->created_at->format('j M, g:i a') }}
                        @if($punch->recorder) by {{ $punch->recorder->name }}@endif
                        @if($punch->ip_address) · {{ $punch->ip_address }}@endif
                        @if($punch->reason) · “{{ $punch->reason }}”@endif
                    </p>

                    @if($punch->isVoided())
                        <p class="mt-1 text-xs font-medium text-rose-700 dark:text-rose-300">
                            Voided {{ $punch->voided_at->format('j M, g:i a') }}
                            @if($punch->voider) by {{ $punch->voider->name }}@endif — “{{ $punch->void_reason }}”
                        </p>
                    @endif

                    @unless($period->isApproved() || $punch->isVoided())
                        {{-- Move it or take it out. Either way the reason is
                             required: the question this answers is asked months
                             later by somebody who was not here. --}}
                        <form method="POST" action="{{ route('timesheets.punch.amend', ['period' => $period, 'user' => $staff, 'punch' => $punch]) }}" class="mt-2 flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="time" name="at" value="{{ sprintf('%02d:%02d', intdiv($punch->minutes(), 60), $punch->minutes() % 60) }}" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                            <input name="reason" required minlength="3" maxlength="160" placeholder="Why — e.g. “forgot to clock out, confirmed with Maria”" class="w-full min-w-[14rem] flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                            <button name="action" value="correct" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Move to this time</button>
                            <button name="action" value="void" class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300">Void</button>
                        </form>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif
</section>

@unless($period->isApproved())
    <section class="glass-card mt-5 rounded-2xl p-5">
        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Add a punch</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            For the punch somebody did not make. Out of order is allowed here and nowhere else — the missing 5pm clock-out
            usually turns up after everything either side of it has been recorded.
        </p>

        <form method="POST" action="{{ route('timesheets.day.punch', ['period' => $period, 'user' => $staff, 'date' => $date->toDateString()]) }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Punch</span>
                <select name="type" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach($types as $type => $label)
                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">At</span>
                <input type="time" name="at" value="{{ old('at') }}" required class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-800">
            </label>
            <label class="block min-w-[16rem] flex-1">
                <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Reason (required)</span>
                <input name="reason" value="{{ old('reason') }}" required minlength="3" maxlength="160" placeholder="e.g. “left at 5, forgot to punch — confirmed with room lead”" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-800">
            </label>
            <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">Add punch</button>
        </form>
    </section>
@endunless

<p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
    Nothing here is ever edited or deleted. A correction is a void plus a replacement, both stamped with who did it and why,
    so this page always shows what was originally pressed as well as what it was changed to. The day's hours are rebuilt from
    the punches that still count, which is why voiding a stray punch produces the same number as if it had never been pressed.
</p>
@endsection
