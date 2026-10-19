{{-- The note on a child's day — the back of the paper sign-in sheet.

     Lives inside the door screen's dialog, where `slot` is the attendance row
     on screen (one child, today, one session) and `child` the child; both come
     from the dialog's own scope. It opens on whatever the server sent so the
     note is there without a second request, and writes with fetch.

     One note a day (17 Oct 2026), not sorted into kinds. A small COMMENT
     label, then a soft box: "+ Add a comment" while there is none, the words
     with who wrote them and when once there is. The box is pressed to write
     or to change — by the author on the day, or the director any time — and
     says nothing about that itself: no pencil, no "edit", the same quiet box
     either way. The composer stays available all day, clocked out or not: a
     note at six about the way home is still a note on today. The Alpine
     component is resources/js/attendance-comments.js. --}}
<section
    x-data="attendanceComments({
        url: '/attendances/' + slot.id + '/comments',
        childId: child.id,
        initial: slot.comments ?? [],
        onAdded: (comment) => { slot.comments = [comment, ...(slot.comments ?? [])]; slot.comments_count = slot.comments.length; },
        onChanged: (comment) => { slot.comments = (slot.comments ?? []).map((c) => c.id === comment.id ? comment : c); },
    })"
    class="text-left"
    data-attendance-comments
>
    {{-- The label, and on the right the day of the child's latest earlier
         comment with a way to it: "Last comment: Fri, Oct 16 · View". Read
         from the dialog's `child`, and absent when there is none. --}}
    <div class="mb-2 flex items-baseline justify-between gap-3">
        <p class="text-[0.6667rem] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Comment</p>
        <template x-if="child?.last_comment_on">
            <p class="text-[13px] leading-[18px] text-[#5F6676] dark:text-slate-400" data-last-comment>
                Last comment: <span x-text="dayShort(child.last_comment_on)"></span> ·
                <button type="button" @click="showPast(child.last_comment_on)" class="font-semibold text-[#4C5A9E] underline-offset-2 hover:underline dark:text-indigo-300" data-view-last-comment>View</button>
            </p>
        </template>
    </div>

    {{-- No note yet: the box invites one. --}}
    <button type="button" x-show="canAdd && !open && comments.length === 0" @click="openComposer()" data-add-comment data-comments-empty
            class="flex min-h-14 w-full items-center gap-3 rounded-2xl bg-slate-50 px-5 text-left text-base text-slate-500 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-white/5 dark:text-slate-400 dark:hover:bg-white/10">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add a comment
    </button>

    {{-- Writing, or changing: the same box, open. --}}
    <div x-show="open" x-cloak class="flex flex-col gap-3 rounded-2xl border border-indigo-300 bg-white p-4 dark:border-indigo-400/60 dark:bg-night-800" data-comment-composer>
        <label :for="'comment-body-' + slot.id" class="sr-only">Comment</label>
        <textarea
            :id="'comment-body-' + slot.id"
            x-ref="body"
            x-model="body"
            rows="2"
            maxlength="1000"
            placeholder="Write a comment…"
            @keydown.meta.enter.prevent="save()" @keydown.ctrl.enter.prevent="save()" @keydown.escape.prevent="cancel()"
            class="w-full resize-none rounded-lg border-0 bg-transparent p-0 text-lg text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0 dark:text-slate-100"
        ></textarea>
        <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert"></p>
        <div class="flex items-center justify-between gap-3">
            <span class="text-sm text-slate-400 dark:text-slate-500">Ctrl+Enter to save · Esc to cancel</span>
            <span class="flex gap-2">
                <button type="button" @click="cancel()"
                    class="min-h-10 rounded-full bg-slate-100 px-5 text-base font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-white/10 dark:text-slate-200 dark:hover:bg-white/20">
                    Cancel
                </button>
                <button type="button" @click="save()" :disabled="!canSave" data-save-comment
                    :class="canSave ? 'bg-slate-800 text-white hover:bg-slate-900 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white' : 'cursor-not-allowed bg-slate-200 text-slate-500 dark:bg-white/10 dark:text-slate-400'"
                    class="min-h-10 rounded-full px-6 text-base font-semibold transition">
                    <span x-text="saving ? 'Saving…' : 'Save'"></span>
                </button>
            </span>
        </div>
    </div>

    {{-- The note — x-text, never x-html: these are typed words. A list
         underneath, for the odd row given more than one before there was
         only one; each is its own box. --}}
    <ul x-show="!open" class="flex flex-col gap-2" data-comment-list>
        <template x-for="c in comments" :key="c.id">
            <li>
                <div :role="c.can_edit ? 'button' : null"
                     :tabindex="c.can_edit ? 0 : null"
                     :data-edit-comment="c.can_edit ? c.id : null"
                     @click="edit(c)" @keydown.enter.prevent="edit(c)" @keydown.space.prevent="edit(c)"
                     :class="c.can_edit ? 'cursor-pointer transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-white/10' : ''"
                     class="flex min-h-14 w-full flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-2xl bg-slate-50 px-5 py-3 text-left dark:bg-white/5">
                    <p class="min-w-0 flex-1 whitespace-pre-line text-base text-slate-800 dark:text-slate-100" x-text="c.body"></p>
                    <span class="shrink-0 text-sm text-slate-500 dark:text-slate-400" x-text="`${c.author} · ${c.time}`"></span>
                </div>
            </li>
        </template>
    </ul>
</section>
