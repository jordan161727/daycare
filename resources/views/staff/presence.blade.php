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

{{-- Laid out as the StaffFlow mock-up (Jordan, 29 Oct 2026: "if I click
     the kiosk tab, move to this"): the real room tablet framed as an iPad,
     beside Who's where. Use a PIN on it and the right-hand column catches
     up within a few seconds. The
     mock-up's Mock time / +15 min / Reset demo are demo controls and are
     left out: this is the live centre. --}}
<div data-presence-board>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Kiosks — room tablets</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">One status per teacher, the same PIN as the front door. Use a PIN on the tablet and watch “Who’s where” update.</p>
        </div>
        <div class="flex items-center gap-2.5">
            <span class="text-[13px] text-slate-500 dark:text-slate-400">Now</span>
            <span class="text-xl font-bold tabular-nums" data-kiosk-now>{{ now()->format('g:i A') }}</span>
            <a href="{{ route('settings.rooms') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50 dark:border-white/10 dark:bg-night-800 dark:text-slate-200">Room codes ⚙</a>
            <a href="{{ route('dashboard') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white hover:brightness-110" style="background: #2f6fb0">← AngelNest</a>
        </div>
    </div>

    {{-- The front door clock frame is off this page (Jordan, 29 Oct 2026:
         "remove this"); the clock lives at /clock and on the sign-in page. --}}
    <div class="mt-5 grid items-start gap-5 xl:grid-cols-[420px_minmax(0,1fr)]">
        {{-- A room tablet. The gear and a room code say which room. --}}
        <div data-kiosk-frame="room">
            <div class="mb-2 ml-1.5 flex items-baseline justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">Room tablet · ratios</p>
                <a href="{{ route('room.kiosk') }}" target="_blank" rel="noopener" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" data-open-room>Full screen ↗</a>
            </div>
            <p class="-mt-1 mb-2 ml-1.5 text-xs text-slate-500 dark:text-slate-400">Tap ⚙ on the tablet and key a room code to change which room it’s in.</p>
            <div class="rounded-[34px] bg-[#1d2333] p-3.5 shadow-[0_10px_30px_rgba(28,33,48,.18)]">
                <iframe src="{{ route('room.kiosk') }}" title="Room tablet" class="block h-[722px] w-full rounded-[22px] bg-[#f4f6fb]"></iframe>
            </div>
        </div>

        {{-- Who's where, refreshed in place so the tablet beside it is
             never reloaded mid-PIN. --}}
        <div data-live>
    @if(session('status'))
        <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
    @endif

    @if($board['flags']->isNotEmpty())
        {{-- Red until somebody says they have seen it. --}}
        <section class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10" data-flags>
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

    <div class="grid gap-4">
        <section class="glass-card rounded-2xl p-5">
            <h2 class="mb-3 text-base font-bold">Who’s where right now</h2>
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
    </div>
</div>

<script>
    // Who's where catches up with the tablets beside it: the right-hand
    // column alone is fetched again and swapped, every few seconds while the
    // page is on screen, so a PIN half-keyed on a tablet is never lost.
    (() => {
        const live = document.querySelector('[data-live]');
        const now = document.querySelector('[data-kiosk-now]');
        const refresh = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(location.href, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });
                if (!res.ok) return;
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const fresh = doc.querySelector('[data-live]');
                if (fresh) live.innerHTML = fresh.innerHTML;
                const t = doc.querySelector('[data-kiosk-now]');
                if (t && now) now.textContent = t.textContent;
            } catch (e) { /* offline for a moment: try again next time */ }
        };
        setInterval(refresh, 5000);
    })();
</script>
@endsection
