@extends('layouts.app')

@section('title', 'My Leave')

@php
    use App\Models\LeaveRequest;

    $tone = [
        LeaveRequest::STATUS_PENDING => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
        LeaveRequest::STATUS_APPROVED => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
        LeaveRequest::STATUS_DENIED => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
        LeaveRequest::STATUS_CANCELLED => 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300',
    ];
@endphp

@section('content')
<x-page-header title="My Leave" :subtitle="$staff->name.' · sick and vacation time'" />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

{{-- The balance first, because it is what the page was opened for. The hours
     already asked for sit next to it rather than inside it: they have not been
     decided, so subtracting them would be telling somebody they have less than
     they do. --}}
<div class="mt-7 grid gap-4 sm:grid-cols-2">
    @foreach($types as $type => $label)
        @php
            $held = $balances[$type] ?? 0;
            $pending = $committed[$type] ?? 0;
            $cap = $caps[$type] ?? null;
        @endphp
        <section class="glass-card rounded-2xl p-6">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $label }}</p>
            <p class="mt-1">
                <span class="text-4xl font-bold tabular-nums">{{ number_format($held, 2) }}</span>
                <span class="text-lg font-semibold text-slate-500">h</span>
                <span class="ml-1 text-sm text-slate-500">available</span>
            </p>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                about {{ $held > 0 ? round($held / $dayHours, 1) : 0 }} day(s) at {{ $dayHours }}h
                @if($cap) · earns up to {{ $cap }}h @endif
            </p>
            @if($pending > 0)
                <p class="mt-2 inline-block rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                    {{ $pending }}h asked for and not yet decided
                </p>
            @endif
        </section>
    @endforeach
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-3">

    {{-- Asking. Short on purpose: dates, how long a day is, and why. --}}
    <section class="glass-card rounded-2xl p-6 lg:col-span-1">
        <h2 class="text-sm font-bold">Ask for time off</h2>

        <form method="POST" action="{{ route('leave.store') }}" class="mt-4 space-y-4">
            @csrf

            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Type</label>
                <select name="leave_type" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach($types as $type => $label)
                        <option value="{{ $type }}" @selected(old('leave_type') === $type)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('leave_type')" class="mt-1" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">First day</label>
                    <input type="date" name="starts_on" value="{{ old('starts_on', today()->toDateString()) }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                    <x-input-error :messages="$errors->get('starts_on')" class="mt-1" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Last day</label>
                    <input type="date" name="ends_on" value="{{ old('ends_on', today()->toDateString()) }}"
                           class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                    <x-input-error :messages="$errors->get('ends_on')" class="mt-1" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Hours per day</label>
                <input type="number" name="hours_per_day" step="0.5" min="0.5" max="12"
                       value="{{ old('hours_per_day', $dayHours) }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                <p class="mt-1 text-[11px] text-slate-500">Half a day off is {{ $dayHours / 2 }} here, not two requests.</p>
                <x-input-error :messages="$errors->get('hours_per_day')" class="mt-1" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Reason <span class="font-normal">(optional)</span></label>
                <input type="text" name="reason" maxlength="200" value="{{ old('reason') }}"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('reason')" class="mt-1" />
            </div>

            <button class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">
                Send the request
            </button>

            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                Weekends and days the centre is closed are not counted, so a request
                over a long weekend costs you the working days only.
            </p>
        </form>
    </section>

    <section class="lg:col-span-2">
        <div class="glass-card overflow-hidden rounded-2xl">
            <header class="border-b border-slate-100 bg-slate-50/60 px-5 py-3 dark:border-white/10 dark:bg-white/5">
                <h2 class="text-sm font-bold">Your requests</h2>
            </header>

            @forelse($requests as $leave)
                <article class="flex flex-wrap items-start gap-x-4 gap-y-2 border-b border-slate-100 px-5 py-4 last:border-0 dark:border-white/10">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold">
                            {{ $leave->label() }}
                            <span class="ml-1 font-normal text-slate-500">{{ $leave->rangeLabel() }}</span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ $leave->days() }} day(s) · {{ $leave->hours() }}h
                            @if($leave->isApproved() && $leave->unpaid_hours > 0)
                                · <span class="font-semibold text-amber-700 dark:text-amber-300">{{ $leave->unpaid_hours }}h of it unpaid</span>
                            @endif
                            @if($leave->reason) · {{ $leave->reason }} @endif
                        </p>
                        @if($leave->decision_note)
                            <p class="mt-1 text-xs italic text-slate-500 dark:text-slate-400">"{{ $leave->decision_note }}"</p>
                        @endif
                        @if($leave->reviewer)
                            <p class="mt-0.5 text-[11px] text-slate-400">{{ ucfirst($leave->status) }} by {{ $leave->reviewer->name }} {{ $leave->reviewed_at?->diffForHumans() }}</p>
                        @endif
                    </div>

                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $tone[$leave->status] ?? '' }}">{{ ucfirst($leave->status) }}</span>

                    @if($leave->isPending())
                        <form method="POST" action="{{ route('leave.destroy', $leave) }}"
                              onsubmit="return confirm('Withdraw this request?')">
                            @csrf @method('DELETE')
                            <button class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Withdraw</button>
                        </form>
                    @endif
                </article>
            @empty
                <p class="px-5 py-12 text-center text-sm text-slate-500">You have not asked for any time off yet.</p>
            @endforelse
        </div>

        {{-- The statement. Here because a balance nobody can take apart is a
             number people argue with rather than trust. --}}
        <div class="glass-card mt-5 overflow-hidden rounded-2xl">
            <header class="border-b border-slate-100 bg-slate-50/60 px-5 py-3 dark:border-white/10 dark:bg-white/5">
                <h2 class="text-sm font-bold">Where the hours came from</h2>
            </header>

            @forelse($statement as $entry)
                <div class="flex items-baseline gap-3 border-b border-slate-100 px-5 py-2.5 text-sm last:border-0 dark:border-white/10">
                    <span class="w-20 shrink-0 text-xs tabular-nums text-slate-400">{{ $entry->effective_on->format('j M Y') }}</span>
                    <span class="w-24 shrink-0 text-xs font-semibold">{{ $types[$entry->leave_type] ?? $entry->leave_type }}</span>
                    <span class="min-w-0 flex-1 truncate text-xs text-slate-500 dark:text-slate-400">
                        {{ $entry->describe() }}@if($entry->note) — {{ $entry->note }}@endif
                    </span>
                    <span class="shrink-0 font-bold tabular-nums {{ $entry->hours >= 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-slate-500' }}">{{ $entry->signedHours() }}</span>
                </div>
            @empty
                <p class="px-5 py-10 text-center text-sm text-slate-500">
                    Nothing yet. Leave is earned as pay periods are approved, so the first entry appears once your hours have been signed off.
                </p>
            @endforelse
        </div>
    </section>
</div>
@endsection
