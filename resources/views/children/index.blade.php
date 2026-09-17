@extends('layouts.app')
@section('title', 'Children')
@section('content')
{{-- The two screens a family stands in front of. See components/kids-background. --}}
<x-kids-background />

<div x-data="{ search: '' }">
    @php($nextDirection = fn ($column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc')
    @php($sortUrl = fn ($column) => route('children.index', array_filter(['sort' => $column, 'direction' => $nextDirection($column), 'status' => $status])))
    @php($arrow = fn ($column) => $sort === $column ? ($direction === 'asc' ? ' ↑' : ' ↓') : '')

    {{-- One line, the same shape as the attendance sheet's: what page this is,
         how the roll stands, and the two controls used on every visit. --}}
    <div class="glass-card rounded-2xl px-3 py-2.5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1 class="text-base font-bold tracking-tight sm:text-lg">{{ auth()->user()->isAdmin() ? 'Children' : 'My Students' }}</h1>

            {{-- The roll by status, as chips: a count apiece so it can be read
                 without pressing anything, and a press to see only those.

                 The same shape the attendance sheet filters its rooms with, and
                 in the same place, because the two pages list the same children
                 and are read one after the other.

                 A status nobody is in is not offered — an "Inactive 0" chip is
                 a control that does nothing, and the centre's first year has no
                 leavers in it at all. --}}
            @php($chip = 'shrink-0 rounded-full px-3 py-1 text-xs font-semibold transition')
            @php($chipOn = 'bg-slate-900 text-white dark:bg-white dark:text-slate-900')
            @php($chipOff = 'border border-slate-200 text-slate-600 hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10')
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('children.index', array_filter(['sort' => $sort, 'direction' => $direction])) }}"
                   class="{{ $chip }} {{ $status === '' ? $chipOn : $chipOff }}"
                   @if($status === '') aria-current="true" @endif>
                    All <span class="ml-0.5 opacity-60">{{ $rollCount }}</span>
                </a>
                @foreach(\App\Models\Child::STATUSES as $option)
                    @continue(($statusCounts[$option] ?? 0) === 0)
                    <a href="{{ route('children.index', array_filter(['sort' => $sort, 'direction' => $direction, 'status' => $option])) }}"
                       class="{{ $chip }} {{ $status === $option ? $chipOn : $chipOff }}"
                       @if($status === $option) aria-current="true" @endif>
                        {{ $option }} <span class="ml-0.5 opacity-60">{{ $statusCounts[$option] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="ml-auto flex items-center gap-1.5">
                <label class="relative hidden sm:block">
                    <svg class="pointer-events-none absolute left-2.5 top-1.5 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                    <input x-model="search" class="w-44 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-3 text-xs transition focus:w-56 focus:ring-2 focus:ring-indigo-500 lg:w-56 dark:border-white/10 dark:bg-slate-800" placeholder="Search children">
                </label>
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('children.create') }}" class="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-indigo-700">+ Add child</a>
                @endif
            </div>
        </div>

        {{-- The search box is dropped below sm on the line above, so on a phone
             it takes the full width here instead of being missing. --}}
        <div class="mt-2.5 border-t border-slate-200/70 pt-2.5 sm:hidden dark:border-white/10">
            <input x-model="search" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-white/10 dark:bg-slate-800" placeholder="Search children">
        </div>
    </div>

    @if(session('success'))<div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif

    {{-- The same table rhythm as the attendance grid: a quiet header row, rows
         two and a half lines tall rather than four, dates in tabular figures so
         a column of them lines up, and the sort arrow on the column it sorts. --}}
    <section class="glass-card mt-3 overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                        @php($head = 'px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400')
                                                <th scope="col" class="{{ $head }} sticky left-0 z-20 w-[4rem] bg-slate-50 dark:bg-slate-900"><a href="{{ $sortUrl('lan') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">LAN{{ $arrow('lan') }}</a></th>
                        <th scope="col" class="{{ $head }} sticky left-[4rem] z-20 bg-slate-50 dark:bg-slate-900"><a href="{{ $sortUrl('last_name') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Student{{ $arrow('last_name') }}</a></th>
                        {{-- Third, as on the attendance sheet. The two tables list
                             the same children and are read one after the other, so a
                             column that sits in a different place on each is one the
                             eye has to hunt for every time it changes screen. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('classroom') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Classroom{{ $arrow('classroom') }}</a></th>
                        {{-- Sorting is by date of birth either way, so the arrow sits
                             on the DOB column and the age beside it follows it. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('age') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Date of birth, year/month/day">DOB{{ $arrow('age') }}</a></th>
                        <th scope="col" class="{{ $head }}">Age</th>
                        <th scope="col" class="{{ $head }}">Schedule</th>
                        {{-- Before Status, because it is the column that changes
                             what a teacher does this morning and Status is the one
                             that says whether they are here at all. --}}
                        <th scope="col" class="{{ $head }}">Alerts</th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('status') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Status{{ $arrow('status') }}</a></th>
                        <th scope="col" class="{{ $head }} text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($children as $child)
                        <tr x-show="@js(strtolower($child->first_name.' '.$child->last_name.' '.$child->classroom.' '.$child->lan)).includes(search.toLowerCase())" class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                                        {{-- Frozen against a sideways scroll: on a tablet the roll is
                                 wider than the screen, and a row read with the name
                                 off-screen is a row about nobody. --}}
                            <td class="sticky left-0 z-10 w-[4rem] bg-white px-3 py-2 text-sm tabular-nums text-slate-400 dark:bg-night-900 dark:text-slate-500">{{ $child->lan }}</td>
                            <td class="sticky left-[4rem] z-10 bg-white px-3 py-2 dark:bg-night-900">
                                {{-- The same avatar and name the attendance sheet draws,
                                     in the same classes, so the two tables cannot drift.

                                     The room is not repeated under the name here: it has
                                     a column of its own a few pixels to the right, and a
                                     list read straight down does not need the same fact
                                     twice in one row. The sheet says it under the name
                                     because a row there is eleven columns wide and the
                                     room would be off to the left of wherever the eye
                                     has got to. --}}
                                <div class="att-person">
                                    <span class="att-avatar"><x-child-avatar :child="$child" /></span>
                                    <a href="{{ route('children.show', $child) }}" class="att-name truncate underline-offset-2 hover:text-indigo-600 hover:underline dark:hover:text-indigo-300">{{ $child->displayName() }}</a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-sm"><x-room-icon :room="$child->classroom" size="text-sm" /> {{ $child->classroom }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ $child->ageLabel() ?? '—' }}</td>
                            {{-- Lining figures: "0y 10m" under "3y 2m" only
                                 compares at a glance if the digits are the same
                                 width down the column. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ $child->ageInWords() ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm">@include('children.partials.schedule')</td>
                            {{-- One under another rather than in a row: two chips
                                 side by side make a line of text to be read, and
                                 these are meant to be counted and their colours
                                 taken in without reading. --}}
                            <td class="px-3 py-2">
                                @forelse($child->alertList() as $alert)
                                    <span class="mb-1 mr-1 inline-flex max-w-[16rem] items-center gap-1.5 truncate rounded-full px-2 py-0.5 text-[0.7333rem] font-semibold {{ $alert['classes'] }}" title="{{ $alert['label'] }}: {{ $alert['text'] }}">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-60" aria-hidden="true"></span>
                                        <span class="truncate">{{ $alert['label'] }}: {{ $alert['text'] }}</span>
                                    </span>
                                @empty
                                    <span class="text-sm text-slate-300 dark:text-slate-600">&mdash;</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-2">
                                @php($statusClasses = [
                                    'Active' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
                                    'Pending' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
                                ])
                                {{-- Pending is amber because it is a place held
                                     rather than a place taken: not here yet, and
                                     not the grey of somebody who has left. --}}
                                <span class="rounded-md px-2 py-0.5 text-[0.7333rem] font-semibold {{ $statusClasses[$child->status] ?? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $child->status }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                {{-- The roster is already filtered to what this
                                     reader may see, so every row here is one
                                     they may also keep up to date. --}}
                                <a href="{{ route('children.edit', $child) }}" class="text-xs font-semibold text-indigo-600 transition hover:text-indigo-800 dark:text-indigo-400">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-12 text-center text-sm text-slate-500">{{ $status === '' ? 'No children have been added yet.' : 'Nobody on the roll is '.strtolower($status).' right now.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
