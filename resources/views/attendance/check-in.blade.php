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

<div x-data="checkInGrid()">

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="text-xl font-bold tracking-tight">Check in</h1>
        <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-800 dark:bg-sky-500/15 dark:text-sky-200">
            {{ \Illuminate\Support\Carbon::parse($today)->format('l, M j') }}
        </span>

        {{-- How much of the record to draw. A link apiece rather than a
             filter, so the choice is in the address and a bookmark to the
             week stays the week. --}}
        <div class="flex shrink-0 items-center gap-0.5 rounded-lg bg-slate-100 p-0.5 dark:bg-slate-800">
            @foreach([\App\Http\Controllers\CheckInController::WEEK => 'This week', \App\Http\Controllers\CheckInController::MONTH => 'This month'] as $value => $label)
                @if($span === $value)
                    <span class="rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-slate-900 shadow-sm dark:bg-night-700 dark:text-night-950" aria-current="page">{{ $label }}</span>
                @else
                    <a href="{{ route('check-in.index', ['span' => $value]) }}" class="rounded-md px-2.5 py-1 text-xs font-semibold text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100">{{ $label }}</a>
                @endif
            @endforeach
        </div>

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

    /** Every press in today's column, caught once on the page. */
    init() {
        this.$el.addEventListener('click', event => {
            const button = event.target.closest('[data-act]');

            if (! button) return;

            event.stopPropagation();

            this.ask(Number(button.dataset.child), button.dataset.date, button.dataset.act, button);
        });
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
