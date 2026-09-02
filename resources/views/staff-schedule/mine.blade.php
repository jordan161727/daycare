@extends('layouts.app')

@section('title', 'My Schedule')

@php
    use App\Models\StaffRule;
    use App\Models\StaffShift;

    $monday = \Illuminate\Support\Carbon::parse($weekStart);
    $hours = round($minutes / 60, 2);

    // Worked out here rather than inline further down: Blade mis-compiles an
    // an inline php directive whose expression carries nested parentheses, and round() inside
    // a subtraction is exactly that shape.
    $difference = $expected > 0 ? round($hours - $expected, 2) : 0.0;
    $progress = $expected > 0 ? min(100, round($hours / $expected * 100)) : 0;

    // The operating day, used as the scale every shift bar is drawn against —
    // so a 7am start and a 10am start are different lengths of empty space,
    // not two identical bars with different captions.
    $dayOpen = (int) config('daycare.open');
    $dayClose = (int) config('daycare.close');
    $dayLength = max(1, $dayClose - $dayOpen);

    // One hue per room, the same hues the full roster uses, so a bar somebody
    // recognises from the wall printout is the same colour here.
    $palette = ['#2c6e63', '#3d6ea5', '#8a5a9e', '#b3452e', '#4f7a3a', '#a06a2c'];
    $roomColour = collect(\App\Services\ClassroomAssignment::rooms())
        ->mapWithKeys(fn ($room, $i) => [$room => $palette[$i % count($palette)]]);
@endphp

@section('content')
<div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
    <x-page-header
        title="My Schedule"
        :subtitle="$staff->name.' · '.$monday->format('D M j').' – '.$monday->copy()->addDays(4)->format('D M j, Y')" />

    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('staff-schedule.mine', ['week' => $monday->copy()->subWeek()->toDateString()]) }}"
           class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">&larr; Last week</a>
        <a href="{{ route('staff-schedule.mine') }}"
           class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">This week</a>
        <a href="{{ route('staff-schedule.mine', ['week' => $monday->copy()->addWeek()->toDateString()]) }}"
           class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:border-white/10 dark:hover:bg-slate-800">Next week &rarr;</a>

        {{-- The whole floor is one click away, not hidden: knowing who else is
             on at 3pm is the reason the roster is readable by everybody. --}}
        <a href="{{ route('staff-schedule.index', ['week' => $weekStart]) }}"
           class="rounded-xl px-4 py-2.5 text-sm font-semibold text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/10">Everyone's week</a>
    </div>
</div>

{{-- The question this page is opened to answer, answered before anything else
     on it. A teacher checking their phone in a corridor wants "you are on at
     seven, Toddler" — not a week they have to scan for today's card. --}}
@if($nextShift)
    {{-- A block, not the inline php directive — Blade matches a raw PHP block by scanning
         lazily for the next @endphp, so an inline one sitting above a later
         block gets swallowed along with everything between them. --}}
    @php
        $nextDate = $nextShift->shift_date;
        $nextIsToday = $nextDate->isToday();
        $nextTone = $roomColour[$nextShift->classroom] ?? '#64748b';
    @endphp
    <section class="mt-7 overflow-hidden rounded-2xl text-white shadow-lg" style="background:{{ $nextTone }}">
        <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-5">
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-wider text-white/70">
                    @if($nextIsToday) On today @elseif($nextDate->isTomorrow()) On tomorrow @else Next shift @endif
                </p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ $nextShift->label() }}</p>
                <p class="mt-0.5 text-sm text-white/85">
                    {{ $nextIsToday ? $nextDate->format('l j M') : $nextDate->format('l j M') }} · {{ $nextShift->classroom }}
                    @if($nextShift->isCover())
                        · {{ $nextShift->role === StaffShift::ROLE_PATCH ? 'covering' : 'floating' }}
                    @endif
                </p>
            </div>
            <span class="shrink-0 rounded-xl bg-white/15 px-4 py-2.5 text-sm font-bold tabular-nums ring-1 ring-white/25">{{ $nextShift->hours() }} h</span>
        </div>
    </section>
@endif

<section class="glass-card mt-5 rounded-2xl p-6">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <div>
            <span class="text-4xl font-bold tabular-nums">{{ $hours }}</span>
            <span class="text-lg font-semibold text-slate-500">h</span>
            <span class="ml-1 text-sm text-slate-500">scheduled this week</span>
        </div>

        @if($expected > 0)
            <span class="rounded-full px-3 py-1 text-xs font-bold
                {{ abs($difference) < 0.01 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200'
                   : ($difference < 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200'
                   : 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200') }}">
                @if(abs($difference) < 0.01)
                    exactly your {{ $expected }} h
                @else
                    {{ $difference < 0 ? abs($difference).' h under' : $difference.' h over' }} your {{ $expected }} h
                @endif
            </span>
        @endif
    </div>

    {{-- The same bar the dashboard uses for attendance, for the same reason:
         a number against a target reads faster as a length than as arithmetic
         the reader has to do themselves. --}}
    @if($expected > 0)
        <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
            <div class="h-full rounded-full transition-all duration-700
                {{ abs($difference) < 0.01 ? 'bg-emerald-500' : ($difference < 0 ? 'bg-amber-500' : 'bg-sky-500') }}"
                 style="width: {{ $progress }}%"></div>
        </div>
    @endif

    @if($week)
        <p class="mt-3 text-xs text-slate-500">
            Built {{ $week->generated_at?->diffForHumans() }}@if($week->generatedBy) by {{ $week->generatedBy->name }}@endif.
            Ask the director if something here is wrong — shifts are not editable from this screen.
        </p>
    @endif
</section>

@if($byDay->isEmpty() && $leave === [] && $closures->isEmpty())
    <div class="glass-card mt-5 rounded-2xl px-6 py-16 text-center">
        <p class="text-sm text-slate-500">
            You are not scheduled for any shift this week.
            @if(! $week) The week has not been built yet — it will appear here once the director generates it. @endif
        </p>
    </div>
@else
    {{-- A card per day rather than a gantt row. This screen is read standing
         up on a phone, where "Tue 19 Aug — 7:00 am to 3:30 pm, Toddler" beats
         any chart you have to measure with your eye.

         Five across on a wide screen, so the week reads as a week instead of
         wrapping three-and-two. --}}
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach($dates as $dayCode => $date)
            @php
                $shifts = ($byDay[$dayCode] ?? collect())->sortBy('starts_at');
                $dayMinutes = $shifts->sum(fn (StaffShift $shift) => $shift->minutes());
                $isToday = $date->isToday();
                $away = $leave[$date->toDateString()] ?? null;
                $closed = $closures[$date->toDateString()] ?? null;
            @endphp

            <section class="glass-card flex flex-col rounded-2xl p-5 {{ $isToday ? 'ring-2 ring-indigo-500' : '' }} {{ $closed ? 'opacity-75' : '' }}">
                <header class="flex items-baseline justify-between gap-3">
                    <h2 class="text-sm font-bold">
                        {{ $date->format('l') }}
                        <span class="ml-1 font-normal text-slate-400">{{ $date->format('j M') }}</span>
                        @if($isToday)<span class="ml-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200">Today</span>@endif
                    </h2>
                    @if($dayMinutes > 0)
                        <span class="shrink-0 text-xs font-semibold tabular-nums text-slate-500">{{ round($dayMinutes / 60, 2) }} h</span>
                    @endif
                </header>

                {{-- A day the centre is shut. Said outright, because the roster
                     is never built for one — so without this the day arrives
                     here as a blank card reading "Not scheduled", which is true
                     of a holiday and of a day you were simply not needed, and
                     those are not the same news. --}}
                @if($closed)
                    <div class="mt-3 rounded-xl border-l-4 border-rose-500 bg-rose-50/70 px-3 py-2.5 dark:bg-rose-500/10">
                        <p class="text-sm font-bold text-rose-800 dark:text-rose-200">Centre closed</p>
                        <p class="mt-0.5 text-xs text-rose-700 dark:text-rose-300">{{ $closed }}</p>
                    </div>
                @endif

                {{-- Approved leave, said before the shifts. A day off you
                     booked and a day nobody rostered you for look identical
                     from an empty card, and only one of them is yours. --}}
                @if($away)
                    <div class="mt-3 rounded-xl border-l-4 border-emerald-500 bg-emerald-50/70 px-3 py-2.5 dark:bg-emerald-500/10">
                        <p class="text-base font-bold text-emerald-800 dark:text-emerald-200">{{ $away->label() }}</p>
                        <p class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                            {{ $away->hours_per_day }}h approved
                            @if($away->reviewer) by {{ $away->reviewer->name }} @endif
                        </p>
                    </div>
                @endif

                @forelse($shifts as $shift)
                    @php
                        $tone = $roomColour[$shift->classroom] ?? '#64748b';

                        // Where the shift sits in the operating day, drawn to
                        // scale. An early and a late are two different pictures
                        // at a glance; as text they are two similar lines that
                        // have to be read and compared.
                        //
                        // A block, for both reasons this file already knows
                        // about: an inline php directive mis-compiles when its
                        // expression carries nested parentheses, and min()
                        // inside max() is exactly that shape.
                        $offset = max(0, min(100, ($shift->starts_at - $dayOpen) / $dayLength * 100));
                        $span = max(3, min(100 - $offset, $shift->minutes() / $dayLength * 100));
                    @endphp
                    <div class="mt-3 rounded-xl border-l-4 bg-slate-50/70 px-3 py-2.5 dark:bg-white/5"
                         style="border-color:{{ $tone }}">
                        <p class="text-base font-bold tabular-nums">{{ $shift->label() }}</p>
                        <p class="mt-0.5 text-xs font-semibold" style="color:{{ $tone }}">{{ $shift->classroom }}</p>

                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200/80 dark:bg-white/10"
                             title="{{ StaffRule::formatTime($dayOpen) }} – {{ StaffRule::formatTime($dayClose) }}">
                            <div class="h-full rounded-full" style="margin-left:{{ $offset }}%;width:{{ $span }}%;background:{{ $tone }}"></div>
                        </div>

                        @if($shift->isCover())
                            <p class="mt-1.5 text-[11px] text-slate-500">
                                {{ $shift->role === StaffShift::ROLE_PATCH
                                    ? 'Covering — you are here to keep the room in ratio.'
                                    : 'Floating — pulled off your usual room for this stretch.' }}
                            </p>
                        @endif
                    </div>
                @empty
                    @unless($away || $closed)
                        <p class="mt-3 text-sm text-slate-400">Not scheduled.</p>
                    @endunless
                @endforelse
            </section>
        @endforeach
    </div>
@endif

<p class="mt-6 text-xs text-slate-500 dark:text-slate-400">
    These are the hours you are rostered for, not the hours you are paid for —
    what you are paid comes from the time clock. If the two disagree, the clock wins and a supervisor sorts it out.
</p>
@endsection
