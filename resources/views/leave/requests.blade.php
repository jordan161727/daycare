@extends('layouts.app')

@section('title', 'Leave Requests')

@php
    use App\Models\LeaveRequest;

    $tabs = [
        'pending' => 'Waiting',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'cancelled' => 'Cancelled',
        'all' => 'All',
    ];

    $tone = [
        LeaveRequest::STATUS_PENDING => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
        LeaveRequest::STATUS_APPROVED => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200',
        LeaveRequest::STATUS_DENIED => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200',
        LeaveRequest::STATUS_CANCELLED => 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-300',
    ];
@endphp

@section('content')
<div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
    <x-page-header
        title="Leave Requests"
        :subtitle="$pendingCount === 0 ? 'Nothing waiting on a decision' : $pendingCount.' waiting on a decision'" />

    <a href="{{ route('leave.balances') }}"
       class="self-start rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">
        Balances &amp; accrual
    </a>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

<div class="mt-6 flex flex-wrap gap-2">
    @foreach($tabs as $value => $label)
        <a href="{{ route('leave.requests', ['status' => $value]) }}"
           class="rounded-full px-4 py-2 text-sm font-semibold {{ $status === $value ? 'bg-indigo-100 text-indigo-700 ring-1 ring-indigo-500 dark:bg-indigo-500/20 dark:text-indigo-200' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
            {{ $label }}
            @if($value === 'pending' && $pendingCount > 0)<span class="ml-1 rounded-full bg-amber-500 px-1.5 text-[10px] text-white">{{ $pendingCount }}</span>@endif
        </a>
    @endforeach
</div>

@forelse($requests as $leave)
    @php
        $held = $balances[$leave->user_id][$leave->leave_type] ?? 0;
        $asked = $leave->hours();
        $short = round($asked - $held, 2);
    @endphp

    <section class="glass-card mt-4 rounded-2xl p-5">
        <div class="flex flex-wrap items-start gap-x-5 gap-y-3">
            <div class="min-w-0 flex-1">
                <p class="text-base font-bold">
                    {{ $leave->user?->name ?? 'Deleted staff member' }}
                    <span class="ml-2 rounded-full px-2.5 py-0.5 text-xs font-bold {{ $tone[$leave->status] ?? '' }}">{{ ucfirst($leave->status) }}</span>
                </p>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                    {{ $leave->label() }} · {{ $leave->rangeLabel() }} ·
                    <span class="font-semibold tabular-nums">{{ $leave->days() }} day(s), {{ $asked }}h</span>
                    <span class="text-slate-400">at {{ $leave->hours_per_day }}h a day</span>
                </p>
                @if($leave->reason)
                    <p class="mt-1 text-sm italic text-slate-500 dark:text-slate-400">"{{ $leave->reason }}"</p>
                @endif
                <p class="mt-1 text-[11px] text-slate-400">Asked {{ $leave->created_at?->diffForHumans() }}</p>
            </div>

            {{-- The balance, right next to the ask. Approving without it is how
                 a centre finds out in March that it granted four weeks off. --}}
            <div class="shrink-0 rounded-xl bg-slate-50 px-4 py-3 text-center dark:bg-white/5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">On their card</p>
                <p class="text-2xl font-bold tabular-nums">{{ number_format($held, 2) }}<span class="text-sm font-semibold text-slate-500">h</span></p>
                @if($leave->isPending() && $short > 0)
                    <p class="mt-1 text-[11px] font-bold text-amber-700 dark:text-amber-300">{{ $short }}h short</p>
                @endif
            </div>
        </div>

        @if($leave->isPending())
            <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-200/70 pt-4 dark:border-white/10">
                <form method="POST" action="{{ route('leave.approve', $leave) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="min-w-[14rem] flex-1">
                        <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400">Note <span class="font-normal">(optional, they see it)</span></label>
                        <input type="text" name="decision_note" maxlength="200"
                               class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                    </div>

                    @if($short > 0)
                        <label class="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs font-semibold text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                            <input type="checkbox" name="allow_unpaid" value="1" class="rounded border-amber-300">
                            approve the shortfall as unpaid
                        </label>
                    @endif

                    <button class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/25 hover:bg-emerald-700">Approve</button>
                </form>

                <form method="POST" action="{{ route('leave.deny', $leave) }}"
                      onsubmit="return confirm('Deny this request?')">
                    @csrf
                    <button class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-50 dark:border-white/10 dark:hover:bg-rose-500/10">Deny</button>
                </form>
            </div>

            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                Approving takes the hours off their balance, removes their shifts from any week already published,
                and puts the days on the timesheet under {{ $leave->timesheetCode() }}.
            </p>
        @elseif($leave->isApproved())
            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-200/70 pt-4 dark:border-white/10">
                <p class="min-w-0 flex-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ $leave->paid_hours }}h paid
                    @if($leave->unpaid_hours > 0) · {{ $leave->unpaid_hours }}h unpaid @endif
                    @if($leave->reviewer) · approved by {{ $leave->reviewer->name }} {{ $leave->reviewed_at?->diffForHumans() }} @endif
                    @if($leave->decision_note) · "{{ $leave->decision_note }}" @endif
                </p>
                <form method="POST" action="{{ route('leave.revoke', $leave) }}"
                      onsubmit="return confirm('Revoke this leave? The hours go back on their balance, but their shifts are not restored — you will need to regenerate the week.')">
                    @csrf
                    <button class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Revoke</button>
                </form>
            </div>
        @elseif($leave->decision_note || $leave->reviewer)
            <p class="mt-3 border-t border-slate-200/70 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                @if($leave->reviewer) {{ ucfirst($leave->status) }} by {{ $leave->reviewer->name }} {{ $leave->reviewed_at?->diffForHumans() }} @endif
                @if($leave->decision_note) · "{{ $leave->decision_note }}" @endif
            </p>
        @endif
    </section>
@empty
    <div class="glass-card mt-5 rounded-2xl px-6 py-16 text-center">
        <p class="text-sm text-slate-500">
            @if($status === 'pending') Nothing is waiting on you. @else No {{ $status }} requests. @endif
        </p>
    </div>
@endforelse
@endsection
