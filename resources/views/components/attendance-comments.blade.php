{{-- The notes on a child's day — the back of the paper sign-in sheet.

     Lives inside the door screen's dialog, where `slot` is the attendance row
     on screen (one child, today, one session) and `child` the child; both come
     from the dialog's own scope. It opens on whatever the server sent so the
     list is there without a second request, and posts new notes with fetch.
     The composer stays open all day, clocked out or not: a note at six about
     the way home is still a note on today. The Alpine component is resources/js/attendance-comments.js.

     Drawn in the app's own palette — indigo for the action, slate for the
     quiet parts — so it sits with the rest of the dialog rather than beside it. --}}
<section
    x-data="attendanceComments({
        url: '/attendances/' + slot.id + '/comments',
        childId: child.id,
        initial: slot.comments ?? [],
        onAdded: (comment) => { slot.comments = [comment, ...(slot.comments ?? [])]; slot.comments_count = slot.comments.length; },
    })"
    class="flex flex-col gap-4 border-t border-slate-200/70 pt-5 text-left dark:border-white/10"
    data-attendance-comments
>
    {{-- Header row --}}
    <div class="flex items-center justify-between">
        <span class="text-[0.6667rem] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            Comments · <span x-text="comments.length"></span>
        </span>
        <button
            type="button"
            x-show="canAdd && !open"
            @click="openComposer()"
            data-add-comment
            class="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-semibold text-indigo-600 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-white/15 dark:bg-night-800 dark:text-indigo-300 dark:hover:bg-white/10"
        >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Add comment
        </button>
    </div>

    {{-- Composer --}}
    <div x-show="open" x-cloak class="flex flex-col gap-3 rounded-xl border-2 border-indigo-500 bg-white p-4 dark:border-indigo-400 dark:bg-night-800" data-comment-composer>
        <label :for="'comment-body-' + slot.id" class="text-sm font-bold">New comment</label>

        <div class="flex flex-wrap gap-2" role="group" aria-label="Comment type">
            <template x-for="t in tags" :key="t.value">
                <button
                    type="button"
                    @click="tag = t.value"
                    :aria-pressed="tag === t.value ? 'true' : 'false'"
                    :data-comment-tag="t.value"
                    :class="tag === t.value
                        ? 'border-indigo-500 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-400 dark:bg-indigo-500/20 dark:text-indigo-200'
                        : 'border-slate-200 bg-white font-medium text-slate-700 hover:bg-slate-50 dark:border-white/15 dark:bg-night-900 dark:text-slate-200 dark:hover:bg-white/10'"
                    class="min-h-9 rounded-full border px-3.5 text-xs transition"
                    x-text="t.label"
                ></button>
            </template>
        </div>

        <textarea
            :id="'comment-body-' + slot.id"
            x-ref="body"
            x-model="body"
            rows="4"
            maxlength="1000"
            placeholder="What should the next teacher or the parent know?"
            @keydown.meta.enter.prevent="save()" @keydown.ctrl.enter.prevent="save()"
            class="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-white/15 dark:bg-night-900 dark:text-slate-100"
        ></textarea>

        <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert"></p>

        <div class="flex justify-end gap-2">
            <button type="button" @click="cancel()"
                class="min-h-11 rounded-full border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/15 dark:bg-night-900 dark:text-slate-200 dark:hover:bg-white/10">
                Cancel
            </button>
            <button type="button" @click="save()" :disabled="!canSave" data-save-comment
                :class="canSave ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'cursor-not-allowed bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-slate-400'"
                class="min-h-11 rounded-full px-5 text-sm font-semibold transition">
                <span x-text="saving ? 'Saving…' : 'Save comment'"></span>
            </button>
        </div>
    </div>

    {{-- Empty state --}}
    <div x-show="comments.length === 0 && !open" data-comments-empty
        class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500 dark:bg-white/5 dark:text-slate-400">
        No comments yet today.
    </div>

    {{-- List — x-text, never x-html: these are typed words. --}}
    <ul class="flex flex-col gap-2" data-comment-list>
        <template x-for="c in comments" :key="c.id">
            <li class="flex flex-col gap-1.5 rounded-xl bg-slate-50 px-4 py-3 dark:bg-white/5">
                <div class="flex items-center justify-between gap-3">
                    <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-[0.6875rem] font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200" x-text="c.tag_label"></span>
                    <span class="text-xs text-slate-500 dark:text-slate-400" x-text="`${c.author} · ${c.time}`"></span>
                </div>
                <p class="whitespace-pre-line text-sm text-slate-800 dark:text-slate-100" x-text="c.body"></p>
            </li>
        </template>
    </ul>
</section>
