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
        'present' => [
            'label' => 'signed in',
            'swatch' => '8:42a',
            'classes' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-400/40 dark:bg-emerald-500/15 dark:text-emerald-200',
        ],
        'unplanned' => [
            'label' => 'unplanned, still billable',
            'swatch' => '9:05a',
            'classes' => 'border-amber-400 bg-amber-50 text-amber-800 dark:border-amber-400/50 dark:bg-amber-500/15 dark:text-amber-100',
        ],
        'scheduled' => [
            'label' => 'scheduled, not in yet',
            // An empty box. The word came off the cells: the box is the mark.
            'swatch' => '',
            // Sky, like today's column: a promise of the day, drawn in the
            // colour the sheet already uses for the day it is about.
            'classes' => 'border-dashed border-sky-400 bg-white text-slate-500 hover:border-sky-600 hover:text-sky-700 dark:border-sky-500/70 dark:bg-transparent dark:text-slate-400 dark:hover:border-sky-400',
        ],
        'off' => [
            'label' => 'not scheduled — tap to sign in anyway',
            'swatch' => '·',
            'classes' => 'border-transparent bg-transparent text-slate-300 hover:text-indigo-500 dark:text-slate-600 dark:hover:text-indigo-300',
        ],
        /*
         * A day the centre was shut.
         *
         * It used to draw the same dot as "nobody booked this day", so two
         * columns that mean entirely different things — nobody was expected,
         * and nobody could have come — read identically down the sheet. The
         * header said LABOUR DAY and the two hundred cells under it said
         * nothing at all.
         *
         * A rule rather than a dot, in the same rose the header's closure chip
         * uses, so the mark and the reason for it are visibly the same fact.
         * Still a button: a closed day that somebody did open takes a sign-in,
         * and the record has to be able to say so.
         */
        'closed' => [
            'label' => 'centre closed',
            'swatch' => '—',
            'classes' => 'border-transparent bg-transparent text-rose-300 hover:text-rose-500 dark:text-rose-500/50 dark:hover:text-rose-300',
        ],
    ];
@endphp
<div x-data="attendanceApp()" @keydown.escape.window="recentOpen = false" @pointermove.window="paintAt($event)" @pointerup.window="endPaint()" @pointercancel.window="endPaint()">
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

                    {{-- The week as one control: a step either side of the range it
                         is showing, rather than two buttons and a label apart. --}}
                    <div class="flex items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                        <a href="{{ route('attendance.index', ['date' => $prevWeek]) }}" class="grid h-6 w-6 place-items-center rounded-md text-slate-500 transition hover:bg-white hover:text-slate-900 dark:hover:bg-night-700 dark:hover:text-night-950" title="Week of {{ \Illuminate\Support\Carbon::parse($prevWeek)->format('M j') }}" aria-label="Previous week">‹</a>
                        <span class="px-1.5 text-xs font-semibold tabular-nums">{{ $weekDates->first()->format('M j') }} – {{ $weekDates->last()->format($weekDates->first()->format('M') === $weekDates->last()->format('M') ? 'j' : 'M j') }}</span>
                        <a href="{{ route('attendance.index', ['date' => $nextWeek]) }}" class="grid h-6 w-6 place-items-center rounded-md text-slate-500 transition hover:bg-white hover:text-slate-900 dark:hover:bg-night-700 dark:hover:text-night-950" title="Week of {{ \Illuminate\Support\Carbon::parse($nextWeek)->format('M j') }}" aria-label="Next week">›</a>
                    </div>
                    {{-- Beside the dates, always: on another week it is the way
                         back, and on this one it is the label that says the dates
                         beside it are the current week rather than one you have
                         stepped to and forgotten. --}}
                    @if($weekStartDate === $thisWeek)
                        <span class="rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white dark:bg-white dark:text-slate-900" aria-current="date">This week</span>
                    @else
                        <a href="{{ route('attendance.index') }}" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10">This week</a>
                    @endif

                    {{-- Jumping to a far-off week, beside the two controls that
                         step to a near one. It was behind the "..." with a "Go to
                         that week" button under it, which is two clicks and a
                         hunt for something that belongs with the arrows either
                         side of the dates.

                         No button: picking a date is the whole instruction, and a
                         second press to confirm a date you have just chosen is a
                         press that only ever means yes. The form still submits
                         normally for anyone without JavaScript. --}}
                    <form method="GET" action="{{ route('attendance.index') }}" class="flex items-center gap-1">
                        <label class="sr-only" for="attendance-date">Jump to a week</label>
                        <input id="attendance-date" type="date" name="date" value="{{ $selectedDate }}"
                               @change="$el.form.submit()"
                               class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs tabular-nums text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:bg-slate-800 dark:text-slate-300"
                               title="Jump to the week containing a date">
                        {{-- Only reached with scripting off, where the change
                             handler above never fires. --}}
                        <noscript><button class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600">Go</button></noscript>
                    </form>

                    {{-- Counts as a sentence rather than three chips: the numbers
                         are the point, so they carry the colour and the weight and
                         the labels stay out of the way. --}}
                    <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{-- The room being looked at, when it is not the whole
                             centre: three numbers that quietly changed meaning
                             when a filter was clicked would be worse than three
                             numbers that never moved. --}}
                        <span x-show="room !== ''" x-cloak class="font-semibold text-slate-700 dark:text-slate-200" x-text="room"></span>
                        <span><b class="font-bold text-slate-900 dark:text-white" x-text="enrolledCount"></b> enrolled</span>
                        <span><b class="font-bold text-emerald-600 dark:text-emerald-400" x-text="presentCount"></b> in</span>
                        <span><b class="font-bold text-rose-600 dark:text-rose-400" x-text="absentCount"></b> not in</span>
                    </p>

                    <div class="ml-auto flex items-center gap-1.5">
                        {{-- Searching is the way a big roll is used, so the box is on
                             the top line rather than below the filters. --}}
                        <label x-show="view === 'signin'" class="relative hidden sm:block">
                            <svg class="pointer-events-none absolute left-2.5 top-1.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                            <input x-model="search" class="w-44 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:w-56 focus:ring-2 focus:ring-indigo-500 lg:w-56 dark:border-white/10 dark:bg-slate-800" placeholder="Search name or LAN">
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
                                <button type="button" x-ref="printer" @click.stop="menu ? menu = false : open()" :aria-expanded="menu" aria-haspopup="true" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10" title="Print the register — landscape Letter">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V4h12v5M6 18H4a1 1 0 01-1-1v-6a1 1 0 011-1h16a1 1 0 011 1v6a1 1 0 01-1 1h-2M6 14h12v6H6z"/></svg>
                                    Print attendance
                                </button>

                                <template x-teleport="body">
                                <div x-show="menu" x-cloak x-transition @click.outside="menu = false" :style="`top: ${y}px; left: ${x}px`" class="fixed z-50 w-[200px] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl dark:border-white/10 dark:bg-slate-900">
                                    @php($printMonth = \Illuminate\Support\Carbon::parse($weekStartDate))
                                    <a href="{{ route('attendance.print', ['date' => $weekStartDate]) }}" target="_blank" rel="noopener" @click="menu = false" class="block px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:hover:bg-white/10">
                                        This week
                                        <span class="mt-0.5 block text-[10.5px] font-normal text-slate-400">{{ $weekDates->first()->format('M j') }} – {{ $weekDates->last()->format('M j') }} · one page</span>
                                    </a>
                                    <a href="{{ route('attendance.print', ['date' => $weekStartDate, 'range' => 'month']) }}" target="_blank" rel="noopener" @click="menu = false" class="block px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:hover:bg-white/10">
                                        Whole month
                                        <span class="mt-0.5 block text-[10.5px] font-normal text-slate-400">{{ $printMonth->format('F Y') }} · a page per week</span>
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
                            <button type="button" x-show="view === 'signin'" @click="editing = ! editing; retiming = null; drafting = null"
                                    :class="editing ? 'bg-amber-500 text-white hover:bg-amber-600' : 'border border-slate-200 text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10'"
                                    class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                                    :title="editing ? 'Stop editing — the sheet goes back to today only' : 'Correct a day gone, or set who is expected on one still to come'"
                                    x-text="editing ? 'Done' : 'Edit'"></button>
                        @endif

                        @if($canEditSchedule)
                            <button type="button" @click="view = view === 'signin' ? 'schedule' : 'signin'; editing = false" :class="view === 'schedule' ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-200 text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10'" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition" x-text="view === 'signin' ? 'Schedule' : 'Sign in'"></button>

                            {{-- Bringing another week's pattern across. Beside the
                                 view switch rather than in a strip over the grid,
                                 because it is a control on the week, not a note
                                 about it. A week opens from each child's own
                                 registered days; this is the one way another
                                 week's ticks get in, and its hover says where
                                 this week's came from if it has been pressed. --}}
                            @if($sourceWeeks->isNotEmpty())
                                {{-- Only while the schedule is on screen: it rewrites the
                                     ticks, and belongs next to them rather than over a
                                     sheet of arrivals it cannot touch. --}}
                                <button type="button" x-show="view === 'schedule'" x-cloak @click="$refs.copyWeek.showModal()"
                                        class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10"
                                        title="@if($scheduleWeek?->copied_from_week_start)Copied from {{ $scheduleWeek->copied_from_week_start->format('M j') }} – {{ $scheduleWeek->copied_from_week_start->copy()->addDays(4)->format('M j') }}, and independent since@else Bring another week's pattern into this one @endif">Copy from another week</button>
                            @endif
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
                            <button type="button" x-ref="trigger" @click.stop="toggle()" :aria-expanded="menu" aria-haspopup="true" class="grid h-6 w-7 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-400 dark:hover:bg-white/10" aria-label="More">…</button>
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
                                    <span class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400">Show names as</span>
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

                {{-- An unlocked sheet has to look unlocked. Past columns take
                     taps in this mode and they do not in the other, and nothing
                     else on screen changes enough to notice. --}}
                <div x-show="editing" x-cloak class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-medium text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <span class="font-bold">Editing</span>
                    <span>Tap an empty cell to flip it &mdash; box means expected, dot means not. An arrival shows its time: retype it, or press ✓ to take it off. On a day gone by, ＋ puts an arrival on. Taking anything off asks first.</span>
                    <button type="button" @click="editing = false" class="ml-auto shrink-0 rounded-md px-1.5 font-semibold underline-offset-2 hover:underline">Done</button>
                </div>

                {{-- A finished week is a record. Say so plainly instead of showing
                     controls that would be refused. --}}
                @if($weekIsFrozen)
                    <div class="mt-2 flex flex-wrap items-center gap-x-2 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
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
                            All <span class="ml-0.5 opacity-60">{{ $totalChildren }}</span>
                        </button>
                        @foreach($classrooms as $classroom)
                            <button @click="room=@js($classroom)" :class="room === @js($classroom) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
                            <x-room-icon :room="$classroom" size="text-sm" /> {{ $classroom }} <span class="ml-0.5 opacity-60" x-text="roomCount(@js($classroom))"></span>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        {{-- Days the centre is shut are why a column is gray, so the
                             count sits beside the filters rather than being found by
                             opening the schedule view. --}}
                        <span x-show="closedCount > 0" x-cloak class="hidden items-center gap-1 text-[11px] font-medium text-slate-500 sm:inline-flex dark:text-slate-400" :title="closedReasons()">
                            <span class="grid h-3.5 w-3.5 place-items-center rounded-full border border-current text-[9px] leading-none" aria-hidden="true">i</span>
                            <span x-text="closedCount"></span> <span x-text="closedCount === 1 ? 'day closed' : 'days closed'"></span>
                        </span>
                        {{-- The key is a strip above the sheet rather than a panel
                             behind a "?". It is read once on the first morning and
                             then never again — which is exactly why it has to be
                             dismissable, and why hiding it is remembered. --}}
                        <button type="button" @click="toggleKey()" class="text-[11px] font-medium text-slate-500 transition hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-300" x-text="showKey ? 'Hide key' : 'Show key'"></button>
                    </div>
                </div>

                {{-- What every mark on the sheet means, in the sheet's own marks.
                     Painted from the same $boxStates the grid is painted from, so
                     a chip here and a cell down there cannot come to disagree. --}}
                <div x-show="view === 'signin' && showKey" x-cloak class="-mx-3 -mb-2.5 mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-b-2xl border-t border-slate-200/70 bg-slate-50/70 px-3 py-2 text-[11px] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                    @foreach($boxStates as $key => $state)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-flex h-[22px] min-w-[52px] items-center justify-center rounded-lg border px-2 font-mono text-[10.5px] font-medium leading-none {{ $state['classes'] }}">{{ $state['swatch'] }}</span>
                            {{ $state['label'] }}
                        </span>
                    @endforeach
                    <span class="ml-auto hidden lg:inline">Tap any cell to sign in or out</span>
                </div>
            </div>
            @if($weekIsOpen)
            {{-- Schedule setup: ticks, not colours. --}}
            @if($canEditSchedule)
                <div class="mt-3" x-show="view === 'schedule'" x-cloak>
                    @include('attendance.partials.checklist')
                </div>
            @endif

            <div class="mt-3" x-show="view === 'signin'">
                <div class="glass-card overflow-hidden rounded-2xl">
                    {{-- Week grid: needs the width, so it only appears from md up. --}}
                    <div class="hidden overflow-x-auto md:block">
                        <table class="sheet-grid w-full min-w-[1040px] border-collapse text-left">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                    {{-- The LAN, not a row number. A count of
                                         where a child happens to fall in today's
                                         sort answers nothing — it changes when
                                         the sort flips or a room is filtered —
                                         whereas the LAN is what the paper
                                         register, the invoice and the phone call
                                         from the office all name a child by. --}}
                                    <th scope="col" class="sticky left-0 z-20 w-[60px] bg-slate-50 px-3 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-400 dark:bg-slate-900" title="Learner account number">LAN</th>
                                    <th scope="col" :aria-sort="sortDirection === 'asc' ? 'ascending' : 'descending'" class="sticky left-[60px] z-20 border-r border-slate-200 bg-slate-50 px-3 py-2.5 text-left dark:border-white/10 dark:bg-slate-900">
                                        <button type="button" @click="toggleSort" class="group inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 transition hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-300" :title="sortDirection === 'asc' ? 'Sorted A–Z, click for Z–A' : 'Sorted Z–A, click for A–Z'">
                                            <span>Student</span>
                                            <span class="text-[11px] leading-none text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-300" x-text="sortDirection === 'asc' ? '↑' : '↓'"></span>
                                        </button>
                                    </th>
                                    {{-- Their own columns rather than more lines under the name:
                                         read down a column these compare at a glance, and the
                                         name cell was already carrying the room, the hours and
                                         the cover. The date is the fact on file; the age is what
                                         the room and the ratio are actually judged on, so both
                                         are here rather than one standing for the other. --}}
                                    <th scope="col" class="w-px px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Classroom</th>
                                    <th scope="col" class="w-px px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="Date of birth, year/month/day">DOB</th>
                                    <th scope="col" class="w-px px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Age</th>
                                    {{-- The hours this child is contracted for, set
                                         on their record at registration. Beside the
                                         boxes rather than behind a click: the sheet
                                         is read at drop-off and at pick-up, and
                                         "when is this one due?" is the question
                                         being asked at both. The day columns say
                                         which days; this says the hours of them. --}}
                                    <th scope="col" class="w-px px-2 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="The hours agreed on the child's record">Hours</th>
                                    @foreach($weekDates as $date)
                                        {{-- Today is the column being worked in, so it is picked
                                             out of the five rather than counted along to. --}}
                                        {{-- Sky, not the brand blue: the brand scale is so pale
                                             that its tints read as white beside white and today
                                             was not picked out at all. Sky is the one colour on
                                             the sheet nothing else uses — the boxes are green,
                                             amber, indigo and grey — so the column is found
                                             without reading a date. Header a shade deeper than
                                             the cells, so the column has a top. --}}
                                        <th scope="col" class="px-2 py-2 text-center {{ $date->isToday() ? 'rounded-t-lg bg-sky-200 dark:bg-sky-500/25' : '' }}">
                                            <span class="block text-[11px] uppercase tracking-wide {{ $date->isToday() ? 'font-semibold text-sky-700 dark:text-sky-300' : 'text-slate-400' }}">{{ $date->format('D') }}</span>
                                            <span class="block text-xs font-semibold {{ $date->isToday() ? 'text-sky-900 dark:text-sky-100' : 'text-slate-700 dark:text-slate-200' }}">{{ $date->format('M d') }}</span>
                                            {{-- A closed day still takes sign-ins, so the column stays live — it just says why it is all gray. --}}
                                            <span x-show="isClosed('{{ $date->toDateString() }}')" x-cloak class="mt-0.5 block rounded-md bg-rose-50 px-1 text-[10px] font-semibold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300" x-text="closureReason('{{ $date->toDateString() }}')"></span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                <template x-for="child in filteredChildren" :key="child.id">
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                        <td class="sticky left-0 z-10 w-[60px] bg-white px-3 py-1.5 text-center text-sm tabular-nums text-slate-400 dark:bg-night-900" :class="blankClass(child.lan)" x-text="child.lan || '—'"></td>
                                        <td class="sticky left-[60px] z-10 border-r border-slate-200 bg-white px-3 py-1.5 dark:border-white/10 dark:bg-night-900">
                                            <div class="flex items-center gap-2.5">
                                                <span class="h-8 w-8 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                                <div class="min-w-0">
                                                    {{-- The name opens the child record: the numbers to ring
                                                         and the enrolment dates that decide whether a box
                                                         exists at all. --}}
                                                    <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="block truncate text-sm font-semibold text-slate-800 underline-offset-2 hover:text-indigo-600 hover:underline dark:text-slate-100" x-text="child.name" :title="'Open ' + child.first_name + '\'s record'"></a>
                                                    <span x-show="! canOpenProfile" class="block truncate text-sm font-semibold" x-text="child.name"></span>
                                                </div>
                                            </div>
                                        </td>
                                        {{-- The room, in a column of its own rather than trailing the
                                             name. It is the thing the sheet is grouped and staffed by,
                                             and beside the name it was read as part of the name — down
                                             a column of its own the rooms stack, and a child sitting in
                                             one nobody else on screen is in stands out.

                                             The brand blue is close enough to gray that a hand-set room
                                             read as an automatic one, so the mark says which it is
                                             without relying on the colour. --}}
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-center text-sm">
                                            <span class="inline-flex items-center gap-0.5" :class="roomClass(child)" :title="roomTitle(child)">
                                                <span x-text="roomLabel(child)"></span>
                                                <span x-show="child.classroom_override" x-cloak x-text="child.override_stale ? '⚠' : '✎'"></span>
                                            </span>
                                        </td>
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-center text-sm tabular-nums text-slate-500 dark:text-slate-400" :class="blankClass(child.birth_date)" x-text="child.birth_date || '—'"></td>
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-center text-sm tabular-nums text-slate-500 dark:text-slate-400" :class="blankClass(child.age)" x-text="child.age || '—'"></td>
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-center text-sm tabular-nums text-slate-500 dark:text-slate-400" :class="blankClass(child.schedule_hours)" x-text="child.schedule_hours || '—'" :title="child.schedule_hours ? child.first_name + ' is here ' + child.schedule_hours : 'No hours agreed yet'"></td>
                                        @foreach($weekDates as $date)
                                            {{-- Closure is painted from Alpine rather than Blade
                                                 because a day is closed and reopened without the
                                                 page reloading, and it wins over today's sky: a
                                                 shut Monday is shut whether or not it is today. --}}
                                            <td class="px-2 py-1.5 text-center align-middle" :class="isClosed('{{ $date->toDateString() }}') ? 'bg-rose-50/60 dark:bg-rose-500/5' : '{{ $date->isToday() ? 'bg-sky-100 dark:bg-sky-500/10' : '' }}'">
                                                @include('attendance.partials.day-buttons', ['date' => $date, 'variant' => 'table'])
                                            </td>
                                        @endforeach
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- Phone layout: one card per child, one row per day. --}}
                    <div class="divide-y divide-slate-100 md:hidden dark:divide-white/10">
                        <div class="flex items-center justify-between px-3 py-2">
                            <button type="button" @click="toggleSort" class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <span>Student</span>
                                <span class="text-[10px] leading-none text-slate-400" x-text="sortDirection === 'asc' ? '▲ A–Z' : '▼ Z–A'"></span>
                            </button>
                            <span class="text-[11px] text-slate-400">{{ $weekDates->first()->format('M d') }} – {{ $weekDates->last()->format('M d') }}</span>
                        </div>
                        <template x-for="child in filteredChildren" :key="'card-' + child.id">
                            <article class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="h-9 w-9 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                    <div class="min-w-0 flex-1">
                                        <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="block truncate text-sm font-semibold underline-offset-2 hover:text-indigo-600 hover:underline" x-text="child.name"></a>
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

                    <p x-show="filteredCount === 0" class="p-8 text-center text-sm text-slate-500">No children match this search.</p>
                    <div x-show="filteredCount > 0" class="border-t border-slate-200 px-4 py-2.5 text-sm text-slate-500 dark:border-white/10">
                        Showing all <span class="font-medium text-slate-700 dark:text-slate-200" x-text="filteredCount"></span> children
                    </div>
                </div>
            </div>
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
                            Opening it starts from each child's registered days &mdash; nothing is copied from another week.
                            @if($previousWeekStart)
                                Once it is open, <b>Copy from another week</b> can bring the week of
                                <b>{{ \Illuminate\Support\Carbon::parse($previousWeekStart)->format('M j') }}</b> across if you want it.
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
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-300" x-text="recent.length"></span>
                    <button type="button" @click="recentOpen = false" class="ml-auto grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Close">✕</button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    {{-- Newest at the top, and the newest one tinted: on a panel
                         glanced at between arrivals, "what just happened" is the
                         whole question being asked. --}}
                    <template x-for="(signIn, index) in recent" :key="signIn.id">
                        <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-2.5 transition dark:border-white/5" :class="index === 0 ? 'bg-emerald-50/60 dark:bg-emerald-500/10' : ''">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 text-[13px] text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300" aria-hidden="true">✓</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] font-semibold" x-text="signIn.name"></p>
                                <p class="truncate text-[11px] text-slate-500 dark:text-slate-400" x-text="signIn.classroom"></p>
                            </div>
                            <span class="shrink-0 font-mono text-[11px] font-medium tabular-nums text-slate-500 dark:text-slate-400" x-text="signIn.time"></span>
                        </div>
                    </template>

                    <p x-show="recent.length === 0" class="px-4 py-12 text-center text-[13px] text-slate-500 dark:text-slate-400">
                        Nobody has signed in yet today.<br>
                        <span class="text-[11px] text-slate-400">Tap a cell on the sheet and they will appear here.</span>
                    </p>
                </div>

                <p class="border-t border-slate-200 px-4 py-2 text-[11px] text-slate-400 dark:border-white/10 dark:text-slate-500">
                    Arrivals on {{ \Illuminate\Support\Carbon::parse($selectedDate)->format('D, M j') }}, newest first.
                </p>
            </div>
        </div>
    </template>

    @if($canEditSchedule && $sourceWeeks->isNotEmpty())
        <dialog x-ref="copyWeek" class="w-[min(26rem,calc(100%-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-800 backdrop:bg-slate-900/50 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
            <form method="POST" action="{{ route('attendance.schedule.copy') }}">
                @csrf
                <input type="hidden" name="week_start" value="{{ $weekStartDate }}">
                <div class="flex flex-col gap-3 p-5">
                    <h2 class="text-base font-bold">Copy the schedule from another week</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Choose the week to copy from.</p>
                    <div class="flex flex-col gap-1.5">
                        @foreach($sourceWeeks as $index => $week)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 px-3 py-2 text-sm has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-white/10 dark:has-[:checked]:bg-indigo-500/10">
                                {{-- Last week is the default; failing that, whatever is nearest. --}}
                                <input type="radio" name="source_week_start" value="{{ $week['value'] }}" @checked($week['is_previous'] || (! $previousWeekStart && $index === 0)) required>
                                <span class="min-w-0 flex-1">
                                    <span class="block">{{ $week['label'] }}</span>
                                    {{-- How busy that week was, so a holiday week is
                                         obvious without opening it. --}}
                                    <span class="block text-[11px] text-slate-500 dark:text-slate-400">
                                        {{ $week['ticked'] }} day{{ $week['ticked'] === 1 ? '' : 's' }} ticked
                                        @if($week['closures'] > 0)
                                            · <span class="font-semibold text-rose-600 dark:text-rose-300">{{ $week['closures'] }} closed day{{ $week['closures'] === 1 ? '' : 's' }}</span>
                                        @endif
                                    </span>
                                </span>
                                @if($week['is_previous'])
                                    <span class="shrink-0 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200">Last week</span>
                                @endif
                            </label>
                        @endforeach
                    </div>

                    {{-- One outcome, said plainly rather than chosen from a
                         list. "Copy the schedule from another week" means this
                         week ends up looking like that one — the part worth
                         warning about is what it clears, so that is the
                         sentence rather than a radio nobody read. --}}
                    <p class="border-t border-slate-200/70 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                        This week ends up an exact match of the week you choose. Days ticked here but not there are cleared.
                        Only the pattern is copied &mdash; sign-ins already recorded are never touched.
                    </p>

                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200/70 px-5 py-3 dark:border-white/10">
                    <button type="button" @click="$refs.copyWeek.close()" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</button>
                    <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Copy schedule</button>
                </div>
            </form>
        </dialog>
    @endif

    {{--
        What a tap is about to do, asked before it does it.

        Only while correcting. On the live sheet a tap is a child standing at the
        door and the answer is always yes — sixty confirmations a morning is a
        dialog nobody reads, and a dialog nobody reads is worse than none.

        In Edit the tap means something else every time: a day that has already
        gone, a day nobody booked, a day the centre was shut, an arrival being
        taken off. So the question names the child, the day, and which of those
        it is — the condition changes, the shape of the question does not.
    --}}
    <div
        x-show="confirming"
        x-cloak
        x-transition.opacity
        @keydown.escape.window="confirming = null"
        class="fixed inset-0 z-50 grid place-items-center bg-slate-900/60 p-4"
        role="alertdialog"
        aria-modal="true"
    >
        <div @click.outside="confirming = null" class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-white/10 dark:bg-slate-900">
            <span class="grid h-11 w-11 place-items-center rounded-full text-xl font-bold"
                  :class="confirming?.destructive
                      ? 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300'
                      : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'"
                  aria-hidden="true" x-text="confirming?.destructive ? '×' : '?'"></span>

            <h2 class="mt-3 text-base font-bold text-slate-900 dark:text-white" x-text="confirming?.title"></h2>

            {{-- One line per thing that makes this tap unusual, rather than a
                 paragraph: the reader is deciding, not reading. --}}
            <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                <template x-for="line in (confirming?.lines ?? [])" :key="line">
                    <li class="flex gap-2"><span class="text-slate-300 dark:text-slate-600" aria-hidden="true">•</span><span x-text="line"></span></li>
                </template>
            </ul>

            <div class="mt-5 flex gap-2">
                <button type="button" @click="confirming = null" class="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10">Cancel</button>
                <button type="button" @click="confirmTap()" x-ref="confirmYes"
                        :class="confirming?.destructive ? 'bg-rose-600 hover:bg-rose-700' : 'bg-amber-500 hover:bg-amber-600'"
                        class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold text-white transition"
                        x-text="confirming?.verb"></button>
            </div>
        </div>
    </div>
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
function attendanceApp() { return {
    search: '',
    room: '',
    sortDirection: 'asc',
    view: 'signin',
    recentOpen: false,
    editing: false,
    confirming: null,
    canAmend: @js($canAmendAttendance),
    // Which cells in this week were put right by hand rather than tapped on
    // the day. Keyed child|date|session, the same shape the attendance map is.
    amended: @js((object) $amendmentMap),
    // Shown until somebody says otherwise, and then it stays hidden. A key is
    // read on the first morning and never again, so asking for it again every
    // day would be the wrong default in both directions.
    //
    // In a try/catch because a private window can throw on the accessor
    // itself, and a page that will not render is a worse outcome than a key
    // that forgets.
    showKey: (() => { try { return localStorage.getItem('attendance.key') !== 'hidden'; } catch { return true; } })(),
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
    profileUrl(childId) { return "{{ route('children.show', ['child' => '__ID__']) }}".replace('__ID__', childId); },
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
    childrenData: @js($children->map(fn($child) => ['id' => $child->id, 'lan' => $child->lan, 'name' => $child->displayName(), 'first_name' => $child->first_name, 'last_name' => $child->last_name, 'avatar' => $avatarMarkup($child), 'birth_date' => $child->ageLabel(), 'age' => $child->ageInWords(), 'classroom' => $child->classroom, 'sessions' => $child->sessions(), 'automatic_classroom' => $child->automaticClassroom(), 'classroom_override' => $child->classroom_override, 'classroom_override_from' => $child->classroom_override_from?->toDateString(), 'override_stale' => $child->classroomOverrideIsStale(), 'schedule_hours' => $child->scheduleLabel(), 'drop_off' => \App\Models\Child::timeInputValue($child->drop_off_time ?: \App\Models\Child::DAY_OPENS_AT), 'schedule_days' => $child->scheduleDays(), 'schedule_days_label' => $child->scheduleDaysLabel(), 'cover' => $roomCover[$child->id] ?? null])->values()),
    rooms: @js(\App\Services\ClassroomAssignment::rooms()),
    // Moving a child between rooms changes who can see them, so it is the
    // director's call rather than a teacher's.
    canEditRooms: @js(auth()->user()->isAdmin()),
    roomEditing: null,
    today: @js(today()->toDateString()),
    todayLabel: @js(today()->format('l, M j')),
    attendance: @js($attendanceMap),
    schedule: @js($scheduleMap),
    // The forecast, kept in its own map so it can never be mistaken for a tick.
    projection: @js((object) $projectionMap),
    projectionChildren: @js((object) $projectionChildren),
    basisLabels: @js($projectionBasisLabels),
    closed: @js($closedDays),
    recent: @js($recentAttendance->map(fn($attendance) => ['id' => $attendance->id, 'name' => $attendance->child->displayName(), 'classroom' => $attendance->child->classroom, 'time' => \App\Models\Child::timeShort($attendance->signed_in_at->timezone(config('app.timezone')))])->values()),
    get filteredChildren() {
        return [...this.childrenData]
            .filter(child => this.matchesChild(child))
            .sort((a, b) => {
                const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
                const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();
                return this.sortDirection === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
            });
    },
    get filteredCount() { return this.filteredChildren.length; },

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
        return [...this.childrenData].sort((a, b) => {
            const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
            const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();
            return this.sortDirection === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
        });
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
    // How a column says it has nothing for this child.
    //
    // A dash hugging the left edge of a column whose other rows read "8:00 AM –
    // 5:30 PM" looks like a very short entry rather than an absent one — and a
    // roster where most children have no hours agreed yet is a whole column of
    // them. Centred in the width the real values set, and a shade lighter, a
    // blank reads as the gap it is.
    blankClass(value) { return value ? '' : 'text-center text-slate-300 dark:text-slate-600'; },
    toggleSort() { this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'; },
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
            const response = await window.postJson("{{ route('attendance.schedule.classroom') }}", {
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
            const response = await window.postJson("{{ route('attendance.schedule.closure') }}", {date, closed: closing, reason});
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
     * Whether an arrival can be put onto this day by hand, at a typed time.
     *
     * Today and the days behind it: the same boundary signing in has always
     * had, because an arrival that has not happened is a guess. In Edit the
     * tap on an empty cell is taken by the expected/not-expected toggle, so
     * this gets a mark of its own beside the box.
     */
    canAddArrival(date) {
        return this.editing && this.canAmend && date <= this.today;
    },

    /** Whether the time on a recorded arrival is open to being retyped. */
    canRetime(childId, date, session) {
        return this.editing && this.canAmend && date <= this.today && this.isPresent(childId, date, session);
    },

    /* ---- the arrival being typed, if any ---- */
    drafting: null,   // 'child|date|session' of the cell showing a time field
    draft: '',        // the HH:MM in it

    /* ---- the recorded time being retyped, if any ---- */
    retiming: null,   // 'child|date|session' whose pill has opened its field

    isRetiming(childId, date, session) {
        return this.retiming === this.cellKey(childId, date, session);
    },
    beginRetime(childId, date, session) {
        if (! this.canRetime(childId, date, session)) return;

        this.retiming = this.cellKey(childId, date, session);
    },
    endRetime() {
        this.retiming = null;
    },

    cellKey(childId, date, session) {
        return childId + '|' + date + '|' + session;
    },
    isDrafting(childId, date, session) {
        return this.drafting === this.cellKey(childId, date, session);
    },

    /**
     * "9:04a" as a time field wants it: "09:04". The sheet stores the short
     * form because that is what it prints; the field is the one place the
     * other is needed.
     */
    timeValue(short) {
        const match = /^(\d{1,2}):(\d{2})([ap])$/.exec(short || '');

        if (! match) return '';

        let hours = Number(match[1]) % 12;
        if (match[3] === 'p') hours += 12;

        return String(hours).padStart(2, '0') + ':' + match[2];
    },

    /** "09:04" back to "9:04a", for the line in the dialog. */
    timeShort(value) {
        const match = /^(\d{2}):(\d{2})$/.exec(value || '');

        if (! match) return value || '';

        const hours = Number(match[1]);

        return ((hours % 12) || 12) + ':' + match[2] + (hours < 12 ? 'a' : 'p');
    },

    /** The moment, as a time field wants it, for an arrival added today. */
    nowValue() {
        const at = new Date();

        return String(at.getHours()).padStart(2, '0') + ':' + String(at.getMinutes()).padStart(2, '0');
    },

    beginArrival(childId, date, session) {
        if (! this.canAddArrival(date)) return;

        const child = this.childrenData.find(candidate => candidate.id === childId);

        // Today starts from now, the same as a tap at the door would. A day
        // gone starts from the hour agreed for it — their drop-off, or when
        // the centre opens — which is what the server would stamp unasked.
        this.draft = date === this.today ? this.nowValue() : (child?.drop_off || '07:00');
        this.drafting = this.cellKey(childId, date, session);
    },
    cancelArrival() {
        this.drafting = null;
        this.draft = '';
    },
    commitArrival(childId, date, session) {
        if (! this.isDrafting(childId, date, session) || ! this.draft) return;

        const time = this.draft;
        this.cancelArrival();

        // Asked about like every other tap in this mode, with the hour in it.
        this.askBeforeTapping(childId, date, session, time);
    },

    /** Whether this cell does anything at all when it is tapped. */
    canTap(date) {
        return this.canSignIn(date) || this.canSetExpected(date);
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
            const width = session === 'FULL' ? ' min-w-[74px]' : ' min-w-[48px]';

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
        return base + (session === 'FULL' ? ' min-w-[74px]' : ' min-w-[48px]');
    },
    // What the cell says. A child who has arrived is the time they arrived —
    // the fact anybody opening this sheet is looking for — and everything else
    // is a placeholder standing in until then.
    boxLabel(childId, date, session) {
        if (this.isPresent(childId, date, session)) return this.sessionTime(childId, date, session);
        if (this.isClosed(date)) return '—';
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
            return 'Signed in ' + this.sessionTime(childId, date, session) + ' — tap to take this arrival off';
        }

        // The reason the column is empty, on every cell in it — the header
        // carries it once, and a row read across does not pass the header.
        if (this.isClosed(date)) {
            return this.closureReason(date) + (this.canSignIn(date) ? ' — tap to sign in anyway' : '');
        }

        // In Edit an empty cell is the plan for the day, and the tap flips it.
        if (this.canSetExpected(date)) {
            return this.isScheduled(childId, date, session)
                ? 'Expected. Tap to make it a day off; ＋ to put an arrival on it.'
                : 'Not expected. Tap to expect them; ＋ to put an arrival on it.';
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
            const response = await window.postJson("{{ route('attendance.schedule.update') }}", {
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
    // What a tap means depends on what is already in the cell. Adding and
    // taking back are the same gesture, which is what makes a correction feel
    // like fixing a sheet rather than operating a form.
    //
    // At the door it happens on the tap. While correcting it is asked about
    // first — see askBeforeTapping for why the two differ.
    tapCell(childId, date, session) {
        // In Edit an empty cell is the plan for that day, and the tap flips
        // it — box to dot, dot to box — the way a tick on the checklist does,
        // and as directly: it is the same act on the same slot. The arrival
        // on a filled cell is handled by the field and the ✓ beside it, so a
        // tap there is nothing.
        if (this.editing) {
            if (this.isPresent(childId, date, session)) return;
            if (this.canSetExpected(date)) return this.toggleOne(childId, date, session);

            return;
        }

        return this.canRemove(childId, date, session)
            ? this.removeSignIn(childId, date, session)
            : this.signIn(childId, date, session);
    },

    /** "Monday, Sep 14" — the sheet shows five columns, so a day needs naming. */
    dayLabel(date) {
        return new Date(date + 'T00:00:00').toLocaleDateString(undefined, {
            weekday: 'long', month: 'short', day: 'numeric',
        });
    },

    /*
     * Say what this tap is about to do, and wait.
     *
     * Every condition gets the same question in the same shape; only what makes
     * the tap unusual changes. That matters more than the wording: somebody
     * correcting a fortnight-old week is reading these to catch the tap they
     * did not mean, and a dialog whose shape moves about is one that gets
     * clicked through rather than read.
     */
    askBeforeTapping(childId, date, session, time = null) {
        const child = this.childrenData.find(candidate => candidate.id === childId);
        const name = child?.name ?? 'this child';
        const day = this.dayLabel(date);
        const half = session === 'FULL' ? '' : (session === 'AM' ? ' (morning)' : ' (afternoon)');
        const lines = [];

        // Taking one off is the destructive half, and the only one that can
        // lose something already recorded.
        if (this.canRemove(childId, date, session)) {
            lines.push(name + ' is recorded as arriving at ' + this.sessionTime(childId, date, session) + ' on ' + day + half + '.');
            lines.push('Taking it off removes that day from what the centre bills for.');

            this.confirming = {
                title: 'Take this arrival off?',
                lines,
                verb: 'Take it off',
                destructive: true,
                childId, date, session,
            };

            return;
        }

        // The hour, when one was typed: that is the thing being confirmed.
        const at = time ? ' at ' + this.timeShort(time) : '';

        lines.push(date === this.today
            ? name + ' will be recorded as arriving ' + (time ? 'at ' + this.timeShort(time) : 'now') + '.'
            : day + ' has already gone — ' + name + ' will be recorded as here that day' + at + '.');

        if (this.isClosed(date)) {
            lines.push('The centre was closed: ' + this.closureReason(date) + '.');
        } else if (this.isScheduled(childId, date, session)) {
            lines.push(name + ' was expected' + (half ? half.trim() : '') + ' on ' + day + '.');
        } else {
            lines.push(name + ' was not scheduled on ' + day + ' — this counts as unplanned, and is still billable.');
        }

        this.confirming = {
            title: 'Sign ' + name + ' in?',
            lines,
            verb: 'Sign in',
            destructive: false,
            childId, date, session, time,
        };
    },

    confirmTap() {
        const asked = this.confirming;
        this.confirming = null;

        if (! asked) return;

        return asked.destructive
            ? this.removeSignIn(asked.childId, asked.date, asked.session)
            : this.signIn(asked.childId, asked.date, asked.session, asked.time);
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
            const response = await window.postJson("{{ route('attendance.signin.retime') }}", {
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
            const response = await window.postJson("{{ route('attendance.signin.remove') }}", {
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
            const response = await window.postJson("{{ route('attendance.signin') }}", {
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
