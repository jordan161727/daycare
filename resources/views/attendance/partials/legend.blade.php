{{--
    The key to the colours on the sheet, behind a "?".

    A colour that has to be learned from a walkthrough is a colour nobody
    reads, so the states are spelled out here rather than in the documentation
    — but spread across the top of the sheet they were two lines of small print
    above every page load, read once and then permanently in the way. Folded
    into one mark, the answer stays a hover away for the person who wants it and
    costs nothing to the person who does not.

    The swatches are painted from the same $boxStates map the boxes are, so a
    colour can never mean one thing in the grid and another here — see the top
    of attendance/index.blade.php.

    The panel is fixed, measured off the button, and teleported to the body.
    Both halves of that are needed: the sheet sits in a card that clips its
    overflow, so an absolute dropdown would be sliced off at the card's edge —
    and that same card carries a backdrop-blur, which makes it the containing
    block for any fixed descendant, so a fixed panel left inside it took its
    viewport coordinates as card-relative ones and opened halfway down the page.
    Teleported out, "fixed" means the viewport again.

    $boxStates array   the four sign-in states: label, hint, classes
    $for       string  'signin' (the grid) or 'schedule' (the checklist)
--}}
@php($isSignIn = ($for ?? 'signin') === 'signin')

{{-- An inline control, not a band: it is dropped in beside whatever row already
     exists on the page — the room filters on the sign-in sheet, the tips line on
     the checklist — rather than claiming a strip of its own above the grid. --}}
<div
    class="relative inline-block shrink-0"
    x-data="{
        open: false,
        closing: null,
        x: 0,
        y: 0,
        place() {
            const box = this.$refs.trigger.getBoundingClientRect();
            // Held off the right edge, so the panel never opens half off-screen
            // on a narrow window.
            this.x = Math.max(12, Math.min(box.left, window.innerWidth - 372));
            this.y = box.bottom + 6;
        },
        show() {
            clearTimeout(this.closing);
            this.place();
            this.open = true;
        },
        // A moment's grace, or the panel shuts while the pointer is still
        // crossing the gap between the button and it.
        hide() {
            this.closing = setTimeout(() => this.open = false, 150);
        },
    }"
    @keydown.escape.window="open = false"
    @scroll.window="open && place()"
    @resize.window="open && place()"
    {{-- On this wrapper rather than on the panel: the panel is teleported to the
         body, so a click on the trigger counts as outside it — which would close
         and immediately reopen, and click-to-close would never work. --}}
    @click.outside="open = false"
>
    <button
        type="button"
        x-ref="trigger"
        @mouseenter="show()"
        @mouseleave="hide()"
        @focus="show()"
        @blur="hide()"
        @click="open ? open = false : show()"
        :aria-expanded="open"
        aria-haspopup="true"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-200 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-slate-200"
    >
        <span class="grid h-4 w-4 place-items-center rounded-full border border-current text-[10px] leading-none" aria-hidden="true">?</span>
        <span>Key</span>
    </button>

    <template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        @mouseenter="show()"
        @mouseleave="hide()"
        :style="`top: ${y}px; left: ${x}px`"
        class="fixed z-50 w-[360px] max-w-[calc(100vw-24px)] rounded-xl border border-slate-200 bg-white p-3 text-[11px] text-slate-500 shadow-xl dark:border-white/10 dark:bg-slate-900 dark:text-slate-400"
        role="tooltip"
    >
        <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">What the boxes mean</p>
        <ul class="space-y-1.5">
            @if($isSignIn)
                @foreach($boxStates as $key => $state)
                    <li class="flex items-start gap-2">
                        <span class="mt-px inline-flex shrink-0 items-center gap-1 rounded-lg border px-1.5 py-0.5 text-[10px] font-semibold leading-tight {{ $state['classes'] }}">
                            <span>{{ $state['swatch'] }}</span>
                            {{-- The one state that means "look at this": a day nobody
                                 planned for. It carries the mark on the box too, so the
                                 two amber-ish states never rely on colour alone. --}}
                            @if($key === 'unplanned')
                                <span class="rounded-sm bg-amber-500/25 px-1 text-[9px] font-bold leading-none">!</span>
                            @endif
                        </span>
                        <span><b class="font-semibold text-slate-600 dark:text-slate-300">{{ $state['label'] }}</b> — {{ $state['hint'] }}</span>
                    </li>
                @endforeach
            @else
                <li class="flex items-start gap-2">
                    <span class="mt-px inline-flex shrink-0 items-center gap-1 rounded-lg border-[1.5px] border-indigo-500 bg-indigo-200 px-1.5 py-0.5 dark:bg-indigo-500/25">
                        <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-indigo-500 bg-indigo-600 text-[8px] leading-none text-white">✓</span>
                    </span>
                    <span><b class="font-semibold text-slate-600 dark:text-slate-300">Ticked</b> — expected that day</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-px inline-flex shrink-0 items-center gap-1 rounded-lg border-[1.5px] border-slate-300 bg-slate-50 px-1.5 py-0.5 dark:border-white/10 dark:bg-slate-800/60">
                        <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-slate-300 bg-white text-[8px] leading-none text-transparent dark:border-white/20 dark:bg-slate-900">✓</span>
                    </span>
                    <span><b class="font-semibold text-slate-600 dark:text-slate-300">Empty</b> — not expected</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-px inline-flex shrink-0 items-center rounded-lg border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-800">Closed</span>
                    <span><b class="font-semibold text-slate-600 dark:text-slate-300">Centre closed</b> — nothing can be ticked</span>
                </li>
            @endif

            {{-- Shared by both views: the two things a box can say without being a
                 state at all — no box to click, and a box the forecast argues with. --}}
            <li class="flex items-start gap-2">
                <span class="mt-px inline-flex shrink-0 items-center rounded-lg border border-dashed border-slate-300 px-2 py-0.5 text-[10px] text-slate-400 dark:border-white/20 dark:text-slate-500">—</span>
                <span><b class="font-semibold text-slate-600 dark:text-slate-300">Not enrolled</b> — before they start or after they leave</span>
            </li>
            <li class="flex items-start gap-2">
                <span class="mt-px inline-flex shrink-0 items-center rounded-lg border border-slate-200 px-2 py-0.5 text-[10px] text-transparent ring-1 ring-sky-400 dark:border-white/10 dark:ring-sky-500">—</span>
                <span><b class="font-semibold text-slate-600 dark:text-slate-300">Sky ring</b> — the projection disagrees with the schedule</span>
            </li>

            @if($isSignIn)
                <li class="flex items-start gap-2">
                    <span class="mt-px shrink-0 font-semibold text-violet-700 dark:text-violet-300">Room ✎</span>
                    <span>room set by hand; <span class="font-semibold text-amber-700 dark:text-amber-300">⚠</span> means their age has caught up with it</span>
                </li>
            @endif
        </ul>
    </div>
    </template>
</div>
