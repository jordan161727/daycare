{{-- One day, room by room, with the ratio bar under each: the view that shows
     the moment a room goes short. Reached from the Summary tab's room coverage
     table; it reads the same variables as the week page that includes it. --}}

    {{-- By room, one day at a time, with the ratio bar underneath. The bar is
         the reason this view exists: it shows the moment a room goes short. --}}
    <div class="mt-5 flex flex-wrap gap-2">
        @foreach($dates as $dayCode => $date)
            <a href="{{ route('staff-schedule.index', ['week' => $weekStart, 'mode' => 'room', 'day' => $dayCode]) }}"
               class="rounded-full px-4 py-2 text-sm font-semibold {{ $day === $dayCode ? 'bg-indigo-100 text-indigo-700 ring-1 ring-indigo-500' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                {{ $date->format('D j') }}
            </a>
        @endforeach
    </div>

    @foreach($rooms as $room)
        @php
            $roomShifts = $shifts->where('day', $day)->where('classroom', $room)->sortBy('starts_at');
            $bands = $coverage[$day][$room] ?? [];
            $steps = $demand[$day][$room] ?? [];
            $peak = collect($steps)->max('children') ?? 0;
        @endphp

        @continue($roomShifts->isEmpty() && $peak === 0)

        <section class="glass-card mt-5 overflow-hidden rounded-2xl">
            <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-slate-100 bg-slate-50/60 px-5 py-3 dark:border-white/10 dark:bg-white/5">
                <h2 class="text-sm font-bold">{{ $room }}</h2>
                <span class="text-xs text-slate-500">
                    {{ $peak }} {{ \Illuminate\Support\Str::plural('child', $peak) }} at peak ·
                    1 staff per {{ config('daycare.ratios')[$room] ?? '—' }}
                </span>
                @if(collect($bands)->contains(fn ($b) => $b['have'] < $b['need']))
                    <span class="ml-auto rounded-full bg-rose-100 px-2.5 py-0.5 text-[11px] font-bold text-rose-700">Under ratio</span>
                @endif
            </header>

            <div class="overflow-x-auto px-5 py-4">
                <div class="min-w-[820px]">
                    @include('staff-schedule.partials.axis', ['hours' => $hours, 'open' => $open, 'span' => $span, 'trailing' => false])

                    @forelse($roomShifts as $shift)
                        <div class="flex items-center gap-3 py-0.5">
                            <div class="w-32 shrink-0 truncate text-xs font-semibold">{{ $shift->user?->name ?? 'Unknown' }}</div>
                            <div class="relative h-6 flex-1 rounded" style="{{ $gridlines }}">
                                <div class="absolute inset-y-0.5 flex items-center overflow-hidden whitespace-nowrap rounded-full border px-2 text-[10px] font-semibold
                                            {{ $shift->role === \App\Models\StaffShift::ROLE_PATCH ? 'border-dashed' : ($shift->role === \App\Models\StaffShift::ROLE_FLOAT ? 'border-dotted' : '') }}"
                                     style="{{ $place($shift->starts_at, $shift->ends_at) }};background:{{ $roomColour[$room] ?? '#64748b' }}1f;border-color:{{ $roomColour[$room] ?? '#64748b' }};color:{{ $roomColour[$room] ?? '#64748b' }}"
                                 title="{{ $shift->label() }} &middot; {{ $shift->hours() }}h{{ $shift->isCover() ? ($shift->role === \App\Models\StaffShift::ROLE_PATCH ? ' (coverage patch)' : ' (floating)') : '' }}">
                                    {{ $compact($shift->starts_at) }} &rarr; {{ $compact($shift->ends_at) }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="py-2 text-xs text-slate-400">Nobody scheduled in this room.</p>
                    @endforelse

                    <div class="mt-2 flex items-center gap-3">
                        <div class="w-32 shrink-0 text-[11px] text-slate-400">Ratio cover</div>
                        <div class="relative h-4 flex-1 overflow-hidden rounded bg-slate-100 dark:bg-white/5">
                            @foreach($bands as $band)
                                <div class="absolute inset-y-0 text-center text-[9px] font-bold leading-4 {{ $band['have'] < $band['need'] ? 'bg-rose-200 text-rose-800' : 'bg-emerald-200 text-emerald-800' }}"
                                     style="{{ $place($band['from'], $band['to']) }}"
                                     title="{{ \App\Models\StaffRule::formatTime($band['from']) }}–{{ \App\Models\StaffRule::formatTime($band['to']) }}: {{ $band['have'] }} of {{ $band['need'] }} needed">
                                    {{ $band['have'] }}/{{ $band['need'] }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endforeach

