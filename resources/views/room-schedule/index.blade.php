@extends('layouts.app')
@section('title', 'Room Schedules')
@section('content')
<div>
    <x-page-header title="Room Schedules" subtitle="The hours each room runs, and the children who are in it."/>

    @if(session('success'))<div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>@endif

    {{-- One form for every room. The list is fixed and short, so saving it in
         one go beats an edit screen per room — and it is the whole centre's
         arrangement, which is easier to get right when it is all on one page
         with the children it is being set for. --}}
    <form method="POST" action="{{ route('room-schedule.update') }}">
        @csrf
        @method('PUT')

        <div class="mt-7 grid gap-5 xl:grid-cols-2">
            @foreach($rooms as $index => $room)
                @php($schedule = $room['schedule'])
                <section class="glass-card overflow-hidden rounded-2xl">
                    <header class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-100 p-5 dark:border-white/10">
                        <div>
                            <input type="hidden" name="rooms[{{ $index }}][room]" value="{{ $room['name'] }}">
                            <h2 class="text-base font-semibold">{{ $room['name'] }}</h2>
                            {{-- The line a child's record will show, so what is being
                                 set is visible as the thing it turns into. --}}
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $room['children']->count() }} {{ \Illuminate\Support\Str::plural('child', $room['children']->count()) }}
                                · {{ $schedule?->hoursLabel() ?? 'No hours set' }}
                            </p>
                        </div>
                        <div class="flex items-end gap-3">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Opens
                                <input type="time" name="rooms[{{ $index }}][opens_at]" value="{{ \App\Models\Child::timeInputValue(old("rooms.$index.opens_at", $schedule?->opens_at)) }}" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                                <x-input-error :messages="$errors->get('rooms.'.$index.'.opens_at')" />
                            </label>
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Closes
                                <input type="time" name="rooms[{{ $index }}][closes_at]" value="{{ \App\Models\Child::timeInputValue(old("rooms.$index.closes_at", $schedule?->closes_at)) }}" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                                <x-input-error :messages="$errors->get('rooms.'.$index.'.closes_at')" />
                            </label>
                        </div>
                    </header>

                    {{-- The children in the room and the hours each of them is
                         contracted for. A child whose day runs past the room's
                         is the thing this page exists to make visible, so their
                         hours sit directly under the room's own. --}}
                    <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto text-sm dark:divide-white/10">
                        @forelse($room['children'] as $child)
                            <li class="flex items-center justify-between gap-4 px-5 py-3">
                                <a href="{{ route('children.show', $child) }}" class="flex min-w-0 items-center gap-2.5 font-medium hover:text-indigo-600">
                                    <x-child-avatar :child="$child" size="h-7 w-7" />
                                    <span class="truncate">{{ $child->first_name }} {{ $child->last_name }}</span>
                                </a>
                                <span class="shrink-0 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{{ $child->scheduleLabel() ?? 'No hours agreed' }}</span>
                            </li>
                        @empty
                            <li class="px-5 py-8 text-center text-sm text-slate-500 dark:text-slate-400">Nobody is in this room.</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>

        <div class="glass-card mt-5 flex items-center justify-between gap-4 rounded-2xl px-6 py-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">A room left blank simply has nothing to show. These are the room's standing hours — the generated week roster is separate and unaffected.</p>
            <button class="shrink-0 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">Save hours</button>
        </div>
    </form>
</div>
@endsection
