{{--
    Asking before something is taken away.

    This was window.confirm, which puts the browser's own grey box at the top
    of the screen with "127.0.0.1:8000 says" above the question — an address
    bar's voice asking about somebody's grandmother. It also cannot say what is
    about to be lost, which is the part that matters here: unlinking and
    deleting look identical in a one-line prompt and are not remotely the same
    act.

    So the dialog carries what the plain sentence could not: which person, what
    they are for this child, and — in as many words — whether the record
    survives. The two questions are told apart by colour as well as by wording,
    because the destructive one is the one somebody presses by habit.
--}}
<div x-show="confirming.open" x-cloak x-transition.opacity
     @keydown.escape.window="answer(false)"
     class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/70 p-5 backdrop-blur-sm"
     @click="answer(false)">

    <div @click.stop x-show="confirming.open" x-transition.scale.origin.center
         role="alertdialog" aria-modal="true" :aria-label="confirming.title"
         class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900">

        <header class="flex items-start gap-3.5 px-6 pt-6">
            {{-- The person's own initials rather than a warning triangle: it is
                 the clearest way to be sure the right row was pressed. --}}
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full text-sm font-bold"
                  :class="confirming.tone === 'rose'
                      ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-200'
                      : 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200'"
                  x-text="initials(confirming.name)"></span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-bold leading-snug text-slate-900 dark:text-white" x-text="confirming.title"></h2>
                <p class="mt-1 text-[12.5px] leading-relaxed text-slate-500 dark:text-slate-400" x-text="confirming.body"></p>
            </div>
        </header>

        {{-- What this person is for this child. Four ticks are easy to forget
             having set, and "Emergency #1" is worth seeing before it goes. --}}
        <div x-show="confirming.marks.length" x-cloak class="mx-6 mt-4 rounded-xl bg-slate-50 px-3.5 py-3 dark:bg-white/5">
            <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                What they are for <span x-text="childName"></span>
            </span>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                <template x-for="mark in confirming.marks" :key="mark">
                    <span class="rounded-md bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-600 shadow-sm dark:bg-slate-800 dark:text-slate-300" x-text="mark"></span>
                </template>
            </div>
        </div>

        {{-- The one line that says whether this can be walked back. --}}
        <p x-show="confirming.detail" x-cloak
           class="mx-6 mt-3 rounded-xl px-3.5 py-2.5 text-[12px] leading-relaxed"
           :class="confirming.tone === 'rose'
               ? 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-200'
               : 'bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-slate-300'"
           x-text="confirming.detail"></p>

        <footer class="mt-5 flex items-center justify-end gap-2 border-t border-slate-100 px-6 py-4 dark:border-white/10">
            <button type="button" @click="answer(false)"
                    class="rounded-lg px-4 py-2 text-[12.5px] font-semibold text-slate-500 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10">
                Cancel
            </button>
            <button type="button" @click="answer(true)" x-ref="confirmAction"
                    class="rounded-lg px-4 py-2 text-[12.5px] font-semibold text-white shadow-sm transition"
                    :class="confirming.tone === 'rose'
                        ? 'bg-rose-600 hover:bg-rose-700'
                        : 'bg-indigo-600 hover:bg-indigo-700'"
                    x-text="confirming.action"></button>
        </footer>
    </div>
</div>
