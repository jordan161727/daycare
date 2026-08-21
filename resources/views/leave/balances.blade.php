@extends('layouts.app')

@section('title', 'Leave Balances')

@section('content')
<div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
    <x-page-header title="Leave Balances" subtitle="What every staff member has earned, and what is promised" />

    <a href="{{ route('leave.requests') }}"
       class="self-start rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">
        Requests
    </a>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

{{-- Accrual is normally posted by approving the pay period. This is here for
     the periods approved before any of this existed, and for the director who
     wants to see what a run would say. --}}
<section class="glass-card mt-7 rounded-2xl p-6">
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <h2 class="text-sm font-bold">Earn a pay period</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Hourly staff earn against the hours they actually worked; salaried staff earn a flat rate.
                Only an approved period accrues, and running the same one twice pays it once.
            </p>
        </div>

        <form method="POST" action="{{ route('leave.accrue') }}" class="ml-auto flex items-end gap-2">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Any date in the period</label>
                <input type="date" name="date" value="{{ $range->start->toDateString() }}"
                       class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
            </div>
            <button class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Run accrual</button>
        </form>
    </div>

    <p class="mt-3 border-t border-slate-200/70 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
        {{ $range->label() }} —
        @if(! $period) no timesheet exists for it yet.
        @elseif($period->isApproved()) approved {{ $period->approved_at?->diffForHumans() }}, so it can be accrued.
        @else still a draft. Approve it on Payroll Prep first; its hours can still change.
        @endif
    </p>
</section>

<section class="glass-card mt-5 overflow-hidden rounded-2xl">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="bg-slate-50/70 dark:bg-white/5">
                    <th class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500">Staff</th>
                    @foreach($types as $type => $label)
                        <th class="border-l border-slate-100 px-4 py-2.5 text-right text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10">{{ $label }}</th>
                    @endforeach
                    <th class="border-l border-slate-100 px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10">Adjust by hand</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @forelse($staff as $person)
                    <tr class="hover:bg-slate-50/60 dark:hover:bg-white/5">
                        <td class="px-4 py-3 align-top">
                            <a href="{{ route('teachers.show', $person) }}" class="font-bold hover:text-indigo-600">{{ $person->name }}</a>
                            <span class="block text-[11px] text-slate-400">{{ $person->employment ?: 'No type' }}</span>
                        </td>

                        @foreach($types as $type => $label)
                            @php
                                $held = $balances[$person->id][$type] ?? 0;
                                $pending = $committed[$person->id][$type] ?? 0;
                                $booked = $upcoming[$person->id][$type] ?? 0;
                                $cap = $caps[$type] ?? null;
                            @endphp
                            <td class="border-l border-slate-100 px-4 py-3 text-right align-top dark:border-white/10">
                                <span class="text-lg font-bold tabular-nums {{ $cap && $held >= $cap ? 'text-amber-600 dark:text-amber-300' : '' }}">{{ number_format($held, 2) }}</span>
                                <span class="text-xs text-slate-400">h</span>

                                @if($cap && $held >= $cap)
                                    <span class="block text-[10px] font-bold uppercase text-amber-600 dark:text-amber-300">at the cap</span>
                                @endif
                                @if($booked > 0)
                                    <span class="block text-[10px] text-slate-400">{{ $booked }}h already booked ahead</span>
                                @endif
                                @if($pending > 0)
                                    <span class="block text-[10px] text-amber-600 dark:text-amber-300">{{ $pending }}h awaiting a decision</span>
                                @endif
                            </td>
                        @endforeach

                        <td class="border-l border-slate-100 px-4 py-3 align-top dark:border-white/10">
                            <form method="POST" action="{{ route('leave.adjust', $person) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <select name="leave_type" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                                    @foreach($types as $type => $label)
                                        <option value="{{ $type }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="number" name="hours" step="0.25" placeholder="±h" required
                                       class="w-20 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs tabular-nums dark:border-white/10 dark:bg-slate-800">
                                <input type="text" name="note" maxlength="200" placeholder="Why" required
                                       class="w-36 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800">
                                <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Post</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($types) + 2 }}" class="px-4 py-12 text-center text-sm text-slate-500">No staff records yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
    A balance is the sum of everything on somebody's ledger, never a stored number — which is why an adjustment
    needs a reason. "Booked ahead" is approved leave that has not been taken yet; it has already come off the balance.
</p>
@endsection
