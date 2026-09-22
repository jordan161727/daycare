@extends('layouts.app')
@section('title', 'Pair '.$device->name)
@section('content')
{{-- One device's pairing link, asked for rather than listed.

     Three ways to get it onto a tablet, because which one is easiest depends
     on what you are holding: point its camera at the code, copy the address,
     or open it here if this page is already on the tablet. --}}

<div class="mx-auto max-w-lg" x-data="{ copied: false, plain: false }">
    <a href="{{ route('settings.devices') }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">&larr; Devices</a>

    <section class="glass-card mt-4 rounded-2xl p-6 text-center">
        <h1 class="text-xl font-bold">{{ $device->name }}</h1>
        @if(filled($device->location))
            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $device->location }}</p>
        @endif

        <div class="mx-auto mt-5 w-fit rounded-2xl bg-white p-3 ring-1 ring-slate-200">
            <img src="{{ route('devices.link.qr', $device) }}" alt="Pairing code for {{ $device->name }}" class="h-56 w-56">
        </div>

        <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
            Point the tablet&rsquo;s camera at this code. It pairs the screen and then drops the token from the address bar, so the tablet can be left on a plain bookmark afterwards.
        </p>

        {{-- The address itself is behind a press. It is not a password, but it
             is still the thing that turns a browser into a time clock, and a
             page showing it by default is a page somebody screenshots. --}}
        <div class="mt-5 border-t border-slate-200 pt-5 dark:border-white/10">
            <button type="button" x-show="! plain" @click="plain = true"
                    class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                Show the address instead
            </button>

            <div x-show="plain" x-cloak class="flex flex-wrap items-center gap-2">
                <input readonly value="{{ $url }}" @focus="$event.target.select()"
                       class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 font-mono text-[11px] dark:border-white/10 dark:bg-slate-900">
                <button type="button"
                        @click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 2000)"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </button>
            </div>
        </div>

        <p class="mt-5 rounded-xl bg-amber-50 px-3 py-2 text-left text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            Anyone with this address can open the clock screen on this device&rsquo;s name. They still need a card or a PIN to record anything &mdash; but if it has gone somewhere it should not have, <a href="{{ route('settings.devices') }}" class="font-semibold underline">re-pair the device</a> and this link stops working.
        </p>
    </section>
</div>
@endsection
