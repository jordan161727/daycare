@extends('layouts.app')

@section('title', 'Week Schedule')

@php
    use App\Models\StaffRule;
    use App\Models\StaffShift;

    $open = (int) config('daycare.open');
    $close = (int) config('daycare.close');
    $span = max(1, $close - $open);
    $days = config('daycare.days');

    /** Where a shift sits on a lane, as percentages of the operating day. */
    $place = fn (int $from, int $to) => sprintf(
        'left:%.4f%%;width:%.4f%%',
        (max($open, $from) - $open) / $span * 100,
        (min($close, $to) - max($open, $from)) / $span * 100
    );

    /** "7a", "1:45p" — short enough to survive a narrow bar. */
    $compact = fn (int $minutes) => StaffRule::compactTime($minutes);

    /**
     * What will actually fit inside a bar of this length.
     *
     * A three-hour shift is a quarter of the day and reads fine; a half-hour
     * cover patch is 2% of it and cannot hold two timestamps at any font size.
     * Clipping the text mid-character — ":30p → 6" — is worse than showing
     * nothing, because it looks like a rendering fault rather than a small
     * shift. Below the threshold the bar carries its times in the tooltip and
     * the row underneath spells them out.
     */
    $barLabel = function (int $from, int $to) use ($span, $compact) {
        $width = ($to - $from) / $span * 100;

        return match (true) {
            $width >= 17 => $compact($from).' → '.$compact($to),
            $width >= 9 => $compact($from),
            default => '',
        };
    };

    /**
     * Pack a day's shifts into non-overlapping rows.
     *
     * The generator will not double-book anybody, so in practice this returns
     * a single lane. It exists so that if one ever does overlap — a hand edit,
     * an import, a bug — the chart stacks the two bars and shows the clash,
     * rather than drawing them on top of each other where the times mangle
     * into each other and the row looks broken instead of wrong.
     */
    $packLanes = function ($dayShifts) {
        $lanes = [];

        foreach ($dayShifts as $shift) {
            foreach ($lanes as $index => $lane) {
                if (end($lane)->ends_at <= $shift->starts_at) {
                    $lanes[$index][] = $shift;

                    continue 2;
                }
            }

            $lanes[] = [$shift];
        }

        return $lanes;
    };

    // An hour rule behind each lane. Without it a bar is a coloured rectangle
    // you cannot read a time off, and there is no room for an axis per column.
    $gridlines = sprintf(
        'background-image:repeating-linear-gradient(90deg,transparent 0,transparent calc(100%%/%1$d - 1px),rgba(148,163,184,.28) calc(100%%/%1$d - 1px),rgba(148,163,184,.28) calc(100%%/%1$d))',
        max(1, (int) round($span / 60))
    );

    // One hue per room so a person's bar is the same colour everywhere they
    // appear — the by-room and by-teacher views have to be readable together.
    $palette = ['#2c6e63', '#3d6ea5', '#8a5a9e', '#b3452e', '#4f7a3a', '#a06a2c'];
    $roomColour = collect($rooms)->mapWithKeys(fn ($room, $i) => [$room => $palette[$i % count($palette)]]);

    $monday = \Illuminate\Support\Carbon::parse($weekStart);
    $hours = range((int) ceil($open / 60), (int) floor($close / 60));
@endphp

@section('content')
<div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
    <x-page-header
        title="Week Schedule"
        :subtitle="$monday->format('D M j').' – '.$monday->copy()->addDays(4)->format('D M j, Y')" />

    <div class="flex flex-wrap items-center gap-2">
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <input type="date" name="week" value="{{ $weekStart }}" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-white/10 dark:bg-slate-800">
            <button class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Go</button>
        </form>

        <div class="inline-flex overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
            @foreach(['teacher' => 'By teacher', 'room' => 'By room'] as $value => $label)
                <a href="{{ route('staff-schedule.index', ['week' => $weekStart, 'mode' => $value, 'day' => $day]) }}"
                   class="px-4 py-2.5 text-sm font-semibold {{ $mode === $value ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800' }}">{{ $label }}</a>
            @endforeach
        </div>

        @if(! auth()->user()->isAdmin())
            <a href="{{ route('staff-schedule.mine', ['week' => $weekStart]) }}"
               class="rounded-xl px-4 py-2.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/10">My schedule</a>
        @endif

        @if(auth()->user()->isAdmin())
            <form method="POST" action="{{ route('staff-schedule.generate') }}"
                  onsubmit="return confirm('Regenerate this week from the current rules? Any shift already on it is replaced.')">
                @csrf
                <input type="hidden" name="week" value="{{ $weekStart }}">
                <input type="hidden" name="mode" value="{{ $mode }}">
                <button class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Generate schedule</button>
            </form>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
@endif

@if($week)
    <p class="mt-4 text-xs text-slate-500">
        Generated {{ $week->generated_at?->diffForHumans() }}@if($week->generatedBy) by {{ $week->generatedBy->name }}@endif.
        Rules changed since then are not reflected until it is generated again.
    </p>
@endif

@if($shifts->isEmpty())
    <div class="glass-card mt-7 rounded-2xl px-6 py-16 text-center">
        <p class="text-sm text-slate-500">
            No staff schedule for this week yet.
            @if(auth()->user()->isAdmin()) Press <b>Generate schedule</b> to build one from the rules on each staff record. @endif
        </p>
    </div>
@else

<div class="mt-6 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500">
    @foreach($rooms as $room)
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full" style="background:{{ $roomColour[$room] }}26;border:1.5px solid {{ $roomColour[$room] }}"></span>{{ $room }}</span>
    @endforeach
</div>
<p class="mt-1.5 text-xs text-slate-400">
    Dotted border = floating between rooms &middot; dashed = coverage patch
    &middot; <span class="font-semibold text-emerald-600 dark:text-emerald-300">green</span> = approved leave, so that person is not available at all
</p>

@if($mode === 'teacher')

    {{-- The whole week in one table: a row is a person, a column is a day.
         Reading across answers "what is Maria's week", reading down answers
         "who is in on Wednesday" — five stacked day charts answered neither
         without scrolling. --}}
    <section class="glass-card mt-5 overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            {{-- Wide on purpose. Five day columns squeezed into a laptop width
                 leave every bar too narrow to label; better to scroll than to
                 render a chart nobody can read. --}}
            <table class="w-full min-w-[1400px] border-collapse">
                <thead>
                    <tr class="bg-slate-50/70 dark:bg-white/5">
                        <th class="sticky left-0 z-10 bg-slate-50/70 px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-night-900/80">Teacher</th>
                        @foreach($dates as $dayCode => $date)
                            <th class="border-l border-slate-100 px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10">
                                {{ strtoupper($date->format('D')) }}
                                <span class="ml-1 font-normal normal-case tracking-normal text-slate-400">{{ $compact($open) }}&ndash;{{ $compact($close) }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach($staff as $person)
                        @php
                            $mine = $shifts->where('user_id', $person->id);
                            $weekHours = round($mine->sum(fn ($s) => $s->minutes()) / 60, 1);
                        @endphp
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-white/5">
                            <td class="sticky left-0 z-10 w-44 bg-white/90 px-4 py-2 align-middle backdrop-blur dark:bg-night-900/80">
                                <a href="{{ route('teachers.show', $person) }}" class="block truncate text-sm font-bold hover:text-indigo-600">{{ $person->name }}</a>
                                <span class="text-[11px] text-slate-400">
                                    {{ $person->employment ?: 'No type' }} &middot; <span class="tabular-nums">{{ $weekHours }}h</span>
                                </span>
                            </td>

                            @foreach($dates as $dayCode => $date)
                                @php
                                    $away = $leave[$person->id][$date->toDateString()] ?? null;
                                    $today = $mine->where('day', $dayCode)->sortBy('starts_at');
                                    // Spell the day out underneath as soon as any one bar is too
                                    // narrow to hold its own times. Whatever the chart cannot
                                    // show, the text does — nothing is only in a tooltip.
                                    $needsCaption = $today->contains(fn ($s) => blank($barLabel($s->starts_at, $s->ends_at)) || $barLabel($s->starts_at, $s->ends_at) === $compact($s->starts_at));
                                @endphp
                                <td class="border-l border-slate-100 px-3 py-2 align-top dark:border-white/10">
                                    {{-- Leave is drawn across the whole lane, not as a bar with
                                         times: it is the absence of a shift rather than a short
                                         one, and a chart that leaves the cell blank cannot tell
                                         "booked off" apart from "nobody got round to them". --}}
                                    @if($away)
                                        <div class="mb-1 flex h-7 items-center justify-center rounded-lg border border-dashed border-emerald-500 bg-emerald-500/10 px-1 text-[10px] font-bold leading-none text-emerald-700 dark:text-emerald-300"
                                             title="{{ $away->label() }} · approved leave · {{ $away->hours_per_day }}h">
                                            {{ strtoupper($away->leave_type) }}
                                        </div>
                                    @endif

                                    <div class="space-y-1">
                                        @foreach($packLanes($today) as $lane)
                                            <div class="relative h-7 rounded" style="{{ $gridlines }}">
                                                @foreach($lane as $shift)
                                                    @php $tone = $roomColour[$shift->classroom] ?? '#64748b'; @endphp
                                                    <div class="absolute inset-y-0 flex items-center justify-center overflow-hidden whitespace-nowrap rounded-lg border px-1 text-[10px] font-semibold leading-none shadow-sm
                                                                {{ $shift->role === StaffShift::ROLE_PATCH ? 'border-dashed' : ($shift->role === StaffShift::ROLE_FLOAT ? 'border-dotted' : '') }}"
                                                         style="{{ $place($shift->starts_at, $shift->ends_at) }};background:{{ $tone }}26;border-color:{{ $tone }};color:{{ $tone }}"
                                                         title="{{ $shift->classroom }} &middot; {{ $shift->label() }} &middot; {{ $shift->hours() }}h{{ $shift->isCover() ? ($shift->role === StaffShift::ROLE_PATCH ? ' (coverage patch)' : ' (floating)') : '' }}">
                                                        {{ $barLabel($shift->starts_at, $shift->ends_at) }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>

                                    @if($needsCaption)
                                        <p class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-[10px] leading-tight">
                                            @foreach($today as $shift)
                                                <span class="whitespace-nowrap" style="color:{{ $roomColour[$shift->classroom] ?? '#64748b' }}"
                                                      title="{{ $shift->classroom }}{{ $shift->isCover() ? ' (cover)' : '' }}">
                                                    {{ $compact($shift->starts_at) }}&ndash;{{ $compact($shift->ends_at) }}
                                                    <span class="opacity-70">{{ \Illuminate\Support\Str::of($shift->classroom)->substr(0, 3) }}</span>
                                                </span>
                                            @endforeach
                                        </p>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

@else

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
                                            {{ $shift->role === StaffShift::ROLE_PATCH ? 'border-dashed' : ($shift->role === StaffShift::ROLE_FLOAT ? 'border-dotted' : '') }}"
                                     style="{{ $place($shift->starts_at, $shift->ends_at) }};background:{{ $roomColour[$room] ?? '#64748b' }}1f;border-color:{{ $roomColour[$room] ?? '#64748b' }};color:{{ $roomColour[$room] ?? '#64748b' }}"
                                 title="{{ $shift->label() }} &middot; {{ $shift->hours() }}h{{ $shift->isCover() ? ($shift->role === StaffShift::ROLE_PATCH ? ' (coverage patch)' : ' (floating)') : '' }}">
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
                                     title="{{ StaffRule::formatTime($band['from']) }}–{{ StaffRule::formatTime($band['to']) }}: {{ $band['have'] }} of {{ $band['need'] }} needed">
                                    {{ $band['have'] }}/{{ $band['need'] }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endforeach

@endif

@endif

@if($week && filled($week->warnings))
    <section class="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/40 dark:bg-amber-500/10">
        <h2 class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ count($week->warnings) }} thing{{ count($week->warnings) === 1 ? '' : 's' }} to look at</h2>
        <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/70">
            The schedule was still saved. These are the constraints it could not satisfy — fix the rule, the staffing, or accept it.
        </p>
        <ul class="mt-3 space-y-1.5 text-sm text-amber-900 dark:text-amber-100">
            @foreach($week->warnings as $warning)
                <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $warning }}</span></li>
            @endforeach
        </ul>
    </section>
@elseif($week)
    <p class="mt-7 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
        Every hard rule and every room ratio is satisfied this week.
    </p>
@endif
@endsection
