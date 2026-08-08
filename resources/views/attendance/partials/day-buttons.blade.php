{{--
    Sign-in controls for one child on one day. Shared by the desktop table and the
    small-screen card list so the two can never drift apart.

    $date    Carbon  the day these buttons stamp
    $variant string  'table' (grid cell) or 'card' (stacked list row)
--}}
@php($iso = $date->toDateString())
@php($base = 'rounded-lg border font-semibold leading-tight transition disabled:cursor-default')
@php($size = $variant === 'table' ? 'px-2 py-1.5 text-[11px]' : 'px-3 py-2 text-xs')
@php($state = fn ($session) => "isPresent(child.id, '{$iso}', '{$session}') ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/30' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-white/10'")

<template x-if="child.classroom === 'School Age'">
    <div class="flex items-center justify-center gap-1.5">
        <button @click="signIn(child.id, '{{ $iso }}', 'AM')" :disabled="isPresent(child.id, '{{ $iso }}', 'AM')" :class="{{ $state('AM') }}" class="{{ $base }} {{ $size }} {{ $variant === 'card' ? 'min-w-[4.5rem]' : '' }}">
            <span x-text="isPresent(child.id, '{{ $iso }}', 'AM') ? 'AM ✓ ' + sessionTime(child.id, '{{ $iso }}', 'AM') : 'AM'"></span>
        </button>
        <button @click="signIn(child.id, '{{ $iso }}', 'PM')" :disabled="isPresent(child.id, '{{ $iso }}', 'PM')" :class="{{ $state('PM') }}" class="{{ $base }} {{ $size }} {{ $variant === 'card' ? 'min-w-[4.5rem]' : '' }}">
            <span x-text="isPresent(child.id, '{{ $iso }}', 'PM') ? 'PM ✓ ' + sessionTime(child.id, '{{ $iso }}', 'PM') : 'PM'"></span>
        </button>
    </div>
</template>
<template x-if="child.classroom !== 'School Age'">
    <button @click="signIn(child.id, '{{ $iso }}', 'FULL')" :disabled="isPresent(child.id, '{{ $iso }}', 'FULL')" :class="{{ $state('FULL') }}" class="{{ $base }} {{ $variant === 'table' ? 'w-full px-2 py-1.5 text-xs' : 'min-w-[7.5rem] px-3 py-2 text-xs' }}">
        <span x-text="isPresent(child.id, '{{ $iso }}', 'FULL') ? '✓ ' + sessionTime(child.id, '{{ $iso }}', 'FULL') : 'Present'"></span>
    </button>
</template>
