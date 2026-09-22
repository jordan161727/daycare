@extends('layouts.app')
@section('title', 'Devices')
@section('content')
{{-- The screens staff punch at.

     A short page on purpose: a centre has a handful of these, they are set up
     once, and the only thing that happens afterwards is a tablet being replaced.
     What it has to do well is the pairing link, which is shown exactly once. --}}

<div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Devices</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The screens staff scan their cards at. Each one is named for where it hangs, because that is what a punch will say six weeks later.</p>
        </div>
    </div>

    @if($issued)
        {{-- The one moment the token exists in readable form. Said loudly, with
             what to do about it, because there is no second chance to copy it
             and the fix afterwards is to pair the device again. --}}
        <section class="mt-6 rounded-2xl border border-emerald-300 bg-emerald-50 p-5 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <h2 class="text-base font-bold text-emerald-900 dark:text-emerald-200">{{ $issued['name'] }} is ready to pair</h2>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-300">
                Open this address once on that tablet. It pairs the screen and then drops the token from the bar, so the tablet can be left on a plain bookmark afterwards.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                <input readonly value="{{ $issued['url'] }}" class="min-w-0 flex-1 rounded-xl border border-emerald-300 bg-white px-3 py-2 font-mono text-xs dark:border-emerald-500/30 dark:bg-slate-900" @focus="$event.target.select()">
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $issued['url'] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </button>
            </div>
            <p class="mt-2 text-xs font-semibold text-emerald-800 dark:text-emerald-300">This link is shown once. It is not stored and cannot be read back.</p>
        </section>
    @endif

    <section class="glass-card mt-6 rounded-2xl p-6">
        <h2 class="card-title"><span class="card-icon">➕</span> Add a device</h2>
        <form method="POST" action="{{ route('devices.store') }}" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
            @csrf
            <label>
                <span class="cs-label">Name</span>
                <input name="name" required maxlength="120" placeholder="Front desk kiosk" class="cs-input">
                <x-input-error :messages="$errors->get('name')" />
            </label>
            <label>
                <span class="cs-label">Where it is</span>
                <input name="location" maxlength="120" placeholder="Lobby, by the sign-in table" class="cs-input">
            </label>
            <div class="flex items-end">
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">Add</button>
            </div>
        </form>
    </section>

    <section class="glass-card mt-5 overflow-hidden rounded-2xl">
        <table class="w-full text-left text-sm">
            <thead class="text-[11px] uppercase tracking-wide text-slate-400">
                <tr class="border-b border-slate-200 dark:border-white/10">
                    <th class="px-5 py-3 font-semibold">Device</th>
                    <th class="px-5 py-3 font-semibold">Last seen</th>
                    <th class="px-5 py-3 font-semibold">Status</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                @forelse($devices as $device)
                    <tr class="{{ $device->is_active ? '' : 'opacity-50' }}">
                        <td class="px-5 py-3">
                            <span class="font-semibold">{{ $device->name }}</span>
                            @if(filled($device->location))
                                <span class="block text-xs text-slate-400">{{ $device->location }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-slate-500 dark:text-slate-400">
                            {{-- Never seen is worth saying plainly: it is the
                                 usual sign that a tablet was never paired. --}}
                            {{ $device->last_seen_at?->diffForHumans() ?? 'Never used' }}
                        </td>
                        <td class="px-5 py-3">
                            @if($device->is_active)
                                <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">Active</span>
                            @else
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500 dark:bg-white/10 dark:text-slate-400">Retired</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            {{-- Shown on its own page rather than in this row:
                                 a list of live kiosk addresses is a list
                                 somebody screenshots. --}}
                            <a href="{{ route('devices.link', $device) }}" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400" title="See this device's pairing link and QR code">Show link</a>

                            <form method="POST" action="{{ route('devices.repair', $device) }}" class="ml-3 inline"
                                  onsubmit="return confirm('Issue a new link for this device? Whichever tablet is paired now will stop working until it is set up again.')">
                                @csrf
                                <button class="text-xs font-semibold text-slate-500 hover:underline dark:text-slate-400" title="Issue a new pairing link. The tablet holding the old one stops working.">Re-pair</button>
                            </form>
                            @if($device->is_active)
                                <form method="POST" action="{{ route('devices.destroy', $device) }}" class="ml-3 inline">
                                    @csrf @method('DELETE')
                                    {{-- Retired, never deleted: the punches it
                                         recorded still name it. --}}
                                    <button class="text-xs font-semibold text-rose-600 hover:underline">Retire</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-10 text-center text-slate-500 dark:text-slate-400">
                            No devices yet. Add the first one above, then open its link on the tablet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
