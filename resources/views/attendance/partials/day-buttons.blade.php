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

    ----

    Why the box is two bindings and not twenty.

    This partial is drawn once per child per day, so on a roll of seventy-five
    it is nearly four hundred boxes, and every binding inside it is one Alpine
    has to create and then watch. Written out — a class, a role, a tabindex, a
    label, a title, four key handlers, a click, and four nested templates for
    the contents — it came to about twenty-two each, and building them was the
    second or two the sheet took to appear.

    So the box states what it is through one attribute object and one string of
    contents, and the taps and key presses are caught once on the table rather
    than bound per box (see onCellClick/onCellKey). The behaviour is the same;
    the cost is four bindings instead of twenty-two.

    The field is left as it was. Only ever one box on the sheet is being typed
    into, and x-if means the rest never build it — a field needs real bindings
    and there is only one of it.

    $date    Carbon  the day these cells stamp
    $variant string  'table' (grid cell) or 'card' (stacked list row)
--}}
@php($iso = $date->toDateString())

{{-- Not enrolled: no slot exists for this day, so there is nothing to tap. --}}
<template x-if="! hasSlot(child.id, '{{ $iso }}')">
    <span class="att-slot w-full text-[0.7333rem] text-slate-300 dark:text-slate-600" title="Not enrolled on this date">—</span>
</template>

<template x-if="hasSlot(child.id, '{{ $iso }}')">
    {{-- One slot whether the room books once a day or twice: the boxes are
         centred in it, so every row is the same height. See .att-slot. --}}
    <div class="att-slot">
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

                {{-- The box. What it is, and what is in it. --}}
                <template x-if="! isRetiming(child.id, '{{ $iso }}', session)">
                    <div x-bind="cellAttrs(child, '{{ $iso }}', session)" x-html="cellInner(child, '{{ $iso }}', session)"></div>
                </template>
            </div>
        </template>
    </div>
</template>
