@props([
    'from',
    'to',
    // Where Apply goes. The chosen dates are added as ?from=&to=, so the page
    // reading them needs no JavaScript of its own.
    'action',
    // Query keys to carry through, so choosing a range does not silently drop
    // the role filter somebody set a moment ago.
    'keep' => [],
    'align' => 'left',
])
{{--
    A date range, chosen either by name or by calendar.

    Two halves, because there are two ways people arrive at a range. Most of the
    time they want a named period — this month, last thirty days — and picking
    those off a calendar means counting backwards from today and getting it
    wrong. The rest of the time they want two specific dates, and no list of
    presets will ever hold the one they mean.

    Two months side by side, because nearly every range anybody asks for crosses
    a month boundary, and paging back and forth to place the second end of it is
    how you lose the first.

    Nothing is applied until Apply. The panel holds a draft: a half-made range —
    one end picked, the other not — is a state the page behind must never be
    asked to render.
--}}
<div
    x-data="dateRange({
        from: @js($from),
        to: @js($to),
        action: @js($action),
        keep: @js($keep),
    })"
    @keydown.escape.window="open = false"
    class="relative"
>
    {{-- One pill, three parts: back a period, the period itself, forward a
         period. Stepping is much the commoner thing to want — "and the week
         before that" — and making it a trip through the calendar each time
         was three clicks for what is one idea. --}}
    <div class="inline-flex items-center rounded-full border border-slate-200 bg-white text-sm font-medium dark:border-white/10 dark:bg-slate-800">
        <button type="button" @click="shift(-1)"
                class="grid h-9 w-9 place-items-center rounded-l-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 dark:hover:bg-white/5"
                :aria-label="'Earlier: ' + shiftedLabel(-1)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>

        <span class="h-5 w-px bg-slate-200 dark:bg-white/10"></span>

        <button type="button" @click="toggle()"
                class="inline-flex items-center gap-2 px-4 py-2 transition hover:bg-slate-50 dark:hover:bg-white/5"
                :aria-expanded="open">
            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <span x-text="label"></span>
        </button>

        <span class="h-5 w-px bg-slate-200 dark:bg-white/10"></span>

        <button type="button" @click="shift(1)"
                class="grid h-9 w-9 place-items-center rounded-r-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 dark:hover:bg-white/5"
                :aria-label="'Later: ' + shiftedLabel(1)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
            </svg>
        </button>
    </div>

    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
         @click.outside="open = false"
         class="absolute z-40 mt-2 w-[min(42rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-900"
         :class="@js($align) === 'right' ? 'right-0' : 'left-0'"
         role="dialog" aria-label="Choose a date range">

        <div class="flex flex-col sm:flex-row">
            {{-- The named periods, which is how most ranges are actually
                 asked for. --}}
            <ul class="shrink-0 border-b border-slate-100 py-2 sm:w-40 sm:border-b-0 sm:border-r dark:border-white/10">
                <template x-for="preset in presets" :key="preset.label">
                    <li>
                        <button type="button" @click="choose(preset)"
                                class="w-full px-4 py-2 text-left text-sm transition hover:bg-slate-50 dark:hover:bg-white/5"
                                :class="isCurrent(preset) ? 'font-semibold text-indigo-600 dark:text-indigo-400' : 'text-slate-600 dark:text-slate-300'"
                                x-text="preset.label"></button>
                    </li>
                </template>
            </ul>

            <div class="min-w-0 flex-1 p-4">
                <div class="flex items-start gap-6">
                    <template x-for="offset in [0, 1]" :key="offset">
                        <div class="min-w-0 flex-1" :class="offset === 1 ? 'hidden sm:block' : ''">
                            <div class="flex items-center justify-between">
                                <button type="button" @click="step(-1)" x-show="offset === 0"
                                        class="grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 dark:hover:bg-white/10"
                                        aria-label="Previous month">‹</button>
                                <span x-show="offset === 1" class="h-7 w-7"></span>

                                <span class="text-sm font-semibold" x-text="monthName(offset)"></span>

                                <button type="button" @click="step(1)" x-show="offset === 1"
                                        class="grid h-7 w-7 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 dark:hover:bg-white/10"
                                        aria-label="Next month">›</button>
                                <span x-show="offset === 0" class="h-7 w-7"></span>
                            </div>

                            <div class="mt-3 grid grid-cols-7 text-center text-[11px] font-semibold text-slate-400">
                                <template x-for="(day, index) in ['Su','Mo','Tu','We','Th','Fr','Sa']" :key="index">
                                    <span class="py-1" x-text="day"></span>
                                </template>
                            </div>

                            <div class="grid grid-cols-7 gap-y-0.5 text-center text-[13px]">
                                <template x-for="cell in monthCells(offset)" :key="cell.key">
                                    {{-- The whole cell carries the range tint,
                                         not just the number, so a selected span
                                         reads as one bar rather than as a row of
                                         separate marks. --}}
                                    <button type="button"
                                            @click="pick(cell.iso)"
                                            @mouseenter="hover = cell.iso"
                                            :disabled="! cell.inMonth"
                                            class="h-9 text-sm transition disabled:cursor-default"
                                            :class="cellClass(cell)"
                                            x-text="cell.day"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 dark:border-white/10">
            {{-- The draft, said back. Somebody who has clicked one end needs to
                 see that the panel is waiting for the other. --}}
            <span class="text-xs text-slate-500 dark:text-slate-400" x-text="draftLabel"></span>

            <span class="flex gap-2">
                <button type="button" @click="cancel()"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 dark:hover:bg-white/10">Cancel</button>
                <button type="button" @click="apply()" :disabled="! draft.from || ! draft.to"
                        class="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-30 dark:bg-white dark:text-slate-900">Apply</button>
            </span>
        </div>
    </div>
</div>

@once
@push('scripts')
<script>
function dateRange(config) { return {
    open: false,
    action: config.action,
    keep: config.keep ?? {},

    // What the page is currently showing, and what the panel is drafting. Kept
    // apart so Cancel is a real cancel.
    from: config.from,
    to: config.to,
    draft: { from: null, to: null },
    hover: null,

    // The left-hand month. The right one is always the month after it.
    cursor: null,

    init() {
        this.cursor = this.startOfMonth(this.from ?? this.today());
        this.resetDraft();
    },

    /* ---- dates, as plain YYYY-MM-DD strings ----

       Everything here is a string rather than a Date, because a Date is a
       moment in a timezone and these are days on a calendar. Building one from
       "2026-09-11" and reading its date back has moved the day by one more
       than once. */

    today() {
        const at = new Date();

        return this.iso(at.getFullYear(), at.getMonth(), at.getDate());
    },

    iso(year, month, day) {
        return new Date(Date.UTC(year, month, day)).toISOString().slice(0, 10);
    },

    parse(value) {
        const [year, month, day] = value.split('-').map(Number);

        return { year, month: month - 1, day };
    },

    startOfMonth(value) {
        const { year, month } = this.parse(value);

        return this.iso(year, month, 1);
    },

    addMonths(value, months) {
        const { year, month } = this.parse(value);

        return this.iso(year, month + months, 1);
    },

    addDays(value, days) {
        const { year, month, day } = this.parse(value);

        return this.iso(year, month, day + days);
    },

    /* ---- the named periods ---- */

    get presets() {
        const today = this.today();
        const { year, month } = this.parse(today);
        const lastMonth = this.addMonths(this.startOfMonth(today), -1);

        return [
            { label: 'Today', from: today, to: today },
            { label: 'Yesterday', from: this.addDays(today, -1), to: this.addDays(today, -1) },
            { label: 'Last 7 Days', from: this.addDays(today, -6), to: today },
            { label: 'Last 14 Days', from: this.addDays(today, -13), to: today },
            { label: 'Last 30 Days', from: this.addDays(today, -29), to: today },
            { label: 'This Month', from: this.iso(year, month, 1), to: this.addDays(this.addMonths(this.startOfMonth(today), 1), -1) },
            { label: 'Last Month', from: lastMonth, to: this.addDays(this.startOfMonth(today), -1) },
        ];
    },

    isCurrent(preset) {
        return this.draft.from === preset.from && this.draft.to === preset.to;
    },

    choose(preset) {
        this.draft = { from: preset.from, to: preset.to };
        this.hover = null;
        // Show the end of what they picked, which is where they will look to
        // check it.
        this.cursor = this.startOfMonth(preset.from);
    },

    /* ---- the calendars ---- */

    monthName(offset) {
        const { year, month } = this.parse(this.addMonths(this.cursor, offset));

        return new Date(Date.UTC(year, month, 1))
            .toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
    },

    monthCells(offset) {
        const first = this.addMonths(this.cursor, offset);
        const { year, month } = this.parse(first);
        const leading = new Date(Date.UTC(year, month, 1)).getUTCDay();
        const cells = [];

        // Six rows always, so the panel does not change height as the months
        // are paged through.
        for (let index = 0; index < 42; index++) {
            const iso = this.iso(year, month, 1 - leading + index);
            const parsed = this.parse(iso);

            cells.push({
                key: offset + ':' + iso,
                iso,
                day: parsed.day,
                inMonth: parsed.month === month && parsed.year === year,
            });
        }

        return cells;
    },

    /* ---- picking ---- */

    pick(iso) {
        // A fresh range if both ends are set, otherwise close the open one.
        if (! this.draft.from || this.draft.to) {
            this.draft = { from: iso, to: null };

            return;
        }

        // Dragged backwards: the earlier click was the end, not the start.
        this.draft = iso < this.draft.from
            ? { from: iso, to: this.draft.from }
            : { from: this.draft.from, to: iso };
    },

    /* The end that is not chosen yet follows the cursor, so the span being
       drawn is visible before it is committed. */
    get provisionalEnd() {
        return this.draft.to ?? this.hover;
    },

    inRange(iso) {
        const end = this.provisionalEnd;

        if (! this.draft.from || ! end) return false;

        const [low, high] = this.draft.from <= end ? [this.draft.from, end] : [end, this.draft.from];

        return iso >= low && iso <= high;
    },

    isEnd(iso) {
        return iso === this.draft.from || iso === this.provisionalEnd;
    },

    cellClass(cell) {
        if (! cell.inMonth) return 'text-transparent';

        if (this.isEnd(cell.iso)) {
            return 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900';
        }

        if (this.inRange(cell.iso)) {
            return 'bg-indigo-100 text-indigo-900 dark:bg-indigo-500/20 dark:text-indigo-100';
        }

        if (cell.iso === this.today()) {
            return 'font-bold text-indigo-600 ring-1 ring-inset ring-indigo-200 dark:text-indigo-300';
        }

        return 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10';
    },

    /* ---- what the button and the footer say ---- */

    pretty(iso, withYear = true) {
        if (! iso) return '';

        const { year, month, day } = this.parse(iso);

        return new Date(Date.UTC(year, month, day))
            .toLocaleDateString(undefined, withYear
                ? { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }
                : { month: 'short', day: 'numeric', timeZone: 'UTC' });
    },

    /**
     * The range, said as shortly as it can be said without ambiguity.
     *
     * "Sep 21, 2026 – Sep 25, 2026" repeats the month and the year to no
     * purpose; the eye has to read past both to find the two numbers that
     * differ. So the month is dropped from the far end when it is the same
     * month, and the year whenever the range sits inside this one — which is
     * nearly always, and the moment it is not the year comes back.
     */
    get label() {
        if (! this.from || ! this.to) return 'Any dates';

        const [ay, am] = this.from.split('-').map(Number);
        const [by, bm] = this.to.split('-').map(Number);
        const thisYear = new Date().getFullYear();
        const showYear = ay !== thisYear || by !== thisYear;

        if (this.from === this.to) return this.pretty(this.from, showYear);

        // Same month and year: "Sep 21 – 25".
        if (ay === by && am === bm) {
            return this.pretty(this.from, false) + ' – ' + Number(this.to.slice(8, 10))
                + (showYear ? ', ' + by : '');
        }

        return this.pretty(this.from, showYear) + ' – ' + this.pretty(this.to, showYear);
    },

    /**
     * Move the whole range back or forward by its own length.
     *
     * By its length rather than by a month, so a working week steps to the
     * working week before it and a fortnight to the fortnight before it. The
     * weekend is skipped on a Mon–Fri range for free, which is the behaviour
     * somebody pressing "back" on a timesheet actually wants.
     */
    shift(direction) {
        const range = this.shifted(direction);

        if (! range) return;

        this.draft = { from: range.from, to: range.to };
        this.apply();
    },

    /** Where a step would land, without taking it. */
    shifted(direction) {
        if (! this.from || ! this.to) return null;

        const days = Math.round((Date.parse(this.to) - Date.parse(this.from)) / 86400000) + 1;

        return {
            from: this.addDays(this.from, direction * days),
            to: this.addDays(this.to, direction * days),
        };
    },

    /** What that step would be called, for the button's label. */
    shiftedLabel(direction) {
        const range = this.shifted(direction);

        if (! range) return 'another range';

        return range.from === range.to
            ? this.pretty(range.from)
            : this.pretty(range.from) + ' – ' + this.pretty(range.to);
    },

    get draftLabel() {
        if (! this.draft.from) return 'Pick a start date';
        if (! this.draft.to) return this.pretty(this.draft.from) + ' — now pick the end';

        const days = Math.round((Date.parse(this.draft.to) - Date.parse(this.draft.from)) / 86400000) + 1;

        return this.pretty(this.draft.from) + ' – ' + this.pretty(this.draft.to) + ' · ' + days + (days === 1 ? ' day' : ' days');
    },

    /* ---- opening and closing ---- */

    toggle() {
        this.open ? this.cancel() : this.openPanel();
    },

    openPanel() {
        this.resetDraft();
        this.cursor = this.startOfMonth(this.from ?? this.today());
        this.open = true;
    },

    resetDraft() {
        this.draft = { from: this.from, to: this.to };
        this.hover = null;
    },

    cancel() {
        // A real cancel: the draft goes back to what the page is showing.
        this.resetDraft();
        this.open = false;
    },

    step(months) {
        this.cursor = this.addMonths(this.cursor, months);
    },

    apply() {
        if (! this.draft.from || ! this.draft.to) return;

        const url = new URL(this.action, window.location.origin);

        Object.entries(this.keep).forEach(([key, value]) => {
            if (value !== null && value !== '') url.searchParams.set(key, value);
        });

        url.searchParams.set('from', this.draft.from);
        url.searchParams.set('to', this.draft.to);

        window.location = url.toString();
    },
}; }
</script>
@endpush
@endonce
