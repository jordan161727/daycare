{{--
    The register's week, drawn as Teacher Attendance draws its day (26 Oct
    2026, Jordan): one joined pill — a week back, the week itself with a
    calendar icon (press it for the calendar), a week forward — and beside it
    "Today", lit on this week and the way back otherwise. Picking any day in
    the calendar opens that day's week; weeks ahead may be looked at, as the
    arrows already allowed.

    Expects: $w (the week payload: weekStart, label, prev, next, days,
    isThisWeek), $date, $link (closure building the page address).
--}}
@php($weekEnd = collect($w['days'])->last()['date'] ?? $w['weekStart'])
<div class="flex items-center gap-2" role="group" aria-label="Week" data-week-stepper>
    <div class="flex items-center rounded-full border border-slate-200 bg-white dark:border-white/10 dark:bg-slate-800">
        <a href="{{ $link(['date' => $w['prev']]) }}" class="grid h-9 w-9 place-items-center rounded-l-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Previous week">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div x-data="weekPicker()" @keydown.escape.window="open = false" @scroll.window="open && place()" @resize.window="open && place()" class="contents" data-week-picker>
            <button type="button" x-ref="trigger" @click.stop="toggle()" :aria-expanded="open" aria-haspopup="dialog"
                    class="flex h-9 items-center gap-1.5 border-x border-slate-200 px-3 text-xs font-semibold tabular-nums transition hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10 {{ $w['isThisWeek'] ? '' : 'text-sky-700 dark:text-sky-300' }}"
                    title="Pick a day — its week opens" data-week-label>
                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                {{ $w['label'] }}
                <span class="sr-only">Choose a week</span>
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

                    <div class="mt-2 grid grid-cols-7 text-center text-[0.7333rem] font-semibold text-slate-400">
                        <template x-for="(name, index) in ['S','M','T','W','T','F','S']" :key="index"><span x-text="name" class="py-1"></span></template>
                    </div>

                    <div class="grid grid-cols-7 gap-y-0.5" @mouseleave="hover = null">
                        <template x-for="cell in cells" :key="cell.key">
                            <button type="button"
                                    @mouseenter="hover = cell.key"
                                    @focus="hover = cell.key"
                                    @click="go(cell.key)"
                                    :class="[
                                        lit(cell) ? 'bg-sky-100 text-sky-800 dark:bg-sky-400/20 dark:text-sky-200' : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10',
                                        cell.outside ? 'opacity-40' : '',
                                        cell.today ? 'font-bold underline decoration-2 underline-offset-2' : 'font-semibold',
                                    ]"
                                    class="mx-auto h-8 w-8 rounded-full text-xs tabular-nums transition"
                                    x-text="cell.day"></button>
                        </template>
                    </div>

                    <div class="mt-2 flex items-center justify-between border-t border-slate-200/70 pt-2 text-xs dark:border-white/10">
                        <button type="button" @click="go(today)" class="font-semibold text-sky-700 transition hover:underline dark:text-sky-300">Jump to today</button>
                        <span class="font-medium text-slate-500 dark:text-slate-400" x-text="label(hover || current)"></span>
                    </div>
                </div>
            </template>
        </div>
        <a href="{{ $link(['date' => $w['next']]) }}" class="grid h-9 w-9 place-items-center rounded-r-full text-slate-400 transition hover:bg-slate-50 hover:text-slate-900 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Next week">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </a>
    </div>
    @if($w['isThisWeek'])
        <span class="rounded-full bg-sky-100 px-4 py-2 text-xs font-semibold text-sky-700 dark:bg-sky-400/15 dark:text-sky-300" aria-current="date" data-today-chip>Today</span>
    @else
        <a href="{{ $link(['date' => today()->toDateString()]) }}" class="flex items-center gap-1.5 rounded-full bg-sky-100 px-4 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-200 dark:bg-sky-400/15 dark:text-sky-300 dark:hover:bg-sky-400/25" data-back-to-today>
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>
            Today
        </a>
    @endif
</div>

@once
<script>
function weekPicker() { return {
    open: false,
    x: 0,
    y: 0,
    hover: null,
    current: @js($date),
    today: @js(today()->toDateString()),
    weekStart: @js($w['weekStart']),
    weekEnd: @js($weekEnd),
    cursor: @js($date),
    base: @js($link([]) ),

    iso(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    },

    get monthLabel() {
        return new Date(this.cursor + 'T00:00:00').toLocaleDateString(undefined, {month: 'long', year: 'numeric'});
    },

    get cells() {
        const base = new Date(this.cursor + 'T00:00:00');
        const first = new Date(base.getFullYear(), base.getMonth(), 1);
        const start = new Date(base.getFullYear(), base.getMonth(), 1 - first.getDay());
        const out = [];
        for (let i = 0; i < 42; i++) {
            const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            if (i >= 35 && day.getMonth() !== base.getMonth()) { break; }
            const key = this.iso(day);
            out.push({key, day: day.getDate(), outside: day.getMonth() !== base.getMonth(), today: key === this.today});
        }
        return out;
    },

    /** Lit as a band: the week being looked at, or the day being pointed at. */
    lit(cell) {
        if (this.hover) return cell.key === this.hover;
        return cell.key >= this.weekStart && cell.key <= this.weekEnd;
    },

    label(day) {
        return new Date(day + 'T00:00:00').toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'});
    },

    step(months) {
        const base = new Date(this.cursor + 'T00:00:00');
        this.cursor = this.iso(new Date(base.getFullYear(), base.getMonth() + months, 1));
    },

    place() {
        const box = this.$refs.trigger.getBoundingClientRect();
        this.x = Math.max(12, Math.min(box.left + box.width / 2 - 152, window.innerWidth - 316));
        this.y = box.bottom + 8;
    },

    toggle() {
        if (this.open) { this.open = false; return; }
        this.cursor = this.current;
        this.hover = null;
        this.place();
        this.open = true;
    },

    /** Any day opens its week; the room and search stay in the address. */
    go(day) {
        if (! day) return;
        const url = new URL(this.base, window.location.origin);
        url.searchParams.set('date', day);
        window.location.assign(url.toString());
    },
}; }
</script>
@endonce
