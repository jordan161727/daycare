{{--
    One person's own record, opened from any row that names them.

    The ten fields are here and only here. A person linked to three children has
    one address and one mobile, and the whole point of the table behind this is
    that correcting either is done once — so the drawer says so, in as many
    words, above the fields rather than after the save.

    What stays per child is underneath: the ticks. Kaylynn is the mother of two
    children and a legal guardian of one of them, and those are different facts
    that this screen has to be able to show at the same time.
--}}
<div x-show="drawer.open" x-cloak class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Person details">
    {{-- The child behind stays visible and inert: the drawer is about somebody
         on the page you are already on, not a place you have navigated to. --}}
    <div class="absolute inset-0 bg-slate-900/30" @click="closeDrawer()"></div>

    <div class="relative flex h-full w-full max-w-xl flex-col bg-white shadow-2xl dark:bg-slate-900" @keydown.escape.window="closeDrawer()">
        <header class="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-white/10">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200"
                  x-text="initials(drawer.person.name)"></span>
            <div class="min-w-0 flex-1">
                <h2 class="truncate text-lg font-bold" x-text="drawer.person.name || 'Person'"></h2>
                <p class="text-[11px] text-slate-400">
                    <span x-text="'Person #P-' + String(drawer.person.id ?? '').padStart(4, '0')"></span>
                    &middot; one record, linked to <span x-text="drawer.children.length"></span>
                    <span x-text="drawer.children.length === 1 ? 'child' : 'children'"></span>
                </p>
            </div>
            <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-slate-400 hover:bg-slate-100 dark:hover:bg-white/10" @click="closeDrawer()" aria-label="Close">&times;</button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
            <div class="cs-card-head">
                <h3 class="cs-card-title">Details</h3>
                <span class="cs-pill" x-text="filledCount() + '/10'"></span>
            </div>

            <div class="cs-grid">
                <label class="sm:col-span-2">
                    <span class="cs-label">Name <span class="text-rose-500">*</span></span>
                    <input type="text" class="cs-input" x-model="drawer.person.name">
                </label>
                <label class="sm:col-span-2">
                    <span class="cs-label">Address</span>
                    <input type="text" class="cs-input" x-model="drawer.person.address"
                           :placeholder="household ? household + ' (same as child\'s household)' : 'Same as child\'s household'">
                    <span class="cs-help">Left blank, it follows the child&rsquo;s household &mdash; so it is still right after they move.</span>
                </label>
                <label><span class="cs-label">Home phone</span><input type="text" class="cs-input" x-model="drawer.person.home_phone"></label>
                <label><span class="cs-label">Work phone</span><input type="text" class="cs-input" x-model="drawer.person.work_phone"></label>
                <label>
                    <span class="cs-label">Cell <span class="text-rose-500">*</span></span>
                    <input type="text" class="cs-input" x-model="drawer.person.cell">
                </label>
                <label><span class="cs-label">Email</span><input type="email" class="cs-input" x-model="drawer.person.email"></label>
                <label><span class="cs-label">Employer</span><input type="text" class="cs-input" x-model="drawer.person.employer"></label>
                <label><span class="cs-label">Title</span><input type="text" class="cs-input" x-model="drawer.person.title"></label>
                <label><span class="cs-label">Fax</span><input type="text" class="cs-input" x-model="drawer.person.fax"></label>
                <label>
                    <span class="cs-label">SSN</span>
                    {{-- Never handed to the browser in full. The last four is
                         what anybody checking an identity reads off it, and a
                         whole one sitting in a page is a whole one to leak. --}}
                    <input type="text" class="cs-input" x-model="drawer.ssn"
                           :placeholder="drawer.person.ssn_last4 ? '•••••' + drawer.person.ssn_last4 : '000 00 0000'">
                    <span class="cs-help" x-text="drawer.person.ssn_last4 ? 'On file, last four shown. Type a new one to replace it.' : 'Not on file.'"></span>
                </label>
            </div>

            {{-- Said before the save, not after it. --}}
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11.5px] text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                Changing anything here updates <span class="font-semibold" x-text="firstName(drawer.person.name)"></span>
                on <template x-if="drawer.children.length === 1"><span>the one child they are linked to.</span></template>
                <template x-if="drawer.children.length !== 1"><span>every child they are linked to (<span x-text="drawer.children.length"></span>).</span></template>
            </p>

            <div class="cs-card-head mt-5">
                <h3 class="cs-card-title">Linked children</h3>
            </div>

            <div class="space-y-2">
                <template x-for="row in drawer.children" :key="row.id">
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10"
                         :class="row.id === childId ? 'bg-slate-50 dark:bg-white/5' : ''">
                        <div class="flex items-center gap-2">
                            <div class="min-w-0 flex-1">
                                <span class="text-[12.5px] font-semibold" x-text="row.name"></span>
                                <span class="block text-[10.5px] text-slate-400">
                                    <span x-text="'LAN ' + (row.lan ?? '—')"></span> &middot;
                                    <span x-text="row.classroom || 'no room'"></span>
                                    <span x-show="row.id === childId"> &middot; this child</span>
                                </span>
                            </div>
                            <span class="text-[11px] text-slate-500" x-text="row.relationship || '—'"></span>
                        </div>
                        {{-- Read-only here on purpose. The ticks are set on the
                             child's own People step, where the person setting
                             them is looking at that child's whole list. --}}
                        <div class="mt-1.5 flex flex-wrap items-center gap-3 text-[10.5px] text-slate-500">
                            <span :class="row.is_guardian ? 'font-semibold text-indigo-600 dark:text-indigo-300' : 'opacity-40'">Guardian</span>
                            <span :class="row.can_pickup && ! row.restriction ? 'font-semibold text-indigo-600 dark:text-indigo-300' : 'opacity-40'">Can pick up</span>
                            <span :class="row.is_emergency ? 'font-semibold text-indigo-600 dark:text-indigo-300' : 'opacity-40'">
                                Emergency<span x-show="row.priority"> #<span x-text="row.priority"></span></span>
                            </span>
                            <span x-show="row.restriction" x-cloak class="rounded bg-rose-100 px-1.5 py-0.5 font-semibold text-rose-700 dark:bg-rose-500/20 dark:text-rose-200" x-text="row.restriction"></span>
                        </div>
                    </div>
                </template>
            </div>

            <p class="mt-2 text-[10.5px] text-slate-400">
                Ticks are per child, and are set on each child&rsquo;s People step.
            </p>

            <p x-show="drawer.error" x-cloak class="mt-3 text-[11px] font-semibold text-rose-600" x-text="drawer.error"></p>
        </div>

        <footer class="flex items-center gap-2 border-t border-slate-200 px-5 py-3 dark:border-white/10">
            {{-- Rule 6: offered, and refused by the server while anything points
                 at them — the honest answer is "unlink them first", not a
                 button that was never there. --}}
            <button type="button" class="text-[11.5px] font-semibold text-rose-600 hover:underline disabled:opacity-40"
                    :disabled="drawer.children.length > 0"
                    :title="drawer.children.length > 0 ? 'Unlink them from every child first' : 'Delete this person'"
                    @click="deletePerson()">
                Delete person
            </button>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" class="rounded-lg px-3 py-1.5 text-[11.5px] font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10" @click="closeDrawer()">Cancel</button>
                <button type="button" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-[11.5px] font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="drawer.saving || ! drawer.person.name" @click="savePerson()">
                    Save person
                </button>
            </div>
        </footer>
    </div>
</div>
