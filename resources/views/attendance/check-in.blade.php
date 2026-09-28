@extends('layouts.app')
@section('title', 'Check in')
@section('content')
{{--
    The door screen, as a grid.

    Four lines per child — in, the check taken then, out, the check taken then
    — and a column per weekday, the same shape as the month sheet and the paper
    form behind it. What is different here is that today's column takes a
    press: an empty cell checks the child in, a time checks them out, and a
    chip sets or corrects the code.

    The other columns are context and nothing else. Somebody at the door
    glances left to see whether this child came yesterday; they do not record
    yesterday from here, because a check typed into a day already gone is one
    nobody performed.
--}}
@php($cell = 'border border-slate-200 dark:border-white/10')

{{-- One click handler on the root for the sheet's four hundred cells, the way
     the register does it, rather than a listener attached in init() — see
     onRootClick(). --}}
<div x-data="checkInGrid()" @click="onRootClick($event)">

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="text-xl font-bold tracking-tight">Check in</h1>
        <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-800 dark:bg-sky-500/15 dark:text-sky-200">
            {{ \Illuminate\Support\Carbon::parse($today)->format('l, M j') }}
        </span>

        {{-- No Week/Month tabs here, at the centre's request: this screen is
             today's roster and nothing else on the face of it. The four-line
             sheet is still rendered below and still opens by address
             (?view=sheet, with ?span=week|month) — the register and the
             month sheet are where the week and the month are read. --}}

        <div class="relative ml-auto">
            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
            <label class="sr-only" for="check-in-search">Search</label>
            <input id="check-in-search" x-model="search" placeholder="Search name or LAN"
                   class="w-48 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:ring-2 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800">
        </div>

@if($canAmend)
            {{-- Edit unlocks the days already gone, so a check nobody
                 recorded at the time can be put right. A mode rather than a
                 permanent state: the screen somebody stands in front of all
                 day offers today and nothing else, because the commonest
                 mistake on a thirty-column grid is the column next to the
                 one you meant. --}}
            <div class="att-mode" :data-edit="editing ? 'true' : 'false'">
                <button type="button" role="switch" class="att-switch" :aria-checked="editing ? 'true' : 'false'" :aria-label="editing ? 'Edit mode' : 'Live mode'" @click="editing = ! editing; closePicker()">
                    <span class="att-knob" x-html="editing ? icons.edit : icons.lock"></span>
                </button>
                <span class="att-mode-name" x-text="editing ? 'Edit mode' : 'Live mode'"></span>
            </div>
        @endif

        <button type="button" @click="legendOpen = ! legendOpen" :aria-expanded="legendOpen"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">
            <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
            Symptom codes
        </button>
    </div>

    <div x-show="legendOpen" x-cloak class="glass-card mt-3 rounded-2xl p-4">
        <ul class="grid gap-x-6 gap-y-2 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($codes as $code)
                <li class="flex items-center gap-2 text-sm">
                    <span class="att-chip {{ $code['code'] === 0 ? 'att-chip-ok' : 'att-chip-sick' }}">{{ $code['code'] }}</span>
                    <span>{{ $code['label'] }}{{ $code['requires_note'] ? ' (specify)' : '' }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- The sheet: four lines a child, a column a day. The other tab. It is
         rendered whatever the tab, because the tests and the exports read it
         and because switching to it should not cost a request. --}}
    <div x-show="view === 'sheet'" x-cloak>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button type="button" @click="room = ''"
                :class="room === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'"
                class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
            All <span x-text="counts.in"></span>/{{ $stats['enrolled'] }}
        </button>

        @foreach($roomChips as $chip)
            <button type="button" @click="room = @js($chip['room'])"
                    :class="room === @js($chip['room']) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'"
                    class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition">
                <span aria-hidden="true">{{ $chip['animal'] }}</span>
                {{ $chip['room'] }}
                <span class="font-normal text-slate-500 dark:text-slate-400" x-text="roomCount(@js($chip['room'])) + '/{{ $chip['total'] }}'"></span>
            </button>
        @endforeach

        <p class="ml-auto flex flex-wrap items-center gap-3 text-sm">
            <span><b>{{ $stats['enrolled'] }}</b> enrolled</span>
            <span class="text-emerald-600 dark:text-emerald-400"><b x-text="counts.in"></b> in</span>
            {{-- The number somebody is actually chasing at half past eight:
                 booked today and not here yet. "Not in" counts the whole
                 roll, most of whom were never coming. --}}
            <span x-show="{{ $stats['awaited'] }} > 0" x-cloak class="text-sky-600 dark:text-sky-400"><b>{{ $stats['awaited'] }}</b> awaited</span>
            <span x-show="counts.sick > 0" x-cloak class="text-amber-600 dark:text-amber-400"><b x-text="counts.sick"></b> sick</span>
            <span class="text-rose-600 dark:text-rose-400"><b x-text="{{ $stats['enrolled'] }} - counts.in"></b> not in</span>
        </p>
    </div>

    <div class="glass-card mt-3 overflow-x-auto rounded-2xl">
        <table class="att-grid-table w-max table-fixed border-collapse">
            <thead>
                <tr>
                    <th scope="col" class="{{ $cell }} att-name-col sticky left-0 z-10 bg-white text-left text-[0.7333rem] font-semibold uppercase tracking-wide text-slate-500 dark:bg-night-900 dark:text-slate-400">Student</th>
                    <th scope="col" class="{{ $cell }} att-line-col bg-slate-50 py-3 dark:bg-white/5"><span class="sr-only">Line</span></th>
                    @foreach($days as $day)
                        {{-- Today is the column being worked in, so it is picked
                             out of the five rather than counted along to. --}}
                        <th scope="col" @class([
                            $cell,
                            'att-day-col px-1 py-2 text-center font-semibold',
                            'bg-sky-50 text-sky-800 dark:bg-sky-500/10 dark:text-sky-200' => $day->toDateString() === $today,
                            'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500' => $day->toDateString() !== $today && $day->isWeekend(),
                            'text-slate-600 dark:text-slate-300' => $day->toDateString() !== $today && ! $day->isWeekend(),
                        ])>
                            <span class="block text-[0.6667rem] uppercase">{{ strtoupper(substr($day->format('D'), 0, 2)) }}</span>
                            <span class="block text-sm">{{ $day->day }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>

            @foreach($rows as $row)
                <tbody x-show="shows(@js($row['room']), @js($row['name'].' '.$row['lan']))">
                    @php($lines = [['IN', 'in', false], ['health', 'in_code', true], ['OUT', 'out', false], ['health', 'out_code', true]])

                    @foreach($lines as $index => [$label, $key, $isCode])
                        <tr>
                            @if($index === 0)
                                <th scope="rowgroup" rowspan="4" class="{{ $cell }} att-name-col sticky left-0 z-10 bg-white text-left align-middle dark:bg-night-900">
                                    <span class="block text-[0.7333rem] font-bold leading-tight">{{ $row['name'] }}</span>
                                    <span class="mt-0.5 block text-[0.6667rem] font-normal text-slate-500 dark:text-slate-400">
                                        <span aria-hidden="true">{{ $row['animal'] }}</span>
                                        {{ $row['room'] }} · {{ $row['lan'] }}
                                    </span>
                                </th>
                            @endif

                            <td class="{{ $cell }} att-line-col bg-slate-50 py-1 text-center text-[0.6rem] font-semibold tracking-wide text-slate-400 dark:bg-white/5">{{ $label }}</td>

                            @foreach($days as $day)
                                @php($iso = $day->toDateString())
                                @php($isToday = $iso === $today)

                                <td @class([
                                    $cell,
                                    'px-2 py-1.5 text-center tabular-nums',
                                    'bg-sky-50/50 dark:bg-sky-500/5' => $isToday,
                                    'bg-slate-100 dark:bg-slate-800' => ! $isToday && $day->isWeekend(),
                                ])>
                                    {{-- Every cell is drawn by Alpine: today's
                                         because it always takes a press, and the
                                         rest because Edit lets them take one too.
                                         Either way a save has to change what is on
                                         screen without a reload. --}}
                                    <span x-html="cell({{ $row['id'] }}, @js($iso), @js($key), @js($isCode))"></span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>

    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
        Today's column takes a press: an empty <b>IN</b> checks the child in, a time on <b>OUT</b> checks them out, and a chip sets or corrects the code.
        @if($canAmend)
            Earlier days are read-only until you switch <b>Edit mode</b> on, above; in Edit a past day takes a symptom code but not a time &mdash; times are corrected on the <a href="{{ route('attendance.index') }}" class="underline underline-offset-2">attendance register</a>, which records who changed them.
        @else
            Earlier days are read-only here &mdash; a director can correct them, or they can be put right on the <a href="{{ route('attendance.index') }}" class="underline underline-offset-2">attendance register</a>.
        @endif
    </p>
    </div>

    {{-- ============================================================
         The roster: today, one card a child.

         What the screen opens on. A row of room pills with who is in over
         who is enrolled, a card a child with their face, their room and
         whether they are here, and a panel beside it with the day's totals.
         Press a card and a small dialog takes the health code and clocks
         the child in or out — the same two endpoints the sheet uses, so a
         card and a cell can never disagree about a child.

         Drawn from the same rows as the sheet, and every count on it is
         worked out from those rows rather than carried as a running total,
         so it cannot drift from the cards under it.
         ============================================================ --}}
    <div x-show="view === 'today'" class="mt-4">
        {{-- Room pills: who is in, over who is enrolled. --}}
        <div class="flex flex-wrap gap-2" role="group" aria-label="Filter children by room">
            <button type="button" @click="room = ''" :aria-pressed="room === '' ? 'true' : 'false'"
                    :class="room === '' ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-night-900 dark:text-slate-300 dark:hover:bg-white/10'"
                    class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition">
                All <span class="ml-1.5 opacity-70 tabular-nums" x-text="tally('').in + '/' + tally('').total"></span>
            </button>
            <template x-for="chip in rooms" :key="chip.room">
                <button type="button" @click="room = chip.room" :aria-pressed="room === chip.room ? 'true' : 'false'"
                        :class="room === chip.room ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:bg-night-900 dark:text-slate-300 dark:hover:bg-white/10'"
                        class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition">
                    <span x-text="chip.animal" aria-hidden="true"></span>
                    <span class="ml-1" x-text="chip.room"></span>
                    <span class="ml-1.5 opacity-70 tabular-nums" x-text="tally(chip.room).in + '/' + tally(chip.room).total"></span>
                </button>
            </template>
        </div>

        <div class="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
            {{-- The cards. --}}
            <section class="glass-card rounded-2xl">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-white/10">
                    <h2 class="text-base font-bold">
                        <span x-text="room === '' ? 'All children' : room"></span>
                        <span class="ml-2 text-sm font-normal text-slate-500 dark:text-slate-400" x-text="cards.length + ' children'"></span>
                    </h2>
                    <p class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span> On premises</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full border border-slate-400" aria-hidden="true"></span> Currently out</span>
                    </p>
                </div>

                {{-- Five across only on a genuinely wide screen: this theme has
                     no 2xl breakpoint, and `desktop` is its word for one. --}}
                <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3 xl:grid-cols-4 desktop:grid-cols-5">
                    {{-- `card`, not `child`. The dialog below is opened by writing
                         the component's `child`, and Alpine writes to the nearest
                         scope that owns the name — so a loop variable also called
                         `child` would swallow the assignment, and the dialog would
                         never open. It did exactly that once. --}}
                    <template x-for="card in cards" :key="card.id">
                        <button type="button" @click="openChild(card.id)"
                                class="group rounded-2xl border border-transparent px-2 py-4 text-center transition hover:-translate-y-0.5 hover:border-indigo-200 hover:bg-indigo-50/40 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:border-indigo-500/30 dark:hover:bg-indigo-500/10"
                                :aria-label="card.display + ', ' + card.room + ', ' + (isIn(card) ? 'on premises' : 'currently out') + '. Open attendance.'">
                            <span class="relative mx-auto block h-24 w-24">
                                {{-- The child's own face, ringed green when they are here. --}}
                                <span class="block h-24 w-24 overflow-hidden rounded-full ring-4 ring-white dark:ring-night-900"
                                      :class="isIn(card) ? 'outline outline-[3px] outline-emerald-500' : 'outline outline-2 outline-slate-200 dark:outline-white/10'"
                                      x-html="card.avatar"></span>
                                <span x-show="isIn(card)" x-cloak class="absolute -bottom-0.5 -right-0.5 grid h-7 w-7 place-items-center rounded-full border-[3px] border-white bg-emerald-500 text-xs font-bold text-white dark:border-night-900" aria-hidden="true">✓</span>
                            </span>
                            <span class="mt-3 block truncate text-[0.9333rem] font-semibold" x-text="card.display"></span>
                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400" x-text="card.room"></span>
                            <span class="mt-2 block text-xs" :class="isIn(card) ? 'font-semibold text-emerald-700 dark:text-emerald-300' : (isOut(card) ? 'text-slate-500 dark:text-slate-400' : 'text-slate-400 dark:text-slate-500')" x-text="stateOf(card)"></span>
                        </button>
                    </template>
                    <p x-show="cards.length === 0" x-cloak class="col-span-full py-12 text-center text-sm text-slate-500 dark:text-slate-400">No children match.</p>
                </div>

                <p class="border-t border-slate-200/70 px-5 py-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">Select a child to record a health code and clock them in or out.</p>
            </section>

            {{-- The day, at a glance. --}}
            <aside class="glass-card rounded-2xl p-5 lg:sticky lg:top-4" aria-label="Today at a glance">
                <p class="text-[0.6667rem] font-bold uppercase tracking-[0.15em] text-slate-500 dark:text-slate-400">Live overview</p>
                <h2 class="mt-1 text-lg font-bold" x-text="room === '' ? 'Today at a glance' : room + ' today'"></h2>

                <dl class="mt-4 grid grid-cols-2 gap-2.5">
                    <div class="rounded-xl bg-emerald-50 px-3 py-3.5 dark:bg-emerald-500/10">
                        <dd class="text-3xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300" x-text="tally(room).in"></dd>
                        <dt class="mt-0.5 text-xs text-emerald-800/80 dark:text-emerald-200/80">Currently in</dt>
                    </div>
                    <div class="rounded-xl bg-slate-100 px-3 py-3.5 dark:bg-white/5">
                        <dd class="text-3xl font-bold tabular-nums" x-text="tally(room).total - tally(room).in"></dd>
                        <dt class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Currently out</dt>
                    </div>
                </dl>

                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10" aria-hidden="true">
                    <div class="h-full rounded-full bg-emerald-500 transition-all" :style="'width:' + (tally(room).total ? tally(room).in / tally(room).total * 100 : 0) + '%'"></div>
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400"><span x-text="tally(room).in"></span> of <span x-text="tally(room).total"></span> children on premises</p>

                <div class="mt-5 border-t border-slate-200/70 pt-4 dark:border-white/10">
                    <h3 class="text-sm font-bold">Across all classrooms</h3>
                    <ul class="mt-2 space-y-2.5">
                        <template x-for="chip in rooms" :key="'sum-' + chip.room">
                            <li class="flex items-center justify-between text-sm text-slate-600 dark:text-slate-300">
                                <span><span x-text="chip.animal" aria-hidden="true"></span> <span class="ml-1" x-text="chip.room"></span></span>
                                <span class="tabular-nums"><b class="text-slate-900 dark:text-white" x-text="tally(chip.room).in"></b> <span class="text-xs text-slate-400" x-text="'/ ' + tally(chip.room).total + ' in'"></span></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </aside>
        </div>
    </div>

    {{-- The card's dialog: the health code, and the clock. --}}
    <template x-if="child">
        <div class="fixed inset-0 z-50 grid place-items-center p-4" role="dialog" aria-modal="true" :aria-labelledby="'child-name-' + child.id" @keydown.escape.window="closeChild()">
            <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="closeChild()" aria-hidden="true"></div>

            <div class="glass-card relative w-full max-w-sm rounded-3xl p-6 text-center">
                <button type="button" @click="closeChild()" class="absolute right-4 top-4 grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-lg text-slate-500 hover:bg-slate-200 dark:bg-white/10 dark:hover:bg-white/20" aria-label="Close">×</button>

                <span class="mx-auto block h-28 w-28 overflow-hidden rounded-full outline outline-[3px]" :class="isIn(child) ? 'outline-emerald-500' : 'outline-slate-200 dark:outline-white/10'" x-html="child.avatar.replace('h-24 w-24', 'h-28 w-28')"></span>
                <h2 class="mt-4 text-2xl font-bold" :id="'child-name-' + child.id" x-text="child.display"></h2>
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400" x-text="child.room + ' · ' + child.lan"></p>
                <span class="mt-3 inline-block rounded-full px-3 py-1 text-xs font-semibold"
                      :class="isIn(child) ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'"
                      x-text="isIn(child) ? '✓ On premises · in at ' + today_of(child).in : (isOut(child) ? '○ Left at ' + today_of(child).out : '○ Currently out')"></span>

                <template x-if="! isOut(child)">
                    <form class="mt-5 text-left" @submit.prevent="clockChild()">
                        <label for="ci-code" class="block text-sm font-semibold">Health code</label>
                        <select id="ci-code" x-model.number="code" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-night-800">
                            <template x-for="entry in codes" :key="entry.code">
                                <option :value="entry.code" x-text="entry.code + ' · ' + entry.label"></option>
                            </template>
                        </select>

                        <div x-show="needsNote(code)" x-cloak class="mt-3">
                            <label for="ci-note" class="block text-sm font-semibold">Note</label>
                            <textarea id="ci-note" x-model="note" rows="2" maxlength="120" placeholder="What was seen — required for “Other”"
                                      class="mt-1.5 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-night-800"></textarea>
                        </div>

                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Defaults to 0 · Normal. Choose another code when something was seen.</p>

                        <p x-show="error" x-cloak class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" x-text="error"></p>

                        <button type="submit" :disabled="saving"
                                :class="isIn(child) ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                                class="mt-4 w-full rounded-xl py-3 text-sm font-bold text-white transition disabled:opacity-50"
                                x-text="saving ? 'Saving…' : (isIn(child) ? 'Clock out' : 'Clock in')"></button>
                    </form>
                </template>

                <template x-if="isOut(child)">
                    <p class="mt-5 rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                        Checked in at <b x-text="today_of(child).in"></b> and out at <b x-text="today_of(child).out"></b>. The codes can be corrected on the sheet.
                    </p>
                </template>
            </div>
        </div>
    </template>

    <p x-show="toast" x-cloak class="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-xl dark:bg-white dark:text-slate-900" role="status" aria-live="polite" x-text="toast"></p>

    {{-- The picker. One on the page, moved to whichever cell was pressed. --}}
    <template x-if="picker">
        <div>
            <div class="fixed inset-0 z-40" @click="closePicker()" aria-hidden="true"></div>

            <div class="absolute z-50 w-[19rem] rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-slate-900"
                 :style="'top:' + picker.top + 'px; left:' + picker.left + 'px'"
                 role="dialog" :aria-label="'Health check for ' + picker.name"
                 @keydown.escape.window="closePicker()">
                <p class="text-xs font-semibold" x-text="picker.title + picker.when"></p>
                <p class="mt-0.5 text-[0.7333rem] text-slate-500 dark:text-slate-400">Choose what was seen. Nothing is recorded until you do.</p>

                <div class="mt-2 grid grid-cols-2 gap-1">
                    <template x-for="entry in codes" :key="entry.code">
                        <button type="button" @click="save(entry.code)" :disabled="saving"
                                :class="entry.code === picker.current
                                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                    : 'hover:bg-slate-100 dark:hover:bg-white/10'"
                                class="flex items-center gap-1.5 rounded-lg px-2 py-2 text-left text-[0.7333rem] font-medium transition disabled:opacity-50">
                            <span class="w-4 shrink-0 text-center tabular-nums" x-text="entry.code"></span>
                            <span class="truncate" x-text="entry.label"></span>
                        </button>
                    </template>
                </div>

                <label class="mt-2 block">
                    <span class="sr-only">Note</span>
                    <input x-model="note" maxlength="120" placeholder="Note — required for “Other”"
                           class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs dark:border-white/10 dark:bg-slate-900">
                </label>

                <p x-show="error" x-cloak class="mt-2 rounded-lg bg-rose-50 px-2 py-1.5 text-[0.7333rem] text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" x-text="error"></p>

                <div class="mt-2 flex justify-end border-t border-slate-100 pt-2 dark:border-white/10">
                    <button type="button" @click="closePicker()" class="text-[0.7333rem] font-semibold text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100">Cancel</button>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function checkInGrid() { return {
    rows: @js($rows->keyBy('id')),
    codes: @js($codes),
    today: @js($today),
    canAmend: @js($canAmend),
    editing: false,
    icons: {
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>',
    },
    search: '',
    room: '',
    legendOpen: false,
    picker: null,
    note: '',
    error: '',
    saving: false,

    /* ---- the roster: today, one card a child ----

       Worked from the same rows as the sheet. Every count here is derived
       from those rows on each read rather than carried as a running total,
       so a card and the number above it can never disagree. */
    view: @js($view),
    child: null,      // the card open in the dialog, if any
    code: 0,
    toast: '',
    toastTimer: null,

    /** The rooms, in order, with their animal — from the rows themselves. */
    get rooms() {
        const seen = {};
        Object.values(this.rows).forEach(row => { seen[row.room] = row.animal; });
        return Object.keys(seen).sort().map(room => ({room, animal: seen[room] || ''}));
    },

    /** The cards on screen: the room chosen, and the search box. */
    get cards() {
        const term = this.search.trim().toLowerCase();
        return Object.values(this.rows)
            .filter(row => this.room === '' || row.room === this.room)
            .filter(row => term === '' || (row.display + ' ' + row.lan).toLowerCase().includes(term))
            .sort((a, b) => a.display.localeCompare(b.display));
    },

    today_of(row) { return row.byDay?.[this.today] ?? null; },
    isIn(row) { const day = this.today_of(row); return !! (day && day.in && ! day.out); },
    isOut(row) { const day = this.today_of(row); return !! (day && day.out); },
    stateOf(row) {
        if (this.isIn(row)) return 'In · ' + this.today_of(row).in;
        if (this.isOut(row)) return 'Out · ' + this.today_of(row).out;
        return 'Currently out';
    },

    /** In over enrolled, for one room or ('') the whole roll. */
    tally(room) {
        const rows = Object.values(this.rows).filter(row => room === '' || row.room === room);
        return {total: rows.length, in: rows.filter(row => this.isIn(row)).length};
    },

    openChild(id) {
        this.child = this.rows[id] ?? null;
        this.code = 0;
        this.note = '';
        this.error = '';
    },

    closeChild() {
        this.child = null;
        this.note = '';
        this.error = '';
    },

    /**
     * Clock the open child in or out, with the code chosen.
     *
     * The same two endpoints the sheet presses — an arrival books the child's
     * first session and takes the arrival check; a departure stamps now and
     * takes the leaving check — so a card and a cell can never disagree.
     */
    async clockChild() {
        if (! this.child || this.saving) return;

        const code = Number(this.code);

        if (this.needsNote(code) && ! this.note.trim()) {
            this.error = 'That code needs a short note saying what was seen.';

            return;
        }

        const row = this.child;
        const day = this.today_of(row);
        const leaving = this.isIn(row);

        this.saving = true;
        this.error = '';

        try {
            const response = await window.postJson(
                leaving ? '/check-in/' + day.id + '/out' : '/check-in',
                leaving
                    ? { health_code: code, health_note: this.note.trim() || null }
                    : { child_id: row.id, session: row.session, health_code: code, health_note: this.note.trim() || null },
            );
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                this.error = Object.values(data.errors ?? {}).flat()[0] || data.message || 'That could not be saved.';

                return;
            }

            this.rows[row.id].byDay[this.today] = {
                id: data.attendance_id,
                in: data.in_at, in_code: data.health_in, in_note: data.health_in_note,
                out: data.out_at, out_code: data.health_out, out_note: data.health_out_note,
            };
            this.rows = { ...this.rows };

            this.closeChild();
            this.toast = row.display + (leaving ? ' clocked out at ' + data.out_at : ' clocked in at ' + data.in_at);
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast = ''; }, 4500);
        } catch {
            this.error = 'That could not be saved. Check the connection and try again.';
        } finally {
            this.saving = false;
        }
    },

    /** Whether one child's four-line block is on screen. */
    shows(room, haystack) {
        if (this.room && room !== this.room) return false;

        const needle = this.search.trim().toLowerCase();

        return ! needle || haystack.toLowerCase().includes(needle);
    },

    get counts() {
        let inNow = 0, sick = 0;

        Object.values(this.rows).forEach(row => {
            const day = row.byDay?.[this.today];

            if (! day) return;

            if (day.in) inNow++;

            if ((day.in_code !== null && day.in_code !== 0)
                || (day.out_code !== null && day.out_code !== 0)) sick++;
        });

        return { in: inNow, sick };
    },

    roomCount(room) {
        return Object.values(this.rows)
            .filter(row => row.room === room && row.byDay?.[this.today]?.in)
            .length;
    },

    /**
     * One of today's cells.
     *
     * Built as a string rather than as markup, so a save can replace it by
     * reassigning the row — the same reason the register's boxes are strings.
     */
    /**
     * Whether a day can be written to from here.
     *
     * Today is everybody's. A day already gone is the director's, and only
     * while the screen is in Edit — the same two-step the register uses, and
     * for the same reason: on a grid this wide the commonest mistake is the
     * column next to the one you meant.
     *
     * Checked again on the server, which is what actually decides it.
     */
    open(date) {
        return date === this.today || (this.canAmend && this.editing);
    },

    cell(childId, date, key, isCode) {
        const day = this.rows[childId]?.byDay?.[date] ?? null;
        const value = day ? day[key] : null;
        const live = this.open(date);

        if (isCode) {
            // A code needs an arrival to hang off, and a leaving code needs a
            // departure. Anything else is a press that would be refused.
            const ready = day && (key === 'in_code' ? day.in : day.out);

            if (! ready) return '';

            const cls = value === null ? 'att-chip-none' : (value === 0 ? 'att-chip-ok' : 'att-chip-sick');

            if (! live) {
                return '<span class="att-chip att-chip-locked ' + cls + '">' + (value === null ? '' : value) + '</span>';
            }

            return '<button type="button" class="att-chip ' + cls + '" data-act="' + key + '" data-child="' + childId + '" data-date="' + date + '">'
                + (value === null ? '' : value) + '</button>';
        }

        /*
         * The register's own three states, so the two screens read as one app:
         * a dashed sky box is expected-not-arrived, a solid emerald one is a
         * time that was recorded. See "The attendance cell" in app.css.
         */
        /*
         * A recorded time reads as a time — the same plain figure the month
         * sheet prints, in the register's emerald. Only the cells still to be
         * filled are drawn as something to press, and they are the size of the
         * chip below them rather than the width of the column.
         */
        if (value) return '<span class="att-mark att-mark-time">' + value + '</span>';

        // A day nobody can write to shows nothing where nothing happened.
        if (! live) return '';

        if (key === 'in') {
            /*
             * Booked today and not here yet is the cell somebody is looking
             * for, so it is the one that is drawn boldly. A child who was
             * never booked today still gets a cell — they may well walk in —
             * but a quiet one, or sixty of them would bury the dozen that
             * are actually outstanding.
             */
            const booked = this.rows[childId]?.booked?.[date];

            return '<button type="button" class="att-mark att-mark-todo' + (booked ? '' : ' att-mark-spare') + '"'
                + ' data-act="check-in" data-child="' + childId + '" data-date="' + date + '"'
                + ' title="' + (booked ? 'Booked in today — not arrived yet' : 'Not booked today — check in anyway') + '"'
                + ' aria-label="Check in">' + (booked ? 'in' : '+') + '</button>';
        }

        // Out: only offered once the child is actually here.
        if (! day || ! day.in) return '';

        return '<button type="button" class="att-mark att-mark-todo" data-act="check-out" data-child="' + childId + '" data-date="' + date + '" aria-label="Check out">out</button>';
    },

    /**
     * Every press in the sheet's today column, caught once on the root.
     *
     * Bound as @click on the root element rather than attached in init(): it
     * is the same delegation the register uses, and it needs no magic
     * property to find the element it is on.
     */
    onRootClick(event) {
        const button = event.target.closest('[data-act]');

        if (! button) return;

        event.stopPropagation();

        this.ask(Number(button.dataset.child), button.dataset.date, button.dataset.act, button);
    },

    ask(childId, date, act, anchor) {
        const row = this.rows[childId];
        const day = this.rows[childId]?.byDay?.[date] ?? null;
        const box = anchor.getBoundingClientRect();

        const direction = (act === 'check-out' || act === 'out_code') ? 'out' : 'in';

        this.error = '';
        this.note = (day ? (direction === 'out' ? day.out_note : day.in_note) : '') || '';
        this.picker = {
            childId,
            date,
            act,
            direction,
            name: row.name,
            title: {
                'check-in': 'Checking in — ' + row.name,
                'check-out': 'Checking out — ' + row.name,
                'in_code': 'Arrival check — ' + row.name,
                'out_code': 'Leaving check — ' + row.name,
            }[act],
            current: day ? (direction === 'out' ? day.out_code : day.in_code) : null,
            // Named where it is not today, so nobody corrects the wrong
            // column on a grid thirty wide.
            when: date === this.today ? '' : ' · ' + date,
            top: box.bottom + window.scrollY + 6,
            left: Math.min(box.left + window.scrollX, window.innerWidth - 320),
        };
    },

    closePicker() {
        this.picker = null;
        this.note = '';
        this.error = '';
    },

    needsNote(code) {
        return !! this.codes.find(entry => entry.code === code)?.requires_note;
    },

    async save(code) {
        if (! this.picker || this.saving) return;

        if (this.needsNote(code) && ! this.note.trim()) {
            this.error = 'That code needs a short note saying what was seen.';

            return;
        }

        const { childId, date, act } = this.picker;
        const row = this.rows[childId];
        const day = this.rows[childId]?.byDay?.[date] ?? null;

        this.saving = true;
        this.error = '';

        try {
            /*
             * Today goes to the door endpoints, which stamp the hour as well
             * as the code. A day already gone goes to the health endpoint,
             * which changes the code and nothing else — the times on a past
             * day are the register's to correct, because that is where a
             * changed time is written to attendance_amendments.
             *
             * That endpoint is also the one that knows a past day is the
             * director's, so a teacher with a stale tab is refused there.
             */
            const past = date !== this.today;

            if (past && ! day) {
                this.error = 'There is no arrival on that day to attach a check to. Record it on the attendance register first.';

                return;
            }

            const url = past
                ? '/attendance/' + day.id + '/health'
                : (act === 'check-in'
                    ? '/check-in'
                    : '/check-in/' + day.id + (act === 'check-out' || act === 'out_code' ? '/out' : '/in'));

            const body = past
                ? { direction: this.picker.direction, code, note: this.note.trim() || null }
                : (act === 'check-in'
                    ? { child_id: childId, session: row.session, health_code: code, health_note: this.note.trim() || null }
                    : { health_code: code, health_note: this.note.trim() || null });

            const response = await window.postJson(url, body);
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                this.error = data?.message || 'That could not be saved.';

                return;
            }

            if (past) {
                // The health endpoint answers about the one code it changed.
                const updated = { ...day };

                if (data.direction === 'out') {
                    updated.out_code = data.code;
                    updated.out_note = data.note;
                } else {
                    updated.in_code = data.code;
                    updated.in_note = data.note;
                }

                this.rows[childId].byDay[date] = updated;
            } else {
                this.rows[childId].byDay[date] = {
                    id: data.attendance_id,
                    in: data.in_at,
                    in_code: data.health_in,
                    in_note: data.health_in_note,
                    out: data.out_at,
                    out_code: data.health_out,
                    out_note: data.health_out_note,
                };
            }

            this.rows = { ...this.rows };
            this.closePicker();
        } catch {
            this.error = 'That could not be saved. Check the connection and try again.';
        } finally {
            this.saving = false;
        }
    },
}}
</script>
@endsection
