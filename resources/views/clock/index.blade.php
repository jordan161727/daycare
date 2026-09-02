@extends('layouts.app')

@section('title', 'Time clock')

@section('content')
@php($state = $day['state'])
@php($stateLabel = ['off' => 'Not on the clock', 'working' => 'On the clock', 'lunch' => 'At lunch', 'break' => 'On a break'][$state])

<x-page-header title="Time clock" :subtitle="$staff->name.' · '.$today->format('l j F Y')" />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>
@endif
@if(session('warning'))
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>
@endif

{{-- Where they stand, and the only buttons that make sense from there. Offering
     an impossible punch is how an exception queue fills up. --}}
<section class="glass-card mt-7 rounded-2xl p-6">
    <div class="flex flex-wrap items-center gap-4">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl text-2xl {{ ['off' => 'bg-slate-100 text-slate-400 dark:bg-slate-800', 'working' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300', 'lunch' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300', 'break' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300'][$state] }}">
            {{ ['off' => '○', 'working' => '●', 'lunch' => '◐', 'break' => '◑'][$state] }}
        </span>
        <div>
            <h2 class="text-xl font-bold">{{ $stateLabel }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                @if($day['punches']->isEmpty())
                    Nothing punched today yet.
                @else
                    {{ \App\Models\TimesheetEntry::formatHours($day['so_far']) }} h so far today
                    @if($day['unpaid_break'] > 0) · {{ $day['unpaid_break'] }} min unpaid @endif
                    @if($day['paid_break'] > 0) · {{ $day['paid_break'] }} min paid break @endif
                @endif
            </p>
        </div>
        <span class="ml-auto text-right text-sm text-slate-500 dark:text-slate-400">
            {{ $range->label() }}
            @if($summary)
                <b class="block text-lg text-slate-800 dark:text-slate-100">{{ number_format($summary['paid_hours'], 2) }} h</b>
                this pay period
            @endif
        </span>
    </div>

    <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200/70 pt-5 dark:border-white/10">
        @foreach($next as $type)
            <form method="POST" action="{{ route('clock.punch') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <button class="rounded-xl px-6 py-3.5 text-base font-bold text-white shadow-lg transition {{ $type === 'IN' ? 'bg-emerald-600 shadow-emerald-500/25 hover:bg-emerald-700' : ($type === 'OUT' ? 'bg-slate-700 shadow-slate-500/25 hover:bg-slate-800' : 'bg-indigo-600 shadow-indigo-500/25 hover:bg-indigo-700') }}">
                    {{ \App\Models\TimePunch::action($type) }}
                </button>
            </form>
        @endforeach

        <p class="self-center text-xs text-slate-500 dark:text-slate-400">
            The time recorded is the moment you press it, to the minute, and it is not rounded.
        </p>
    </div>
</section>

{{-- Today, punch by punch. A punch a supervisor moved says so, and says where
     it was moved from — somebody whose Tuesday was corrected finds out here
     rather than on their payslip. --}}
<section class="glass-card mt-5 rounded-2xl p-5">
    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Today</h3>

    @if($day['punches']->isEmpty())
        <p class="mt-3 text-sm text-slate-500">Nothing yet. Press <b>Clock in</b> when you start.</p>
    @else
        <ol class="mt-3 space-y-1.5">
            @foreach($day['punches'] as $punch)
                <li class="flex flex-wrap items-baseline gap-2 text-sm">
                    <span class="w-20 shrink-0 font-semibold tabular-nums">{{ $punch->time() }}</span>
                    <span>{{ $punch->label() }}</span>
                    @if($punch->isCorrection())
                        <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200" title="{{ $punch->reason }}">
                            {{ $punch->corrects ? 'moved from '.$punch->corrects->time() : 'added' }} by {{ $punch->recorder?->name }}
                        </span>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $punch->reason }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if($day['problems'] !== [])
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
            This day does not add up — {{ implode('; ', $day['problems']) }}. It is worth nothing until a supervisor puts it right,
            so tell them. You cannot correct your own punches, and that is deliberate.
        </p>
    @endif
</section>

{{-- The last fortnight, so a missed punch is found before payday. --}}
@if(count($week) > 1)
    <section class="glass-card mt-5 overflow-hidden rounded-2xl">
        <div class="border-b border-slate-200/70 px-5 py-4 dark:border-white/10">
            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Your last fortnight</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-night-800/60">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Day</th>
                        <th class="px-3 py-3 font-semibold">In</th>
                        <th class="px-3 py-3 font-semibold">Out</th>
                        <th class="px-3 py-3 text-right font-semibold">Worked</th>
                        <th class="px-5 py-3 font-semibold">Punches</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach($week as $iso => $entry)
                        @php($when = \Illuminate\Support\Carbon::parse($iso))
                        <tr class="{{ $entry['problems'] !== [] ? 'bg-rose-50/60 dark:bg-rose-500/5' : '' }}">
                            <td class="whitespace-nowrap px-5 py-2.5 font-semibold">{{ $when->format('D j M') }}</td>
                            <td class="px-3 py-2.5 tabular-nums">{{ $entry['first_in'] !== null ? sprintf('%d:%02d', intdiv($entry['first_in'], 60), $entry['first_in'] % 60) : '—' }}</td>
                            <td class="px-3 py-2.5 tabular-nums">{{ $entry['last_out'] !== null ? sprintf('%d:%02d', intdiv($entry['last_out'], 60), $entry['last_out'] % 60) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">
                                {{ $entry['problems'] !== [] ? '—' : \App\Models\TimesheetEntry::formatHours($entry['worked']) }}
                            </td>
                            <td class="px-5 py-2.5 text-xs text-slate-500 dark:text-slate-400">
                                @if($entry['problems'] !== [])
                                    <span class="font-semibold text-rose-600 dark:text-rose-300">{{ ucfirst(implode('; ', $entry['problems'])) }}</span>
                                @else
                                    {{ $entry['punches']->count() }} punch(es)
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

<p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
    Lunch is unpaid. A break is paid for its first {{ config('daycare.timesheet.clock.paid_break_cap') }} minutes and unpaid beyond them,
    which is why they are separate buttons. Your punches go straight onto the timesheet the centre sends to payroll —
    a day that does not add up pays nothing until a supervisor has sorted it out, rather than being guessed at.
</p>
@endsection
