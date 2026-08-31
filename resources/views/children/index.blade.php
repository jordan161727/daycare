@extends('layouts.app')
@section('title', 'Children')
@section('content')
<div x-data="{ search: '' }">
    @php($nextDirection = fn ($column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc')
    @php($sortUrl = fn ($column) => route('children.index', ['sort' => $column, 'direction' => $nextDirection($column)]))
    @php($arrow = fn ($column) => $sort === $column ? ($direction === 'asc' ? ' ↑' : ' ↓') : '')

    {{-- One line, the same shape as the attendance sheet's: what page this is,
         how the roll stands, and the two controls used on every visit. --}}
    <div class="glass-card rounded-2xl px-3 py-2.5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h1 class="text-base font-bold tracking-tight sm:text-lg">{{ auth()->user()->isAdmin() ? 'Children' : 'My Students' }}</h1>

            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                <span><b class="font-bold text-slate-900 dark:text-white">{{ $children->total() }}</b> on the roll</span>
                <span><b class="font-bold text-emerald-600 dark:text-emerald-400">{{ $activeCount }}</b> active</span>
            </p>

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
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('lan') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">LAN{{ $arrow('lan') }}</a></th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('last_name') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Student{{ $arrow('last_name') }}</a></th>
                        {{-- Sorting is by date of birth either way, so the arrow sits
                             on the DOB column and the age beside it follows it. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('age') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Date of birth, year/month/day">DOB{{ $arrow('age') }}</a></th>
                        <th scope="col" class="{{ $head }}">Age</th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('classroom') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Classroom{{ $arrow('classroom') }}</a></th>
                        <th scope="col" class="{{ $head }}">Schedule</th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('status') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Status{{ $arrow('status') }}</a></th>
                        <th scope="col" class="{{ $head }} text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @forelse($children as $child)
                        <tr x-show="@js(strtolower($child->first_name.' '.$child->last_name.' '.$child->classroom.' '.$child->lan)).includes(search.toLowerCase())" class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                            <td class="px-3 py-2 text-sm tabular-nums text-slate-400 dark:text-slate-500">{{ $child->lan }}</td>
                            <td class="px-3 py-2">
                                {{-- Name and room read as one line on the sheet; here the
                                     room has a column of its own, so the name stands alone. --}}
                                <div class="flex items-center gap-3">
                                    <x-child-avatar :child="$child" />
                                    <a href="{{ route('children.show', $child) }}" class="truncate text-sm font-semibold text-slate-800 underline-offset-2 hover:text-indigo-600 hover:underline dark:text-slate-100">{{ $child->first_name }} {{ $child->last_name }}</a>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ $child->ageLabel() ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-sm text-slate-500 dark:text-slate-400">{{ $child->ageInWords() ?? '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-sm">{{ $child->classroom }}</td>
                            <td class="px-3 py-2 text-sm">@include('children.partials.schedule')</td>
                            <td class="px-3 py-2">
                                <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $child->status === 'Active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">{{ $child->status }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                @if(auth()->user()->isAdmin())
                                    <a href="{{ route('children.edit', $child) }}" class="text-xs font-semibold text-indigo-600 transition hover:text-indigo-800 dark:text-indigo-400">Edit</a>
                                @else
                                    <a href="{{ route('children.show', $child) }}" class="text-xs font-semibold text-indigo-600 transition hover:text-indigo-800 dark:text-indigo-400">View</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-12 text-center text-sm text-slate-500">No children have been added yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($children->hasPages())
            <div class="border-t border-slate-200/70 px-3 py-2 dark:border-white/10">{{ $children->links() }}</div>
        @endif
    </section>
</div>
@endsection
