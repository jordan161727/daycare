{{-- Schedule setup: plain tick boxes, drag to fill a run, quick-set per child. --}}
<div class="glass-card overflow-hidden rounded-2xl">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-slate-200/70 bg-slate-50/70 px-4 py-2 text-xs text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
        <span>Tick the days each child is expected — <b>every child, every room</b>.</span>
        <span><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold dark:border-white/10 dark:bg-slate-800">Drag</kbd> to fill several at once</span>
        <span><kbd class="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold dark:border-white/10 dark:bg-slate-800">Space</kbd> toggles the focused day</span>
        <span>Tap a day name for the whole column.</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded ring-1 ring-sky-400"></span> ringed — the projection disagrees with the tick</span>
    </div>

    <div class="overflow-x-auto" style="touch-action: none;">
        <table class="w-full min-w-[880px] border-collapse text-left">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                    <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Student</th>
                    @foreach($weekDates as $date)
                        @php($iso = $date->toDateString())
                        <th scope="col" class="px-2 py-1.5 text-center align-top">
                            <button type="button" @click="toggleColumn('{{ $iso }}')" :disabled="isClosed('{{ $iso }}')" class="rounded-lg px-2.5 py-1 transition enabled:hover:bg-indigo-50 disabled:opacity-50 dark:enabled:hover:bg-indigo-500/10" title="Tick or clear {{ $date->format('l') }} for everyone">
                                <span class="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $date->format('D') }}</span>
                                <span class="block text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $date->format('M d') }}</span>
                            </button>
                            {{-- Closing is a whole-centre fact, so it lives on the
                                 column rather than in every child's row. --}}
                            <button
                                type="button"
                                @click="toggleClosure('{{ $iso }}')"
                                x-text="isClosed('{{ $iso }}') ? 'Closed' : 'Close day'"
                                :class="isClosed('{{ $iso }}')
                                    ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-300'
                                    : 'border-slate-200 text-slate-400 hover:border-rose-300 hover:text-rose-600 dark:border-white/10'"
                                class="mx-auto mt-1 block rounded-md border px-1.5 py-0.5 text-[10px] font-semibold transition"
                            ></button>
                        </th>
                    @endforeach
                    <th scope="col" class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-400">Days</th>
                    {{-- What the week is forecast to be, beside what has been
                         ticked for it, so the two can be read against each other. --}}
                    <th scope="col" class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-sky-500" title="Projected from last week's attendance, the enrolment dates and the expected hours">Projected</th>
                    <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-400">Quick set</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                <template x-for="child in scheduleChildren" :key="'sched-' + child.id">
                    <tr>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-3">
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 text-[11px] font-bold text-indigo-700" x-text="(child.first_name.charAt(0) + child.last_name.charAt(0)).toUpperCase()"></span>
                                <div class="min-w-0">
                                    {{-- Straight to the enrolment dates, which are the reason a
                                         row has fewer boxes than the rest. --}}
                                    <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="block truncate text-sm font-semibold underline-offset-2 hover:text-indigo-600 hover:underline" x-text="child.first_name + ' ' + child.last_name"></a>
                                    <p x-show="! canOpenProfile" class="truncate text-sm font-semibold" x-text="child.first_name + ' ' + child.last_name"></p>
                                    {{-- The room is where a ratio comes from, so it is set
                                         here, where the week is being planned. --}}
                                    <button
                                        type="button"
                                        @click="editRoom(child)"
                                        :disabled="! canEditRooms"
                                        :class="roomClass(child)"
                                        :title="roomTitle(child)"
                                        class="flex max-w-full items-center gap-1 truncate text-xs enabled:hover:underline"
                                    >
                                        <span class="truncate" x-text="roomLabel(child)"></span>
                                        <span x-show="child.classroom_override" x-cloak class="shrink-0" x-text="child.override_stale ? '⚠' : '✎'"></span>
                                    </button>
                                </div>
                            </div>

                            <div x-show="roomEditing === child.id" x-cloak class="mt-2 space-y-1.5 rounded-lg border border-violet-200 bg-violet-50/60 p-2 dark:border-violet-500/30 dark:bg-violet-500/10">
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Automatic: <span class="font-semibold" x-text="child.automatic_classroom || 'none'"></span>
                                </p>
                                <select x-model="child.pendingRoom" class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                                    <option value="">Use the automatic room</option>
                                    <template x-for="room in rooms" :key="room">
                                        <option :value="room" x-text="room"></option>
                                    </template>
                                </select>
                                <input type="date" x-model="child.pendingFrom" :placeholder="today" class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                                <p class="text-[10px] text-slate-500 dark:text-slate-400">Starts today unless you date it. Room counts on that day onward follow it.</p>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" @click="saveRoom(child, child.pendingRoom, child.pendingFrom)" class="rounded-md bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-violet-700">Save</button>
                                    <button type="button" x-show="child.classroom_override" @click="saveRoom(child, null, null)" class="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300">Clear override</button>
                                    <button type="button" @click="roomEditing = null" class="rounded-md px-2 py-1 text-[11px] font-semibold text-slate-500 transition hover:text-slate-700">Cancel</button>
                                </div>
                            </div>
                        </td>

                        @foreach($weekDates as $date)
                            @php($iso = $date->toDateString())
                            <td class="px-2 py-1.5">
                                <template x-if="! hasSlot(child.id, '{{ $iso }}')">
                                    <span class="grid min-h-[38px] place-items-center rounded-lg border border-dashed border-slate-200 text-[11px] text-slate-300 dark:border-white/10 dark:text-slate-600" title="Not enrolled on this date">—</span>
                                </template>
                                {{-- Closed: gray for everyone, nothing to tick. --}}
                                <template x-if="hasSlot(child.id, '{{ $iso }}') && isClosed('{{ $iso }}')">
                                    <span class="grid min-h-[38px] place-items-center rounded-lg border border-slate-200 bg-slate-100 text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-800" x-text="closureReason('{{ $iso }}')"></span>
                                </template>
                                <template x-if="hasSlot(child.id, '{{ $iso }}') && ! isClosed('{{ $iso }}')">
                                    <div class="flex justify-center gap-1.5">
                                        <template x-for="session in child.sessions" :key="session">
                                            <span
                                                role="checkbox"
                                                tabindex="0"
                                                :data-slot="child.id + '|{{ $iso }}|' + session"
                                                :aria-checked="isScheduled(child.id, '{{ $iso }}', session)"
                                                :aria-label="child.first_name + ' ' + child.last_name + ', ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                                                @pointerdown.prevent="startPaint(child.id, '{{ $iso }}', session)"
                                                @keydown.space.prevent="toggleOne(child.id, '{{ $iso }}', session)"
                                                @keydown.enter.prevent="toggleOne(child.id, '{{ $iso }}', session)"
                                                :title="projectionNote(child.id, '{{ $iso }}', session)"
                                                :class="[
                                                    isScheduled(child.id, '{{ $iso }}', session)
                                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                        : 'border-slate-200 bg-slate-50 text-slate-400 hover:border-indigo-400 dark:border-white/10 dark:bg-slate-800/60 dark:text-slate-500',
                                                    projectionDiffers(child.id, '{{ $iso }}', session) ? 'ring-1 ring-sky-400 dark:ring-sky-500' : '',
                                                ]"
                                                class="flex min-h-[38px] flex-1 cursor-pointer select-none items-center justify-center gap-1.5 rounded-lg border-[1.5px] px-2 text-xs font-semibold transition"
                                            >
                                                <span
                                                    :class="isScheduled(child.id, '{{ $iso }}', session)
                                                        ? 'border-indigo-500 bg-indigo-600 text-white'
                                                        : 'border-slate-300 bg-white text-transparent dark:border-white/20 dark:bg-slate-900'"
                                                    class="grid h-4 w-4 shrink-0 place-items-center rounded border-[1.5px] text-[10px] leading-none"
                                                >✓</span>
                                                <span x-show="session !== 'FULL'" x-text="session"></span>
                                            </span>
                                        </template>
                                    </div>
                                </template>
                            </td>
                        @endforeach

                        <td class="whitespace-nowrap px-2 py-2 text-center text-xs tabular-nums text-slate-500 dark:text-slate-400" x-text="rowSummary(child)"></td>
                        <td class="whitespace-nowrap px-2 py-2 text-center text-xs tabular-nums" :class="projectionSummaryClass(child.id)" :title="projectionSummaryTitle(child.id)" x-text="projectionSummary(child.id)"></td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-1">
                                <button type="button" @click="applyPreset(child, 'all')" class="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800">Full week</button>
                                <button type="button" @click="applyPreset(child, 'mwf')" class="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800">M W F</button>
                                <button type="button" @click="applyPreset(child, 'tth')" class="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800">T Th</button>
                                <button type="button" @click="applyPreset(child, 'none')" class="rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800">Clear</button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-4 py-2.5 text-sm text-slate-500 dark:border-white/10">
        <span><span class="font-semibold text-slate-700 dark:text-slate-200" x-text="scheduledCount"></span> of <span x-text="slotCount"></span> possible days ticked this week.</span>
        <span x-show="saving" x-cloak class="text-xs text-indigo-600 dark:text-indigo-300">Saving…</span>
        <span x-show="saveError" x-cloak class="text-xs font-semibold text-rose-600" x-text="saveError"></span>
        <button type="button" @click="view = 'signin'" class="ml-auto rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">Done — back to sign-in</button>
    </div>
</div>
