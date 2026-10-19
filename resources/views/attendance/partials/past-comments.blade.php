{{-- A child's earlier comments, by day — the dialog's Past comments tab.

     Expects the enclosing Alpine component to carry `child` and the state
     pastCommentsMixin() gives it (`past`, loadPast, loadOlder, …). A white
     card a day that has a comment, newest first; days without one are not
     drawn. Each card: the day in bold, the door's clock on it in muted
     text, and under a hairline the words with who wrote them and when. --}}
<div class="mt-4 text-left" data-past-comments>
    <div class="flex items-center justify-between gap-3">
        <label :for="'past-range-' + child.id" class="text-xs font-semibold uppercase tracking-[0.06em] text-[#5F6676] dark:text-slate-400">Show</label>
        <select :id="'past-range-' + child.id" x-model="past.range" @change="changePastRange()" data-past-range
                class="min-h-11 rounded-lg border border-[#CFD3DE] bg-white px-3 text-[15px] leading-[22px] text-[#1C2130] focus:border-[#1B5E91] focus:ring-2 focus:ring-[#DCEBFA] dark:border-white/10 dark:bg-night-800 dark:text-slate-100">
            <template x-for="option in pastRanges" :key="option.value">
                <option :value="option.value" x-text="option.label"></option>
            </template>
        </select>
    </div>

    {{-- One day, chosen. The app's mm/dd/yyyy picker dresses this field. --}}
    <div x-show="past.range === 'date'" x-cloak class="mt-3">
        <label :for="'past-date-' + child.id" class="sr-only">Date</label>
        <input :id="'past-date-' + child.id" type="date" x-model="past.date" :max="pastToday" @change="changePastRange()" data-past-date
               class="min-h-11 w-full rounded-lg border border-[#CFD3DE] bg-white px-3 text-[15px] text-[#1C2130] dark:border-white/10 dark:bg-night-800 dark:text-slate-100">
    </div>

    <p x-show="past.loading && past.days.length === 0" x-cloak class="mt-4 text-[15px] leading-[22px] text-[#5F6676] dark:text-slate-400" data-past-loading>Loading comments…</p>
    <p x-show="past.error" x-cloak x-text="past.error" class="mt-4 rounded-lg bg-[#FFE4E6] px-3 py-2 text-[13px] leading-[18px] text-[#BE123C] dark:bg-rose-500/10 dark:text-rose-300" role="alert"></p>
    <p x-show="! past.loading && ! past.error && past.days.length === 0" x-cloak class="mt-4 rounded-xl bg-[#F2F4F9] px-4 py-5 text-center text-[15px] leading-[22px] text-[#5F6676] dark:bg-white/5 dark:text-slate-400" data-past-empty
       x-text="past.range === 'date' && ! past.date ? 'Pick a date to read its comment.' : (past.range === 'all' ? 'No comments have been written for ' + child.first_name + ' yet.' : 'No comments in this range.')"></p>

    <div class="mt-3 space-y-3">
        <template x-for="dayCard in past.days" :key="'past-day-' + dayCard.date">
            <article :data-past-day="dayCard.date"
                     :class="past.highlightDate === dayCard.date && ! past.highlightId ? 'ring-2 ring-[#1B5E91]' : ''"
                     class="rounded-[20px] border border-[#E7E9F0] bg-white text-left shadow-[0_1px_2px_rgba(20,24,40,.04),0_6px_20px_rgba(20,24,40,.04)] transition dark:border-white/10 dark:bg-night-800">
                <header class="px-4 pt-3.5 pb-3">
                    <p class="text-[15px] font-bold leading-[22px] text-[#1C2130] dark:text-white" x-text="dayCard.label"></p>
                    <p class="mt-0.5 text-[13px] leading-[18px] text-[#5F6676] dark:text-slate-400" x-text="dayCard.summary"></p>
                </header>
                <div class="divide-y divide-[#E7E9F0] border-t border-[#E7E9F0] dark:divide-white/10 dark:border-white/10">
                    <template x-for="c in dayCard.comments" :key="'past-comment-' + c.id">
                        <div :data-past-comment="c.id"
                             :class="past.highlightId === c.id ? 'bg-[#DCEBFA] dark:bg-sky-500/20' : ''"
                             class="px-4 py-3 transition-colors duration-700">
                            <p class="whitespace-pre-line text-[15px] leading-[22px] text-[#1C2130] dark:text-slate-100" x-text="c.body"></p>
                            <p class="mt-1 text-[13px] leading-[18px] text-[#5F6676] dark:text-slate-400"
                               x-text="c.author + ' · ' + c.author_role + ' · ' + c.time + (c.edited ? ' · edited' : '')"></p>
                        </div>
                    </template>
                </div>
            </article>
        </template>
    </div>

    <button type="button" x-show="past.hasMore" x-cloak @click="loadOlder()" :disabled="past.loading" data-load-older
            class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-[#CFD3DE] bg-white px-5 text-[15px] font-semibold text-[#1C2130] transition hover:bg-[#F2F4F9] disabled:opacity-50 dark:border-white/10 dark:bg-night-800 dark:text-slate-100 dark:hover:bg-white/10"
            x-text="past.loading ? 'Loading…' : 'Load older'"></button>
</div>
