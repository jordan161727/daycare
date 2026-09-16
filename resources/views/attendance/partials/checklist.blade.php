{{-- Schedule setup: plain tick boxes, drag to fill a run, quick-set per child.
     Nothing above the grid but the key: the tips it used to carry were read
     once, and the week's provenance now lives on the copy dialog's button. --}}
{{-- All and Clear only. MWF and TTh were two American patterns hard-coded into
     a centre whose children come on whatever days their parents contracted for
     — four days, Tuesday to Friday, mornings only — so the two buttons were
     nearly always the wrong answer, and a mis-click on the wrong row rewrote a
     week. What is left is the pair that means something for every child. --}}

<div class="glass-card overflow-hidden rounded-2xl">
    {{-- What every mark on the checklist means, in the checklist's own marks.

         Its own strip rather than the sign-in sheet's: this view has ticks
         where that one has arrival times, and a key naming states the grid
         below it cannot draw would be worse than none. --}}
    <div x-show="showKey" x-cloak class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-slate-200/70 bg-white/40 px-4 py-2 text-[0.7333rem] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border-[1.5px] border-indigo-500 bg-indigo-200 px-1.5 py-0.5 dark:bg-indigo-500/25">
                <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-indigo-500 bg-indigo-600 text-[0.5333rem] leading-none text-white">✓</span>
            </span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Ticked</b> — expected that day
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border-[1.5px] border-slate-300 bg-slate-50 px-1.5 py-0.5 dark:border-white/10 dark:bg-night-800/60">
                <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-slate-300 bg-white text-[0.5333rem] leading-none text-transparent dark:border-white/20 dark:bg-slate-900">✓</span>
            </span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Empty</b> — not expected
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border border-dashed border-slate-200 bg-slate-100/70 px-1.5 py-0.5 dark:border-white/10 dark:bg-white/5">
                <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-slate-200 bg-white text-[0.5333rem] leading-none text-transparent dark:border-white/10 dark:bg-slate-900">✓</span>
            </span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Shaded</b> — not one of their days, tick it for a one-off
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-800">Closed</span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Centre closed</b> — nothing can be ticked
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border border-dashed border-slate-300 px-2 py-0.5 text-[0.6667rem] text-slate-400 dark:border-white/20 dark:text-slate-500">—</span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Not enrolled</b> — before they start or after they leave
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border border-slate-200 px-2 py-0.5 text-[0.6667rem] text-transparent ring-1 ring-sky-400 dark:border-white/10 dark:ring-sky-500">—</span>
            <b class="font-semibold text-slate-600 dark:text-slate-300">Sky ring</b> — this week departs from the days on their record
        </span>
    </div>

    <div class="overflow-x-auto" style="touch-action: none;">
        <table class="sheet-grid w-full min-w-[58.6667rem] border-collapse text-left">
            <thead>
                <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                    <th scope="col" class="sticky left-0 z-20 border-r border-slate-200 bg-slate-50 px-2 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-white/10 dark:bg-slate-900 dark:text-slate-400">Student</th>
                    {{-- Their own columns, the same as the sign-in sheet, so the
                         two views of the same week read the same way. --}}
                    <th scope="col" class="w-px px-1.5 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="Date of birth, year/month/day">DOB</th>
                    <th scope="col" class="w-px px-1.5 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Age</th>
                    <th scope="col" class="w-px px-1.5 py-2.5 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400" title="The hours agreed on the child's record">Hours</th>
                    @foreach($weekDates as $date)
                        @php($iso = $date->toDateString())
                        <th scope="col" class="px-1 py-1.5 text-center align-top">
                            <button type="button" @click="toggleColumn('{{ $iso }}')" :disabled="isClosed('{{ $iso }}')" class="whitespace-nowrap rounded-lg px-1.5 py-1 transition enabled:hover:bg-indigo-50 disabled:opacity-50 dark:enabled:hover:bg-indigo-500/10" title="Tick or clear {{ $date->format('l') }} for everyone">
                                <span class="block text-[0.7333rem] font-semibold uppercase tracking-wide text-slate-400">{{ $date->format('D') }}</span>
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
                                class="mx-auto mt-1 block whitespace-nowrap rounded-md border px-1 py-0.5 text-[0.6667rem] font-semibold transition"
                            ></button>
                        </th>
                    @endforeach
                    <th scope="col" class="px-1 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-400">Days</th>
                    {{-- What the week is forecast to be, beside what has been
                         ticked for it, so the two can be read against each other. --}}
                    <th scope="col" class="px-1 py-2 text-center text-xs font-semibold uppercase tracking-wide text-sky-500" title="Projected from last week's attendance, the enrolment dates and the expected hours">Projected</th>
                    {{-- Pinned to the right edge. This column ends the row, so it
                         is the first thing to fall off a sheet with eleven of
                         them, and a control nobody can see is a control nobody
                         uses. Pinned, the days scroll under it and it is always
                         there — whatever the screen is. --}}
                    <th scope="col" class="sticky right-0 z-10 border-l border-slate-200 bg-slate-50 px-1.5 py-2 text-right text-xs font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-900">Quick set</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                <template x-for="child in scheduleChildren" :key="'sched-' + child.id">
                    <tr>
                        <td class="sticky left-0 z-10 border-r border-slate-200 bg-white px-2 py-1.5 dark:border-white/10 dark:bg-slate-900">
                            <div class="flex items-center gap-2.5">
                                <span class="h-8 w-8 shrink-0 overflow-hidden rounded-full" x-html="child.avatar"></span>
                                <div class="min-w-0">
                                    {{-- Straight to the enrolment dates, which are the reason a
                                         row has fewer boxes than the rest. --}}
                                    <a x-show="canOpenProfile" :href="profileUrl(child.id)" class="block truncate text-sm font-semibold underline-offset-2 hover:text-indigo-600 hover:underline" x-text="child.name"></a>
                                    <p x-show="! canOpenProfile" class="truncate text-sm font-semibold" x-text="child.name"></p>
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
                                <p class="text-[0.7333rem] text-slate-500 dark:text-slate-400">
                                    Automatic: <span class="font-semibold" x-text="child.automatic_classroom || 'none'"></span>
                                </p>
                                <select x-model="child.pendingRoom" class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                                    <option value="">Use the automatic room</option>
                                    <template x-for="room in rooms" :key="room">
                                        <option :value="room" x-text="room"></option>
                                    </template>
                                </select>
                                <input type="date" x-model="child.pendingFrom" :placeholder="today" class="w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                                <p class="text-[0.6667rem] text-slate-500 dark:text-slate-400">Starts today unless you date it. Room counts on that day onward follow it.</p>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" @click="saveRoom(child, child.pendingRoom, child.pendingFrom)" class="rounded-md bg-violet-600 px-2 py-1 text-[0.7333rem] font-semibold text-white transition hover:bg-violet-700">Save</button>
                                    <button type="button" x-show="child.classroom_override" @click="saveRoom(child, null, null)" class="rounded-md border border-slate-200 px-2 py-1 text-[0.7333rem] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300">Clear override</button>
                                    <button type="button" @click="roomEditing = null" class="rounded-md px-2 py-1 text-[0.7333rem] font-semibold text-slate-500 transition hover:text-slate-700">Cancel</button>
                                </div>
                            </div>
                        </td>
                        <td class="w-px whitespace-nowrap px-1.5 py-1.5 align-top text-center text-sm tabular-nums text-slate-500 dark:text-slate-400" :class="blankClass(child.birth_date)" x-text="child.birth_date || '—'"></td>
                        <td class="w-px whitespace-nowrap px-1.5 py-1.5 align-top text-center text-sm text-slate-500 dark:text-slate-400" :class="blankClass(child.age)" x-text="child.age || '—'"></td>
                        <td class="w-px whitespace-nowrap px-1.5 py-1.5 align-top text-center text-sm tabular-nums text-slate-500 dark:text-slate-400" :class="blankClass(child.schedule_hours)" x-text="child.schedule_hours || '—'"></td>

                        @foreach($weekDates as $date)
                            @php($iso = $date->toDateString())
                            <td class="px-1 py-1">
                                <template x-if="! hasSlot(child.id, '{{ $iso }}')">
                                    <span class="grid min-h-[2rem] place-items-center rounded-lg border border-dashed border-slate-200 text-[0.7333rem] text-slate-300 dark:border-white/10 dark:text-slate-600" title="Not enrolled on this date">—</span>
                                </template>
                                {{-- Closed: gray for everyone, nothing to tick. --}}
                                <template x-if="hasSlot(child.id, '{{ $iso }}') && isClosed('{{ $iso }}')">
                                    <span class="grid min-h-[2rem] place-items-center rounded-lg border border-slate-200 bg-slate-100 text-[0.6667rem] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-800" x-text="closureReason('{{ $iso }}')"></span>
                                </template>
                                <template x-if="hasSlot(child.id, '{{ $iso }}') && ! isClosed('{{ $iso }}')">
                                    <div class="flex justify-center gap-1.5">
                                        <template x-for="session in child.sessions" :key="session">
                                            <span
                                                role="checkbox"
                                                tabindex="0"
                                                :data-slot="child.id + '|{{ $iso }}|' + session"
                                                :aria-checked="isScheduled(child.id, '{{ $iso }}', session)"
                                                :aria-label="child.name + ', ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                                                @pointerdown.prevent="startPaint(child.id, '{{ $iso }}', session)"
                                                @keydown.space.prevent="toggleOne(child.id, '{{ $iso }}', session)"
                                                @keydown.enter.prevent="toggleOne(child.id, '{{ $iso }}', session)"
                                                :title="offPattern(child, '{{ $iso }}') && ! isScheduled(child.id, '{{ $iso }}', session) ? 'Not one of their days (' + (child.schedule_days_label || '') + '). Tick it anyway for a one-off.' : projectionNote(child.id, '{{ $iso }}', session)"
                                                :class="[
                                                    {{-- Three fills now: ticked, a day they are down
                                                         for and have not been ticked, and a day their
                                                         record does not cover at all. The third is the
                                                         printed sheet's shading, and like the paper it
                                                         can still be filled — a ticked box looks the
                                                         same whichever day it falls on, because an
                                                         exception that hides itself is worse than the
                                                         pattern it breaks. --}}
                                                    isScheduled(child.id, '{{ $iso }}', session)
                                                        ? 'border-indigo-500 bg-indigo-200 text-indigo-800 dark:bg-indigo-500/25 dark:text-indigo-100'
                                                        : (offPattern(child, '{{ $iso }}')
                                                            ? 'border-dashed border-slate-200 bg-slate-100/70 text-slate-300 hover:border-indigo-300 dark:border-white/10 dark:bg-white/5 dark:text-slate-600'
                                                            : 'border-slate-300 bg-slate-50 text-slate-400 hover:border-indigo-400 dark:border-white/10 dark:bg-night-800/60 dark:text-slate-500'),
                                                    projectionDiffers(child.id, '{{ $iso }}', session) ? 'ring-1 ring-sky-400 dark:ring-sky-500' : '',
                                                ]"
                                                class="flex min-h-[2rem] flex-1 cursor-pointer select-none items-center justify-center gap-1.5 rounded-lg border-[1.5px] px-2 text-xs font-semibold transition"
                                            >
                                                {{-- White interior even on a shaded day, the same as
                                                     the printed sheet: it is the thing an unplanned
                                                     arrival gets marked in. --}}
                                                <span
                                                    :class="isScheduled(child.id, '{{ $iso }}', session)
                                                        ? 'border-indigo-500 bg-indigo-600 text-white'
                                                        : (offPattern(child, '{{ $iso }}')
                                                            ? 'border-slate-200 bg-white text-transparent dark:border-white/10 dark:bg-slate-900'
                                                            : 'border-slate-300 bg-white text-transparent dark:border-white/20 dark:bg-slate-900')"
                                                    class="grid h-4 w-4 shrink-0 place-items-center rounded border-[1.5px] text-[0.6667rem] leading-none"
                                                >✓</span>
                                                <span x-show="session !== 'FULL'" x-text="session"></span>
                                            </span>
                                        </template>
                                    </div>
                                </template>
                            </td>
                        @endforeach

                        <td class="whitespace-nowrap px-1 py-1.5 text-center text-xs tabular-nums text-slate-500 dark:text-slate-400" x-text="rowSummary(child)"></td>
                        <td class="whitespace-nowrap px-1 py-1.5 text-center text-xs tabular-nums" :class="projectionSummaryClass(child.id)" :title="projectionSummaryTitle(child.id)" x-text="projectionSummary(child.id)"></td>
                        {{-- The presets, labelled as short as they can still be
                             read. Spelled out — "Full week", "M W F" — this one
                             column ran to nearly three hundred pixels and pushed
                             the end of the sheet off the side of the screen,
                             where nobody scrolled to find it. The title says the
                             whole thing on hover. --}}
                        <td class="sticky right-0 z-10 border-l border-slate-200 bg-white px-1.5 py-1.5 dark:border-white/10 dark:bg-slate-900">
                            <div class="flex justify-end gap-0.5">
                                {{-- Their days, named on the button. A child
                                     contracted for three days used to get five
                                     from this button and the two wrong ones had
                                     to be found and cleared by hand — which is
                                     how a Tuesday ends up on a sheet the centre
                                     bills from. --}}
                                <button
                                    type="button"
                                    @click="applyPreset(child, 'days')"
                                    :disabled="presetDisabled(child)"
                                    :title="presetTitle(child)"
                                    x-text="presetLabel(child)"
                                    class="whitespace-nowrap rounded-md border border-slate-200 px-1 py-1 text-[0.7333rem] font-semibold text-slate-600 transition enabled:hover:bg-slate-100 disabled:opacity-40 dark:border-white/10 dark:text-slate-300 dark:enabled:hover:bg-slate-800"
                                ></button>
                                <button type="button" @click="applyPreset(child, 'none')" title="Clear every day" class="rounded-md border border-slate-200 px-1 py-1 text-[0.7333rem] font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-slate-800">Clear</button>
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
