@extends('layouts.app')
@section('title', 'Rooms & hours')
@section('content')
{{-- Settings → Rooms & hours — Phase 1.2 of docs/work.md.

     The rooms as a table (ratio, capacity, New York's group cap, the hours
     each room keeps, and a check), then the centre's own hours and the
     shift limits. Everything the floor plan, the dashboard and the schedule
     read about a room or the day comes from here, through
     App\Services\CentreSettings; a saved value wins over config/daycare.php. --}}
<div class="mx-auto max-w-6xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-bold tracking-tight">Rooms &amp; hours</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Each room's ratio, capacity and hours, and the centre's day. The floor plan, the dashboard and the schedule read from here.</p>

        @if(session('status'))
            <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
        @endif

        @if($warnings !== [])
            {{-- Saved values that disagree with each other, said here rather than found on the floor. --}}
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100" role="alert" data-settings-warnings>
                <p class="font-semibold">Worth a look</p>
                <ul class="mt-1 list-inside list-disc space-y-0.5">
                    @foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if($errors->any())
            <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('settings.rooms.update') }}" class="mt-6 space-y-6">
            @csrf
            @method('PUT')

            <section class="glass-card overflow-hidden rounded-2xl">
                <header class="border-b border-slate-100 px-5 py-4 dark:border-white/10">
                    <h2 class="text-base font-semibold">Rooms</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">NY max group is the largest group New York allows for the age (18 NYCRR 418-1.8). A capacity above it means the room runs as two groups.</p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" data-rooms-table>
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-white/5 dark:text-slate-400">
                            <tr>
                                <th class="whitespace-nowrap px-2.5 py-2.5">Room</th>
                                <th class="whitespace-nowrap px-2.5 py-2.5">Ages</th>
                                <th class="px-2.5 py-2.5">Staff : children</th>
                                <th class="px-2.5 py-2.5">Capacity</th>
                                <th class="px-2.5 py-2.5">NY max group</th>
                                <th class="px-2.5 py-2.5">Room open hours</th>
                                <th class="px-2.5 py-2.5">Room tablet code</th>
                                <th class="whitespace-nowrap px-2.5 py-2.5">Check</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach($rooms as $i => $row)
                                <tr data-room-row="{{ $row['room'] }}">
                                    <td class="whitespace-nowrap px-2.5 py-2.5 font-semibold"><x-room-icon :room="$row['room']" size="text-sm" /> {{ $row['room'] }}<input type="hidden" name="rooms[{{ $i }}][room]" value="{{ $row['room'] }}"></td>
                                    <td class="whitespace-nowrap px-2.5 py-2.5 text-slate-600 dark:text-slate-300">{{ $row['ages'] }}</td>
                                    <td class="px-2.5 py-2.5"><span class="inline-flex items-center gap-1 whitespace-nowrap tabular-nums">1 : <input type="number" name="rooms[{{ $i }}][ratio]" value="{{ old("rooms.$i.ratio", $row['ratio']) }}" min="1" max="30" class="w-14 rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-white/10 dark:bg-night-800" aria-label="{{ $row['room'] }} ratio"></span></td>
                                    <td class="px-2.5 py-2.5"><input type="number" name="rooms[{{ $i }}][capacity]" value="{{ old("rooms.$i.capacity", $row['capacity']) }}" min="0" max="60" class="w-16 rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-white/10 dark:bg-night-800 {{ $row['check']['tone'] === 'warn' && str_contains($row['check']['text'], 'group') ? 'border-amber-400' : '' }}" aria-label="{{ $row['room'] }} capacity"></td>
                                    <td class="px-2.5 py-2.5 tabular-nums text-slate-600 dark:text-slate-300">{{ $row['ny_max'] ?: '—' }}</td>
                                    <td class="px-2.5 py-2.5"><span class="inline-flex items-center gap-1.5 whitespace-nowrap tabular-nums"><input type="time" name="rooms[{{ $i }}][opens_at]" value="{{ old("rooms.$i.opens_at", $row['opens_at']) }}" class="rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-white/10 dark:bg-night-800" aria-label="{{ $row['room'] }} opens"> – <input type="time" name="rooms[{{ $i }}][closes_at]" value="{{ old("rooms.$i.closes_at", $row['closes_at']) }}" class="rounded-lg border border-slate-200 px-2 py-1 text-sm dark:border-white/10 dark:bg-night-800" aria-label="{{ $row['room'] }} closes"></span></td>
                                    {{-- The four digits keyed into a tablet's gear to make it this
                                         room (HANDOFF §5.3). Never a staff PIN — the save refuses it. --}}
                                    <td class="px-2.5 py-2.5">
                                        <input type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="rooms[{{ $i }}][tablet_code]" value="{{ old("rooms.$i.tablet_code", $row['tablet_code']) }}" placeholder="····" class="w-20 rounded-lg border border-slate-200 px-2 py-1 font-mono text-sm tabular-nums dark:border-white/10 dark:bg-night-800" aria-label="{{ $row['room'] }} room tablet code">
                                        <span class="block text-[11px] text-slate-500 dark:text-slate-400" data-room-tablets="{{ $row['room'] }}">{{ $row['tablets'] }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-2.5 py-2.5"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $row['check']['tone'] === 'warn' ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-100' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200' }}" data-room-check="{{ $row['check']['tone'] }}">{{ $row['check']['text'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="glass-card rounded-2xl p-5">
                <h2 class="text-base font-semibold">Centre hours &amp; shift limits</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Only staff who may open the building can start before the earliest start; everyone else's shifts begin at it or later.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach(\App\Services\CentreSettings::KEYS as $key => $meta)
                        <label class="block">
                            <span class="block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $meta['label'] }}</span>
                            @if($key === 'early_dropoff_room')
                                <select name="centre[{{ $key }}]" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm dark:border-white/10 dark:bg-night-800">
                                    @foreach(\App\Services\ClassroomAssignment::rooms() as $room)
                                        <option value="{{ $room }}" @selected(old("centre.$key", $centre[$key]) === $room)>{{ $room }}</option>
                                    @endforeach
                                </select>
                            @elseif(is_int($meta['default']))
                                <input type="number" name="centre[{{ $key }}]" value="{{ old("centre.$key", $centre[$key]) }}" min="1" max="{{ $key === 'max_week_hours' ? 80 : 16 }}" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums dark:border-white/10 dark:bg-night-800">
                            @else
                                <input type="time" name="centre[{{ $key }}]" value="{{ old("centre.$key", $centre[$key]) }}" class="mt-1 w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm tabular-nums dark:border-white/10 dark:bg-night-800">
                            @endif
                        </label>
                    @endforeach
                </div>
            </section>

            <div class="flex justify-end">
                <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">Save</button>
            </div>
        </form>
    </div>
</div>
@endsection
