{{--
    The key to the colours on the sheet.

    A colour that has to be learned from a walkthrough is a colour nobody
    reads, so the states are spelled out beside the grid rather than in the
    documentation. The swatches are painted from the same $boxStates map the
    boxes are, so a colour can never mean one thing in the grid and another
    here — see the top of attendance/index.blade.php.

    $boxStates array   the four sign-in states: label, hint, classes
    $for       string  'signin' (the grid) or 'schedule' (the checklist)
--}}
@php($isSignIn = ($for ?? 'signin') === 'signin')

<div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-slate-200/70 bg-slate-50/70 px-3 py-2 text-[11px] text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
    <span class="font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Key</span>

    @if($isSignIn)
        @foreach($boxStates as $key => $state)
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-flex items-center gap-1 rounded-lg border px-1.5 py-0.5 text-[10px] font-semibold leading-tight {{ $state['classes'] }}">
                    <span>{{ $state['swatch'] }}</span>
                    {{-- The one state that means "look at this": a day nobody
                         planned for. It carries the mark on the box too, so the
                         two amber-ish states never rely on colour alone. --}}
                    @if($key === 'unplanned')
                        <span class="rounded-sm bg-amber-500/25 px-1 text-[9px] font-bold leading-none">!</span>
                    @endif
                </span>
                <span><b class="font-semibold text-slate-600 dark:text-slate-300">{{ $state['label'] }}</b> — {{ $state['hint'] }}</span>
            </span>
        @endforeach
    @else
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-lg border-[1.5px] border-indigo-500 bg-indigo-200 px-1.5 py-0.5 dark:bg-indigo-500/25">
                <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-indigo-500 bg-indigo-600 text-[8px] leading-none text-white">✓</span>
            </span>
            <span><b class="font-semibold text-slate-600 dark:text-slate-300">Ticked</b> — expected that day</span>
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-lg border-[1.5px] border-slate-300 bg-slate-50 px-1.5 py-0.5 dark:border-white/10 dark:bg-slate-800/60">
                <span class="grid h-3 w-3 place-items-center rounded border-[1.5px] border-slate-300 bg-white text-[8px] leading-none text-transparent dark:border-white/20 dark:bg-slate-900">✓</span>
            </span>
            <span><b class="font-semibold text-slate-600 dark:text-slate-300">Empty</b> — not expected</span>
        </span>
        <span class="inline-flex items-center gap-1.5">
            <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-slate-400 dark:border-white/10 dark:bg-slate-800">Closed</span>
            <span><b class="font-semibold text-slate-600 dark:text-slate-300">Centre closed</b> — nothing can be ticked</span>
        </span>
    @endif

    {{-- Shared by both views: the two things a box can say without being a state
         at all — no box to click, and a box the forecast argues with. --}}
    <span class="inline-flex items-center gap-1.5">
        <span class="inline-flex items-center rounded-lg border border-dashed border-slate-300 px-2 py-0.5 text-[10px] text-slate-400 dark:border-white/20 dark:text-slate-500">—</span>
        <span><b class="font-semibold text-slate-600 dark:text-slate-300">Not enrolled</b> — before they start or after they leave</span>
    </span>
    <span class="inline-flex items-center gap-1.5">
        <span class="inline-flex items-center rounded-lg border border-slate-200 px-2 py-0.5 text-[10px] text-transparent ring-1 ring-sky-400 dark:border-white/10 dark:ring-sky-500">—</span>
        <span><b class="font-semibold text-slate-600 dark:text-slate-300">Sky ring</b> — the projection disagrees with the schedule</span>
    </span>

    @if($isSignIn)
        <span class="inline-flex items-center gap-1.5">
            <span class="font-semibold text-violet-700 dark:text-violet-300">Room ✎</span>
            <span>room set by hand; <span class="font-semibold text-amber-700 dark:text-amber-300">⚠</span> means their age has caught up with it</span>
        </span>
    @endif
</div>
