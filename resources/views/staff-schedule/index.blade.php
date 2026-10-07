@extends('layouts.app')

@section('title', 'Weekly schedule')

@php
    use App\Models\StaffRule;
    use App\Models\StaffShift;

    $open = (int) config('daycare.open');
    $close = (int) config('daycare.close');
    $span = max(1, $close - $open);
    $days = config('daycare.days');

    /** Where a shift sits on a lane, as percentages of the operating day. */
    $place = fn (int $from, int $to) => sprintf(
        'left:%.4f%%;width:%.4f%%',
        (max($open, $from) - $open) / $span * 100,
        (min($close, $to) - max($open, $from)) / $span * 100
    );

    /** "7a", "1:45p" — short enough to survive a narrow bar. */
    $compact = fn (int $minutes) => StaffRule::compactTime($minutes);

    // An hour rule behind each lane. Without it a bar is a coloured rectangle
    // you cannot read a time off, and there is no room for an axis per column.
    $gridlines = sprintf(
        'background-image:repeating-linear-gradient(90deg,transparent 0,transparent calc(100%%/%1$d - 1px),rgba(148,163,184,.28) calc(100%%/%1$d - 1px),rgba(148,163,184,.28) calc(100%%/%1$d))',
        max(1, (int) round($span / 60))
    );

    // One hue per room so a person's bar is the same colour everywhere they
    // appear — the by-room and by-teacher views have to be readable together.
    $palette = ['#2c6e63', '#3d6ea5', '#8a5a9e', '#b3452e', '#4f7a3a', '#a06a2c'];
    $roomColour = collect($rooms)->mapWithKeys(fn ($room, $i) => [$room => $palette[$i % count($palette)]]);

    $monday = \Illuminate\Support\Carbon::parse($weekStart);
    $hours = range((int) ceil($open / 60), (int) floor($close / 60));
@endphp

@section('content')
@php
    $last = $monday->copy()->addDays(count($dates) - 1);
    $rangeLabel = $monday->format('M j').' – '.($last->isSameMonth($monday) ? $last->format('j') : $last->format('M j'));
    $link = fn (array $extra = []) => route('staff-schedule.index', array_filter(
        array_merge(['week' => $weekStart, 'room' => $room, 'tab' => $tab === 'summary' ? null : $tab], $extra),
        fn ($value) => $value !== null && $value !== ''
    ));
    $dayNumber = array_flip(array_map('strtoupper', \App\Models\Child::WEEKDAYS));
    $plural = fn (int $n, string $word) => $n.' '.\Illuminate\Support\Str::plural($word, $n);
    $hoursLabel = fn (float $hours) => rtrim(rtrim(number_format($hours, 1), '0'), '.');

    // Room colours from the daycare palette: a light fill with its darker
    // text, one pair per room in the order the rooms are listed, and the
    // room's name always printed on the block so colour is never the only
    // thing saying which room it is.
    $roomTones = [
        ['fill' => '#eef1fb', 'text' => '#4c5a9e'],   // Infant
        ['fill' => '#e6f4f1', 'text' => '#1f6f5f'],   // Transition
        ['fill' => '#dcebfa', 'text' => '#1b5e91'],   // Toddler
        ['fill' => '#f2f4f9', 'text' => '#1c2130'],   // PreK
        ['fill' => '#fef3c7', 'text' => '#b45309'],   // UPK-4
        ['fill' => '#f3ecf7', 'text' => '#6b4a8a'],   // School Age
    ];
    $tone = function (?string $name) use ($rooms, $roomTones) {
        $index = array_search($name, $rooms, true);

        return $index === false ? ['fill' => '#f2f4f9', 'text' => '#5f6676'] : $roomTones[$index % count($roomTones)];
    };

    // Who is where, by day and room — the one fact every tab reads.
    $attends = fn ($child, string $dayCode) => ($days = $child->scheduleDays()) === null || in_array($dayNumber[$dayCode] ?? 0, $days, true);
    $childrenOn = fn (string $dayCode, string $roomName) => $children->filter(fn ($child) => $child->classroom === $roomName && $attends($child, $dayCode));
    $staffOn = fn (string $dayCode, string $roomName) => $shifts->where('day', $dayCode)->where('classroom', $roomName)->pluck('user_id')->unique()->count();

    $problems = [];
    foreach ($dates as $dayCode => $date) {
        if (isset($closures[$date->toDateString()])) continue;
        foreach ($rooms as $roomName) {
            $booked = $childrenOn($dayCode, $roomName);
            if ($booked->isNotEmpty() && $staffOn($dayCode, $roomName) === 0) {
                $problems[] = ['date' => $date, 'day' => $dayCode, 'room' => $roomName, 'children' => $booked];
            }
        }
    }

    $scheduledStaff = $staff->filter(fn ($person) => $shifts->where('user_id', $person->id)->isNotEmpty());
    $totalHours = $shifts->sum(fn ($shift) => $shift->minutes()) / 60;
    $attendanceDays = $children->sum(fn ($child) => collect($dates)->keys()->filter(fn ($dayCode) => $attends($child, $dayCode))->count());
    $roomsInUse = collect($rooms)->filter(fn ($roomName) => $shifts->where('classroom', $roomName)->isNotEmpty()
        || collect($dates)->keys()->contains(fn ($dayCode) => $childrenOn($dayCode, $roomName)->isNotEmpty()))->values();

    // The Staff and Students tabs, narrowed to one room when one is chosen.
    $roster = $staff->filter(fn ($person) => $room === null || $shifts->where('user_id', $person->id)->where('classroom', $room)->isNotEmpty());
    $pupils = $children->filter(fn ($child) => $room === null || $child->classroom === $room);

    $chip = 'inline-flex min-h-[44px] items-center rounded-full px-4 text-sm font-semibold transition';
@endphp

<div x-data="{ tab: @js($tab), weekends: false, search: '' }" class="space-y-4">

    {{-- Header card: where in the calendar, and the one primary action. --}}
    <section class="glass-card rounded-2xl px-6 py-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:text-la-faint">Little Angels Day Care</p>
                <h1 class="mt-1 text-xl font-bold tracking-tight text-la-ink dark:text-white">Weekly schedule</h1>
                <p class="mt-1 hidden text-sm text-la-muted print:block">{{ $monday->format('l, M j') }} – {{ $last->format('l, M j, Y') }}{{ $room ? ' · '.$room : '' }}</p>
            </div>

            <div class="no-print flex flex-wrap items-center gap-2">
                <div class="inline-flex min-h-[44px] items-center rounded-full border border-la-border dark:border-white/10">
                    <a href="{{ $link(['week' => $monday->copy()->subWeek()->toDateString()]) }}" aria-label="Previous week"
                       class="grid h-11 w-11 place-items-center rounded-full text-la-muted hover:bg-la-well dark:text-slate-300 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <form method="GET" class="contents">
                        @if($room)<input type="hidden" name="room" value="{{ $room }}">@endif
                        @if($tab !== 'summary')<input type="hidden" name="tab" value="{{ $tab }}">@endif
                        <label onclick="try { this.querySelector('input').showPicker() } catch (e) {}"
                               class="cursor-pointer px-2 text-sm font-semibold tabular-nums text-la-ink dark:text-white">
                            {{ $rangeLabel }}
                            <input type="date" name="week" value="{{ $weekStart }}" onchange="this.form.submit()" class="sr-only" aria-label="Jump to a week">
                        </label>
                    </form>
                    <a href="{{ $link(['week' => $monday->copy()->addWeek()->toDateString()]) }}" aria-label="Next week"
                       class="grid h-11 w-11 place-items-center rounded-full text-la-muted hover:bg-la-well dark:text-slate-300 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <a href="{{ $link(['week' => \App\Models\StaffScheduleWeek::startOf(today()->toDateString())]) }}"
                   class="{{ $chip }} bg-la-navpill text-la-link hover:bg-indigo-200 dark:bg-la-accent-soft0/20 dark:text-indigo-200">This week</a>

                <button type="button" onclick="window.print()" x-show="tab !== 'summary'" x-cloak
                        class="{{ $chip }} border border-la-border text-la-ink hover:bg-la-well dark:border-white/10 dark:text-white dark:hover:bg-white/10">Print</button>

                {{-- The only primary button. It rebuilds the week from the rules
                     on each staff record, and asks first in the app's own dialog
                     rather than the browser's. --}}
                <div x-data="{ confirming: false }" @keydown.escape.window="confirming = false">
                    <form method="POST" action="{{ route('staff-schedule.generate') }}" x-ref="generate">
                        @csrf
                        <input type="hidden" name="week" value="{{ $weekStart }}">
                        <button type="button" @click="confirming = true"
                                class="{{ $chip }} bg-la-accent text-white shadow-sm hover:bg-[#174f7a]">+ Generate schedule</button>
                    </form>

                    {{-- Teleported to the body: the header card carries a backdrop blur,
                         which makes a fixed overlay inside it fixed to the card rather
                         than the screen, and the dialog came up clipped to the header. --}}
                    <template x-teleport="body">
                    <div x-show="confirming" x-cloak x-transition.opacity
                         class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/70 p-5 backdrop-blur-sm"
                         @click="confirming = false">
                        <div @click.stop x-show="confirming" x-transition.scale.origin.center
                             role="alertdialog" aria-modal="true" aria-labelledby="generate-title"
                             class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
                            <header class="flex items-start gap-3.5 px-6 pt-6">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full {{ $shifts->isEmpty() ? 'bg-la-navpill text-la-link' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200' }}">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <h2 id="generate-title" class="text-[15px] font-bold leading-snug text-la-ink dark:text-white">
                                        {{ $shifts->isEmpty() ? 'Generate this week?' : 'Regenerate this week?' }}
                                    </h2>
                                    <p class="mt-1 text-[12.5px] leading-relaxed text-la-muted dark:text-la-faint">
                                        {{ $monday->format('D M j') }} – {{ $last->format('D M j, Y') }}, built from the rules on each staff record.
                                    </p>
                                </div>
                            </header>
                            @if($shifts->isEmpty())
                                <p class="mx-6 mt-4 rounded-xl bg-la-well px-3.5 py-2.5 text-[12px] leading-relaxed text-la-muted dark:bg-white/5 dark:text-slate-300">
                                    There is no schedule for this week yet, so nothing is replaced.
                                </p>
                            @else
                                <p class="mx-6 mt-4 rounded-xl bg-amber-50 px-3.5 py-2.5 text-[12px] leading-relaxed text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                                    The {{ $plural($shifts->count(), 'shift') }} on this week, including any moved by hand, {{ $shifts->count() === 1 ? 'is' : 'are' }} replaced with what the current rules produce. This cannot be undone.
                                </p>
                            @endif
                            <footer class="mt-5 flex items-center justify-end gap-2 border-t border-la-border px-6 py-4 dark:border-white/10">
                                <button type="button" @click="confirming = false"
                                        class="{{ $chip }} text-la-muted hover:bg-la-well dark:text-slate-300 dark:hover:bg-white/10">Cancel</button>
                                <button type="button" @click="$refs.generate.submit()" x-ref="go"
                                        x-init="$watch('confirming', open => open && $nextTick(() => $refs.go.focus()))"
                                        class="{{ $chip }} text-white shadow-sm {{ $shifts->isEmpty() ? 'bg-la-accent hover:bg-[#174f7a]' : 'bg-amber-600 hover:bg-amber-700' }}">
                                    {{ $shifts->isEmpty() ? 'Generate schedule' : 'Replace and regenerate' }}
                                </button>
                            </footer>
                        </div>
                    </div>
                    </template>
                </div>
            </div>
        </div>
    </section>

    @if(session('success'))
        {{-- Good news needs no reply: it shows, then gets out of the way. --}}
        <div x-data="{ open: true }" x-init="setTimeout(() => open = false, 6000)" x-show="open" x-transition.opacity
             class="no-print flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-medium text-emerald-800" role="status">
            <span class="flex-1">{{ session('success') }}</span>
            <button type="button" @click="open = false" aria-label="Close" class="grid h-9 w-9 place-items-center rounded-full hover:bg-emerald-100">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    @endif

    @if($mode === 'room')
        {{-- One day by room, with the ratio bars. Reached from the coverage table. --}}
        <a href="{{ $link() }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-la-link hover:underline">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to the week
        </a>
        <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-la-muted">
            @foreach($rooms as $legendRoom)
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full" style="background:{{ $tone($legendRoom)['fill'] }};border:1.5px solid {{ $tone($legendRoom)['text'] }}"></span>{{ $legendRoom }}</span>
            @endforeach
        </div>
        @include('staff-schedule.partials.day-view')
    @else

    {{-- Tabs. The selected one is blue with a blue underline. --}}
    <nav class="no-print flex flex-wrap items-center gap-1 border-b border-la-border px-1 dark:border-white/10" role="tablist" aria-label="Schedule views">
        @foreach(['summary' => ['Summary', null], 'staff' => ['Staff', $roster->count()], 'students' => ['Students', $pupils->count()]] as $key => [$label, $count])
            <button type="button" role="tab" @click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-la-accent text-la-accent' : 'border-transparent text-la-muted hover:text-la-ink dark:text-la-faint dark:hover:text-white'"
                    class="-mb-px inline-flex min-h-[44px] items-center gap-2 border-b-2 px-4 text-sm font-semibold transition">
                {{ $label }}
                @if($count !== null)<span class="rounded-full bg-la-well px-2 py-0.5 text-[11px] font-semibold text-la-muted dark:bg-white/10 dark:text-slate-300">{{ $count }}</span>@endif
            </button>
        @endforeach
    </nav>

    {{-- ============================== SUMMARY ============================== --}}
    <div x-show="tab === 'summary'" x-cloak role="tabpanel" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $cards = [
                    ['Staff scheduled', $scheduledStaff->count(), $hoursLabel($totalHours).' hours this week', false],
                    ['Students booked', $children->count(), $plural($attendanceDays, 'attendance day'), false],
                    ['Rooms in use', $roomsInUse->count(), $roomsInUse->isEmpty() ? 'No room has anyone in it' : $roomsInUse->implode(', '), false],
                    ['Needs attention', count($problems), 'Rooms with children and no staff', count($problems) > 0],
                ];
            @endphp
            @foreach($cards as [$label, $value, $detail, $alert])
                <div class="rounded-2xl border px-5 py-4 {{ $alert ? 'border-rose-200 bg-rose-50 dark:border-rose-500/30 dark:bg-rose-500/10' : 'glass-card' }}">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] {{ $alert ? 'text-rose-700 dark:text-rose-300' : 'text-la-muted dark:text-la-faint' }}">{{ $label }}</p>
                    <p class="mt-1.5 text-3xl font-bold tabular-nums {{ $alert ? 'text-rose-700 dark:text-rose-200' : 'text-la-ink dark:text-white' }}">{{ $value }}</p>
                    <p class="mt-1 truncate text-xs {{ $alert ? 'text-rose-700/80 dark:text-rose-200/80' : ($label === 'Rooms in use' ? 'text-la-accent dark:text-indigo-300' : 'text-la-muted dark:text-la-faint') }}" title="{{ $detail }}">{{ $detail }}</p>
                </div>
            @endforeach
        </div>

        @if($shifts->isEmpty())
            <section class="glass-card rounded-2xl px-6 py-10 text-center">
                <p class="text-sm text-la-muted dark:text-la-faint">No staff schedule for this week yet. Press <b>Generate schedule</b> to build one from the rules on each staff record.</p>
            </section>
        @endif

        @if(count($problems))
            <section class="glass-card rounded-2xl p-5">
                <h2 class="text-sm font-bold text-la-ink dark:text-white">Needs attention</h2>
                <ul class="mt-3 space-y-2">
                    @foreach($problems as $problem)
                        <li class="flex flex-wrap items-center gap-3 rounded-2xl bg-la-well px-4 py-3 dark:bg-white/5">
                            <span class="h-2 w-2 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-la-ink dark:text-white">{{ $problem['date']->format('l, M j') }} — {{ $problem['room'] }} has no staff</p>
                                <p class="text-xs text-la-muted dark:text-la-faint">
                                    @php $first = $problem['children']->first(); @endphp
                                    {{ $first->displayName() }}{{ $problem['children']->count() > 1 ? ' and '.$plural($problem['children']->count() - 1, 'other') : '' }}
                                    {{ $problem['children']->count() > 1 ? 'are' : 'is' }} booked{{ $first->scheduleLabel() ? ' '.$first->scheduleLabel() : '' }}.
                                </p>
                            </div>
                            <a href="{{ $link(['room' => $problem['room'], 'tab' => 'staff']) }}"
                               class="inline-flex min-h-[44px] items-center rounded-full bg-white px-4 text-xs font-semibold text-la-ink shadow-sm ring-1 ring-la-border hover:bg-la-well dark:bg-slate-800 dark:text-white dark:ring-white/10">Assign staff</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="glass-card rounded-2xl p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-bold text-la-ink dark:text-white">Room coverage</h2>
                <p class="text-xs text-la-muted dark:text-la-faint">Staff and children per room, {{ $monday->format('l') }} to {{ $last->format('l') }}. Click a day to see its ratio bars.</p>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[640px] border-separate border-spacing-y-1.5 text-left">
                    <thead>
                        <tr>
                            <th class="w-36 px-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted"></th>
                            @foreach($dates as $dayCode => $date)
                                <th class="px-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:text-la-faint">
                                    <a href="{{ route('staff-schedule.index', ['week' => $weekStart, 'mode' => 'room', 'day' => $dayCode]) }}" class="hover:text-la-accent">{{ $date->format('D j') }}</a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rooms as $roomName)
                            @php $t = $tone($roomName); @endphp
                            <tr>
                                <td class="px-2 py-1">
                                    <span class="inline-flex items-center gap-2 text-xs font-bold text-la-ink dark:text-white">
                                        <span class="h-2.5 w-2.5 rounded-full" style="background:{{ $t['text'] }}" aria-hidden="true"></span>{{ $roomName }}
                                    </span>
                                </td>
                                @foreach($dates as $dayCode => $date)
                                    @php
                                        $closed = $closures[$date->toDateString()] ?? null;
                                        $kids = $childrenOn($dayCode, $roomName)->count();
                                        $adults = $staffOn($dayCode, $roomName);
                                    @endphp
                                    <td class="px-1 py-0.5">
                                        @if($closed)
                                            <span class="block rounded-xl border border-dashed border-la-border px-3 py-2 text-xs text-la-muted dark:border-white/15 dark:text-la-faint" title="{{ $closed }}">Closed</span>
                                        @elseif($kids === 0 && $adults === 0)
                                            <span class="block rounded-xl border border-dashed border-la-border px-3 py-2 text-xs text-la-muted dark:border-white/15 dark:text-la-faint">Closed</span>
                                        @elseif($adults === 0)
                                            <span class="block rounded-xl bg-rose-100 px-3 py-2 text-xs font-semibold text-rose-700 dark:bg-rose-500/15 dark:text-rose-200">No staff · {{ $plural($kids, 'child') }}</span>
                                        @else
                                            <span class="block rounded-xl px-3 py-2 text-xs font-medium" style="background:{{ $t['fill'] }};color:{{ $t['text'] }}">{{ $adults }} staff · {{ $kids === 0 ? 'no children' : $plural($kids, 'child') }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ============================== TOOLBAR (Staff + Students) ============================== --}}
    <div x-show="tab !== 'summary'" x-cloak class="no-print flex flex-wrap items-center gap-2 glass-card rounded-2xl px-4 py-3">
        <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Room filter">
            <a href="{{ $link(['room' => null]) }}" x-bind:href="'{{ $link(['room' => null, 'tab' => 'TAB']) }}'.replace('TAB', tab)"
               class="{{ $chip }} {{ $room === null ? 'bg-la-accent text-white' : 'bg-la-well text-la-muted hover:bg-la-border dark:bg-white/10 dark:text-slate-300' }}">All rooms</a>
            @foreach($rooms as $roomName)
                <a href="{{ $link(['room' => $roomName]) }}" x-bind:href="'{{ $link(['room' => $roomName, 'tab' => 'TAB']) }}'.replace('TAB', tab)"
                   class="{{ $chip }} {{ $room === $roomName ? 'bg-la-accent text-white' : 'bg-la-well text-la-muted hover:bg-la-border dark:bg-white/10 dark:text-slate-300' }}">{{ $roomName }}</a>
            @endforeach
        </div>
        <label class="ml-auto inline-flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-la-muted dark:text-slate-300">
            <input type="checkbox" x-model="weekends" class="h-4 w-4 rounded border-la-border text-la-accent focus:ring-la-accent"> Show weekends
        </label>
        <label class="relative block">
            <span class="sr-only">Search names</span>
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-la-muted" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
            <input type="search" x-model.trim="search" placeholder="Search names"
                   class="min-h-[44px] w-48 rounded-full border border-la-border bg-white pl-9 pr-4 text-sm text-la-ink placeholder:text-la-faint focus:border-la-accent focus:ring-0 dark:border-white/10 dark:bg-slate-950 dark:text-white">
        </label>
    </div>

    {{-- ============================== STAFF ============================== --}}
    <section x-show="tab === 'staff'" x-cloak role="tabpanel"
             x-data="{ get shown() { return Array.from($el.querySelectorAll('[data-name]')).filter(row => row.dataset.name.includes(search.toLowerCase())).length } }"
             class="overflow-hidden glass-card rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[840px] border-separate border-spacing-0 text-left">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 w-56 border-b border-la-border bg-white px-5 py-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:border-white/10 dark:bg-slate-900">Staff</th>
                        <th x-show="weekends" x-cloak class="border-b border-la-border px-3 py-3 text-center text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:border-white/10">Sun {{ $monday->copy()->subDay()->format('j') }}</th>
                        @foreach($dates as $dayCode => $date)
                            <th class="border-b border-la-border px-3 py-3 text-center text-[10px] font-semibold uppercase tracking-[0.14em] {{ $date->isToday() ? 'text-la-accent' : 'text-la-muted' }} dark:border-white/10">{{ $date->format('D j') }}</th>
                        @endforeach
                        <th x-show="weekends" x-cloak class="border-b border-la-border px-3 py-3 text-center text-[10px] font-semibold uppercase tracking-[0.14em] text-la-muted dark:border-white/10">Sat {{ $last->copy()->addDay()->format('j') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roster as $person)
                        @php
                            $mine = $shifts->where('user_id', $person->id);
                            $weekHours = $mine->sum(fn ($s) => $s->minutes()) / 60;
                        @endphp
                        <tr data-name="{{ strtolower($person->name) }}" x-show="'{{ strtolower(e($person->name)) }}'.includes(search.toLowerCase())" class="group">
                            <td class="sticky left-0 z-10 border-b border-la-border bg-white px-5 py-3 align-middle group-hover:bg-la-well dark:border-white/10 dark:bg-slate-900 dark:group-hover:bg-white/5">
                                <div class="flex items-center gap-3">
                                    <x-user-avatar :user="$person" size="h-10 w-10" text="text-xs" class="bg-la-navpill text-la-link" />
                                    <div class="min-w-0">
                                        <a href="{{ route('teachers.show', $person) }}" class="block truncate text-sm font-semibold text-la-ink hover:text-la-accent dark:text-white">{{ $person->name }}</a>
                                        <span class="text-xs tabular-nums text-la-muted dark:text-la-faint">{{ $hoursLabel($weekHours) }} hrs this week</span>
                                    </div>
                                </div>
                            </td>
                            <td x-show="weekends" x-cloak class="border-b border-la-border px-3 py-3 text-center text-xs text-la-muted dark:border-white/10">Off</td>
                            @foreach($dates as $dayCode => $date)
                                @php
                                    $away = $leave[$person->id][$date->toDateString()] ?? null;
                                    $today = $mine->where('day', $dayCode)->when($room !== null, fn ($c) => $c->where('classroom', $room))->sortBy('starts_at');
                                @endphp
                                <td class="border-b border-la-border p-1.5 align-top group-hover:bg-la-well/60 dark:border-white/10 dark:group-hover:bg-white/5">
                                    <div class="space-y-1">
                                        @if($away)
                                            <div class="rounded-xl border border-dashed border-emerald-300 bg-emerald-50 px-3 py-2 dark:border-emerald-500/40 dark:bg-emerald-500/10" title="{{ $away->label() }} · approved leave · {{ $away->hours_per_day }}h">
                                                <p class="text-[11px] font-bold leading-tight text-emerald-800 dark:text-emerald-200">{{ $away->label() }}</p>
                                                <p class="text-[11px] leading-tight text-emerald-700/80 dark:text-emerald-300/80">Approved leave</p>
                                            </div>
                                        @endif
                                        @forelse($today as $shift)
                                            @php $t = $tone($shift->classroom); @endphp
                                            <div class="rounded-xl px-3 py-2 {{ $shift->isCover() ? 'border border-dashed border-current/30' : '' }}"
                                                 style="background:{{ $t['fill'] }};color:{{ $t['text'] }}"
                                                 title="{{ $shift->classroom }} · {{ $shift->label() }} · {{ $shift->hours() }}h{{ $shift->isCover() ? ' · cover' : '' }}">
                                                <p class="truncate text-[11px] font-bold leading-tight">{{ $shift->classroom }}@if($shift->isCover())<span class="font-medium opacity-70"> · cover</span>@endif</p>
                                                <p class="whitespace-nowrap text-[11px] leading-tight tabular-nums opacity-90">{{ $compact($shift->starts_at) }} → {{ $compact($shift->ends_at) }}</p>
                                            </div>
                                        @empty
                                            @unless($away)<p class="px-3 py-2 text-center text-xs text-la-muted dark:text-la-muted">Off</p>@endunless
                                        @endforelse
                                    </div>
                                </td>
                            @endforeach
                            <td x-show="weekends" x-cloak class="border-b border-la-border px-3 py-3 text-center text-xs text-la-muted dark:border-white/10">Off</td>
                        </tr>
                    @empty
                    @endforelse
                    <tr x-show="shown === 0" x-cloak>
                        <td colspan="{{ count($dates) + 3 }}" class="px-5 py-8 text-center text-sm text-la-muted dark:text-la-faint">No one matches these filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- ============================== STUDENTS ============================== --}}
    <section x-show="tab === 'students'" x-cloak role="tabpanel"
             x-data="{ get shown() { return Array.from($el.querySelectorAll('[data-name]')).filter(row => row.dataset.name.includes(search.toLowerCase())).length } }"
             class="glass-card rounded-2xl">
        <ul class="divide-y divide-la-border dark:divide-white/10">
            @foreach($pupils as $child)
                @php
                    $t = $tone($child->classroom);
                    $attendingDays = collect($dates)->keys()->filter(fn ($dayCode) => $attends($child, $dayCode))->count();
                @endphp
                <li data-name="{{ strtolower($child->displayName()) }}" x-show="'{{ strtolower(e($child->displayName())) }}'.includes(search.toLowerCase())"
                    class="flex flex-wrap items-center gap-x-5 gap-y-3 px-5 py-3.5">
                    <div class="flex min-w-[220px] flex-1 items-center gap-3">
                        <x-child-avatar :child="$child" size="h-10 w-10" />
                        <div class="min-w-0">
                            <a href="{{ route('children.show', $child) }}" class="block truncate text-sm font-semibold text-la-ink hover:text-la-accent dark:text-white">{{ $child->displayName() }}</a>
                            <span class="text-xs text-la-muted dark:text-la-faint">{{ $plural($attendingDays, 'day') }} this week</span>
                        </div>
                    </div>
                    <span class="inline-flex min-h-[32px] items-center gap-2 rounded-full px-3 text-xs font-semibold" style="background:{{ $t['fill'] }};color:{{ $t['text'] }}">
                        {{ $child->classroom ?: 'No room' }}
                        @if($child->scheduleLabel())<span class="font-medium opacity-80 tabular-nums">{{ $child->scheduleLabel() }}</span>@endif
                    </span>
                    <div class="flex items-center gap-1" role="list" aria-label="Days attending">
                        <span x-show="weekends" x-cloak role="listitem" class="grid h-8 w-10 place-items-center rounded-full border border-dashed border-la-border text-[11px] font-semibold text-la-muted line-through dark:border-white/15">Sun</span>
                        @foreach($dates as $dayCode => $date)
                            @if($attends($child, $dayCode))
                                <span role="listitem" class="grid h-8 w-10 place-items-center rounded-full bg-la-accent text-[11px] font-semibold text-white" title="Attends {{ $date->format('l') }}">{{ $date->format('D') }}</span>
                            @else
                                <span role="listitem" class="grid h-8 w-10 place-items-center rounded-full border border-dashed border-la-border text-[11px] font-semibold text-la-muted line-through dark:border-white/15" title="Not attending {{ $date->format('l') }}">{{ $date->format('D') }}</span>
                            @endif
                        @endforeach
                        <span x-show="weekends" x-cloak role="listitem" class="grid h-8 w-10 place-items-center rounded-full border border-dashed border-la-border text-[11px] font-semibold text-la-muted line-through dark:border-white/15">Sat</span>
                    </div>
                </li>
            @endforeach
            <li x-show="shown === 0" x-cloak class="px-5 py-8 text-center text-sm text-la-muted dark:text-la-faint">No one matches these filters.</li>
        </ul>
    </section>

    @endif

    @if($week)
        <p class="no-print px-1 text-xs text-la-muted dark:text-la-faint">
            Generated {{ $week->generated_at?->diffForHumans() }}@if($week->generatedBy) by {{ $week->generatedBy->name }}@endif.
            Rules changed since then are not reflected until it is generated again.
        </p>
    @endif

    @if($week && filled($week->warnings))
        @php $count = count($week->warnings); @endphp
        {{-- The warnings fold away on their own after a short count, so a
             week with forty-nine of them does not bury the schedule under
             them on every visit. The count is shown and the mouse pauses it;
             what folded away stays one click from coming back. --}}
        <div class="no-print" x-data="{ open: true, left: 20, timer: null,
                       start() { this.stop(); this.timer = setInterval(() => { if (--this.left <= 0) this.close() }, 1000) },
                       stop() { clearInterval(this.timer); this.timer = null },
                       close() { this.stop(); this.open = false },
                       reopen() { this.open = true; this.left = 0 } }"
             x-init="start()">
            <section x-show="open" x-transition.opacity @mouseenter="stop()" @mouseleave="left > 0 && start()" @focusin="stop()"
                     class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/40 dark:bg-amber-500/10" role="status">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ $count }} thing{{ $count === 1 ? '' : 's' }} to look at</h2>
                        <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/70">
                            The schedule was still saved. These are the constraints it could not satisfy — fix the rule, the staffing, or accept it.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span x-show="left > 0" class="inline-flex min-h-[44px] items-center gap-1.5 text-xs font-semibold tabular-nums text-amber-800/80 dark:text-amber-200/70" aria-live="off">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                            Closes in <span x-text="left"></span>s
                        </span>
                        <button type="button" x-show="left > 0" @click="stop(); left = 0"
                                class="inline-flex min-h-[44px] items-center rounded-full border border-amber-300 px-3.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-500/40 dark:text-amber-100 dark:hover:bg-amber-500/20">Keep open</button>
                        <button type="button" @click="close()" aria-label="Close"
                                class="grid h-11 w-11 place-items-center rounded-full text-amber-800 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-500/20">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                </div>
                <ul class="mt-3 space-y-1.5 text-sm text-amber-900 dark:text-amber-100">
                    @foreach($week->warnings as $warning)
                        <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $warning }}</span></li>
                    @endforeach
                </ul>
            </section>
            <button type="button" x-show="! open" x-cloak @click="reopen()"
                    class="inline-flex min-h-[44px] items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
                <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                {{ $count }} thing{{ $count === 1 ? '' : 's' }} to look at
            </button>
        </div>
    @elseif($week)
        <p class="no-print rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
            Every hard rule and every room ratio is satisfied this week.
        </p>
    @endif
</div>
@endsection
