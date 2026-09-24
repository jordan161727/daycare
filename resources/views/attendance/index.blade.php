@extends('layouts.app')
@section('title', 'Class Attendance')
@section('content')
{{-- The two screens a family stands in front of. See components/kids-background. --}}
<x-kids-background />

@php
    /*
     * The four states of a sign-in cell, in one place.
     *
     * boxClass() paints the grid from this map and the key above it paints its
     * chips from it, so the strip beside the sheet cannot drift away from the
     * sheet itself.
     *
     * The cell is the time, not a word: a filled cell answers "what time did
     * they come in" without a second glance, and the two states that matter —
     * arrived as expected, and arrived on a day nobody planned for — are told
     * apart by a fill rather than by reading. A cell still waiting reads
     * "expected" in a dashed outline, which is a promise rather than a button.
     * A day nobody is booked for is a dot: it is the commonest cell on a sheet
     * sixty rows deep, and anything louder would shout over the sign-ins.
     */
    $boxStates = [
        // Component classes (resources/css/app.css, "The attendance cell"),
        // shared by the grid and this key so the two cannot disagree.
        'present' => [
            'label' => 'signed in',
            'swatch' => '8:42 AM',
            'classes' => 'att-time',
        ],
        'unplanned' => [
            'label' => 'unplanned, still billable',
            'swatch' => '9:05 AM',
            'classes' => 'att-time att-unplanned',
        ],
        'scheduled' => [
            'label' => 'scheduled, not in yet',
            // An empty box. The word came off the cells: the box is the mark.
            'swatch' => '',
            'classes' => 'att-expected',
        ],
        // The same dashed box as the state above, with the hour a day still
        // to come is agreed for inside it. The box is what says "coming";
        // the hour only says when, and it is pointedly not the emerald of
        // an arrival — which is what this key exists to stop it reading as.
        'due' => [
            'label' => 'coming, at the hour agreed — not an arrival',
            'swatch' => '8:00a',
            'classes' => 'att-expected att-due',
        ],
        'off' => [
            'label' => 'not scheduled — tap to sign in anyway',
            'swatch' => '·',
            'classes' => 'att-none',
        ],
        /*
         * A day the centre was shut. A rule rather than a dot, in rose, so the
         * mark and the reason for it in the header are visibly the same fact.
         * Still tappable: a closed day that somebody did open takes a sign-in,
         * and the record has to be able to say so.
         */
        'closed' => [
            'label' => 'centre closed',
            'swatch' => '—',
            'classes' => 'att-closed',
        ],    ];
@endphp
{{-- The taps on the sheet are caught here, once, rather than bound to each of
     the four hundred boxes below — see cellAttrs/onCellClick. Both handlers
     look for a box and return immediately when the press was anywhere else,
     so the toolbar and the search box are unaffected. --}}
<div x-data="attendanceApp()" @attendance-key.window="toggleKey()" @keydown.escape.window="recentOpen = false" @pointermove.window="paintAt($event)" @pointerup.window="endPaint()" @pointercancel.window="endPaint()" @click="onCellClick($event)" @keydown="onCellKey($event)">
    {{-- What the last copy did. Without this the page redirects back looking
         untouched, and a copy that worked is indistinguishable from one that
         never ran. --}}
    @if(session('success') || session('warning'))
        @php($isWarning = (bool) session('warning'))
        <div
            x-data="{ show: true }"
            x-show="show"
            x-transition
            class="mb-3 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm font-medium {{ $isWarning
                ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200'
                : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' }}"
            role="status"
        >
            <span class="text-base leading-none">{{ $isWarning ? '!' : '✓' }}</span>
            <span class="flex-1">{{ session('warning') ?: session('success') }}</span>
            <button type="button" @click="show = false" class="shrink-0 rounded-md px-1.5 leading-none opacity-60 transition hover:opacity-100" aria-label="Dismiss">✕</button>
        </div>
    @endif

    <div class="flex flex-col gap-4">
        <section class="min-w-0 flex-1">
            <div class="glass-card rounded-2xl px-3 py-2.5">
                {{-- One line: what page this is, which week, how the week stands,
                     and the controls used on every visit. What is left behind the
                     "…" is the handful that are not — a toolbar showing every
                     control equally makes the ones that matter no easier to find
                     than the rest. --}}
                @php($prevWeek = \Illuminate\Support\Carbon::parse($weekStartDate)->subWeek()->toDateString())
                @php($nextWeek = \Illuminate\Support\Carbon::parse($weekStartDate)->addWeek()->toDateString())
                @php($thisWeek = \Illuminate\Support\Carbon::today()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString())
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h1 class="text-base font-bold tracking-tight sm:text-lg">Attendance</h1>

                    {{-- The week as one control: a step either side of the range
                         it is showing, rather than two buttons and a label apart.
                         Hairline dividers rather than gaps, so the three read as
                         segments of one object.

                         The chevrons are drawn rather than typed. As the
                         characters ‹ › they came out at whatever weight and
                         baseline the font had for them, which is not the same
                         font on a tablet as on the office machine. --}}
                    <div class="flex items-center rounded-full border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-800">
                        <a href="{{ route('attendance.index', ['date' => $prevWeek]) }}" class="grid h-7 w-8 place-items-center rounded-l-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" title="Week of {{ \Illuminate\Support\Carbon::parse($prevWeek)->format('M j') }}" aria-label="Previous week">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                        {{-- The dates open a month. Hovering it picks a whole
                             week rather than a day: this page is addressed by a
                             Monday, so a Wednesday and the Tuesday beside it are
                             the same answer, and a day-picker that pretended
                             otherwise was asking a question it then ignored.

                             Teleported to the body, like every other panel here.
                             The toolbar is a glass card, and a card carrying a
                             backdrop-blur clips what hangs out of it. --}}
                        <div x-data="weekPicker()" @keydown.escape.window="open = false" @scroll.window="open && place()" @resize.window="open && place()" class="contents">
                            <button type="button" x-ref="trigger" @click.stop="toggle()" :aria-expanded="open" aria-haspopup="dialog" class="flex items-center gap-1.5 border-x border-slate-200 px-3 py-1 text-xs font-semibold tabular-nums transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10" title="Pick a week">
                                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                {{ $weekDates->first()->format('M j') }} &ndash; {{ $weekDates->last()->format($weekDates->first()->format('M') === $weekDates->last()->format('M') ? 'j' : 'M j') }}
                            </button>

                            <template x-teleport="body">
                                <div x-show="open" x-cloak x-transition.opacity.duration.120ms @click.outside="open = false" :style="`top: ${y}px; left: ${x}px`" role="dialog" aria-label="Pick a week" class="fixed z-50 w-[19rem] rounded-2xl border border-slate-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-slate-900">

                                    <div class="flex items-center justify-between px-1">
                                        <button type="button" @click="step(-1)" class="grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Previous month">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                                        </button>
                                        <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="monthLabel"></span>
                                        <button type="button" @click="step(1)" class="grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Next month">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                        </button>
                                    </div>

                                    {{-- Sunday first, because that is how a wall
                                         calendar reads, even though the sheet
                                         itself starts on the Monday beside it. --}}
                                    <div class="mt-2 grid grid-cols-7 text-center text-[0.7333rem] font-semibold text-slate-400">
                                        <template x-for="(name, index) in ['S','M','T','W','T','F','S']" :key="index"><span x-text="name" class="py-1"></span></template>
                                    </div>

                                    {{-- No gap between the columns: the highlight
                                         is a bar across the row, and a gap would
                                         cut it into seven. --}}
                                    <div class="grid grid-cols-7" @mouseleave="hover = null">
                                        <template x-for="cell in cells" :key="cell.key">
                                            <button type="button"
                                                    @mouseenter="hover = cell.monday"
                                                    @focus="hover = cell.monday"
                                                    @click="go(cell.monday)"
                                                    :class="[
                                                        lit(cell) ? 'bg-sky-100 text-sky-800 dark:bg-sky-400/20 dark:text-sky-200' : 'text-slate-700 dark:text-slate-300',
                                                        lit(cell) && cell.first ? 'rounded-l-full' : '',
                                                        lit(cell) && cell.last ? 'rounded-r-full' : '',
                                                        cell.outside ? 'opacity-40' : '',
                                                        cell.today ? 'font-bold underline decoration-2 underline-offset-2' : 'font-semibold',
                                                    ]"
                                                    class="py-1.5 text-xs tabular-nums transition"
                                                    x-text="cell.day"></button>
                                        </template>
                                    </div>

                                    {{-- The week under the pointer, named before
                                         the press rather than after it. --}}
                                    <div class="mt-2 flex items-center justify-between border-t border-slate-200/70 pt-2 text-xs dark:border-white/10">
                                        <button type="button" @click="go(today)" class="font-semibold text-sky-700 transition hover:underline dark:text-sky-300">Jump to this week</button>
                                        <span class="font-medium text-slate-500 dark:text-slate-400" x-text="'Week of ' + label(hover || current)"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <a href="{{ route('attendance.index', ['date' => $nextWeek]) }}" class="grid h-7 w-8 place-items-center rounded-r-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" title="Week of {{ \Illuminate\Support\Carbon::parse($nextWeek)->format('M j') }}" aria-label="Next week">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </a>
                    </div>
                    {{-- Beside the dates, always: on another week it is the way
                         back, and on this one it is the label that says the dates
                         beside it are the current week rather than one you have
                         stepped to and forgotten. The arrow belongs only to the
                         first — there is nowhere to return to from today. --}}
                    @if($weekStartDate === $thisWeek)
                        <span class="rounded-full bg-sky-100 px-4 py-1.5 text-xs font-semibold text-sky-700 dark:bg-sky-400/15 dark:text-sky-300" aria-current="date">This week</span>
                    @else
                        <a href="{{ route('attendance.index') }}" class="flex items-center gap-1.5 rounded-full bg-sky-100 px-4 py-1.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-200 dark:bg-sky-400/15 dark:text-sky-300 dark:hover:bg-sky-400/25">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>
                            This week
                        </a>
                    @endif

                    {{-- Everything in here is 26px tall and centred on one
                         line. When the row runs out of width it wraps as a
                         block, still flush right, rather than breaking up. --}}
                    <div class="ml-auto flex min-w-0 flex-1 flex-wrap items-center justify-end gap-1.5">
                        {{-- Searching is the way a big roll is used, so the box is on
                             the top line rather than below the filters. --}}
                        {{-- The elastic one. Everything else in this row has a
                             width it needs; a search box has none, so it takes
                             what the buttons leave and gives it back first. --}}
                        <label x-show="view === 'signin'" class="relative hidden min-w-0 flex-1 basis-40 sm:block sm:max-w-[13rem]">
                            <svg class="pointer-events-none absolute left-2.5 top-1.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                            <input x-model="search" class="w-full rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:ring-2 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800" placeholder="Search name or LAN">
                        </label>

                        {{-- The week for the clipboard. Only once the week exists:
                             a page of nothing is not a register, and printing must
                             never be what builds the week. Opens in its own tab
                             with the print dialog up, so the sheet here is not
                             navigated away from mid-morning. --}}
                        {{-- The week or the month it sits in.

                             Asked here rather than on the printed page, because
                             that page opens the print dialog the moment it
                             loads — arriving at the wrong range would mean
                             cancelling a dialog to fix it. The sheet itself
                             carries a way to switch for anyone who lands there
                             anyway.

                             Lifted out of the card like every other panel on
                             this page: a glass card clips what hangs out of it. --}}
                        @if($weekIsOpen)
                            <div
                                x-data="{
                                    menu: false,
                                    x: 0,
                                    y: 0,
                                    open() {
                                        const box = this.$refs.printer.getBoundingClientRect();
                                        this.x = Math.max(12, Math.min(box.right - 200, window.innerWidth - 212));
                                        this.y = box.bottom + 6;
                                        this.menu = true;
                                    },
                                }"
                                @keydown.escape.window="menu = false"
                                @scroll.window="menu = false"
                                class="relative shrink-0"
                            >
                                {{-- "Print" on the button, "Print attendance" as
                                     its name: the printer icon carries the rest,
                                     and the row needed the sixty pixels more than
                                     the label did. --}}
                                <button type="button" x-ref="printer" @click.stop="menu ? menu = false : open()" :aria-expanded="menu" aria-haspopup="true" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10" aria-label="Print attendance" title="Print attendance — landscape Letter">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V4h12v5M6 18H4a1 1 0 01-1-1v-6a1 1 0 011-1h16a1 1 0 011 1v6a1 1 0 01-1 1h-2M6 14h12v6H6z"/></svg>
                                    Print
                                </button>

                                <template x-teleport="body">
                                <div x-show="menu" x-cloak x-transition @click.outside="menu = false" :style="`top: ${y}px; left: ${x}px`" class="fixed z-50 w-[13.3333rem] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl dark:border-white/10 dark:bg-slate-900">
                                    @php($printMonth = \Illuminate\Support\Carbon::parse($weekStartDate))
                                    <a href="{{ route('attendance.print', ['date' => $weekStartDate]) }}" target="_blank" rel="noopener" @click="menu = false" class="block px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:hover:bg-white/10">
                                        This week
                                        <span class="mt-0.5 block text-[0.7rem] font-normal text-slate-400">{{ $weekDates->first()->format('M j') }} – {{ $weekDates->last()->format('M j') }} · one page</span>
                                    </a>
                                    <a href="{{ route('attendance.print', ['date' => $weekStartDate, 'range' => 'month']) }}" target="_blank" rel="noopener" @click="menu = false" class="block px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:hover:bg-white/10">
                                        Whole month
                                        <span class="mt-0.5 block text-[0.7rem] font-normal text-slate-400">{{ $printMonth->format('F Y') }} · a page per week</span>
                                    </a>
                                </div>
                                </template>
                            </div>
                        @endif

                        {{-- The count rides on the button, so the number is
                             answerable without opening the drawer at all. --}}
                        <button type="button" x-show="view === 'signin'" @click="recentOpen = true" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10" title="The arrivals recorded today, newest first">
                            <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
                                <span x-show="recent.length > 0" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="recent.length > 0 ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'"></span>
                            </span>
                            Recent
                            <span class="font-mono opacity-60" x-text="recent.length"></span>
                        </button>

                        {{-- Edit unlocks the days already gone in this week, so a
                             child who was here on Monday and never tapped in can
                             be put right. It is a mode rather than a permanent
                             state: the sheet a teacher stands in front of all day
                             offers today and nothing else, because the commonest
                             mistake on a five-column grid is the column next to
                             the one you meant. --}}
                        @if($canAmendAttendance || $canEditSchedule)
                            {{-- Live or Edit, as a switch. The mode is a state the
                                 whole sheet is in — every column changes with it —
                                 and a switch says "in it" or "not" the way a button
                                 labelled Edit never quite did. --}}
                            <div x-show="view === 'signin'" class="att-mode" :data-edit="editing ? 'true' : 'false'">
                                <button type="button" role="switch" class="att-switch" :aria-checked="editing ? 'true' : 'false'" :aria-label="editing ? 'Edit mode' : 'Live mode'" @click="switchMode()">
                                    <span class="att-knob" x-html="editing ? icons.edit : icons.lock"></span>
                                </button>
                                <span class="att-mode-name" x-text="editing ? 'Edit mode' : 'Live mode'"></span>
                            </div>
                        @endif

                        {{-- Parked, not removed: see daycare.schedule_view. --}}
                        @if($canEditSchedule && config('daycare.schedule_view'))
                            <button type="button" @click="switchView(view === 'signin' ? 'schedule' : 'signin')" :class="view === 'schedule' ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-200 text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10'" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition" x-text="view === 'signin' ? 'Schedule' : 'Sign in'"></button>

                        @endif

                        {{-- The rarely-wanted controls, kept but not on show.

                             Fixed, measured off the button, and teleported to the
                             body — the same treatment the legend needs, for the
                             same reason. The toolbar is a glass card, and a card
                             carrying a backdrop-blur clips what hangs out of it:
                             an absolute panel was sliced off partway down, so
                             half of what was in it was there and unreachable.
                             Teleported out there is
                             nothing left to clip it, and "fixed" means the viewport
                             again rather than that card.

                             The trigger stops its own click so the panel's
                             click-outside does not see it — otherwise opening the
                             menu would close it in the same gesture. --}}
                        <div
                            x-data="{
                                menu: false,
                                x: 0,
                                y: 0,
                                place() {
                                    const box = this.$refs.trigger.getBoundingClientRect();
                                    // Held off both edges, so it never opens half
                                    // off-screen on a narrow window.
                                    this.x = Math.max(12, Math.min(box.right - 224, window.innerWidth - 236));
                                    this.y = box.bottom + 6;
                                },
                                toggle() {
                                    if (this.menu) { this.menu = false; return; }
                                    this.place();
                                    this.menu = true;
                                },
                            }"
                            @keydown.escape.window="menu = false"
                            @scroll.window="menu && place()"
                            @resize.window="menu && place()"
                            class="relative shrink-0"
                        >
                            <button type="button" x-ref="trigger" @click.stop="toggle()" :aria-expanded="menu" aria-haspopup="true" class="grid h-[1.7333rem] w-7 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-400 dark:hover:bg-white/10" aria-label="More">…</button>
                            <template x-teleport="body">
                            <div x-show="menu" x-cloak x-transition @click.outside="menu = false" :style="`top: ${y}px; left: ${x}px`" class="fixed z-50 w-56 rounded-xl border border-slate-200 bg-white p-2.5 shadow-xl dark:border-white/10 dark:bg-slate-900">
                                {{-- How names read on this reader's own screens.
                                     The office works from surnames because that is
                                     how the paper file is ordered; the room works
                                     from first names because that is what a child
                                     answers to. Nobody else's sheet changes.

                                     The row order does not follow it: the roll is
                                     sorted by surname because that is how a roll is
                                     found, which is a different question from how a
                                     name reads. --}}
                                @php($nameFormat = auth()->user()->nameFormat())
                                <form method="POST" action="{{ route('profile.name-format') }}" class="space-y-1.5">
                                    @csrf
                                    <span class="block text-[0.7333rem] font-semibold text-slate-500 dark:text-slate-400">Show names as</span>
                                    @foreach(\App\Models\User::NAME_FORMATS as $value => $example)
                                        <button
                                            name="name_format"
                                            value="{{ $value }}"
                                            class="flex w-full items-center gap-2 rounded-lg border px-2 py-1 text-left text-xs transition {{ $nameFormat === $value
                                                ? 'border-indigo-500 bg-indigo-50 font-semibold text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-100'
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10' }}"
                                            @if($nameFormat === $value) aria-current="true" @endif
                                        >
                                            <span class="w-3 shrink-0 text-center">{{ $nameFormat === $value ? '✓' : '' }}</span>
                                            <span>{{ $example }}</span>
                                        </button>
                                    @endforeach
                                </form>
                            </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- A finished week is a record. Say so plainly instead of showing
                     controls that would be refused. --}}
                @if($weekIsFrozen)
                    <div class="mt-2 flex flex-wrap items-center gap-x-2 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[0.7333rem] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <span>🔒 This week has ended — the schedule is locked. Sign-ins already recorded stand as they are.</span>
                    </div>
                @endif

                {{-- Search and room filters on the second line. Hidden while setting
                     the schedule, which always covers the whole centre — a filter
                     sitting there would imply it applies. --}}
                <div x-show="view === 'signin'" class="mt-2.5 flex items-center gap-2 border-t border-slate-200/70 pt-2.5 dark:border-white/10">
                    {{-- Rooms scroll sideways on a phone instead of stacking three
                         rows deep. Each carries its own count, so the size of a
                         room is answered without filtering to it first. --}}
                    <div class="-mx-1 flex min-w-0 flex-1 gap-1.5 overflow-x-auto px-1 pb-0.5 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">
                        <button @click="room=''" :class="room === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
                            All <span class="ml-0.5 opacity-60 tabular-nums" x-text="centreIn + '/' + {{ $totalChildren }}" :title="centreIn + ' of {{ $totalChildren }} in'"></span>
                        </button>
                        @foreach($classrooms as $classroom)
                            <button @click="room=@js($classroom)" :class="room === @js($classroom) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
                            <x-room-icon :room="$classroom" size="text-sm" /> {{ $classroom }} <span class="ml-0.5 opacity-60 tabular-nums" x-text="roomIn(@js($classroom)) + '/' + roomCount(@js($classroom))" :title="roomIn(@js($classroom)) + ' of ' + roomCount(@js($classroom)) + ' in'"></span>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        {{-- Days the centre is shut are why a column is gray, so the
                             count sits beside the filters rather than being found by
                             opening the schedule view. --}}
                        <span x-show="closedCount > 0" x-cloak class="hidden items-center gap-1 text-[0.7333rem] font-medium text-slate-500 sm:inline-flex dark:text-slate-400" :title="closedReasons()">
                            <span class="grid h-3.5 w-3.5 place-items-center rounded-full border border-current text-[0.6rem] leading-none" aria-hidden="true">i</span>
                            <span x-text="closedCount"></span> <span x-text="closedCount === 1 ? 'day closed' : 'days closed'"></span>
                        </span>

                        {{-- Counts as a sentence rather than three chips: the numbers
                             are the point, so they carry the colour and the weight and
                             the labels stay out of the way. --}}
                        <p class="flex shrink-0 items-center gap-x-2.5 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                            {{-- The room being looked at, when it is not the whole
                                 centre: three numbers that quietly changed meaning
                                 when a filter was clicked would be worse than three
                                 numbers that never moved. --}}
                            <span x-show="room !== ''" x-cloak class="font-semibold text-slate-700 dark:text-slate-200" x-text="room"></span>
                            <span><b class="font-bold text-slate-900 dark:text-white" x-text="enrolledCount"></b> enrolled</span>
                            <span><b class="font-bold text-emerald-600 dark:text-emerald-400" x-text="presentCount"></b> in</span>
                            <span><b class="font-bold text-rose-600 dark:text-rose-400" x-text="absentCount"></b> not in</span>
                        </p>
                    </div>
                </div>

                {{-- What every mark on the sheet means, in the sheet's own marks.
                     Painted from the same $boxStates the grid is painted from, so
                     a chip here and a cell down there cannot come to disagree. --}}
                <div x-show="view === 'signin' && showKey" x-cloak class="-mx-3 -mb-2.5 mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-b-2xl border-t border-slate-200/70 bg-slate-50/70 px-3 py-2 text-[0.7333rem] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                    @foreach($boxStates as $key => $state)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-flex h-[1.4667rem] min-w-[3.4667rem] items-center justify-center rounded-lg border px-2 font-mono text-[0.7rem] font-medium leading-none {{ $state['classes'] }}">{{ $state['swatch'] }}</span>
                            {{ $state['label'] }}
                        </span>
                    @endforeach
                    <span class="ml-auto hidden lg:inline" x-text="hint"></span>
                </div>
            </div>
            @if($weekIsOpen)
            {{-- Schedule setup: ticks, not colours. --}}
            {{-- x-if rather than x-show, here and on the sheet below: this is the
                 whole roll five days wide, and the view nobody is looking at was
                 being built anyway — every cell, every binding — before the one
                 they asked for could appear. Built when it is switched to. --}}
            @if($canEditSchedule)
                <template x-if="ready && view === 'schedule'">
                    <div class="mt-3">
                        @include('attendance.partials.checklist')
                    </div>
                </template>
            @endif

            {{-- What is on screen while the grid is being built.

                 Seventy-five children five days wide is a few thousand boxes,
                 and building them is the one unavoidable pause on this page.
                 An empty card for that second reads as a page that failed; a
                 turning circle reads as a page that is working, which is the
                 truth. It is plain markup with no x-cloak on purpose, so it is
                 on screen from the first paint — before Alpine has started —
                 and `ready` is set a frame later so the browser gets to draw it
                 before the grid takes the thread. --}}
            <div x-show="! ready" class="glass-card mt-3 grid place-items-center rounded-2xl px-6 py-16">
                <div class="h-9 w-9 animate-spin rounded-full border-4 border-slate-200 border-t-indigo-600 dark:border-white/10 dark:border-t-indigo-400" role="status" aria-label="Loading the sheet"></div>
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Loading the sheet&hellip;</p>
            </div>

            <template x-if="ready && view === 'signin'">
            <div class="mt-3">
                <div class="glass-card overflow-hidden rounded-2xl">
                    {{-- Week grid: needs the width, so it only appears from md up.
                         Gated on the breakpoint rather than merely hidden at it —
                         a phone was building the grid it would never show, and a
                         desktop was building the cards, so every roll was drawn
                         twice over. --}}
                    <template x-if="! isPhone">
                    <div class="overflow-x-auto">
                        {{-- att-split when this week holds a room that books
                             twice a day. It is what gives every row the height
                             of a stacked AM/PM cell, so a School Age child and
                             a Toddler line up across the week instead of the
                             columns stepping in and out. Left off when no such
                             room is on the sheet, because then the taller row
                             would be empty space on every line. --}}
                        <table class="att-table w-full min-w-[72rem] {{ $children->contains(fn ($row) => count($row->sessions()) > 1) ? 'att-split' : '' }}">
                            <thead>
                                <tr>
                                    {{-- The LAN, not a row number: it is what the paper
                                         register, the invoice and the office all name a
                                         child by. Frozen with the name against a sideways
                                         scroll on a tablet. --}}
                                    <th scope="col" :aria-sort="sortedBy('lan') ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'" class="att-th att-col-lan sticky left-0 z-20 bg-white dark:bg-night-900">
                                        <button type="button" @click="toggleSort('lan')" class="inline-flex items-center gap-1.5 transition hover:text-indigo-600 dark:hover:text-indigo-300" :title="sortedBy('lan') ? (sortDirection === 'asc' ? 'Sorted 1–9, click for 9–1' : 'Sorted 9–1, click for 1–9') : 'Sort by learner account number'">
                                            <span>LAN</span>
                                            <span x-show="sortedBy('lan')" class="text-[0.7333rem] leading-none opacity-60" x-text="sortDirection === 'asc' ? '↑' : '↓'"></span>
                                        </button>
                                    </th>
                                    <th scope="col" :aria-sort="sortedBy('name') ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'" class="att-th att-col-student sticky left-[4rem] z-20 bg-white dark:bg-night-900">
                                        <button type="button" @click="toggleSort('name')" class="group inline-flex items-center gap-1.5 transition hover:text-indigo-600 dark:hover:text-indigo-300" :title="sortedBy('name') ? (sortDirection === 'asc' ? 'Sorted A–Z, click for Z–A' : 'Sorted Z–A, click for A–Z') : 'Sort by name'">
                                            <span>Student</span>
                                            <span x-show="sortedBy('name')" class="text-[0.7333rem] leading-none opacity-60" x-text="sortDirection === 'asc' ? '↑' : '↓'"></span>
                                        </button>
                                    </th>
                                    {{-- Read down a column these compare at a glance, which
                                         is what they are for: the room the ratio is staffed
                                         by, the age it is judged on, and the hours the day
                                         was agreed for. The room is under the name too — the
                                         column is what the eye runs down, the line under the
                                         name is what it reads in place. --}}
                                    <th scope="col" class="att-th att-meta att-w-room">Classroom</th>
                                    <th scope="col" class="att-th att-meta att-w-dob" title="Date of birth, year/month/day">DOB</th>
                                    <th scope="col" class="att-th att-meta att-w-age">Age</th>
                                    <th scope="col" class="att-th att-meta att-w-hours" title="The hours agreed on the child's record">Hours</th>
                                    @foreach($weekDates as $date)
                                        @php($iso = $date->toDateString())
                                        {{-- Today is the column being worked in, so it is
                                             picked out of the five rather than counted along
                                             to. A pill, not a tint the width of the column:
                                             sky is the one colour the sheet does not use
                                             elsewhere — the boxes are green, amber, blue and
                                             grey — so the day is found without reading a
                                             date. --}}
                                        <th scope="col" class="att-th att-day {{ $date->isToday() ? 'att-today' : '' }}">
                                            <span class="{{ $date->isToday() ? 'att-dayhead att-dayhead-today' : 'att-dayhead' }}">
                                            <span class="block">{{ $date->format('D') }}</span>
                                            <span class="block">
                                                {{ $date->format('M j') }}
                                                {{-- Locked: nothing in this column takes a tap in
                                                     the mode the sheet is in. --}}
                                                <span x-show="! canTap('{{ $iso }}')" x-cloak class="att-lock" x-html="icons.lock" title="Locked — switch to Edit mode to change this day"></span>
                                            </span>
                                            {{-- A closed day still takes sign-ins, so the column
                                                 stays live — it just says why it is all dashes. --}}
                                            </span>
                                            <span x-show="isClosed('{{ $iso }}')" x-cloak class="mt-0.5 block rounded-md bg-rose-50 px-1 text-[0.6667rem] font-semibold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300" x-text="closureReason('{{ $iso }}')"></span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="child in filteredChildren" :key="child.id">
                                    <tr class="transition hover:bg-slate-50/60 dark:hover:bg-white/5">
                                        <td class="att-td att-lan sticky left-0 z-10 bg-white dark:bg-night-900" x-text="child.lan || '—'"></td>
                                        <td class="att-td att-student sticky left-[4rem] z-10 bg-white dark:bg-night-900">
                                            <div class="att-person">
                                                <span class="att-avatar" x-html="child.avatar"></span>
                                                <span class="min-w-0">
                                                    {{-- The name opens the child record: the numbers to
                                                         ring and the enrolment dates that decide whether
                                                         a box exists at all. Its hover carries the hours
                                                         they are contracted for, which used to be a
                                                         column of their own. --}}
                                                    <a x-show="canOpenProfile" :href="profileUrl(child.lan)" class="att-name truncate underline-offset-2 hover:text-indigo-600 hover:underline dark:hover:text-indigo-300" x-text="child.name" :title="'Open ' + child.first_name + '\'s record' + (child.schedule_hours ? ' — here ' + child.schedule_hours : '')"></a>
                                                    <span x-show="! canOpenProfile" class="att-name truncate" x-text="child.name" :title="child.schedule_hours ? child.first_name + ' is here ' + child.schedule_hours : ''"></span>
                                                    {{-- No room here: it has a column of its own three
                                                         along, and a row does not need to say it twice.

                                                         The status is here though, and only when it is
                                                         not Active — on a roll of sixty, sixty rows
                                                         reading "Active" would hide the one that does
                                                         not. A week gone by can hold a child who has
                                                         since left, and this is what says so. --}}
                                                    <span x-show="child.status !== 'Active'" x-cloak
                                                          class="att-status"
                                                          :class="child.status === 'Pending' ? 'att-status-pending' : 'att-status-off'"
                                                          x-text="child.status"></span>
                                                </span>
                                            </div>
                                        </td>
                                        {{-- The brand blue is close enough to grey that a
                                             hand-set room read as an automatic one, so the
                                             mark says which it is without relying on colour. --}}
                                        <td class="att-td att-meta att-w-room">
                                            <span class="inline-flex items-center gap-0.5" :class="roomClass(child)" :title="roomTitle(child)">
                                                <span x-text="roomLabel(child)"></span>
                                                <span x-show="child.classroom_override" x-cloak x-text="child.override_stale ? '⚠' : '✎'"></span>
                                            </span>
                                        </td>
                                        <td class="att-td att-meta att-w-dob" :class="blankClass(child.birth_date)" x-text="child.birth_date || '—'"></td>
                                        <td class="att-td att-meta att-w-age" :class="blankClass(child.age)" x-text="child.age || '—'"></td>
                                        {{-- One time a line. The guard is
                                             schedule_hours because that is null
                                             until both ends are agreed, and half
                                             a range on a register reads as a time
                                             that was cut off. --}}
                                        <td class="att-td att-meta att-w-hours" :class="blankClass(child.schedule_hours)" :title="child.schedule_hours ? child.first_name + ' is here ' + child.schedule_hours : 'No hours agreed yet'">
                                            <template x-if="child.schedule_hours">
                                                <span class="att-hours">
                                                    <span x-text="child.drop_off_label"></span>
                                                    <span x-text="child.pick_up_label"></span>
                                                </span>
                                            </template>
                                            <template x-if="! child.schedule_hours"><span>&mdash;</span></template>
                                        </td>
                                        @foreach($weekDates as $date)
                                            {{-- Closure is painted from Alpine rather than Blade
                                                 because a day is closed and reopened without the
                                                 page reloading, and it wins over today's sky: a
                                                 shut Monday is shut whether or not it is today. --}}
                                            <td class="att-td att-day" :class="isClosed('{{ $date->toDateString() }}') ? 'att-closed-col' : '{{ $date->isToday() ? 'att-today' : '' }}'">
                                                @include('attendance.partials.day-buttons', ['date' => $date, 'variant' => 'table'])
                                            </td>
                                        @endforeach
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    </template>

                    {{-- Phone layout: one card per child, one row per day. --}}
                    <template x-if="isPhone">
                    <div class="divide-y divide-slate-200 dark:divide-white/10">
                        <div class="flex items-center justify-between px-3 py-2">
                            <button type="button" @click="toggleSort(sortBy)" class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <span>Student</span>
                                <span class="text-[0.6667rem] leading-none text-slate-400" x-text="sortedBy('lan') ? (sortDirection === 'asc' ? '▲ LAN' : '▼ LAN') : (sortDirection === 'asc' ? '▲ A–Z' : '▼ Z–A')"></span>
                            </button>
                            <span class="text-[0.7333rem] text-slate-400">{{ $weekDates->first()->format('M d') }} – {{ $weekDates->last()->format('M d') }}</span>
                        </div>
                        <template x-for="child in filteredChildren" :key="'card-' + child.id">
                            <article class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="h-9 w-9 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                    <div class="min-w-0 flex-1">
                                        <a x-show="canOpenProfile" :href="profileUrl(child.lan)" class="block truncate text-sm font-semibold underline-offset-2 hover:text-indigo-600 hover:underline" x-text="child.name"></a>
                                        <p x-show="! canOpenProfile" class="truncate text-sm font-semibold" x-text="child.name"></p>
                                        <p class="truncate text-xs text-slate-500" x-text="[roomLabel(child), child.birth_date, child.schedule_hours].filter(Boolean).join(' · ')" title="Room, date of birth and the hours agreed"></p>
                                    </div>
                                    <span class="text-xs tabular-nums text-slate-400" x-text="child.lan" title="Learner account number"></span>
                                </div>
                                <div class="mt-2 space-y-1 rounded-xl bg-slate-50 p-1.5 dark:bg-night-800/50">
                                    @foreach($weekDates as $date)
                                        <div class="flex items-center justify-between gap-2 rounded-lg px-2 py-1">
                                            <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $date->format('D') }} <span class="text-slate-400">{{ $date->format('M d') }}</span></span>
                                            <div class="flex shrink-0 items-center gap-1.5">
                                                @include('attendance.partials.day-buttons', ['date' => $date, 'variant' => 'card'])
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        </template>
                    </div>
                    </template>

                    <p x-show="filteredCount === 0" class="p-8 text-center text-sm text-slate-500">No children match this search.</p>
                    <div x-show="filteredCount > 0" class="border-t border-slate-200 px-4 py-2.5 text-sm text-slate-500 dark:border-white/10">
                        Showing all <span class="font-medium text-slate-700 dark:text-slate-200" x-text="filteredCount"></span> children
                        {{-- What a tap does, in the mode you are in. --}}
                        <span class="att-hint ml-2" x-text="hint"></span>
                    </div>
                </div>
            </div>
            </template>
            @else
                {{-- A week nobody has built yet. It shows as it is — no children,
                     no boxes, nothing to colour in — because the alternative is a
                     sheet that appears to be planned purely because somebody
                     looked at it. Opening is the deliberate act, and it is the
                     moment the previous week is copied forward. --}}
                <div class="glass-card mt-3 rounded-2xl px-6 py-12 text-center">
                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-slate-100 text-2xl dark:bg-slate-800">🗓</span>
                    <h2 class="mt-3 text-base font-bold">
                        {{ $weekDates->first()->format('M j') }} – {{ $weekDates->last()->format('M j, Y') }} has not been set up
                    </h2>
                    @if($canOpenWeek)
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500 dark:text-slate-400">
                            @if($previousWeekStart)
                                Opening it copies the week of
                                <b>{{ \Illuminate\Support\Carbon::parse($previousWeekStart)->format('M j') }}</b> forward &mdash; the days
                                ticked there, and the days somebody actually arrived on. Nothing is copied until you do.
                            @else
                                Nothing came before it, so the days start from each child's registered days.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('attendance.week.open') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="week_start" value="{{ $weekStartDate }}">
                            <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                Open this week
                            </button>
                        </form>
                    @elseif($weekIsFrozen)
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500 dark:text-slate-400">
                            This week has ended and was never set up. A finished week is a record, so it cannot be planned now.
                        </p>
                    @else
                        <p class="mx-auto mt-1.5 max-w-md text-sm text-slate-500 dark:text-slate-400">
                            A teacher or the director opens the week before it can be used.
                        </p>
                    @endif
                </div>
            @endif
        </section>
    </div>

    {{--
        Recent sign-ins: a drawer off the right edge, not a slab under the sheet.

        It was a full-width panel below sixty rows of register, which is the one
        place on the page nobody looks — you had to scroll past everything the
        page is for to reach the thing that changes every few minutes. It also
        pushed the sheet's own "showing all N children" line into the middle of
        the screen.

        A drawer instead: out of the way until it is asked for, over the sheet
        rather than after it, and the button that opens it carries the count, so
        "how many are in" is answered without opening anything at all.

        Teleported to the body. The sheet sits in a glass card, and a card
        carrying a backdrop-blur clips whatever hangs out of it — the same trap
        the "..." menu and the legend both had to be lifted out of.
    --}}
    <template x-teleport="body">
        <div x-show="recentOpen" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Recent sign-ins">
            {{-- The sheet stays readable underneath: this is something you glance
                 at, not something you fill in, so it does not black out the page. --}}
            <div x-show="recentOpen" x-transition.opacity @click="recentOpen = false" class="absolute inset-0 bg-slate-900/20 backdrop-blur-[1px]"></div>

            <div
                x-show="recentOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="absolute inset-y-0 right-0 flex w-[min(22rem,100%)] flex-col border-l border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-night-900"
            >
                <header class="flex items-center gap-2.5 border-b border-slate-200 px-4 py-3 dark:border-white/10">
                    {{-- A pulse rather than the words "live updates": the list
                         fills as children arrive, and a dot that moves says so
                         without spending a line on saying it. --}}
                    <span class="relative flex h-2 w-2" aria-hidden="true">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </span>
                    <h2 class="text-sm font-bold">Recent sign-ins</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-[0.7333rem] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-300" x-text="recent.length"></span>
                    <button type="button" @click="recentOpen = false" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Close">✕</button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    {{-- Newest at the top, and the newest one tinted: on a panel
                         glanced at between arrivals, "what just happened" is the
                         whole question being asked. --}}
                    <template x-for="(signIn, index) in recent" :key="signIn.id">
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-2.5 transition dark:border-white/5" :class="index === 0 ? 'bg-emerald-50/60 dark:bg-emerald-500/10' : ''">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 text-[0.8667rem] text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300" aria-hidden="true">✓</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[0.8667rem] font-semibold" x-text="signIn.name"></p>
                                <p class="truncate text-[0.7333rem] text-slate-500 dark:text-slate-400" x-text="signIn.classroom"></p>
                            </div>
                            <span class="shrink-0 font-mono text-[0.7333rem] font-medium tabular-nums text-slate-500 dark:text-slate-400" x-text="signIn.time"></span>
                        </div>
                    </template>

                    <p x-show="recent.length === 0" class="px-4 py-12 text-center text-[0.8667rem] text-slate-500 dark:text-slate-400">
                        Nobody has signed in yet today.<br>
                        <span class="text-[0.7333rem] text-slate-400">Tap a cell on the sheet and they will appear here.</span>
                    </p>
                </div>

                <p class="border-t border-slate-200 px-4 py-2 text-[0.7333rem] text-slate-400 dark:border-white/10 dark:text-slate-500">
                    Arrivals on {{ \Illuminate\Support\Carbon::parse($selectedDate)->format('D, M j') }}, newest first.
                </p>
            </div>
        </div>
    </template>


    {{-- Why a sign-in was refused, centred on the sheet rather than dropped from
         the top of the browser by alert(). --}}
    <div
        x-show="notice"
        x-cloak
        x-transition.opacity
        @keydown.escape.window="notice = ''"
        class="fixed inset-0 z-50 grid place-items-center bg-slate-900/60 p-4"
        role="alertdialog"
        aria-modal="true"
    >
        <div @click.outside="notice = ''" class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-2xl dark:border-white/10 dark:bg-slate-900">
            <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-amber-100 text-2xl font-bold text-amber-600 dark:bg-amber-500/15 dark:text-amber-300">!</span>
            <h2 class="mt-3 text-base font-bold text-slate-900 dark:text-white">Cannot sign in</h2>
            <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-300" x-text="notice"></p>
            <button type="button" @click="notice = ''" x-ref="noticeOk" class="mt-5 w-full rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">OK</button>
        </div>
    </div>
</div>
<script>
/**
 * The month behind the dates in the toolbar.
 *
 * Its own island rather than part of attendanceApp: it is chrome for choosing
 * which week to look at, and it navigates rather than changing anything on the
 * sheet, so the sheet has no business holding its state.
 *
 * Every row of the grid is one Sunday-to-Saturday week, so the seven cells of a
 * row all resolve to the same Monday — which is what makes "hovering picks a
 * week" one comparison rather than a range test.
 */
function weekPicker() { return {
    open: false,
    x: 0,
    y: 0,
    hover: null,
    current: @js($weekStartDate),
    today: @js($thisWeek),
    cursor: @js($weekStartDate),

    iso(date) {
        return date.getFullYear() + '-'
            + String(date.getMonth() + 1).padStart(2, '0') + '-'
            + String(date.getDate()).padStart(2, '0');
    },

    /** The Monday of the week a date falls in. Sunday belongs to the week after it. */
    mondayOf(date) {
        const shift = date.getDay() === 0 ? 1 : 1 - date.getDay();
        return new Date(date.getFullYear(), date.getMonth(), date.getDate() + shift);
    },

    get monthLabel() {
        return new Date(this.cursor + 'T00:00:00')
            .toLocaleDateString(undefined, {month: 'long', year: 'numeric'});
    },

    get cells() {
        const base = new Date(this.cursor + 'T00:00:00');
        const first = new Date(base.getFullYear(), base.getMonth(), 1);
        const start = new Date(base.getFullYear(), base.getMonth(), 1 - first.getDay());
        const today = this.iso(new Date());
        const out = [];

        for (let i = 0; i < 42; i++) {
            const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);

            // A last row lying entirely in the next month is a row of nothing.
            if (i >= 35 && day.getMonth() !== base.getMonth()) { break; }

            out.push({
                key: this.iso(day),
                day: day.getDate(),
                monday: this.iso(this.mondayOf(day)),
                outside: day.getMonth() !== base.getMonth(),
                today: this.iso(day) === today,
                first: i % 7 === 0,
                last: i % 7 === 6,
            });
        }

        return out;
    },

    /** Lit as a row: the week being pointed at, or the one being looked at. */
    lit(cell) {
        return cell.monday === (this.hover || this.current);
    },

    label(monday) {
        return new Date(monday + 'T00:00:00')
            .toLocaleDateString(undefined, {month: 'short', day: 'numeric'});
    },

    step(months) {
        const base = new Date(this.cursor + 'T00:00:00');
        this.cursor = this.iso(new Date(base.getFullYear(), base.getMonth() + months, 1));
    },

    place() {
        const box = this.$refs.trigger.getBoundingClientRect();
        // Held off both edges, so it never opens half off-screen.
        this.x = Math.max(12, Math.min(box.left + box.width / 2 - 152, window.innerWidth - 316));
        this.y = box.bottom + 8;
    },

    toggle() {
        if (this.open) { this.open = false; return; }
        // Opens on the week being looked at, not on whatever month was left
        // showing the last time it was used.
        this.cursor = this.current;
        this.hover = null;
        this.place();
        this.open = true;
    },

    go(monday) {
        window.location = @js(route('attendance.index')) + '?date=' + monday;
    },
}; }

function attendanceApp() { return {
    search: '',
    room: '',
    // Which column the sheet is ordered by, and which way. LAN to begin
    // with, like the roll: it is the number on the cabinet and the parent
    // letter, so it is what somebody arrives already holding.
    sortBy: 'lan',
    sortDirection: 'asc',
    view: 'signin',
    recentOpen: false,
    /*
     * Live or Edit is read from the address, not remembered in the page.
     *
     * Switching mode reloads the sheet — see switchMode() — so the mode has
     * to be carried across that reload, and ?mode=edit is the plainest
     * place to carry it: visible, sharable, gone when the tab is closed.
     * Anybody without the power to edit lands in Live whatever the URL
     * says; the switch is not drawn for them either.
     */
    editing: @js(request('mode') === 'edit' && ($canAmendAttendance || $canEditSchedule)),
    canAmend: @js($canAmendAttendance),

    /*
     * Which of the three grids is built, rather than which is merely visible.
     *
     * The desktop sheet, the phone cards and the schedule checklist are the
     * same roll five days wide. Hidden with x-show, all three were still built
     * on load — on a roll of seventy-five that is eleven hundred cells and the
     * reactive bindings behind every one of them — and the page sat empty while
     * Alpine worked through the two nobody was looking at. They are gated with
     * x-if now, so only the one on screen is built, and these two say which.
     *
     * Read once and then kept current: a tablet turned on its side crosses the
     * breakpoint, and the grid it crosses into has to exist by the time it
     * lands there.
     */
    isPhone: window.matchMedia('(max-width: 767px)').matches,

    /*
     * False until the browser has had a chance to paint. Building the grid is
     * a few thousand boxes and it holds the thread while it happens, so doing
     * it inside init() would mean the spinner never appeared — the first thing
     * drawn would be the finished sheet, after the wait rather than during it.
     * Two frames: the first gets the spinner on screen, the second hands the
     * thread back so it is actually turning while the grid is built.
     */
    ready: false,

    /* ---- saves in flight ----

       Every tap saves itself the moment it is made, so nothing on this sheet
       is ever waiting to be submitted. But a request takes a moment to cross
       the wire, and switchMode() reloads the page — reloading with one still
       out would throw that tap away. So the saves are counted on the way out
       and back, and the switch waits for the count to reach zero. */
    inFlight: 0,

    post(url, body) {
        this.inFlight++;

        return window.postJson(url, body).finally(() => { this.inFlight--; });
    },

    /**
     * Live ↔ Edit, by reloading the sheet.
     *
     * Flipping a flag would do, and did. The reload is deliberate: it means
     * the sheet you arrive in is exactly what the server holds — every tick,
     * every hour, every arrival re-read — rather than what this tab has been
     * keeping up to date on its own. Switching mode is a natural moment to
     * ask for that, because it is the moment the meaning of every cell
     * changes and the moment somebody looks at the whole sheet again.
     *
     * The mode travels in the address so it is still the mode after the
     * reload; date and room travel with it because they were already there.
     */
    async switchMode() {
        this.cancelRetime();

        // Let any save still on the wire land first.
        while (this.inFlight > 0) {
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        const url = new URL(window.location.href);

        if (this.editing) {
            url.searchParams.delete('mode');
        } else {
            url.searchParams.set('mode', 'edit');
        }

        window.location.assign(url.toString());
    },
    init() {
        window.matchMedia('(max-width: 767px)')
            .addEventListener('change', event => { this.isPhone = event.matches; });

        this.buildAfterPaint();
    },

    /*
     * How many rows are built. A screenful to begin with, then null — meaning
     * all of them — once that screenful is on the glass.
     */
    rowLimit: 18,

    buildAfterPaint() {
        this.ready = false;
        this.rowLimit = 18;

        requestAnimationFrame(() => requestAnimationFrame(() => {
            this.ready = true;

            // Once the first rows have been painted, fill in the rest. Two
            // frames again: the first draws them, the second is when the
            // browser is free to take the work.
            requestAnimationFrame(() => requestAnimationFrame(() => { this.rowLimit = null; }));
        }));
    },

    /*
     * Switching between the sheet and the checklist builds the other grid from
     * nothing, which is the same pause the page opens with — so it gets the
     * same circle rather than a toolbar that stops responding.
     */
    switchView(next) {
        if (this.view === next) return;

        this.view = next;
        this.editing = false;
        this.buildAfterPaint();
    },

    /*
     * The roster is sorted and filtered on the way into the grid, and the grid
     * asks for it far more often than it changes — every cell that re-renders
     * reads the list it belongs to. Copying, filtering and locale-sorting the
     * whole roll on each of those reads was most of the cost of a keystroke.
     *
     * So it is worked out once per change and held. The key is everything the
     * answer depends on: the search box, the room chip, the sort direction and
     * a counter the one place that edits a child in place bumps by hand.
     */
    rosterVersion: 0,
    filteredCache: null,
    scheduleCache: null,

    /* Inline, because they are painted from Alpine into cells that re-render. */
    icons: {
        pencil: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13.5 6.5l3 3"/></svg>',
        lock: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
        edit: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z"/></svg>',
    },

    get hint() {
        return this.editing
            ? 'Tap a cell to cycle not attending → expected → time. On a day still to come a tap is just expected ↔ not attending, and the pencil sets the hour they are due — which is the plan, not an arrival.'
            : 'Only ' + this.todayLabel + ' can be changed.';
    },
    // Which cells in this week were put right by hand rather than tapped on
    // the day. Keyed child|date|session, the same shape the attendance map is.
    amended: @js((object) $amendmentMap),
    // Away until somebody asks for it, and then it stays. A key is read on the
    // first morning and never again, so a sheet that opens carrying one is
    // spending a strip of the screen on a question nobody is asking — and the
    // "?" in the bar is always there to ask it with.
    //
    // In a try/catch because a private window can throw on the accessor
    // itself, and a page that will not render is a worse outcome than a key
    // that forgets.
    showKey: (() => { try { return localStorage.getItem('attendance.key') === 'shown'; } catch { return false; } })(),
    toggleKey() {
        this.showKey = ! this.showKey;
        try { localStorage.setItem('attendance.key', this.showKey ? 'shown' : 'hidden'); } catch {}
    },
    // The date the header counts are about: the day the page was opened on,
    // which is today unless somebody jumped to another week.
    countDate: @js($selectedDate),
    canEdit: @js($canEditSchedule),
    // Only an admin may open a child record, so only they get a link.
    canOpenProfile: true,
    {{-- The record, not the edit form. Everybody on this page is looking at
         children they may already see — the list is filtered by the same rule
         the record is — so the teacher gets the phone numbers and the pick-up
         list from here too, instead of a link they are refused. --}}
    // By LAN, not by row id: that is how a child's page is addressed, and
    // how the office names them on paper.
    profileUrl(lan) { return "{{ route('children.show', ['child' => '__LAN__']) }}".replace('__LAN__', lan); },
    weekStart: @js($weekStartDate),
    paint: null,
    pending: {},
    saving: false,
    saveError: '',
    notice: '',
    {{-- The avatar is drawn here rather than in Alpine, so the roster, the
         record and this page all get the same face out of the one component —
         a second copy of the drawing in JavaScript would drift from it within
         a month. It arrives as markup and goes in with x-html; sizing is left
         to the wrapper, so the one string serves the 32px and 36px rows. --}}
    @php($avatarMarkup = fn ($child) => preg_replace('/>\s+</', '><', trim(view('components.child-avatar', [
        'child' => $child,
        'size' => 'h-full w-full',
        'shape' => '',
        'attributes' => new \Illuminate\View\ComponentAttributeBag,
    ])->render())))
    childrenData: @js($children->map(fn($child) => ['id' => $child->id, 'lan' => $child->lan, 'name' => $child->displayName(), 'status' => $child->status, 'first_name' => $child->first_name, 'last_name' => $child->last_name, 'avatar' => $avatarMarkup($child), 'birth_date' => $child->ageLabel(), 'age' => $child->ageInWords(), 'classroom' => $child->classroom, 'sessions' => $child->sessions(), 'automatic_classroom' => $child->automaticClassroom(), 'classroom_override' => $child->classroom_override, 'classroom_override_from' => $child->classroom_override_from?->toDateString(), 'override_stale' => $child->classroomOverrideIsStale(), 'schedule_hours' => $child->scheduleLabel(), 'drop_off_label' => \App\Models\Child::timeLabel($child->drop_off_time), 'pick_up_label' => \App\Models\Child::timeLabel($child->pick_up_time), 'drop_off' => \App\Models\Child::timeInputValue($child->drop_off_time ?: \App\Models\Child::DAY_OPENS_AT), 'schedule_days' => $child->scheduleDays(), 'schedule_days_label' => $child->scheduleDaysLabel(), 'cover' => $roomCover[$child->id] ?? null])->values()),
    rooms: @js(\App\Services\ClassroomAssignment::rooms()),
    // Moving a child between rooms changes who can see them, so it is the
    // director's call rather than a teacher's.
    canEditRooms: @js(auth()->user()->isAdmin()),
    roomEditing: null,
    today: @js(today()->toDateString()),
    todayLabel: @js(today()->format('l, M j')),
    attendance: @js($attendanceMap),
    schedule: @js($scheduleMap),

    // The hour a day still to come is booked for, where one has been agreed.
    // Sparse on purpose: most booked days have no hour, and this is read on
    // every one of four hundred boxes.
    planned: @js($plannedMap),
    // The forecast, kept in its own map so it can never be mistaken for a tick.
    projection: @js((object) $projectionMap),
    projectionChildren: @js((object) $projectionChildren),
    basisLabels: @js($projectionBasisLabels),
    closed: @js($closedDays),
    recent: @js($recentAttendance->map(fn($attendance) => ['id' => $attendance->id, 'name' => $attendance->child->displayName(), 'classroom' => $attendance->child->classroom, 'time' => \App\Models\Child::timeShort($attendance->signed_in_at->timezone(config('app.timezone')))])->values()),
    get filteredChildren() {
        const rows = this.matchingChildren;

        // The first screenful, then the rest. Building seventy-five rows is
        // one piece of work the browser cannot be interrupted during, so the
        // sheet used to appear all at once at the end of it. Cut in two, the
        // rows somebody is actually looking at are on screen while the rest
        // are still being built, which is the whole of the difference.
        return this.rowLimit === null ? rows : rows.slice(0, this.rowLimit);
    },

    get matchingChildren() {
        const key = `${this.search}|${this.room}|${this.sortBy}|${this.sortDirection}|${this.rosterVersion}`;

        if (this.filteredCache?.key !== key) {
            this.filteredCache = {
                key,
                rows: this.childrenData
                    .filter(child => this.matchesChild(child))
                    .sort((a, b) => this.byColumn(a, b)),
            };
        }

        return this.filteredCache.rows;
    },

    /* The column the header is set to, in the direction it is set to. */
    byColumn(a, b) {
        const order = this.sortDirection === 'asc' ? 1 : -1;

        if (this.sortBy === 'lan') {
            /*
             * A LAN is a string — a centre's numbering can carry a prefix —
             * so compared as text it reads 1, 10, 100, 1001, 2. Numbers are
             * compared as numbers, and anything that is not one falls to the
             * end rather than scattering through the middle.
             */
            const left = Number(a.lan);
            const right = Number(b.lan);
            const leftIsNumber = a.lan !== '' && a.lan !== null && ! Number.isNaN(left);
            const rightIsNumber = b.lan !== '' && b.lan !== null && ! Number.isNaN(right);

            if (leftIsNumber && rightIsNumber) return (left - right) * order;
            if (leftIsNumber) return -1;
            if (rightIsNumber) return 1;

            return String(a.lan ?? '').localeCompare(String(b.lan ?? '')) * order;
        }

        const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
        const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();

        return nameA.localeCompare(nameB) * order;
    },
    // Everyone the search matches, not just the rows built so far — "showing
    // all 75" has to say 75 while the last of them are still being drawn.
    get filteredCount() { return this.matchingChildren.length; },

    /*
     * The three numbers in the header, and what they are counting.
     *
     * The room filter moves them, so clicking "Infant 3" answers "how is Infant
     * doing this morning" rather than leaving the centre's totals sitting above
     * a sheet showing three children. That was the whole of the confusion: a
     * room of three with "13 enrolled" over it.
     *
     * The search box deliberately does not move them. Typing a name is looking
     * something up, not changing what you are responsible for, and watching the
     * centre's roll fall to one as you type would be alarming for no reason.
     */
    get scopeChildren() {
        return this.room === ''
            ? this.childrenData
            : this.childrenData.filter(child => child.classroom === this.room);
    },
    get enrolledCount() { return this.scopeChildren.length; },
    // Counted off the attendance the page was given rather than carried as a
    // running total, so it cannot drift from the sheet below it — and a sign-in
    // updates it by updating the sheet, with nothing to keep in step by hand.
    get presentCount() {
        return this.scopeChildren.filter(child => this.hasAnyAttendanceForDate(child.id, this.countDate)).length;
    },
    get absentCount() { return this.enrolledCount - this.presentCount; },

    /* ---- setting the schedule is a whole-centre job: every child, every room,
            whatever the sign-in view happens to be filtered to ---- */
    get scheduleChildren() {
        const key = `${this.sortBy}|${this.sortDirection}|${this.rosterVersion}`;

        if (this.scheduleCache?.key !== key) {
            this.scheduleCache = { key, rows: [...this.childrenData].sort((a, b) => this.byColumn(a, b)) };
        }

        return this.scheduleCache.rows;
    },
    matches(name, classroom) { return name.includes(this.search.toLowerCase()) && (this.room === '' || classroom === this.room); },
    // The LAN is searched as well as the name: it is on the sheet now, and a
    // number somebody can read off a row but not type into the box beside it
    // is a column that only half works. The office rings up quoting the LAN.
    // Searched in both orders whichever one is on show, so "lovelace, ada" and
    // "ada lovelace" both find her — the format is how a name reads, not what
    // the box will accept.
    matchesChild(child) { return this.matches((child.first_name + ' ' + child.last_name + ' ' + child.name + ' ' + (child.lan ?? '')).toLowerCase(), child.classroom); },
    /* Two names fit on a row; the rest are counted, and the tooltip has the
       day-by-day breakdown for anyone who needs it. */
    coverNames(child) {
        const names = child.cover?.names ?? [];
        return names.length > 2 ? names.slice(0, 2).join(', ') + ' +' + (names.length - 2) : names.join(', ');
    },
    coverTitle(child) {
        if (! child.cover) return '';
        const detail = child.cover.detail;
        return child.cover.partial ? detail + ' · some booked days have no cover in their hours' : detail;
    },
    // What each room holds, counted off the roll the page was given rather than
    // queried per room. The filter chips carry it, so the size of a room is
    // answered without having to filter to it and read the rows.
    roomCount(room) { return this.childrenData.filter(child => child.classroom === room).length; },

    /**
     * How many of a room are in, for the "3/6" on its chip.
     *
     * Counted off the same attendance the sheet below is drawn from, like the
     * line at the end of the filters — a chip carrying its own running total
     * would be a second version of the truth, and the one that drifts is
     * always the one nobody is looking at.
     *
     * The chips do not move with the room filter: they are how a room is
     * chosen, so a chip that changed when another chip was pressed would be
     * answering a question about somewhere else.
     */
    roomIn(room) {
        return this.childrenData.filter(
            child => child.classroom === room && this.hasAnyAttendanceForDate(child.id, this.countDate)
        ).length;
    },

    /** The same reading for the whole centre, so the All chip matches the rest. */
    get centreIn() {
        return this.childrenData.filter(child => this.hasAnyAttendanceForDate(child.id, this.countDate)).length;
    },
    // How a column says it has nothing for this child.
    //
    // A dash hugging the left edge of a column whose other rows read "8:00 AM –
    // 5:30 PM" looks like a very short entry rather than an absent one — and a
    // roster where most children have no hours agreed yet is a whole column of
    // them. Centred in the width the real values set, and a shade lighter, a
    // blank reads as the gap it is.
    blankClass(value) { return value ? '' : 'text-center text-slate-300 dark:text-slate-600'; },
    /*
     * Press a header to sort by it; press the one you are on to turn it
     * round. A new column always starts ascending, because arriving at a
     * column already reversed reads as a bug rather than a choice.
     */
    toggleSort(column = 'name') {
        if (this.sortBy === column) {
            this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        this.sortBy = column;
        this.sortDirection = 'asc';
    },
    sortedBy(column) { return this.sortBy === column; },
    isPresent(childId, date, session) { return !!this.attendance?.[childId]?.[date]?.[session]; },
    sessionTime(childId, date, session) { return this.attendance?.[childId]?.[date]?.[session] ?? ''; },
    hasAnyAttendanceForDate(childId, date) { return !!this.attendance?.[childId]?.[date] && Object.keys(this.attendance[childId][date]).length > 0; },

    /* ---- the schedule: a missing slot means the child is not enrolled that day ---- */
    hasSlot(childId, date) { return !!this.schedule?.[childId]?.[date]; },
    isScheduled(childId, date, session) { return this.schedule?.[childId]?.[date]?.[session] === true; },

    /* ---- the projection: what last week's attendance, the enrolment dates and
            the contracted hours expect of this week. Read-only here — the only
            way it reaches the ticks is the director asking for it in the dialog ---- */
    isProjected(childId, date, session) { return this.projection?.[childId]?.[date]?.[session] === true; },
    // The two disagreeing is the whole point of showing it: a day expected but
    // not ticked is one to add, a day ticked but not expected is one to check.
    /**
     * The registered pattern by child id, built once.
     *
     * projectionDiffers() runs per box on a sheet sixty rows deep, so it
     * cannot go looking down the roster for a child each time.
     */
    registeredDaysFor(childId) {
        if (this.daysById === null) {
            this.daysById = {};
            this.childrenData.forEach(child => {
                this.daysById[child.id] = Array.isArray(child.schedule_days) ? child.schedule_days : null;
            });
        }

        return this.daysById[childId] ?? null;
    },
    daysById: null,

    /** Which weekday of the week a date falls on, 1–5. */
    isoWeekdayOf(date) {
        const index = @js($weekDates->map->toDateString()).indexOf(date);

        return index === -1 ? null : this.weekdayNumbers[index];
    },

    /**
     * Whether this box disagrees with what the child is down for.
     *
     * Two references, and the stronger one wins. A registered pattern is
     * somebody stating the arrangement, so the week is checked against it: a
     * ring means this week departs from what was agreed, which is the thing
     * worth a second look. Only where no pattern has ever been recorded does
     * the forecast decide — inferred from last week's attendance, which is
     * all there is to go on for those children.
     *
     * The difference matters: read against last week, one sick day rang every
     * Wednesday after it and a single unplanned Tuesday rang every Tuesday.
     */
    projectionDiffers(childId, date, session) {
        if (!this.hasSlot(childId, date) || this.isClosed(date)) return false;

        const days = this.registeredDaysFor(childId);
        const ticked = this.isScheduled(childId, date, session);

        if (days !== null) {
            const weekday = this.isoWeekdayOf(date);

            return weekday !== null && days.includes(weekday) !== ticked;
        }

        return this.isProjected(childId, date, session) !== ticked;
    },
    projectionNote(childId, date, session) {
        if (!this.projectionDiffers(childId, date, session)) return '';

        const days = this.registeredDaysFor(childId);

        if (days !== null) {
            const named = this.registeredLabelFor(childId);

            return this.isScheduled(childId, date, session)
                ? 'Ticked, but not one of their days' + (named ? ' (' + named + ')' : '') + '.'
                : 'One of their days' + (named ? ' (' + named + ')' : '') + ', but not ticked this week.';
        }

        return this.isProjected(childId, date, session)
            ? 'Projected to attend, but not scheduled — ' + this.basisFor(childId).toLowerCase() + '.'
            : 'Scheduled, but not projected to attend.';
    },
    registeredLabelFor(childId) {
        return this.childrenData.find(child => child.id === childId)?.schedule_days_label || '';
    },
    basisFor(childId) {
        const basis = this.projectionChildren?.[childId]?.basis || 'none';
        return this.basisLabels[basis] || '';
    },
    get mismatchCount() {
        return this.eachSlot().filter(s => this.projectionDiffers(s.child_id, s.slot_date, s.session)).length;
    },
    // "3 days · 27.0 h" against the contract, for the checklist row.
    projectionSummary(childId) {
        const row = this.projectionChildren?.[childId];
        if (!row) return '—';
        const hours = row.projected_hours.toFixed(1) + ' h';
        if (row.contract_hours === null) return row.days + 'd · ' + hours;
        return row.days + 'd · ' + hours + ' / ' + row.contract_hours.toFixed(1) + ' h';
    },
    projectionSummaryClass(childId) {
        const row = this.projectionChildren?.[childId];
        if (!row) return 'text-slate-400';
        if (row.basis === 'contract') return 'font-semibold text-amber-700 dark:text-amber-300';
        if (row.variance !== null && Math.abs(row.variance) >= 0.01) return 'font-semibold text-amber-700 dark:text-amber-300';
        return 'text-sky-700 dark:text-sky-300';
    },
    projectionSummaryTitle(childId) {
        const row = this.projectionChildren?.[childId];
        if (!row) return '';
        const basis = this.basisFor(childId);
        if (row.contract_hours === null) return basis + '. No expected hours on file.';
        if (row.basis === 'contract') return row.contract_hours.toFixed(1) + ' h expected, but nothing says which days — set them by hand.';
        const gap = row.variance > 0 ? row.variance.toFixed(1) + ' h over' : (-row.variance).toFixed(1) + ' h short';
        return basis + '. ' + (Math.abs(row.variance) < 0.01 ? 'Matches the expected hours.' : gap + ' of the ' + row.contract_hours.toFixed(1) + ' h expected.');
    },

    /* ---- which room a child is in. Worked out from their age, unless the
            director has moved them by hand — an override is coloured so the
            two never read the same, and says on hover what it replaced ---- */
    // The map comes from ClassroomAssignment so the rows drawn here and the
    // room-icon component rendered elsewhere cannot name different animals.
    // Written as a name, not a tag: Blade compiles a component tag wherever it
    // finds one, comment or not — the same trap an inline php directive sets.
    roomAnimals: @js(\App\Services\ClassroomAssignment::animals()),
    roomAnimal(room) { return this.roomAnimals[room] || '⭐'; },
    roomLabel(child) {
        return child.classroom
            ? this.roomAnimal(child.classroom) + ' ' + child.classroom
            : 'Unassigned';
    },
    roomTitle(child) {
        if (! child.classroom_override) {
            return child.automatic_classroom
                ? 'From date of birth: ' + child.automatic_classroom
                : 'No age band covers this date of birth.';
        }
        const automatic = child.automatic_classroom || 'no room';
        const from = child.classroom_override_from ? ', from ' + child.classroom_override_from : '';
        return child.override_stale
            ? 'Set by hand' + from + '. Automatic: ' + automatic + ' — their age has caught up, so this can be cleared.'
            : 'Set by hand' + from + '. Automatic: ' + automatic + '.';
    },
    roomClass(child) {
        if (! child.classroom_override) return 'text-slate-500';
        // Stale is a different colour again: the override is still in force but
        // no longer moving the child anywhere, so it is one to clear.
        return child.override_stale
            ? 'font-semibold text-amber-700 dark:text-amber-300'
            : 'font-semibold text-violet-700 dark:text-violet-300';
    },
    editRoom(child) {
        if (! this.canEditRooms) return;

        if (this.roomEditing === child.id) {
            this.roomEditing = null;

            return;
        }

        // Open on what is already set, so saving without touching anything is
        // not a way to accidentally change the room.
        child.pendingRoom = child.classroom_override || '';
        child.pendingFrom = child.classroom_override_from || this.today;
        this.roomEditing = child.id;
    },
    async saveRoom(child, room, from) {
        if (! this.canEditRooms) return;
        this.saveError = '';

        try {
            const response = await this.post("{{ route('attendance.schedule.classroom') }}", {
                child_id: child.id,
                classroom: room || null,
                effective_from: room ? (from || this.today) : null,
            });

            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(data.message || 'Could not change that room.');

            const sessionsChanged = JSON.stringify(data.sessions) !== JSON.stringify(child.sessions);

            Object.assign(child, {
                classroom: data.classroom,
                automatic_classroom: data.automatic_classroom,
                classroom_override: data.classroom_override,
                classroom_override_from: data.classroom_override_from,
                override_stale: data.override_stale,
                sessions: data.sessions,
            });

            this.roomEditing = null;
            // The roll is sorted and filtered once and held; the room is one of
            // the things it is filtered by, so moving a child has to say so or
            // they would stay in the room they just left until the next reload.
            this.rosterVersion++;

            // Moving in or out of School Age turns a full day into AM and PM.
            // The server reshaped the boxes; reload rather than guess the grid.
            if (sessionsChanged) window.location.reload();
        } catch (error) {
            this.saveError = error.message;
        }
    },

    /* ---- centre closures: a whole column gray in one move ---- */
    isClosed(date) { return date in this.closed; },
    get closedCount() { return Object.keys(this.closed).length; },
    closureReason(date) { return this.closed[date] || 'Closed'; },
    // The reasons behind the count, since "2 days closed" does not say which
    // two or why — and a gray column with no explanation is the thing people
    // come to the office to ask about.
    closedReasons() {
        return Object.entries(this.closed)
            .map(([date, reason]) => new Date(date + 'T00:00:00').toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'}) + ' — ' + reason)
            .join('\n');
    },
    async toggleClosure(date) {
        if (!this.canEdit) return;
        const closing = !this.isClosed(date);
        const reason = closing ? (prompt('Why is the centre closed? (holiday, snow day…)', 'Holiday') || 'Centre closed') : null;

        try {
            const response = await this.post("{{ route('attendance.schedule.closure') }}", {date, closed: closing, reason});
            if (!response.ok) throw new Error('Could not change that day.');

            if (closing) {
                this.closed[date] = reason;
                // The server grayed every box on that date; match it here rather
                // than reloading the whole sheet.
                Object.keys(this.schedule).forEach(childId => {
                    Object.keys(this.schedule[childId][date] || {}).forEach(session => {
                        this.schedule[childId][date][session] = false;
                    });
                });
            } else {
                delete this.closed[date];

                // Reopening puts back the ticks the closure took off. The grid
                // in the browser still shows them cleared, so let the server's
                // answer redraw it rather than guessing which came back.
                const body = await response.json().catch(() => ({}));
                if (body.restored > 0) window.location.reload();
            }
        } catch (error) {
            this.notice = error.message;
        }
    },
    sessionLabel(session) { return session === 'FULL' ? 'all day' : (session === 'AM' ? 'morning' : 'afternoon'); },

    /* ---- the four states of a sign-in box, painted from the one map the
            legend above the grid is painted from ---- */
    boxStates: @js($boxStates),
    // Signed in on a day nobody planned for. Amber, and marked, so it is not
    // green's colour alone that separates the two.
    isUnplanned(childId, date, session) {
        return this.isPresent(childId, date, session) && ! this.isScheduled(childId, date, session);
    },
    /*
     * Which cells will take a tap.
     *
     * Today, always: a sign-in stamps the moment somebody arrived, and that is
     * what the sheet is open for. Days already gone only while correcting, and
     * only inside this week — a finished week is the record the centre has
     * already reported from, and the server refuses it whatever the sheet
     * offers. Tomorrow never: an arrival that has not happened is a guess.
     *
     * The cell says so by being disabled rather than by taking the tap and
     * refusing it afterwards.
     */
    canSignIn(date) {
        if (date === this.today) return true;

        return this.editing && this.canAmend && date < this.today;
    },

    /**
     * Setting who is expected, on a day that has not happened yet.
     *
     * The other half of Edit mode. Correcting what a past day turned out to be
     * and saying what a future day is meant to be are the same job from the
     * same sheet, and it needs the permission that ticks the checklist rather
     * than the one that amends the register — they are different powers over
     * different things.
     *
     * Today is deliberately not included. The column is live: a tap there
     * signs a child in, and one control that means "they are here" at nine and
     * "they were meant to be here" at nine-oh-one is the way an arrival gets
     * deleted by somebody planning next week.
     */
    canSetExpected(date) {
        return this.editing && this.canEdit;
    },

    /**
     * Whether this column does anything at all when it is tapped.
     *
     * The one predicate the whole sheet reads: the box takes its locked look
     * from it, the header draws its padlock from it, and tapCell() refuses on
     * it. Live, that is today alone; in Edit it is every day the reader has
     * the power over — which is the difference the mode switch is announcing.
     */
    canTap(date) {
        return this.canSignIn(date) || this.canSetExpected(date);
    },

    /** Whether the time on a recorded arrival is open to being retyped. */
    canRetime(childId, date, session) {
        return this.editing && this.canAmend && date <= this.today && this.isPresent(childId, date, session);
    },

    /* ---- the hour being typed, if any ---- */
    retiming: null,   // 'child|date|session' of the cell that is a field right now
    draft: '',        // what has been typed into it

    cellKey(childId, date, session) {
        return childId + '|' + date + '|' + session;
    },
    isRetiming(childId, date, session) {
        return this.retiming === this.cellKey(childId, date, session);
    },

    /**
     * Open the field. From the pencil on a time, or E on any cell in Edit
     * that could take an arrival — which then records one at the typed hour.
     */
    beginRetime(childId, date, session) {
        const present = this.isPresent(childId, date, session);

        // A day still to come is typed into as well, and what is typed is
        // the hour it is booked for. The field is the same field; where the
        // value goes is decided on the way out, in commitRetime.
        if (date > this.today) {
            if (! this.canPlanTime(date) || ! this.isScheduled(childId, date, session)) return;

            this.draft = this.plannedTime(childId, date, session) || '';
            this.retiming = this.cellKey(childId, date, session);

            return;
        }

        if (present ? ! this.canRetime(childId, date, session) : ! (this.editing && this.canSignIn(date))) return;

        this.draft = present ? this.displayTime(childId, date, session) : '';
        this.retiming = this.cellKey(childId, date, session);
    },
    cancelRetime() {
        this.retiming = null;
        this.draft = '';
    },
    endRetime() { this.cancelRetime(); },

    /**
     * Enter, or leaving the field. An empty field changes nothing — a blur
     * with nothing in it is somebody changing their mind, not an instruction.
     */
    commitRetime(childId, date, session) {
        if (! this.isRetiming(childId, date, session)) return;

        const value = this.normalizeTime(this.draft);
        this.cancelRetime();

        if (! value) return;

        // Where the typed hour goes is the whole difference between the two
        // halves of Edit mode: onto the plan for a day to come, into the
        // register for today or a day gone.
        if (date > this.today) return this.setPlannedTime(childId, date, session, value);

        return this.isPresent(childId, date, session)
            ? this.retime(childId, date, session, value)
            : this.signIn(childId, date, session, value);
    },

    /**
     * Whatever was typed, as HH:MM for the server. "8", "8:15", "815",
     * "8.15a", "3p", "15:05", "8:15 AM" all land. Before seven with no
     * suffix is read as afternoon, since no child arrives at three in the
     * morning and plenty leave at three.
     */
    normalizeTime(raw) {
        const text = String(raw || '').trim().toLowerCase().replace(/\s+/g, '');
        const match = /^(\d{1,2})[:.]?(\d{2})?(a|p|am|pm)?$/.exec(text);

        if (! match) return '';

        let hours = parseInt(match[1], 10);
        const minutes = Math.min(match[2] ? parseInt(match[2], 10) : 0, 59);

        if (hours > 23) return '';

        /*
         * A padded hour with no am/pm is already a 24-hour one, and is taken
         * as it stands. That is the form this component writes — nudgeDraft()
         * hands back "06:59" — so without this the arrows read their own
         * output back through the afternoon rule below and a second press
         * jumped from seven in the morning to nearly seven at night.
         *
         * It is also the form a person uses when they mean to be unambiguous:
         * "06:30" is half past six in the morning to anybody who writes the
         * nought.
         */
        if (! match[3] && /^0\d|^1\d|^2[0-3]/.test(match[1]) && match[1].length === 2) {
            return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
        }

        // Otherwise it is shorthand somebody typed, and an hour before seven
        // is the afternoon: no child arrives at three in the morning, and
        // plenty leave at three.
        const suffix = match[3] ? match[3][0] : (hours < 7 || hours === 12 ? 'p' : 'a');

        if (hours <= 12) {
            if (suffix === 'p' && hours !== 12) hours += 12;
            if (suffix === 'a' && hours === 12) hours = 0;
        }

        return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
    },

    /**
     * Move the time in the open field by so many minutes.
     *
     * Wraps within the day rather than running off either end: a drop-off at
     * seven and a pick-up at six are both a couple of presses from anywhere,
     * and an arrow that stopped dead at midnight would only ever be a dead key.
     *
     * An empty field starts from the hour the centre opens, so the first press
     * lands near the morning it is about to record rather than at midnight.
     */
    nudgeDraft(minutes) {
        const from = this.normalizeTime(this.draft) || @js(\App\Models\Child::DAY_OPENS_AT);
        const [hours, mins] = from.split(':').map(Number);

        // Modulo twice: JavaScript's % keeps the sign, so a step below zero
        // would come back negative and format as "-1:59".
        const total = ((hours * 60 + mins + minutes) % 1440 + 1440) % 1440;

        this.draft = String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
    },

    /** "9:04a" as the field wants it: "09:04". */
    timeValue(short) {
        const match = /^(\d{1,2}):(\d{2})([ap])$/.exec(short || '');
        if (! match) return '';
        let hours = Number(match[1]) % 12;
        if (match[3] === 'p') hours += 12;
        return String(hours).padStart(2, '0') + ':' + match[2];
    },

    /* ---- the plan's own hour ----

       A booked day can carry the hour it is booked for. It is not an arrival
       and never becomes one by itself: nothing is written to `attendances`
       until somebody actually walks in, so a Thursday with six planned hours
       on it still reports nobody present. See ScheduleController::plannedTime.
    */

    /**
     * Whether the child's own record names this weekday.
     *
     * Only ever used to word a tooltip. The registered days are where a
     * box starts — WeekSchedule seeds the week from them — and after that
     * the box is the answer, because the reason to open a future column is
     * usually that this particular week is not the usual one. A child down
     * for every weekday can be dotted off tomorrow in one tap, and this is
     * how the cell explains itself when somebody hovers to check.
     */
    isRegisteredDay(childId, date) {
        const child = this.childrenData.find(row => row.id === childId);

        // A record that has never named a pattern does not disagree with
        // anything, so there is nothing to point out.
        return !! child && this.childDays(child) !== null && ! this.offPattern(child, date);
    },

    /** "08:00", or null for a day booked with no hour agreed. */
    plannedTime(childId, date, session) {
        return this.planned?.[childId]?.[date]?.[session] ?? null;
    },
    hasPlanned(childId, date, session) {
        return this.plannedTime(childId, date, session) !== null;
    },

    /** "08:00" → "8:00a": the same short clock an arrival is drawn in. */
    shortTime(value) {
        const match = /^(\d{1,2}):(\d{2})/.exec(value || '');
        if (! match) return '';

        const hours = Number(match[1]);

        return ((hours % 12) || 12) + ':' + match[2] + (hours < 12 ? 'a' : 'p');
    },
    plannedLabel(childId, date, session) {
        return this.shortTime(this.plannedTime(childId, date, session));
    },

    /**
     * Whether an hour may be put on this day.
     *
     * Days still to come only. Today and the days behind it have a real
     * arrival time or a blank that means nobody came, and an intention printed
     * over either would be read as the fact. The server draws the same line —
     * this is the affordance, that is the rule.
     */
    canPlanTime(date) {
        return this.editing && this.canEdit && date > this.today && ! this.isClosed(date);
    },

    /** Whether this box is currently showing a planned hour rather than a tick. */
    isPlanned(childId, date, session) {
        return date > this.today
            && this.isScheduled(childId, date, session)
            && this.hasPlanned(childId, date, session);
    },

    /**
     * Put an hour on a booked day, or take it off.
     *
     * Optimistic, like the ticks beside it: the box moves and the request
     * follows, because the alternative is a grid that lags a drag across a
     * row. A refusal puts the old value back and says why.
     */
    async setPlannedTime(childId, date, session, value) {
        if (! this.canPlanTime(date)) return;

        const before = this.plannedTime(childId, date, session);

        this.writePlanned(childId, date, session, value);

        try {
            const response = await this.post("{{ route('attendance.schedule.time') }}", {
                child_id: childId,
                slot_date: date,
                session,
                planned_time: value,
            });

            if (! response.ok) {
                const problem = await response.json().catch(() => ({}));
                const firstError = Object.values(problem.errors ?? {}).flat()[0];

                throw new Error(firstError || problem.message || 'Could not set that hour.');
            }

            const data = await response.json();

            this.writePlanned(childId, date, session, data.planned_time);

            // An hour implies the day, and the server ticks it. Match that here
            // rather than leaving a box showing an hour on a day marked "not
            // attending" until the next reload.
            if (data.is_scheduled && ! this.isScheduled(childId, date, session)) {
                this.schedule[childId][date][session] = true;
            }
        } catch (error) {
            this.writePlanned(childId, date, session, before);
            this.notice = error.message;
        }
    },

    /** The one place the sparse map is written, so Alpine always sees it move. */
    writePlanned(childId, date, session, value) {
        const forChild = {...(this.planned[childId] ?? {})};
        const forDate = {...(forChild[date] ?? {})};

        if (value === null || value === undefined || value === '') {
            delete forDate[session];
        } else {
            forDate[session] = value;
        }

        forChild[date] = forDate;
        this.planned = {...this.planned, [childId]: forChild};
    },
    /**
     * What the cell shows: "11:54a", morning or afternoon, whole day or half.
     *
     * A whole day used to read "11:52 AM" while the two halves beside it read
     * "11:54a" — the same reading written two ways in one column, so comparing
     * two arrivals meant measuring two shapes. The short form is the one that
     * has to fit, because a half day shares its cell with its twin, and a
     * format that fits the tightest cell fits every other.
     */
    displayTime(childId, date, session) {
        return this.sessionTime(childId, date, session);
    },

    /* ---- the box, in two bindings ----

       See the note at the top of partials/day-buttons.blade.php for why. The
       short of it: four hundred boxes, and what each one costs to build is
       most of what the sheet costs to open. */

    /** Anything that could be read back out as markup goes through here. */
    esc(value) {
        return String(value ?? '').replace(/[&<>"]/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
        }[character]));
    },

    /**
     * What the box is: its state, whether it takes a tap, and what a screen
     * reader is told. data-cell is how the one handler on the table works out
     * which box was pressed, so it is the tap target's identity as well.
     */
    cellAttrs(child, date, session) {
        /*
         * Only what never changes for the life of the element.
         *
         * An x-bind object is applied once, at mount, and never re-evaluated
         * — Alpine turns each key into a static literal. That is fine for the
         * identity of the box and for whether it can be tapped at all, because
         * a mode switch reloads the whole sheet. It is not fine for anything
         * that changes when the cell's state does: class, title and label are
         * bound live in the partial instead. Putting the class here is how a
         * tapped-off day kept its dashed box around the dot.
         */
        const tappable = this.canTap(date);

        return {
            'role': tappable ? 'button' : null,
            'tabindex': tappable ? 0 : null,
            'data-cell': this.cellKey(child.id, date, session),
        };
    },

    /**
     * What is in the box: the half-day tag, then one of the three states, then
     * the pencil if the hour can be retyped.
     *
     * An expected day is deliberately empty — the outline is the statement.
     */
    cellInner(child, date, session) {
        const present = this.isPresent(child.id, date, session);
        const closed = this.isClosed(date);

        let html = session === 'FULL' ? '' : '<span class="att-tag">' + this.esc(session) + '</span>';

        html += '<span class="att-main">';

        if (present) {
            html += '<span>' + this.esc(this.displayTime(child.id, date, session)) + '</span>';
        } else if (closed) {
            html += '<span aria-hidden="true">&mdash;</span>';
        } else if (this.isPlanned(child.id, date, session)) {
            // The hour a day to come is agreed for, inside the same dashed box
            // an expected day already wears. It is the box that says "coming";
            // the hour only says when.
            html += '<span class="att-due">' + this.esc(this.plannedLabel(child.id, date, session)) + '</span>';
        } else if (! this.isScheduled(child.id, date, session)) {
            /*
             * The dot: not coming, on any day of the week.
             *
             * It reads the same either side of today, and that is the point. A
             * future column briefly had a filled dot for "coming" and a hollow
             * ring for "not", which made the dot mean one thing on Thursday and
             * the opposite on Tuesday, in columns three inches apart. One mark,
             * one meaning, across the whole sheet.
             */
            html += '<span class="att-dot" aria-hidden="true"></span>';
        }

        html += '</span>';

        // The pencil: the exact hour, typed. Only on a time, only in Edit. The
        // pad keeps a half-day box the same width as one that has a pencil.
        if (this.canRetime(child.id, date, session)) {
            html += '<button type="button" class="att-pencil" data-pencil aria-label="'
                + this.esc('Type an exact time for ' + child.name) + '">' + this.icons.pencil + '</button>';
        } else if (this.canPlanTime(date) && this.isScheduled(child.id, date, session)) {
            // On a booked day still to come the pencil is where the hour
            // lives — set, changed or cleared — so the tap is left free to
            // mean the one thing it should: coming, or not.
            html += '<button type="button" class="att-pencil" data-pencil aria-label="'
                + this.esc('Type the hour ' + child.name + ' is booked in for') + '">' + this.icons.pencil + '</button>';
        } else if (session !== 'FULL') {
            html += '<span class="att-pad" aria-hidden="true"></span>';
        }

        return html;
    },

    /**
     * Every tap on the sheet, caught once.
     *
     * Bound on the table rather than on each box: four hundred click handlers
     * and twelve hundred key handlers were being created on load to do what
     * two can do. The box says which child, day and session it is through
     * data-cell, which is all either of these needs.
     */
    onCellClick(event) {
        const box = event.target.closest('[data-cell]');

        if (! box) return;

        const [childId, date, session] = box.dataset.cell.split('|');

        // The pencil sits inside the box, and pressing it must not also move
        // the box along — what @click.stop used to do on the button itself.
        if (event.target.closest('[data-pencil]')) {
            return this.beginRetime(Number(childId), date, session);
        }

        this.tapCell(Number(childId), date, session);
    },

    onCellKey(event) {
        const box = event.target.closest('[data-cell]');

        if (! box) return;

        const [childId, date, session] = box.dataset.cell.split('|');

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();

            return this.tapCell(Number(childId), date, session);
        }

        if (event.key === 'e' || event.key === 'E') {
            event.preventDefault();

            this.beginRetime(Number(childId), date, session);
        }
    },

    /* ---- the box itself ---- */
    sessionsById: null,
    sessionCount(childId) {
        if (this.sessionsById === null) {
            this.sessionsById = {};
            this.childrenData.forEach(child => { this.sessionsById[child.id] = (child.sessions || ['FULL']).length; });
        }
        return this.sessionsById[childId] ?? 1;
    },

    /**
     * The classes for one box — see "The attendance cell" in app.css.
     * State first, then the two things that quieten it: a day gone by, and a
     * column this mode cannot touch.
     */
    cellClass(childId, date, session) {
        const classes = ['att-cell'];
        const present = this.isPresent(childId, date, session);

        if (this.sessionCount(childId) > 1) classes.push('att-half');

        if (present) {
            classes.push('att-time');
            if (this.isUnplanned(childId, date, session)) classes.push('att-unplanned');
        } else if (this.isClosed(date)) {
            classes.push('att-closed');
        } else if (this.isScheduled(childId, date, session)) {
            classes.push('att-expected');
        } else {
            classes.push('att-none');
        }

        /*
         * No quietening of days gone by.
         *
         * A past arrival used to lose its box — the time stayed, the outline
         * went — so that the week read as a slope down into today. The centre
         * asked for the opposite: the same three marks, drawn the same way,
         * whichever column they are in. A box round a time means "they came"
         * on Monday exactly as it does on Wednesday, and a reader correcting
         * last week should see the same sheet they see today. What still
         * quietens a column is being untouchable, and that is .att-locked.
         */
        if (! this.canTap(date)) classes.push('att-locked');

        return classes.join(' ');
    },

    cellLabel(child, date, session) {
        /*
         * A day to come that has an hour on it says so, because "expected" and
         * "expected at 8:30" are different amounts of knowing and the box looks
         * the same for both. Everything else keeps the sheet's own words: the
         * marks read the same either side of today, so the labels do too.
         */
        const ahead = this.isPlanned(child.id, date, session)
            ? 'expected at ' + this.plannedLabel(child.id, date, session)
            : null;

        const state = this.isPresent(child.id, date, session)
            ? this.displayTime(child.id, date, session)
            : (this.isClosed(date) ? 'centre closed' : (ahead || (this.isScheduled(child.id, date, session) ? 'expected' : 'not attending')));
        const half = session === 'FULL' ? '' : ' ' + this.sessionLabel(session);

        return child.name + ' ' + this.dayLabel(date) + half + ': ' + state + (this.canTap(date) ? '' : ', locked');
    },
    /*
     * Whether this cell was put right by hand, and by whom.
     *
     * Worth showing, because the register stopped being self-evidently a record
     * of what happened the moment days already gone became editable. A row that
     * somebody added in October to a week in September is a different kind of
     * fact from one stamped at the door, and the person reconciling the month
     * is the one who needs to be able to tell.
     */
    amendment(childId, date, session) {
        return this.amended[childId + '|' + date + '|' + session] ?? null;
    },

    // Tapping an arrival takes it back off, but only in the mode where undoing
    // things is what you came to do. On the live sheet a filled cell is inert,
    // so a passing elbow cannot delete this morning.
    canRemove(childId, date, session) {
        return this.editing && this.canAmend && this.isPresent(childId, date, session);
    },
    boxClass(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            const filled = this.boxStates[this.isUnplanned(childId, date, session) ? 'unplanned' : 'present'].classes;

            // A dashed edge on an arrival that was entered after the fact. The
            // fill still says what it says — they were here, and whether it was
            // planned — and the broken edge says nobody was at the door for it.
            const width = session === 'FULL' ? ' min-w-[4.9333rem]' : ' min-w-[3.2rem]';

            return filled + width + (this.amendment(childId, date, session) ? ' border-dashed' : '');
        }
        // Closed beats booked: a day nobody could attend is not "expected", and
        // the closure clears the ticks anyway. It sits below a real arrival,
        // though — a child who was here on a day the centre was shut is a fact
        // about the day, not a thing to hide.
        let base = this.isClosed(date)
            ? this.boxStates.closed.classes
            : this.boxStates[this.isScheduled(childId, date, session) ? 'scheduled' : 'off'].classes;

        // Nothing to click here, so nothing that looks clickable: the hover
        // fills come off and the box is faded to sit behind today's column.
        if (! this.canTap(date)) {
            base = base.replace(/\s*(dark:)?hover:\S+/g, '') + ' cursor-not-allowed opacity-60';
        }

        // No ring here. The box is sky now, and a sky ring on a sky box read
        // as a heavier box rather than a second meaning — two boxes on one
        // row that should have matched did not. The disagreement is still
        // marked where the plan is made: the checklist keeps the ring, and
        // the hover on this cell still says it.

        // A morning and an afternoon share a cell, so each gets half the room a
        // whole day takes. Both are wide enough for a time, which is what they
        // hold the moment a child arrives.
        return base + (session === 'FULL' ? ' min-w-[4.9333rem]' : ' min-w-[3.2rem]');
    },
    // What the cell says. A child who has arrived is the time they arrived —
    // the fact anybody opening this sheet is looking for — and everything else
    // is a placeholder standing in until then.
    boxLabel(childId, date, session) {
        if (this.isPresent(childId, date, session)) return this.sessionTime(childId, date, session);
        if (this.isClosed(date)) return '—';

        // Booked for an hour, on a day that has not happened. Drawn in the
        // box an arrival would fill, in the outlined style of the plan — so
        // it reads as the hour somebody is due rather than the hour they came.
        if (this.isPlanned(childId, date, session)) return this.plannedLabel(childId, date, session);

        if (session !== 'FULL') return session;

        // An empty dashed box for a booked day, and a dot for a day nobody
        // booked. The box used to say "expected" in it; the word was doing
        // what the outline already does, sixty times down the sheet. The dot
        // stays, because a dot is the quietest mark there is and it is the
        // commonest cell.
        return this.isScheduled(childId, date, session) ? '' : '·';
    },
    boxTitle(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            const note = this.isScheduled(childId, date, session)
                ? 'Signed in ' + this.sessionTime(childId, date, session)
                : 'Signed in ' + this.sessionTime(childId, date, session) + ' — not scheduled, still billable';

            // Who put it right, and when. The register can be corrected now, so
            // it has to be able to say which rows were.
            const amended = this.amendment(childId, date, session);

            if (! amended) return note;

            const did = {added: 'Added', removed: 'Removed', retimed: 'Time changed'}[amended.action] || 'Changed';

            return note + '\n' + did + ' by ' + amended.by + ' on ' + amended.on;
        }
        // In the mode where taps undo things, say so on the cell that will.
        if (this.canRemove(childId, date, session)) {
            return 'Signed in ' + this.displayTime(childId, date, session) + ' — tap: not attending. Pencil: type the exact time.';
        }

        // The reason the column is empty, on every cell in it — the header
        // carries it once, and a row read across does not pass the header.
        if (this.isClosed(date)) {
            return this.closureReason(date) + (this.canSignIn(date) ? ' — tap to sign in anyway' : '');
        }

        // In Edit an empty cell is the plan for the day, and the tap flips it.
        if (this.canSetExpected(date)) {
            if (date > this.today) {
                if (! this.isScheduled(childId, date, session)) {
                    // Said on the cell that overrides it. A record saying
                    // Mon–Fri and a dot on Thursday is not a contradiction —
                    // it is somebody having been told the child is off.
                    return this.isRegisteredDay(childId, date)
                        ? 'Not attending, though their record says this is one of their days. Tap: expected.'
                        : 'Not attending. Tap: expected.';
                }

                if (this.hasPlanned(childId, date, session)) {
                    return 'Expected at ' + this.plannedLabel(childId, date, session)
                        + ' — the hour agreed, not an arrival. Nobody is marked present.'
                        + '\nTap: not attending. Pencil: change the hour.';
                }

                return this.canPlanTime(date)
                    ? 'Expected. Tap: not attending. Pencil: the hour they are due.'
                    : 'Expected. Tap: not attending.';
            }

            return this.isScheduled(childId, date, session)
                ? 'Expected. Tap: record an arrival. E: type the hour.'
                : 'Not attending. Tap: expected.';
        }

        // Say which day can be signed in, on the box that cannot: the sheet
        // shows five columns and "only today" does not say which one.
        if (! this.canSignIn(date)) {
            const scheduled = this.isScheduled(childId, date, session) ? 'Scheduled. ' : '';
            const ahead = date > this.today && this.canEdit
                ? ' Press Edit to set who is expected.'
                : '';

            return scheduled + 'Only ' + this.todayLabel + ' can be signed in.' + ahead;
        }

        const base = this.isScheduled(childId, date, session) ? 'Scheduled. Click to sign in.' : 'Not scheduled. Click to sign in anyway.';
        const note = this.projectionNote(childId, date, session);

        return note ? base + ' ' + note : base;
    },

    /* ---- counts shown under the checklist ---- */
    get slotCount() { return this.eachSlot().length; },
    get scheduledCount() { return this.eachSlot().filter(s => this.isScheduled(s.child_id, s.slot_date, s.session)).length; },
    eachSlot() {
        const slots = [];
        this.scheduleChildren.forEach(child => {
            Object.keys(this.schedule?.[child.id] ?? {}).forEach(date => {
                Object.keys(this.schedule[child.id][date]).forEach(session => {
                    slots.push({child_id: child.id, slot_date: date, session});
                });
            });
        });
        return slots;
    },
    rowSummary(child) {
        const days = Object.keys(this.schedule?.[child.id] ?? {});
        let on = 0, total = 0;
        days.forEach(date => Object.keys(this.schedule[child.id][date]).forEach(session => {
            total++;
            if (this.isScheduled(child.id, date, session)) on++;
        }));
        return on + ' of ' + total;
    },

    /* ---- ticking: one box, a drag, a preset or a whole column ---- */
    setSlot(childId, date, session, value) {
        // Nobody is scheduled on a day the centre is shut, so a tick there is
        // refused here as well as on the server.
        if (this.isClosed(date)) return false;
        if (!this.hasSlot(childId, date) || this.isScheduled(childId, date, session) === value) return false;
        this.schedule[childId][date][session] = value;
        this.pending[childId + '|' + date + '|' + session] = true;
        return true;
    },
    toggleOne(childId, date, session) {
        if (!this.canEdit) return;
        const value = !this.isScheduled(childId, date, session);
        if (this.setSlot(childId, date, session, value)) this.flush(value);
    },
    startPaint(childId, date, session) {
        if (!this.canEdit) return;
        this.paint = !this.isScheduled(childId, date, session);
        this.setSlot(childId, date, session, this.paint);
    },
    paintAt(event) {
        if (this.paint === null) return;
        const cell = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-slot]');
        if (!cell) return;
        const [childId, date, session] = cell.dataset.slot.split('|');
        this.setSlot(Number(childId), date, session, this.paint);
    },
    endPaint() {
        if (this.paint === null) return;
        const value = this.paint;
        this.paint = null;
        this.flush(value);
    },
    /* ---- quick set: the days on their record, or every day ---- */

    /**
     * The ISO weekday of each column, so a pattern registered as "1, 3, 5" can
     * be read against the dates this particular week happens to hold.
     */
    weekdayNumbers: @js($weekDates->map(fn ($date) => $date->dayOfWeekIso)->values()),

    /** Their registered pattern, or null for a record that has never said. */
    childDays(child) {
        return Array.isArray(child.schedule_days) ? child.schedule_days : null;
    },

    /**
     * A day their standing arrangement does not cover.
     *
     * Drawn back rather than taken away: it is the printed register's shaded
     * box, which keeps a white interior precisely so an unplanned day can
     * still be marked in it. A record that has never named a pattern has no
     * off days — every day is as likely as any other for them.
     */
    offPattern(child, date) {
        const days = this.childDays(child);
        if (days === null) return false;

        const index = @js($weekDates->map->toDateString()).indexOf(date);

        return index !== -1 && ! days.includes(this.weekdayNumbers[index]);
    },

    /**
     * What the button says. "M W F" for a registered pattern, because the
     * days are the whole of what the button does and a row of five children
     * with five different arrangements has to be read down at a glance.
     */
    presetLabel(child) {
        const days = this.childDays(child);
        if (days === null) return 'All';
        if (days.length === 0) return '—';
        if (days.length === 5) return 'Full';

        const letters = {1: 'M', 2: 'T', 3: 'W', 4: 'Th', 5: 'F'};
        return days.map(day => letters[day]).join(' ');
    },

    presetTitle(child) {
        const days = this.childDays(child);
        if (days === null) return 'Every open day this week — no days are set on their record';
        if (days.length === 0) return 'Their record says no days. Set them on the child’s profile first.';

        return 'Tick ' + (child.schedule_days_label || '') + ' — the days on their record — and clear the rest';
    },

    /** [] is a real answer ("not currently coming"), and Clear already says it. */
    presetDisabled(child) {
        const days = this.childDays(child);
        return days !== null && days.length === 0;
    },

    applyPreset(child, preset) {
        if (!this.canEdit) return;

        const days = this.childDays(child);

        // 'days' is the row's own pattern; a record that has never named one
        // falls back to every open day, which is what the button then says.
        const wanted = index => {
            if (preset === 'none') return false;
            if (days === null) return true;

            return days.includes(this.weekdayNumbers[index]);
        };

        @js($weekDates->map->toDateString()).forEach((date, index) => {
            (child.sessions || []).forEach(session => this.setSlot(child.id, date, session, wanted(index)));
        });

        // A preset both ticks and unticks, so send the two halves separately.
        this.flushMixed();
    },
    toggleColumn(date) {
        if (!this.canEdit) return;
        const slots = [];
        // One day for everyone, exactly as the column header says.
        this.scheduleChildren.forEach(child => {
            if (!this.hasSlot(child.id, date)) return;
            (child.sessions || []).forEach(session => slots.push({child: child.id, session}));
        });
        const allOn = slots.every(s => this.isScheduled(s.child, date, s.session));
        slots.forEach(s => this.setSlot(s.child, date, s.session, !allOn));
        this.flush(!allOn);
    },

    /* ---- persistence: the browser batches, the server writes ---- */
    flush(value) {
        const slots = Object.keys(this.pending).map(entry => {
            const [child_id, slot_date, session] = entry.split('|');
            return {child_id: Number(child_id), slot_date, session};
        });
        this.pending = {};
        if (slots.length) this.persist(slots, value);
    },
    flushMixed() {
        // Split the pending set by the value each slot now holds.
        const on = [], off = [];
        Object.keys(this.pending).forEach(entry => {
            const [child_id, slot_date, session] = entry.split('|');
            (this.isScheduled(Number(child_id), slot_date, session) ? on : off)
                .push({child_id: Number(child_id), slot_date, session});
        });
        this.pending = {};
        if (on.length) this.persist(on, true);
        if (off.length) this.persist(off, false);
    },
    async persist(slots, value) {
        this.saving = true;
        this.saveError = '';
        try {
            const response = await this.post("{{ route('attendance.schedule.update') }}", {
                week_start: this.weekStart,
                is_scheduled: value,
                slots,
            });
            if (!response.ok) throw new Error('save failed');
        } catch (error) {
            this.saveError = 'Could not save the schedule. Reload and try again.';
        } finally {
            this.saving = false;
        }
    },
    // What a tap means depends on what is already in the cell and on the mode.
    // Live: an arrival at the door, and nothing else. Edit: the box moves one
    // step along its cycle — see cycle() — and a tap that can be tapped again
    // is its own undo, which is why nothing here asks first.
    tapCell(childId, date, session) {
        if (! this.canTap(date)) return;

        // Live: today, at the door. A tap is an arrival and nothing else — a
        // recorded one is left alone, so a passing elbow cannot delete a
        // morning.
        if (! this.editing) {
            if (date === this.today && ! this.isPresent(childId, date, session)) return this.signIn(childId, date, session);

            return;
        }

        return this.cycle(childId, date, session);
    },

    /**
     * Move the box along.
     *
     *   ahead of today   dot → expected → hour → dot (the plan)
     *   today or gone    dot → expected → time → dot (the plan, then the fact)
     *
     * The two read alike on purpose and mean different things. On a day to
     * come the hour is what the child is booked in for and nothing is
     * written to the register; on today or a day gone it is the hour they
     * actually arrived, and an attendance row says so.
     *
     * The step onto "time" records an arrival: now if it is today, the hour
     * the day was agreed for if it has gone. The step off it takes the
     * arrival back and clears the tick with it — a dot means not attending,
     * and a day not attended was not, in the end, expected either.
     */
    cycle(childId, date, session) {
        const present = this.isPresent(childId, date, session);
        const expected = this.isScheduled(childId, date, session);

        if (date > this.today) {
            if (! this.canSetExpected(date)) return;

            /*
             * Coming, or not coming. One tap, both ways.
             *
             * This is the question a future column is actually opened to
             * answer, and the commonest answer is the awkward one: a child
             * whose record says every weekday, off tomorrow. Their
             * registered days are what the box started as, not a thing the
             * box has to argue with — one tap and it is a dot.
             *
             * The hour used to sit between the two as a middle step, which
             * put a second tap in front of the excuse to save a keystroke
             * on the rarer job. It is on the pencil instead.
             */
            if (expected && this.hasPlanned(childId, date, session)) {
                // A day nobody is coming on has no hour either.
                this.setPlannedTime(childId, date, session, null);
            }

            this.toggleOne(childId, date, session);

            return;
        }

        if (present) {
            if (! this.canRemove(childId, date, session)) return;

            this.removeSignIn(childId, date, session);
            if (expected && this.canSetExpected(date)) this.toggleOne(childId, date, session);

            return;
        }

        if (! expected && this.canSetExpected(date)) {
            this.toggleOne(childId, date, session);

            return;
        }

        if (this.canSignIn(date)) this.signIn(childId, date, session);
    },
    /** "Monday, Sep 14" — the sheet shows five columns, so a day needs naming. */
    dayLabel(date) {
        return new Date(date + 'T00:00:00').toLocaleDateString(undefined, {
            weekday: 'long', month: 'short', day: 'numeric',
        });
    },

    /**
     * Move the hour on an arrival already recorded.
     *
     * The field saves on change, with no dialog: the typed value is the
     * decision, and a question after it would only ask the same thing twice.
     * The old hour is kept server-side in the amendment, not here.
     */
    async retime(childId, date, session, value) {
        if (! this.canRetime(childId, date, session) || ! value) return;
        if (value === this.timeValue(this.sessionTime(childId, date, session))) return;

        try {
            const response = await this.post("{{ route('attendance.signin.retime') }}", {
                child_id: childId,
                attendance_date: date,
                session,
                signed_in_time: value,
            });

            if (! response.ok) {
                const problem = await response.json().catch(() => ({}));
                const firstError = Object.values(problem.errors ?? {}).flat()[0];

                throw new Error(firstError || problem.message || 'Could not move that arrival.');
            }

            const data = await response.json();

            this.attendance[childId][date][session] = data.time;
            if (data.amendment) this.amended[this.cellKey(childId, date, session)] = data.amendment;
            this.endRetime();

            // The drawer quotes the time, so it moves there too.
            this.recent = this.recent.map(signIn => signIn.child_id === childId && signIn.date === date && signIn.session === session
                ? {...signIn, time: data.time}
                : signIn);
        } catch (error) {
            this.notice = error.message;
        }
    },
    async removeSignIn(childId, date, session) {
        try {
            const response = await this.post("{{ route('attendance.signin.remove') }}", {
                child_id: childId,
                attendance_date: date,
                session,
            });

            if (! response.ok) {
                const problem = await response.json().catch(() => ({}));
                const firstError = Object.values(problem.errors ?? {}).flat()[0];

                throw new Error(firstError || problem.message || 'Could not take that arrival off.');
            }

            delete this.attendance[childId]?.[date]?.[session];

            // The drawer lists arrivals, so one taken back off leaves it too.
            this.recent = this.recent.filter(signIn => ! (signIn.child_id === childId && signIn.date === date && signIn.session === session));
        } catch (error) {
            this.notice = error.message;
        }
    },
    async signIn(childId, date, session, time = null) {
        if (this.isPresent(childId, date, session)) {
            return;
        }

        try {
            const response = await this.post("{{ route('attendance.signin') }}", {
                child_id: childId,
                attendance_date: date,
                session,
                // Only when typed. At the door the server stamps the moment.
                ...(time ? {signed_in_time: time} : {}),
            });

            if (!response.ok) {
                // A 422 carries the rule that refused. Read whichever field it
                // came from rather than only the date: the rules that catch a
                // stale tab — a child off the roll, a session their room does
                // not use — are the ones whose wording actually helps, and
                // reading one field meant falling back to "unable to sign in"
                // for exactly those.
                const problem = await response.json().catch(() => ({}));
                const firstError = Object.values(problem.errors ?? {}).flat()[0];

                throw new Error(firstError || problem.message || 'Unable to sign in right now. Please try again.');
            }

            const data = await response.json();

            if (data.success) {
                this.attendance[childId] = this.attendance[childId] || {};
                this.attendance[childId][date] = this.attendance[childId][date] || {};
                this.attendance[childId][date][data.session] = data.time;

                // A day gone by got this row from a hand, and the cell says so
                // at once rather than after a reload.
                if (data.amendment) this.amended[this.cellKey(childId, date, data.session)] = data.amendment;


                // Keyed as well as labelled, so an arrival taken back off can be
                // found here and removed rather than lingering in the drawer.
                this.recent.unshift({id: 'new-'+childId+'-'+date+'-'+session, child_id: childId, date, session, name: data.child.name, classroom: data.child.classroom, time: data.time});
                this.recent = this.recent.slice(0, 10);
            }
        } catch (error) {
            this.notice = error.message;
        }
    }
}; }
</script>
@endsection
