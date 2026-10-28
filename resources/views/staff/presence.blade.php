@extends('layouts.app')
@section('title', 'Who’s where')
@section('content')
{{-- The Kiosks page: Who's where right now (HANDOFF §5.3.1) beside a room
     tablet.

     Laid out as Jordan's "Kiosks — full-width layout" mock-up (29 Oct
     2026): four counts across the top, the real /room tablet at the
     tablet a little over the mock-up's iPad width (500px, Jordan 29 Oct) on the left, and Who's where filling the rest —
     rooms as tiles two across, then who is on break, in the office and not
     in, each as a small card. It reads the same StaffPresence the kiosks
     write; the counts and the right-hand column are fetched again every few
     seconds and swapped in place, so the tablet is never reloaded mid-PIN.
     The front door clock is not framed here (Jordan: "remove this"); it is
     at /clock and on the sign-in page. --}}
@php
    $tone = fn (string $t) => [
        'ok' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
        'bad' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
        'info' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
        'brk' => 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-100',
    ][$t] ?? 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300';
    $initials = fn (string $name) => collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    // How long a break has run, from its "since" (today's time): "5h 21m".
    $length = function (string $since) {
        try {
            $m = (int) \Illuminate\Support\Carbon::parse(today()->toDateString().' '.$since, config('app.timezone'))->diffInMinutes(now(), true);
        } catch (\Throwable) {
            return '';
        }

        return $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    };
    $rooms = collect($board['rooms']);
    $stats = [
        ['Children in rooms', $rooms->sum('children'), null, ''],
        ['Teachers in rooms', $rooms->sum('teachers'), null, ''],
        ['On break', count($board['on_break']), null, 'text-amber-600 dark:text-amber-400'],
        ['Rooms open', $rooms->where('children', '>', 0)->count(), 'of '.$rooms->count(), ''],
    ];
@endphp

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

    {{-- The four counts. --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4" data-live-part="stats" data-kiosk-stats>
        @foreach($stats as [$label, $value, $of, $colour])
            <div class="glass-card rounded-2xl px-4 py-3">
                <p class="text-[10.5px] font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $colour }}">{{ $value }}@if($of) <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $of }}</span>@endif</p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid items-start gap-5 lg:grid-cols-[500px_minmax(0,1fr)]">
        {{-- A room tablet. The gear and a room code say which room. --}}
        <div data-kiosk-frame="room">
            <div class="mb-2 ml-1.5 flex items-baseline justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">Room tablet · ratios</p>
                <a href="{{ route('room.kiosk') }}" target="_blank" rel="noopener" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" data-open-room>Full screen ↗</a>
            </div>
            <p class="-mt-1 mb-2 ml-1.5 text-xs text-slate-500 dark:text-slate-400">Tap ⚙ on the tablet and key a room code to change which room it’s in.</p>
            <div class="rounded-[34px] bg-[#1d2333] p-3.5 shadow-[0_10px_30px_rgba(28,33,48,.18)]">
                <iframe src="{{ route('room.kiosk') }}" title="Room tablet" class="block h-[820px] w-full rounded-[22px] bg-[#f4f6fb]"></iframe>
            </div>
        </div>

        <div class="grid gap-4" data-live-part="live" data-live>
            @if(session('status'))
                <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
            @endif

            @if($board['flags']->isNotEmpty())
                {{-- Red until somebody says they have seen it. --}}
                <section class="rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10" data-flags>
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

            <section class="glass-card rounded-2xl p-5" data-whos-where>
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-base font-bold">Who’s where right now</h2>
                    <span class="text-xs font-medium text-amber-700 dark:text-amber-400">Updates as teachers key in</span>
                </div>

                {{-- Rooms, two across. --}}
                <div class="mt-3 grid gap-2.5 sm:grid-cols-2">
                    @foreach($board['rooms'] as $room)
                        <div class="min-w-0 rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-white/5" data-room-row="{{ $room['room'] }}">
                            <div class="flex gap-2.5">
                                <span class="w-1 shrink-0 self-stretch rounded-full" style="background: {{ $room['colour'] }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <b class="truncate text-sm">{{ $room['room'] }}</b>
                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $room['tone'] === 'mut' ? 'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-night-800 dark:text-slate-300 dark:ring-white/10' : $tone($room['tone']) }}" data-room-ratio>{{ $room['label'] }}</span>
                                    </div>
                                    @forelse($room['people'] as $person)
                                        <div class="flex justify-between gap-2 text-xs"><span class="truncate">{{ $person['name'] }}</span><span class="shrink-0 text-slate-500 dark:text-slate-400">since {{ $person['since'] }}</span></div>
                                    @empty
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Nobody checked in</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- On break: one card each, how long so far on the right. --}}
                <div class="mt-5" data-on-break>
                    <p class="flex items-center gap-1.5 text-[10.5px] font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">On break <span class="rounded-full px-1.5 py-px text-[10px] {{ $tone('brk') }}">{{ count($board['on_break']) }}</span></p>
                    <div class="mt-2 grid gap-2">
                        @forelse($board['on_break'] as $person)
                            <div class="flex items-center gap-3 rounded-xl bg-amber-50 px-3 py-2.5 dark:bg-amber-500/10">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-200/70 text-[11px] font-bold text-amber-900 dark:bg-amber-500/30 dark:text-amber-100">{{ $initials($person['name']) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold">{{ $person['name'] }}</p>
                                    <p class="truncate text-xs text-amber-800/80 dark:text-amber-200/70">Since {{ $person['since'] }} · from {{ $person['last'] }}</p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums text-amber-700 dark:text-amber-300">{{ $length($person['since']) }}</span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400">Nobody on break.</p>
                        @endforelse
                    </div>
                </div>

                {{-- In the building, no room. --}}
                <div class="mt-5" data-office>
                    <p class="flex items-center gap-1.5 text-[10.5px] font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">Office / no room <span class="rounded-full px-1.5 py-px text-[10px] {{ $tone('mut') }}">{{ count($board['office']) }}</span></p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @forelse($board['office'] as $person)
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2 dark:bg-white/5">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ $initials($person['name']) }}</span>
                                <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $person['name'] }}</p>
                                <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ $person['since'] }}</span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400">Nobody in the office.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Not on the clock, with why. --}}
                <div class="mt-5" data-not-in>
                    <p class="flex items-center gap-1.5 text-[10.5px] font-bold uppercase tracking-[.06em] text-slate-500 dark:text-slate-400">Not clocked in <span class="rounded-full px-1.5 py-px text-[10px] {{ $tone('mut') }}">{{ count($board['not_in']) }}</span></p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach($board['not_in'] as $person)
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-2 dark:bg-white/5">
                                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ $initials($person['name']) }}</span>
                                <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $person['name'] }}</p>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold {{ $tone($person['tone']) }}">{{ $person['note'] }}</span>
                            </div>
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

<script>
    // The counts and Who's where catch up with the tablet beside them: those
    // parts alone are fetched again and swapped every few seconds while the
    // page is on screen, so a PIN half-keyed on the tablet is never lost.
    (() => {
        const refresh = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(location.href, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });
                if (!res.ok) return;
                const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                document.querySelectorAll('[data-live-part]').forEach((part) => {
                    const fresh = doc.querySelector(`[data-live-part="${part.dataset.livePart}"]`);
                    if (fresh) part.innerHTML = fresh.innerHTML;
                });
                const t = doc.querySelector('[data-kiosk-now]');
                const now = document.querySelector('[data-kiosk-now]');
                if (t && now) now.textContent = t.textContent;
            } catch (e) { /* offline for a moment: try again next time */ }
        };
        setInterval(refresh, 5000);
    })();
</script>
@endsection
