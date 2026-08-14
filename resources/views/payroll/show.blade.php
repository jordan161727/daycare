@extends('layouts.app')

@section('title', 'Review payroll')

@php
    $states = [
        'sent' => ['bg-emerald-500', 'Sent'],
        'failed' => ['bg-rose-500', 'Failed'],
        'unmatched' => ['bg-amber-500', 'Not matched to anybody'],
        'no-email' => ['bg-amber-400', 'No email on file'],
        'pending' => ['bg-slate-300', 'Not sent yet'],
    ];
    $position = $current ? $slips->search(fn ($slip) => $slip->is($current)) : null;
@endphp

@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <x-page-header
        :title="$batch->period_label ?: 'Payroll run'"
        :subtitle="$batch->original_filename.' · '.$batch->page_count.' pages · '.$slips->count().' payslips · '.$batch->sentCount().' sent'" />
    <a href="{{ route('payroll.index') }}" class="w-fit rounded-xl px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">All payroll runs</a>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">{{ session('error') }}</div>
@endif

@if($slips->where('user_id', null)->isNotEmpty())
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
        {{ $slips->where('user_id', null)->count() }} page group could not be matched to a staff member. Open each one and assign it by hand before sending — or check the legal name on that person's record.
    </div>
@endif

<div class="mt-7 grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">

    {{-- The run, one row per person, so a half-finished batch can be picked up
         later without anybody being emailed twice. --}}
    <nav class="glass-card h-fit overflow-hidden rounded-2xl">
        <h2 class="border-b border-slate-100 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10">Payslips</h2>
        <ul class="max-h-[560px] overflow-y-auto">
            @foreach($slips as $slip)
                @php [$dot, $title] = $states[$slip->state()]; @endphp
                <li>
                    <a href="{{ route('payroll.show', ['batch' => $batch, 'slip' => $slip->id]) }}"
                       title="{{ $title }}"
                       class="flex items-center gap-2.5 border-l-[3px] px-4 py-2.5 text-sm {{ $current && $slip->is($current) ? 'border-indigo-600 bg-indigo-50 font-semibold dark:bg-indigo-500/10' : 'border-transparent hover:bg-slate-50 dark:hover:bg-white/5' }}">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dot }}"></span>
                        <span class="min-w-0 flex-1 truncate">{{ $slip->displayName() }}</span>
                        <span class="shrink-0 text-[10px] text-slate-400">p{{ implode(',', array_map(fn ($p) => $p + 1, $slip->pages)) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    @if(! $current)
        <div class="glass-card rounded-2xl px-6 py-16 text-center text-sm text-slate-500">
            Nothing was extracted from this PDF. If it is a scan, it has no text layer to match names against.
        </div>
    @else
        <section class="glass-card rounded-2xl p-6">
            <div class="flex flex-wrap items-center gap-3">
                @if($position > 0)
                    <a href="{{ route('payroll.show', ['batch' => $batch, 'slip' => $slips[$position - 1]->id]) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">&larr; Prev</a>
                @endif
                <b class="text-sm">{{ $position + 1 }} of {{ $slips->count() }}</b>
                @if($position < $slips->count() - 1)
                    <a href="{{ route('payroll.show', ['batch' => $batch, 'slip' => $slips[$position + 1]->id]) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Next &rarr;</a>
                @endif

                @if($current->isSent())
                    <span class="ml-auto rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">Sent to {{ $current->sent_to }} {{ $current->sent_at?->diffForHumans() }}</span>
                @elseif($current->status === 'failed')
                    <span class="ml-auto rounded-full bg-rose-100 px-3 py-1 text-xs font-bold text-rose-700">Last attempt failed</span>
                @endif
            </div>

            {{-- Reassignment is its own form: correcting a bad match must not
                 require, or be confused with, pressing send. --}}
            <form method="POST" action="{{ route('payroll.reassign', [$batch, $current]) }}" class="mt-5 flex flex-wrap items-end gap-3">
                @csrf @method('PUT')
                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">This payslip belongs to</span>
                    <select name="user_id" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold dark:border-white/10 dark:bg-slate-800">
                        <option value="">— Not assigned —</option>
                        @foreach($staff as $person)
                            <option value="{{ $person->id }}" @selected($current->user_id === $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Reassign</button>

                <p class="text-xs text-slate-500">
                    @if($current->matched_name)Matched on “{{ $current->matched_name }}”. @endif
                    @if($current->user){{ $current->user->email ?: 'No email on their record.' }}@endif
                </p>
            </form>

            @if($current->period_start || $current->check_date)
                <p class="mt-3 text-xs text-slate-500">
                    Read off the payslip:
                    @if($current->period_start)period {{ $current->period_start->format('M j') }} – {{ $current->period_end?->format('M j, Y') }}@endif
                    @if($current->check_date) · check date {{ $current->check_date->format('M j, Y') }}@endif
                </p>
            @endif

            <iframe src="{{ route('payroll.preview', [$batch, $current]) }}"
                    title="Payslip for {{ $current->displayName() }}"
                    class="mt-5 h-[520px] w-full rounded-xl border border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-slate-800"></iframe>

            <form method="POST" action="{{ route('payroll.send', [$batch, $current]) }}" class="mt-6"
                  x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                @csrf

                <button type="button" @click="open = ! open" class="text-xs font-semibold text-slate-500 hover:text-slate-700">
                    <span x-text="open ? '▾' : '▸'"></span> Email subject &amp; body
                </button>

                <div x-show="open" x-cloak class="mt-3 space-y-4">
                    <p class="text-xs text-slate-500">
                        Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{period}</code>
                        <code>{period_start}</code> <code>{period_end}</code> <code>{check_date}</code>.
                        <code>{period}</code> uses this run's period label, falling back to the dates on the payslip.
                    </p>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Subject</span>
                        <input name="subject" value="{{ $subject }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
                        <x-input-error :messages="$errors->get('subject')" />
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">Body</span>
                        <textarea name="body" rows="7" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 font-mono text-xs dark:border-white/10 dark:bg-slate-800">{{ $body }}</textarea>
                        <x-input-error :messages="$errors->get('body')" />
                    </label>
                </div>

                {{-- The editor is collapsed with x-show, which only hides it —
                     the fields above still post their defaults, so no hidden
                     duplicates are needed and none can drift out of sync. --}}

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <button name="to_self" value="0"
                            @disabled(! $current->isSendable())
                            class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ $current->isSent() ? 'Send again' : 'Email this teacher' }}
                    </button>
                    <button name="to_self" value="1" class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">
                        Send a copy to me
                    </button>

                    @unless($current->isSendable())
                        <span class="text-xs font-medium text-amber-700">
                            {{ $current->user ? 'No email address on their staff record.' : 'Assign this payslip to somebody first.' }}
                        </span>
                    @endunless
                </div>
            </form>
        </section>
    @endif
</div>
@endsection
