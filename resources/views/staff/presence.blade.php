@extends('layouts.app')
@section('title', 'Who’s where')
@section('content')
{{-- Who's where right now (HANDOFF §5.3.1).

     The right-hand column of the kiosk mock-up as a page: room by room with
     the ratio pill, on break, in the office, not clocked in with the reason,
     the flags the office has to answer, and the feed. It reads the same
     StaffPresence the kiosks write, so what a teacher just did at a tablet
     is here on the next refresh. --}}
@php($tone = fn (string $t) => [
    'ok' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
    'bad' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
    'info' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
    'brk' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
][$t] ?? 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300')

<div class="mx-auto max-w-6xl" data-presence-board>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Who’s where right now</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">What the front door clock and the room tablets say, as of {{ now()->format('g:i A') }}. Ratios count only people checked into a room.</p>
        </div>
        <div class="flex items-center gap-2 text-xs">
            <a href="{{ route('staff.presence') }}" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-night-800 dark:text-slate-300">Refresh</a>
            <a href="{{ route('settings.rooms') }}" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-night-800 dark:text-slate-300">Room codes ⚙</a>
            {{-- The kiosks themselves (Ray, 28 Oct 2026). The clock opens in
                 its own tab: on a browser that was never paired it says so,
                 and pairing is one click away under Devices. --}}
            <a href="{{ route('clock.kiosk') }}" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-night-800 dark:text-slate-300" data-open-clock>Front door clock ↗</a>
            <a href="{{ route('settings.devices') }}" class="rounded-xl px-3 py-1.5 font-semibold text-white hover:brightness-110" style="background: #1b5e91" data-pair-kiosk>Pair a kiosk</a>
        </div>
    </div>

    @if(session('status'))
        <p class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
    @endif

    @if($board['flags']->isNotEmpty())
        {{-- Red until somebody says they have seen it. --}}
        <section class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10" data-flags>
            <h2 class="text-sm font-bold text-rose-900 dark:text-rose-200">For the office · {{ $board['flags']->count() }}</h2>
            <ul class="mt-2 divide-y divide-rose-200/70 dark:divide-rose-500/20">
                @foreach($board['flags'] as $flag)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span><span class="tabular-nums text-rose-700/70 dark:text-rose-300/70">{{ $flag->occurred_at->format('g:i A') }}</span> · {{ $flag->text }}</span>
                        <form method="POST" action="{{ route('staff.presence.resolve', $flag) }}">
                            @csrf
                            <button class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-rose-800 ring-1 ring-rose-200 hover:bg-rose-100 dark:bg-night-800 dark:text-rose-200 dark:ring-rose-500/30">Seen to</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_380px]">
        <section class="glass-card rounded-2xl p-5">
            <div class="space-y-3">
                @foreach($board['rooms'] as $room)
                    <div class="rounded-r-xl border-l-[6px] py-1.5 pl-3" style="border-color: {{ $room['colour'] }}" data-room-row="{{ $room['room'] }}">
                        <div class="flex items-center justify-between gap-2">
                            <b class="text-sm"><x-room-icon :room="$room['room']" size="text-sm" /> {{ $room['room'] }}</b>
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone($room['tone']) }}" data-room-ratio>{{ $room['label'] }}</span>
                        </div>
                        @forelse($room['people'] as $person)
                            <div class="flex justify-between text-sm"><span>{{ $person['name'] }}</span><span class="text-slate-500 dark:text-slate-400">since {{ $person['since'] }}</span></div>
                        @empty
                            <p class="text-xs text-slate-400">Nobody checked in</p>
                        @endforelse
                    </div>
                @endforeach

                <div class="rounded-r-xl border-l-[6px] border-amber-500 py-1.5 pl-3" data-on-break>
                    <div class="flex items-center justify-between gap-2"><b class="text-sm">On break</b><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone('brk') }}">{{ count($board['on_break']) }}</span></div>
                    @foreach($board['on_break'] as $person)
                        <div class="flex justify-between text-sm"><span>{{ $person['name'] }}</span><span class="text-slate-500 dark:text-slate-400">since {{ $person['since'] }} · from {{ $person['last'] }}</span></div>
                    @endforeach
                </div>

                <div class="rounded-r-xl border-l-[6px] border-slate-400 py-1.5 pl-3" data-office>
                    <div class="flex items-center justify-between gap-2"><b class="text-sm">Office / no room</b><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone('mut') }}">{{ count($board['office']) }}</span></div>
                    @foreach($board['office'] as $person)
                        <div class="flex justify-between text-sm"><span>{{ $person['name'] }}</span><span class="text-slate-500 dark:text-slate-400">{{ $person['since'] }}</span></div>
                    @endforeach
                </div>

                <div class="rounded-r-xl border-l-[6px] border-slate-200 py-1.5 pl-3 dark:border-white/10" data-not-in>
                    <b class="text-sm">Not clocked in</b>
                    @foreach($board['not_in'] as $person)
                        <div class="flex items-center justify-between gap-2 text-sm"><span>{{ $person['name'] }}</span><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone($person['tone']) }}">{{ $person['note'] }}</span></div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="glass-card rounded-2xl p-5" data-activity>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-bold">Activity</h2>
                <span class="text-[11px] text-slate-500 dark:text-slate-400"><span class="inline-block h-2 w-2 rounded-full" style="background:#1b5e91"></span> punch · <span class="inline-block h-2 w-2 rounded-full" style="background:#9dc585"></span> room · <span class="inline-block h-2 w-2 rounded-full" style="background:#be123c"></span> flag</span>
            </div>
            <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/5">
                @forelse($board['activity'] as $line)
                    <li class="grid grid-cols-[64px_12px_1fr] items-baseline gap-2 py-1.5 text-[13px]">
                        <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ $line['time'] }}</span>
                        <span class="inline-block h-2 w-2 rounded-full" style="background: {{ $line['colour'] }}"></span>
                        <span class="{{ $line['kind'] === 'flag' && $line['resolved'] ? 'text-slate-400 line-through' : '' }}">{{ $line['text'] }}</span>
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-400">Nothing yet today.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
@endsection
