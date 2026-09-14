{{--
    One child, one day. Shared by the desktop grid and the small-screen card
    list so the two can never drift apart.

    On the live sheet every state is one tap: a cell waiting is an empty dashed
    box and becomes the arrival time; a day nobody booked is a dot and takes a
    sign-in anyway. The fills are defined once in attendance/index.blade.php
    and painted from there, so the key above the sheet and the sheet itself
    cannot disagree.

    In Edit the cell is what it already says it is, made editable:

      an arrival    its time as a field — retype it — with ✓ beside it to take
                    the arrival off
      a box / dot   a tap flips it: expected ↔ not expected
      a day gone    ＋ beside the box or dot puts an arrival on it, at a typed
                    time

    $date    Carbon  the day these cells stamp
    $variant string  'table' (grid cell) or 'card' (stacked list row)
--}}
@php($iso = $date->toDateString())
{{-- Tighter in the grid than in the card list: the grid is read a screenful at
     a time and every pixel of row height is a child you cannot see, while the
     cards are tapped on a phone and need the target. --}}
@php($size = $variant === 'table' ? 'h-[26px] text-[11px]' : 'h-8 text-[11px]')
@php($iconSize = $variant === 'table' ? 'h-[26px] w-[22px] text-[12px]' : 'h-8 w-7 text-[13px]')

{{-- Not enrolled: no slot exists for this day, so there is nothing to tap. --}}
<template x-if="! hasSlot(child.id, '{{ $iso }}')">
    <span class="grid min-h-[26px] w-full place-items-center text-[11px] text-slate-300 dark:text-slate-600" title="Not enrolled on this date">—</span>
</template>

<template x-if="hasSlot(child.id, '{{ $iso }}')">
    <div class="flex items-center justify-center gap-1.5">
        <template x-for="session in child.sessions" :key="session">
            <span class="inline-flex items-center gap-0.5">

                {{-- An arrival, in Edit: the same pill the live sheet draws,
                     with the ✓ inside it on the left and the time as a field.
                     The field saves on change, because the typed value is the
                     decision. Pressing the ✓ asks before the arrival goes. --}}
                <template x-if="canRetime(child.id, '{{ $iso }}', session)">
                    <span
                        :class="boxClass(child.id, '{{ $iso }}', session)"
                        class="inline-flex items-center gap-1 rounded-lg border pl-1 pr-1.5 font-mono font-medium leading-none tabular-nums transition {{ $size }}"
                    >
                        <button
                            type="button"
                            @click="askBeforeTapping(child.id, '{{ $iso }}', session)"
                            class="grid h-4 w-4 shrink-0 place-items-center rounded text-[11px] leading-none transition hover:bg-rose-100 hover:text-rose-600 dark:hover:bg-rose-500/20 dark:hover:text-rose-200"
                            title="Take this arrival off"
                            :aria-label="'Take ' + child.name + ' off ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                        >✓</button>
                        {{-- The time as text — the very string the live pill
                             shows — until it is pressed, when it becomes the
                             field. Enter or leaving the field saves; Escape
                             puts the text back. --}}
                        <template x-if="! isRetiming(child.id, '{{ $iso }}', session)">
                            <button
                                type="button"
                                @click="beginRetime(child.id, '{{ $iso }}', session)"
                                class="rounded px-0.5 leading-none transition hover:bg-white/60 dark:hover:bg-white/10"
                                title="Press to retype the time they arrived"
                                :aria-label="child.name + ', arrived ' + sessionTime(child.id, '{{ $iso }}', session) + ', ' + sessionLabel(session) + ' {{ $date->format('M j') }} — press to change'"
                                x-text="sessionTime(child.id, '{{ $iso }}', session)"
                            ></button>
                        </template>
                        <template x-if="isRetiming(child.id, '{{ $iso }}', session)">
                            <input
                                type="time"
                                :value="timeValue(sessionTime(child.id, '{{ $iso }}', session))"
                                x-init="$nextTick(() => $el.focus())"
                                @change="retime(child.id, '{{ $iso }}', session, $event.target.value)"
                                @blur="endRetime()"
                                @keydown.enter.prevent="$event.target.blur()"
                                @keydown.escape.prevent="endRetime()"
                                :aria-label="child.name + ', arrival time ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                                class="w-[86px] border-0 bg-transparent p-0 font-mono text-inherit leading-none tabular-nums focus:outline-none focus:ring-0"
                            >
                        </template>
                    </span>
                </template>
                {{-- An arrival being typed onto a day gone by. Enter or ✓
                     asks; Escape or × puts the cell back as it was. --}}
                <template x-if="isDrafting(child.id, '{{ $iso }}', session)">
                    <span class="inline-flex items-center gap-0.5">
                        <input
                            type="time"
                            x-model="draft"
                            x-init="$nextTick(() => $el.focus())"
                            @keydown.enter.prevent="commitArrival(child.id, '{{ $iso }}', session)"
                            @keydown.escape.prevent="cancelArrival()"
                            :aria-label="child.name + ', arrival time to record for ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                            class="inline-flex items-center justify-center rounded-lg border border-amber-400 bg-amber-50 px-1 font-mono font-medium leading-none tabular-nums text-amber-800 transition dark:border-amber-400/50 dark:bg-amber-500/15 dark:text-amber-100 {{ $size }}"
                        >
                        <button type="button" @click="commitArrival(child.id, '{{ $iso }}', session)" class="inline-flex items-center justify-center rounded-lg border border-amber-400 bg-amber-50 font-semibold leading-none text-amber-800 transition hover:bg-amber-100 dark:border-amber-400/50 dark:bg-amber-500/15 dark:text-amber-100 {{ $iconSize }}" title="Record this arrival">✓</button>
                        <button type="button" @click="cancelArrival()" class="inline-flex items-center justify-center rounded-lg border border-transparent leading-none text-slate-400 transition hover:text-slate-600 dark:hover:text-slate-200 {{ $iconSize }}" title="Never mind" aria-label="Cancel">×</button>
                    </span>
                </template>

                {{-- Everything else: the box, the dot, the closed rule, and on
                     the live sheet the arrival itself. --}}
                <template x-if="! canRetime(child.id, '{{ $iso }}', session) && ! isDrafting(child.id, '{{ $iso }}', session)">
                    <span class="inline-flex items-center gap-0.5">
                        <button
                            @click="tapCell(child.id, '{{ $iso }}', session)"
                            {{-- Dead on a day this reader cannot write to, so a
                                 tap never has to be answered with a dialog
                                 explaining why it was refused — and dead on an
                                 arrival already recorded, outside Edit. The
                                 rules are enforced on the server either way;
                                 this is only the sheet declining the tap. --}}
                            :disabled="! canTap('{{ $iso }}') || isPresent(child.id, '{{ $iso }}', session)"
                            :class="boxClass(child.id, '{{ $iso }}', session)"
                            :title="boxTitle(child.id, '{{ $iso }}', session)"
                            class="inline-flex items-center justify-center rounded-lg border px-2 font-mono font-medium leading-none tabular-nums transition {{ $size }}"
                            x-text="boxLabel(child.id, '{{ $iso }}', session)"
                        ></button>

                        {{-- A day gone by, in Edit, with no arrival on it: the
                             tap above sets the plan, so putting an arrival on
                             gets a mark of its own. --}}
                        <template x-if="canAddArrival('{{ $iso }}') && ! isPresent(child.id, '{{ $iso }}', session)">
                            <button
                                type="button"
                                @click="beginArrival(child.id, '{{ $iso }}', session)"
                                class="inline-flex items-center justify-center rounded-lg border border-dashed border-slate-300 leading-none text-slate-400 transition hover:border-emerald-400 hover:text-emerald-600 dark:border-white/20 dark:text-slate-500 dark:hover:border-emerald-400 dark:hover:text-emerald-300 {{ $iconSize }}"
                                title="Put an arrival on this day"
                                :aria-label="'Record an arrival for ' + child.name + ', ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                            >＋</button>
                        </template>
                    </span>
                </template>

            </span>
        </template>
    </div>
</template>
