{{--
    One child, one day. Shared by the desktop grid and the small-screen card
    list so the two can never drift apart.

    One box, three things it can be — a dot (not attending), an empty outlined
    box (expected), a green time (came) — and one tap that moves it along:

      live    today only: expected → time (a sign-in at the door)
      edit    any day gone or today: dot → expected → time → dot
              a day still to come: dot ↔ expected

    In Edit a time carries a pencil down its right edge; press it and the time
    becomes a field to type the exact hour into. Enter or leaving saves,
    Escape puts it back. A half-day room stacks its AM and PM boxes.

    $date    Carbon  the day these cells stamp
    $variant string  'table' (grid cell) or 'card' (stacked list row)
--}}
@php($iso = $date->toDateString())

{{-- Not enrolled: no slot exists for this day, so there is nothing to tap. --}}
<template x-if="! hasSlot(child.id, '{{ $iso }}')">
    <span class="grid min-h-[1.7333rem] w-full place-items-center text-[0.7333rem] text-slate-300 dark:text-slate-600" title="Not enrolled on this date">—</span>
</template>

<template x-if="hasSlot(child.id, '{{ $iso }}')">
    <div :class="child.sessions.length > 1 ? 'att-stack' : ''">
        <template x-for="session in child.sessions" :key="session">
            <div>
                {{-- The hour being typed. --}}
                <template x-if="isRetiming(child.id, '{{ $iso }}', session)">
                    <div class="att-cell att-editing" :class="child.sessions.length > 1 ? 'att-half' : ''">
                        <span x-show="session !== 'FULL'" class="att-tag" x-text="session"></span>
                        <input
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            x-model="draft"
                            x-init="$nextTick(() => { $el.focus(); $el.select(); })"
                            :placeholder="session === 'FULL' ? '8:00 AM' : '8:00a'"
                            @keydown.enter.prevent="$event.target.blur()"
                            @keydown.escape.prevent="cancelRetime()"
                            {{-- The correction being made is usually "a bit
                                 earlier than that" rather than a time somebody
                                 knows, so the arrows walk it: a minute, or an
                                 hour with Shift. --}}
                            @keydown.up.prevent="nudgeDraft($event.shiftKey ? 60 : 1)"
                            @keydown.down.prevent="nudgeDraft($event.shiftKey ? -60 : -1)"
                            @keydown.page-up.prevent="nudgeDraft(60)"
                            @keydown.page-down.prevent="nudgeDraft(-60)"
                            @blur="commitRetime(child.id, '{{ $iso }}', session)"
                            :aria-label="child.name + ', arrival time ' + sessionLabel(session) + ' {{ $date->format('M j') }}'"
                        >
                    </div>
                </template>

                {{-- The box. --}}
                <template x-if="! isRetiming(child.id, '{{ $iso }}', session)">
                    <div
                        :class="cellClass(child.id, '{{ $iso }}', session)"
                        :role="canTap('{{ $iso }}') ? 'button' : null"
                        :tabindex="canTap('{{ $iso }}') ? 0 : null"
                        :aria-label="cellLabel(child, '{{ $iso }}', session)"
                        :title="boxTitle(child.id, '{{ $iso }}', session)"
                        @click="tapCell(child.id, '{{ $iso }}', session)"
                        @keydown.enter.prevent="tapCell(child.id, '{{ $iso }}', session)"
                        @keydown.space.prevent="tapCell(child.id, '{{ $iso }}', session)"
                        @keydown.e.prevent="beginRetime(child.id, '{{ $iso }}', session)"
                    >
                        <span x-show="session !== 'FULL'" class="att-tag" x-text="session"></span>
                        <span class="att-main">
                            <template x-if="isPresent(child.id, '{{ $iso }}', session)">
                                <span x-text="displayTime(child.id, '{{ $iso }}', session)"></span>
                            </template>
                            <template x-if="! isPresent(child.id, '{{ $iso }}', session) && ! isScheduled(child.id, '{{ $iso }}', session) && ! isClosed('{{ $iso }}')">
                                <span class="att-dot" aria-hidden="true"></span>
                            </template>
                            <template x-if="! isPresent(child.id, '{{ $iso }}', session) && isClosed('{{ $iso }}')">
                                <span aria-hidden="true">—</span>
                            </template>
                        </span>
                        {{-- The pencil: the exact hour, typed. Only on a time,
                             only in Edit. Stops the tap so pressing it does not
                             also move the box along. --}}
                        <template x-if="canRetime(child.id, '{{ $iso }}', session)">
                            <button type="button" class="att-pencil" @click.stop="beginRetime(child.id, '{{ $iso }}', session)" :aria-label="'Type an exact time for ' + child.name" x-html="icons.pencil"></button>
                        </template>
                        <template x-if="! canRetime(child.id, '{{ $iso }}', session) && session !== 'FULL'">
                            <span class="att-pad" aria-hidden="true"></span>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
