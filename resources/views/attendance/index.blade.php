@extends('layouts.app')
@section('title', 'Class Attendance')
@section('content')
@php
    /*
     * The four states of a sign-in box, in one place.
     *
     * boxClass() paints the grid from this map and the legend paints its
     * swatches from it, so the key beside the sheet cannot drift away from the
     * sheet itself. Every fill is strong enough to be told apart at arm's
     * length across a room — the brand blue is a pale one, so a 50-weight tint
     * of it read as white next to gray and the two most common states were
     * effectively the same colour.
     */
    $boxStates = [
        'present' => [
            'label' => 'Signed in',
            'hint' => 'with the time they arrived',
            'swatch' => '✓',
            'classes' => 'border-emerald-500 bg-emerald-100 text-emerald-800 dark:border-emerald-400/50 dark:bg-emerald-500/20 dark:text-emerald-200',
        ],
        'unplanned' => [
            'label' => 'Signed in, not scheduled',
            'hint' => 'unplanned, and still billable',
            'swatch' => '✓',
            'classes' => 'border-amber-500 bg-amber-100 text-amber-900 dark:border-amber-400/50 dark:bg-amber-500/20 dark:text-amber-100',
        ],
        'scheduled' => [
            'label' => 'Scheduled',
            'hint' => 'expected today, not signed in yet',
            'swatch' => 'Present',
            'classes' => 'border-indigo-500 bg-indigo-200 text-indigo-800 hover:bg-indigo-300 dark:border-indigo-400/50 dark:bg-indigo-500/20 dark:text-indigo-100',
        ],
        'off' => [
            'label' => 'Not scheduled',
            'hint' => 'click to sign them in anyway',
            'swatch' => 'Present',
            'classes' => 'border-slate-300 bg-slate-100 text-slate-500 hover:bg-slate-200 dark:border-white/10 dark:bg-slate-800 dark:text-slate-400',
        ],
    ];
@endphp
<div x-data="attendanceApp()" @pointermove.window="paintAt($event)" @pointerup.window="endPaint()" @pointercancel.window="endPaint()">
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
                     and the two controls used on every visit. Everything else —
                     jumping to a far-off date, the copy dialog — is behind the
                     "…", because a toolbar that shows every control equally makes
                     the two that matter no easier to find than the rest. --}}
                @php($prevWeek = \Illuminate\Support\Carbon::parse($weekStartDate)->subWeek()->toDateString())
                @php($nextWeek = \Illuminate\Support\Carbon::parse($weekStartDate)->addWeek()->toDateString())
                @php($thisWeek = \Illuminate\Support\Carbon::today()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString())
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h1 class="text-base font-bold tracking-tight sm:text-lg">Attendance</h1>

                    {{-- The week as one control: a step either side of the range it
                         is showing, rather than two buttons and a label apart. --}}
                    <div class="flex items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
                        <a href="{{ route('attendance.index', ['date' => $prevWeek]) }}" class="grid h-6 w-6 place-items-center rounded-md text-slate-500 transition hover:bg-white hover:text-slate-900 dark:hover:bg-slate-700 dark:hover:text-white" title="Week of {{ \Illuminate\Support\Carbon::parse($prevWeek)->format('M j') }}" aria-label="Previous week">‹</a>
                        <span class="px-1.5 text-xs font-semibold tabular-nums">{{ $weekDates->first()->format('M j') }} – {{ $weekDates->last()->format($weekDates->first()->format('M') === $weekDates->last()->format('M') ? 'j' : 'M j') }}</span>
                        <a href="{{ route('attendance.index', ['date' => $nextWeek]) }}" class="grid h-6 w-6 place-items-center rounded-md text-slate-500 transition hover:bg-white hover:text-slate-900 dark:hover:bg-slate-700 dark:hover:text-white" title="Week of {{ \Illuminate\Support\Carbon::parse($nextWeek)->format('M j') }}" aria-label="Next week">›</a>
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

                    {{-- Counts as a sentence rather than three chips: the numbers
                         are the point, so they carry the colour and the weight and
                         the labels stay out of the way. --}}
                    <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                        <span><b class="font-bold text-slate-900 dark:text-white">{{ $totalChildren }}</b> enrolled</span>
                        <span><b class="font-bold text-emerald-600 dark:text-emerald-400" x-text="presentCount"></b> in</span>
                        <span><b class="font-bold text-rose-600 dark:text-rose-400" x-text="{{ $totalChildren }} - presentCount"></b> not in</span>
                    </p>

                    <div class="ml-auto flex items-center gap-1.5">
                        {{-- Searching is the way a big roll is used, so the box is on
                             the top line rather than below the filters. --}}
                        <label x-show="view === 'signin'" class="relative hidden sm:block">
                            <svg class="pointer-events-none absolute left-2.5 top-1.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                            <input x-model="search" class="w-44 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:w-56 focus:ring-2 focus:ring-indigo-500 lg:w-56 dark:border-white/10 dark:bg-slate-800" placeholder="Search children">
                        </label>

                        @if($canEditSchedule)
                            <button type="button" @click="view = view === 'signin' ? 'schedule' : 'signin'" :class="view === 'schedule' ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'border border-slate-200 text-slate-700 hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10'" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition" x-text="view === 'signin' ? 'Set schedule' : 'Sign in'"></button>
                        @endif

                        {{-- The rarely-wanted control, kept but not on show. --}}
                        <div x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape.window="menu = false" class="relative shrink-0">
                            <button type="button" @click="menu = ! menu" :aria-expanded="menu" class="grid h-6 w-7 place-items-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-400 dark:hover:bg-white/10" aria-label="More">…</button>
                            <div x-show="menu" x-cloak x-transition class="absolute right-0 z-30 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-2.5 shadow-xl dark:border-white/10 dark:bg-slate-900">
                                <form method="GET" action="{{ route('attendance.index') }}" class="space-y-1.5">
                                    <label class="block text-[11px] font-semibold text-slate-500 dark:text-slate-400" for="attendance-date">Jump to a date</label>
                                    <input id="attendance-date" type="date" name="date" value="{{ $selectedDate }}" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                                    <button class="w-full rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-indigo-700">Go to that week</button>
                                </form>
                            </div>
                        </div>
                    </div>
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
                        <button @click="room=''" :class="room === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition">
                            All <span class="ml-0.5 font-bold opacity-60">{{ $totalChildren }}</span>
                        </button>
                        @foreach($classrooms as $classroom)
                            <button @click="room=@js($classroom)" :class="room === @js($classroom) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition">
                                {{ $classroom }} <span class="ml-0.5 font-bold opacity-60" x-text="roomCount(@js($classroom))"></span>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        {{-- Days the centre is shut are why a column is gray, so the
                             count sits beside the filters rather than being found by
                             opening the schedule view. --}}
                        <span x-show="closedCount > 0" x-cloak class="hidden items-center gap-1 rounded-lg bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-600 sm:inline-flex dark:bg-rose-500/10 dark:text-rose-300">
                            <span x-text="closedCount"></span> <span x-text="closedCount === 1 ? 'day closed' : 'days closed'"></span>
                        </span>
                        {{-- The key, last: read once and then wanted rarely. --}}
                        @include('attendance.partials.legend', ['for' => 'signin', 'boxStates' => $boxStates])
                    </div>
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
                        <table class="sheet-grid w-full min-w-[860px] border-collapse text-left">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                    <th scope="col" class="w-12 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">#</th>
                                    <th scope="col" :aria-sort="sortDirection === 'asc' ? 'ascending' : 'descending'" class="px-3 py-2.5 text-left">
                                        <button type="button" @click="toggleSort" class="group inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 transition hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-300" :title="sortDirection === 'asc' ? 'Sorted A–Z, click for Z–A' : 'Sorted Z–A, click for A–Z'">
                                            <span>Student</span>
                                            <span class="text-[10px] leading-none text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-300" x-text="sortDirection === 'asc' ? '▲ A–Z' : '▼ Z–A'"></span>
                                        </button>
                                    </th>
                                    {{-- Their own columns rather than more lines under the name:
                                         read down a column these compare at a glance, and the
                                         name cell was already carrying the room, the hours and
                                         the cover. The date is the fact on file; the age is what
                                         the room and the ratio are actually judged on, so both
                                         are here rather than one standing for the other. --}}
                                    <th scope="col" class="w-px px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="Date of birth, year/month/day">DOB</th>
                                    <th scope="col" class="w-px px-2 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Age</th>
                                    @foreach($weekDates as $date)
                                        {{-- Today is the column being worked in, so it is picked
                                             out of the five rather than counted along to. --}}
                                        <th scope="col" class="px-2 py-2 text-center {{ $date->isToday() ? 'rounded-t-lg bg-indigo-50 dark:bg-indigo-500/10' : '' }}">
                                            <span class="block text-[11px] uppercase tracking-wide {{ $date->isToday() ? 'font-semibold text-indigo-600 dark:text-indigo-300' : 'text-slate-400' }}">{{ $date->format('D') }}</span>
                                            <span class="block text-xs font-semibold {{ $date->isToday() ? 'text-indigo-700 dark:text-indigo-200' : 'text-slate-700 dark:text-slate-200' }}">{{ $date->format('M d') }}</span>
                                            {{-- A closed day still takes sign-ins, so the column stays live — it just says why it is all gray. --}}
                                            <span x-show="isClosed('{{ $date->toDateString() }}')" x-cloak class="mt-0.5 block rounded-md bg-rose-50 px-1 text-[10px] font-semibold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300" x-text="closureReason('{{ $date->toDateString() }}')"></span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                <template x-for="(child, index) in filteredChildren" :key="child.id">
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                        <td class="px-3 py-1.5 text-sm text-slate-400" x-text="index + 1"></td>
                                        <td class="px-3 py-1.5">
                                            <div class="flex items-center gap-2.5">
                                                <span class="h-8 w-8 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                                <div class="min-w-0">
                                                    {{-- Name and room on one line. Stacked, every row was two
                                                         lines tall for a room name that is repeated down the
                                                         whole column — beside the name it reads as the aside
                                                         it is, and the register fits half again as many
                                                         children on a screen. --}}
                                                    <p class="flex min-w-0 items-baseline gap-1.5">
                                                        {{-- The name opens the child record: the numbers to ring
                                                             and the enrolment dates that decide whether a box
                                                             exists at all. --}}
                                                        <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="truncate text-sm font-semibold text-slate-800 underline-offset-2 hover:text-indigo-600 hover:underline dark:text-slate-100" x-text="child.first_name + ' ' + child.last_name" :title="'Open ' + child.first_name + '\'s record'"></a>
                                                        <span x-show="! canOpenProfile" class="truncate text-sm font-semibold" x-text="child.first_name + ' ' + child.last_name"></span>
                                                        {{-- The brand blue is close enough to gray that a
                                                             hand-set room read as an automatic one. The mark
                                                             says which it is without relying on the colour. --}}
                                                        <span class="flex shrink-0 items-center gap-0.5 text-xs" :class="roomClass(child)" :title="roomTitle(child)">
                                                            <span x-text="roomLabel(child)"></span>
                                                            <span x-show="child.classroom_override" x-cloak x-text="child.override_stale ? '⚠' : '✎'"></span>
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-sm tabular-nums text-slate-500 dark:text-slate-400" x-text="child.birth_date || '—'"></td>
                                        <td class="w-px whitespace-nowrap px-2 py-1.5 text-sm text-slate-500 dark:text-slate-400" x-text="child.age || '—'"></td>
                                        @foreach($weekDates as $date)
                                            <td class="px-2 py-1.5 text-center align-middle {{ $date->isToday() ? 'bg-indigo-50/50 dark:bg-indigo-500/5' : '' }}">
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
                        <template x-for="(child, index) in filteredChildren" :key="'card-' + child.id">
                            <article class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="h-9 w-9 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                    <div class="min-w-0 flex-1">
                                        <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="block truncate text-sm font-semibold underline-offset-2 hover:text-indigo-600 hover:underline" x-text="child.first_name + ' ' + child.last_name"></a>
                                        <p x-show="! canOpenProfile" class="truncate text-sm font-semibold" x-text="child.first_name + ' ' + child.last_name"></p>
                                        <p class="truncate text-xs text-slate-500" x-text="child.birth_date ? child.classroom + ' · ' + child.birth_date : child.classroom" title="Room and date of birth"></p>
                                    </div>
                                    <span class="text-xs text-slate-400" x-text="'#' + (index + 1)"></span>
                                </div>
                                <div class="mt-2 space-y-1 rounded-xl bg-slate-50 p-1.5 dark:bg-slate-800/50">
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
                            @if($previousWeekStart)
                                Opening it copies the schedule from the week of
                                <b>{{ \Illuminate\Support\Carbon::parse($previousWeekStart)->format('M j') }}</b>
                                forward. Nothing is copied until you do.
                            @else
                                Opening it starts a blank week — there is nothing before it to copy from.
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
        <section class="glass-card mt-4 rounded-2xl p-4 sm:p-5" x-show="view === 'signin'">
            <div class="border-b border-slate-200 pb-3 dark:border-white/10">
                <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">LIVE UPDATES</p>
                <h2 class="mt-0.5 text-base font-bold sm:text-lg">Recent sign-ins</h2>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-white/10">
                <template x-for="signIn in recent" :key="signIn.id">
                    <div class="flex items-center gap-3 py-3">
                        <span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-100 text-emerald-700">✓</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" x-text="signIn.name"></p>
                            <p class="text-xs text-slate-500" x-text="signIn.classroom"></p>
                        </div>
                        <span class="text-xs font-medium text-slate-500" x-text="signIn.time"></span>
                    </div>
                </template>
                <p x-show="recent.length === 0" class="p-8 text-center text-sm text-slate-500">No sign-ins yet.</p>
            </div>
        </section>
    </div>

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

                    {{-- What happens to the days already ticked here. --}}
                    <div class="flex flex-col gap-1.5 border-t border-slate-200/70 pt-3 dark:border-white/10">
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 px-3 py-2 text-xs has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-white/10 dark:has-[:checked]:bg-indigo-500/10">
                            <input type="radio" name="mode" value="add" class="mt-0.5" checked>
                            <span><b>Add to this week.</b> Keeps every day already ticked here and adds that week's days on top. Nothing is removed.</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 px-3 py-2 text-xs has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-white/10 dark:has-[:checked]:bg-indigo-500/10">
                            <input type="radio" name="mode" value="replace" class="mt-0.5">
                            <span><b>Replace this week.</b> This week ends up an exact match of that week — days ticked here but not there are cleared.</span>
                        </label>
                    </div>

                    {{-- Sample data only: this writes attendance for days nobody signed in. --}}
                    @if(auth()->user()->isAdmin())
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                            <input type="checkbox" name="with_sign_ins" value="1" class="mt-0.5">
                            <span>
                                <b>Also copy the sign-ins.</b>
                                Reproduces that week's arrivals on the matching days here. Sign-ins already recorded are left alone. For building sample data — it records attendance for days nobody was signed in.
                            </span>
                        </label>
                    @endif

                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200/70 px-5 py-3 dark:border-white/10">
                    <button type="button" @click="$refs.copyWeek.close()" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</button>
                    <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Copy schedule</button>
                </div>
            </form>
        </dialog>
    @endif

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
    presentCount: {{ $presentToday }},
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
    childrenData: @js($children->map(fn($child) => ['id' => $child->id, 'first_name' => $child->first_name, 'last_name' => $child->last_name, 'avatar' => $avatarMarkup($child), 'birth_date' => $child->ageLabel(), 'age' => $child->ageInWords(), 'classroom' => $child->classroom, 'sessions' => $child->sessions(), 'automatic_classroom' => $child->automaticClassroom(), 'classroom_override' => $child->classroom_override, 'classroom_override_from' => $child->classroom_override_from?->toDateString(), 'override_stale' => $child->classroomOverrideIsStale(), 'schedule_hours' => $child->scheduleLabel(), 'cover' => $roomCover[$child->id] ?? null])->values()),
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
    recent: @js($recentAttendance->map(fn($attendance) => ['id' => $attendance->id, 'name' => $attendance->child->first_name.' '.$attendance->child->last_name, 'classroom' => $attendance->child->classroom, 'time' => $attendance->signed_in_at->timezone(config('app.timezone'))->format('g:i A')])->values()),
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
    matchesChild(child) { return this.matches((child.first_name + ' ' + child.last_name).toLowerCase(), child.classroom); },
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
    projectionDiffers(childId, date, session) {
        return this.hasSlot(childId, date)
            && !this.isClosed(date)
            && this.isProjected(childId, date, session) !== this.isScheduled(childId, date, session);
    },
    projectionNote(childId, date, session) {
        if (!this.projectionDiffers(childId, date, session)) return '';
        return this.isProjected(childId, date, session)
            ? 'Projected to attend, but not scheduled — ' + this.basisFor(childId).toLowerCase() + '.'
            : 'Scheduled, but not projected to attend.';
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
    roomLabel(child) { return child.classroom || 'Unassigned'; },
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
    // A sign-in stamps the moment somebody arrived, so only today can be
    // signed in — a day already gone is a record, not a form. The box says so
    // by being disabled rather than by taking the click and refusing it after.
    canSignIn(date) { return date === this.today; },
    boxClass(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            return this.boxStates[this.isUnplanned(childId, date, session) ? 'unplanned' : 'present'].classes;
        }
        let base = this.boxStates[this.isScheduled(childId, date, session) ? 'scheduled' : 'off'].classes;

        // Nothing to click here, so nothing that looks clickable: the hover
        // fills come off and the box is faded to sit behind today's column.
        if (! this.canSignIn(date)) {
            base = base.replace(/\s*(dark:)?hover:\S+/g, '') + ' cursor-not-allowed opacity-60';
        }

        // Sky ring: the forecast disagrees with the plan. A ring rather than a
        // fill, so it never competes with the four states the box already has.
        return this.projectionDiffers(childId, date, session) ? base + ' ring-1 ring-sky-400 dark:ring-sky-500' : base;
    },
    boxLabel(childId, date, session) {
        if (this.isPresent(childId, date, session)) return session === 'FULL' ? '✓' : session + ' ✓';
        return session === 'FULL' ? 'Present' : session;
    },
    boxTitle(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            return this.isScheduled(childId, date, session)
                ? 'Signed in ' + this.sessionTime(childId, date, session)
                : 'Signed in ' + this.sessionTime(childId, date, session) + ' — not scheduled, still billable';
        }
        // Say which day can be signed in, on the box that cannot: the sheet
        // shows five columns and "only today" does not say which one.
        if (! this.canSignIn(date)) {
            const scheduled = this.isScheduled(childId, date, session) ? 'Scheduled. ' : '';

            return scheduled + 'Only ' + this.todayLabel + ' can be signed in.';
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
    applyPreset(child, preset) {
        if (!this.canEdit) return;
        const pattern = {all: [1,1,1,1,1], mwf: [1,0,1,0,1], tth: [0,1,0,1,0], none: [0,0,0,0,0]}[preset];
        @js($weekDates->map->toDateString()).forEach((date, index) => {
            (child.sessions || []).forEach(session => this.setSlot(child.id, date, session, !!pattern[index]));
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
    async signIn(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            return;
        }

        const alreadyPresent = this.hasAnyAttendanceForDate(childId, date);

        try {
            const response = await window.postJson("{{ route('attendance.signin') }}", {
                child_id: childId,
                attendance_date: date,
                session,
            });

            if (!response.ok) {
                // A 422 carries the rule that refused — "only today", most often.
                // Anything vaguer than that leaves the teacher guessing.
                const problem = await response.json().catch(() => ({}));
                throw new Error(problem.errors?.attendance_date?.[0] || problem.message || 'Unable to sign in right now. Please try again.');
            }

            const data = await response.json();

            if (data.success) {
                this.attendance[childId] = this.attendance[childId] || {};
                this.attendance[childId][date] = this.attendance[childId][date] || {};
                this.attendance[childId][date][data.session] = data.time;

                if (!alreadyPresent && date === '{{ $selectedDate }}') {
                    this.presentCount++;
                }

                this.recent.unshift({id: 'new-'+childId+'-'+date+'-'+session, name: data.child.name, classroom: data.child.classroom, time: data.time});
                this.recent = this.recent.slice(0, 10);
            }
        } catch (error) {
            this.notice = error.message;
        }
    }
}; }
</script>
@endsection
