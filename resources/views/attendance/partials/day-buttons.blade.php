{{--
    Sign-in controls for one child on one day, in all four states. Shared by the
    desktop table and the small-screen card list so the two can never drift apart.

    $date    Carbon  the day these buttons stamp
    $variant string  'table' (grid cell) or 'card' (stacked list row)
--}}
@php($iso = $date->toDateString())
@php($size = $variant === 'table' ? 'px-2 py-1.5 text-[11px]' : 'min-w-[4.5rem] px-3 py-2 text-xs')

{{-- Not enrolled: no slot exists for this day, so there is nothing to click. --}}
<template x-if="! hasSlot(child.id, '{{ $iso }}')">
    <span class="grid min-h-[34px] w-full place-items-center rounded-lg border border-dashed border-slate-200 text-[11px] text-slate-300 dark:border-white/10 dark:text-slate-600" title="Not enrolled on this date">—</span>
</template>

<template x-if="hasSlot(child.id, '{{ $iso }}')">
    <div class="flex items-center justify-center gap-1.5">
        <template x-for="session in child.sessions" :key="session">
            <button
                @click="signIn(child.id, '{{ $iso }}', session)"
                :disabled="isPresent(child.id, '{{ $iso }}', session)"
                :class="boxClass(child.id, '{{ $iso }}', session)"
                :title="boxTitle(child.id, '{{ $iso }}', session)"
                class="rounded-lg border font-semibold leading-tight transition {{ $size }} {{ $variant === 'table' && '' }}"
            >
                <span x-text="boxLabel(child.id, '{{ $iso }}', session)"></span>
                <span x-show="isPresent(child.id, '{{ $iso }}', session)" class="block text-[10px] font-medium opacity-90" x-text="sessionTime(child.id, '{{ $iso }}', session)"></span>
            </button>
        </template>
    </div>
</template>
