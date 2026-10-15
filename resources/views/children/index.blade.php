@extends('layouts.app')
@section('title', 'Children')
@section('content')
{{-- The two screens a family stands in front of. See components/kids-background. --}}
<x-kids-background />

{{-- The page's own state: the search box, and — for the director — the
     archive dialog, the toast with Undo on it, and the chip counts, which are
     corrected in place when a row goes rather than by reloading the roll. --}}
<div x-data="childrenRoll({
        csrf: @js(csrf_token()),
        archivedTab: @js($status === \App\Models\Child::ARCHIVED),
     })"
     @keydown.escape.window="confirming = null">
    @php($nextDirection = fn ($column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc')
    @php($sortUrl = fn ($column) => route('children.index', array_filter(['sort' => $column, 'direction' => $nextDirection($column), 'status' => $status])))
    {{-- The arrow the register uses: quieter than the heading it sits beside,
         because it is the answer to "which way" and not part of the name of
         the column. --}}
    @php($arrow = fn ($column) => $sort === $column
        ? '<span class="ml-1 text-[0.7333rem] leading-none opacity-60">'.($direction === 'asc' ? '↑' : '↓').'</span>'
        : '')

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
                   data-count="all"
                   @if($status === '') aria-current="true" @endif>
                    All <span class="ml-0.5 opacity-60">{{ $rollCount }}</span>
                </a>
                {{-- The roll's statuses only. Archived is not on the roll: it
                     has its own tab below, and only when the setting asks. --}}
                @foreach(\App\Models\Child::ROLL_STATUSES as $option)
                    @continue(($statusCounts[$option] ?? 0) === 0)
                    <a href="{{ route('children.index', array_filter(['sort' => $sort, 'direction' => $direction, 'status' => $option])) }}"
                       class="{{ $chip }} {{ $status === $option ? $chipOn : $chipOff }}"
                       data-count="{{ $option }}"
                       @if($status === $option) aria-current="true" @endif>
                        {{ $option }} <span class="ml-0.5 opacity-60">{{ $statusCounts[$option] }}</span>
                    </a>
                @endforeach
                @if($showArchived)
                    {{-- Off the roll, so its count stands apart from the others
                         and is never added into All. See Settings › Children. --}}
                    <a href="{{ route('children.index', array_filter(['sort' => $sort, 'direction' => $direction, 'status' => \App\Models\Child::ARCHIVED])) }}"
                       class="{{ $chip }} {{ $status === \App\Models\Child::ARCHIVED ? $chipOn : $chipOff }}"
                       data-count="Archived" data-keep
                       @if($status === \App\Models\Child::ARCHIVED) aria-current="true" @endif>
                        Archived <span class="ml-0.5 opacity-60">{{ $archivedCount }}</span>
                    </a>
                @endif
            </div>

            <div class="ml-auto flex items-center gap-1.5">
                <label class="relative hidden sm:block">
                    <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
                    <input x-ref="childrenSearch" x-model="search" @keydown.escape="search = ''" class="w-44 rounded-lg border border-slate-200 bg-white py-1 pl-8 pr-7 text-xs transition focus:w-56 focus:ring-2 focus:ring-indigo-500 lg:w-56 dark:border-white/10 dark:bg-slate-800" placeholder="Search children">
                    {{-- The way out of a search: one press, rather than backspacing a
                         name away. Only there while there is something to clear. A
                         bare ×, no disc behind it. --}}
                    <button type="button" x-show="search" x-cloak @click="search = ''; $refs.childrenSearch?.focus()"
                            class="absolute right-1.5 top-1/2 grid h-5 w-5 -translate-y-1/2 place-items-center text-base leading-none text-slate-400 transition hover:text-slate-700 dark:hover:text-slate-200"
                            aria-label="Clear search">×</button>

                </label>
                {{-- The roll as a spreadsheet.

                     Carries the filter and the sort with it, because it is
                     built from the same query as the table below — so the
                     chip that says "Active 62" is what the file holds, and a
                     teacher's copy is their own rooms. The link keeps the
                     current query string for exactly that reason.

                     Not behind the admin check beside it: reading the roll and
                     taking a copy of what you are already reading are the same
                     permission. Adding a child is not. --}}
                {{-- The timestamp is a cache-buster, never read: a browser that kept an
                     earlier download must see a new address, not a reason to reuse. --}}
                {{-- Not offered on the Archived tab: the export is the roll, and
                     the archived are off it. --}}
                @if($status !== \App\Models\Child::ARCHIVED)
                <a href="{{ route('children.export', request()->only('sort', 'direction', 'status') + ['t' => now()->timestamp]) }}"
                   class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10"
                   title="Download this list as an Excel file">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>
                    </svg>
                    Export
                </a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('children.create') }}" class="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-indigo-700">+ Add child</a>
                @endif
            </div>
        </div>

        {{-- The search box is dropped below sm on the line above, so on a phone
             it takes the full width here instead of being missing. --}}
        <div class="mt-2.5 border-t border-slate-200/70 pt-2.5 sm:hidden dark:border-white/10">
            <label class="relative block">
                <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
                <input x-ref="childrenSearchPhone" x-model="search" @keydown.escape="search = ''" class="w-full rounded-lg border border-slate-200 bg-white py-1.5 pl-8 pr-7 text-xs transition focus:ring-2 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800" placeholder="Search children">
                {{-- The way out of a search: one press, rather than backspacing a
                     name away. Only there while there is something to clear. A
                     bare ×, no disc behind it. --}}
                <button type="button" x-show="search" x-cloak @click="search = ''; $refs.childrenSearchPhone?.focus()"
                        class="absolute right-1.5 top-1/2 grid h-5 w-5 -translate-y-1/2 place-items-center text-base leading-none text-slate-400 transition hover:text-slate-700 dark:hover:text-slate-200"
                        aria-label="Clear search">×</button>
            </label>
        </div>
    </div>

    @if(session('success'))<div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif

    @if($status === \App\Models\Child::ARCHIVED)
        {{-- Said once, above the drawer, because the chips a few lines up
             have just been read and none of them includes these children. --}}
        <p class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
            Archived children are not counted in All, Active or Inactive and don't appear in search or Export.
        </p>
    @endif

    {{-- The same table rhythm as the attendance grid: a quiet header row, rows
         two and a half lines tall rather than four, dates in tabular figures so
         a column of them lines up, and the sort arrow on the column it sorts. --}}
    <section class="glass-card mt-3 overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                        @php($head = 'px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400')
                                                <th scope="col" class="{{ $head }} sticky left-0 z-20 w-[4rem] bg-slate-50 dark:bg-slate-900"><a href="{{ $sortUrl('lan') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">LAN{!! $arrow('lan') !!}</a></th>
                        <th scope="col" class="{{ $head }} sticky left-[4rem] z-20 bg-slate-50 dark:bg-slate-900"><a href="{{ $sortUrl('last_name') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Student{!! $arrow('last_name') !!}</a></th>
                        {{-- Third, as on the attendance sheet. The two tables list
                             the same children and are read one after the other, so a
                             column that sits in a different place on each is one the
                             eye has to hunt for every time it changes screen. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('classroom') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Classroom{!! $arrow('classroom') !!}</a></th>
                        {{-- Sorting is by date of birth either way, so the arrow sits
                             on the DOB column and the age beside it follows it. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('dob') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Date of birth, month/day/year — earliest first">DOB{!! $arrow('dob') !!}</a></th>
                        {{-- Age is the date the other way up: youngest first. Two
                             columns, two sorts, so neither reads backwards. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('age') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Youngest first">Age{!! $arrow('age') !!}</a></th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('schedule') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Earliest drop-off first; no hours on record at the foot">Schedule{!! $arrow('schedule') !!}</a></th>
                        {{-- Before Status, because it is the column that changes
                             what a teacher does this morning and Status is the one
                             that says whether they are here at all. --}}
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('alerts') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300" title="Children with an alert first">Alerts{!! $arrow('alerts') !!}</a></th>
                        <th scope="col" class="{{ $head }}"><a href="{{ $sortUrl('status') }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">Status{!! $arrow('status') !!}</a></th>
                        <th scope="col" class="{{ $head }} text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($children as $child)
                        <tr x-show="!gone.includes(@js($child->lan)) && @js(strtolower($child->first_name.' '.$child->last_name.' '.$child->classroom.' '.$child->lan)).includes(search.toLowerCase())" class="transition hover:bg-slate-50 dark:hover:bg-white/5">
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
                                    // A shade darker than Inactive's grey: off the roll, not merely away.
                                    'Archived' => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
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
                                <div class="inline-flex items-center justify-end gap-1">
                                    <a href="{{ route('children.edit', $child) }}" class="inline-flex min-h-[44px] items-center rounded-lg px-2.5 text-xs font-semibold text-indigo-600 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-indigo-400">Edit</a>
                                    @if(auth()->user()->isAdmin())
                                        @php($childName = $child->first_name.' '.$child->last_name)
                                        @if($child->isArchived())
                                            <button type="button"
                                                    @click="unarchive({ lan: @js($child->lan), name: @js($childName), url: @js(route('children.unarchive', $child)) })"
                                                    class="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg bg-indigo-600 px-3 text-xs font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                                    aria-label="Unarchive {{ $childName }}">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                                Unarchive
                                            </button>
                                        @else
                                            {{-- Opens the dialog below; nothing is archived
                                                 until "Archive child" is pressed there. --}}
                                            <button type="button"
                                                    @click="confirm({ lan: @js($child->lan), name: @js($childName), url: @js(route('children.archive', $child)), undo: @js(route('children.unarchive', $child)) })"
                                                    class="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white"
                                                    aria-label="Archive {{ $childName }}">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7.5V19a2 2 0 01-2 2H6a2 2 0 01-2-2V7.5M3 4h18a0 0 0 010 0v3.5H3V4a0 0 0 010 0zM10 12h4"/></svg>
                                                Archive
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-12 text-center text-sm text-slate-500">{{ $status === '' ? 'No children have been added yet.' : ($status === \App\Models\Child::ARCHIVED ? 'No archived children.' : 'Nobody on the roll is '.strtolower($status).' right now.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if(auth()->user()->isAdmin())
        {{-- The question before a child goes. Says where they go and that it
             is reversible, so the one press that hides a record is never the
             one that is taken on faith. --}}
        <div x-show="confirming" x-cloak
             class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center"
             @click.self="confirming = null">
            <div role="dialog" aria-modal="true" aria-labelledby="archive-dialog-title" aria-describedby="archive-dialog-body"
                 class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-white/10 dark:bg-slate-900"
                 x-init="$watch('confirming', value => { if (value) $nextTick(() => $refs.archiveConfirm?.focus()) })">
                <h2 id="archive-dialog-title" class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">Archive <span x-text="confirming?.name"></span>?</h2>
                <p id="archive-dialog-body" class="mt-2 text-sm text-slate-600 dark:text-slate-300">They'll be hidden from the Children list, search, Export and the All / Active / Inactive counts. Their records are kept, and you can restore them anytime from Settings › Children.</p>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" @click="confirming = null"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">Cancel</button>
                    <button type="button" x-ref="archiveConfirm" @click="archive()" :disabled="busy"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Archive child</button>
                </div>
            </div>
        </div>

        {{-- What just happened, and the way back. Stays until dismissed or
             until it has had a fair while to be read; Undo is on it because
             a row that vanished is the kind of thing somebody wants to take
             back in the next few seconds, not from another screen. --}}
        <div x-show="toast" x-cloak role="status" aria-live="polite"
             class="fixed bottom-6 left-1/2 z-50 flex w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-white dark:text-slate-900">
            <span class="min-w-0 flex-1" x-text="toast?.message"></span>
            <button type="button" x-show="toast?.undo" @click="undo()" :disabled="busy"
                    class="inline-flex min-h-[44px] shrink-0 items-center rounded-lg px-3 text-sm font-bold text-indigo-300 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 disabled:opacity-60 dark:text-indigo-600 dark:hover:text-indigo-800">Undo</button>
            <button type="button" @click="toast = null" aria-label="Dismiss"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-xl leading-none text-white/70 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 dark:text-slate-500 dark:hover:text-slate-900">×</button>
        </div>
    @endif
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('childrenRoll', ({ csrf, archivedTab }) => ({
        search: '',
        // LANs of rows that have gone since the page loaded — archived, or
        // unarchived off the Archived tab. Hidden rather than removed, so
        // Undo can put one straight back.
        gone: [],
        confirming: null,
        toast: null,
        toastTimer: null,
        busy: false,
        archivedTab,

        confirm(child) {
            this.confirming = child;
        },

        async post(url, body = {}) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            if (! response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        },

        async archive() {
            const child = this.confirming;
            if (! child || this.busy) return;

            this.busy = true;
            try {
                const data = await this.post(child.url);
                this.confirming = null;
                this.gone.push(child.lan);
                this.applyCounts(data.counts);
                this.show({ message: data.message, undo: child });
            } catch (error) {
                this.confirming = null;
                this.show({ message: 'Could not archive ' + child.name + '. Please try again.' });
            } finally {
                this.busy = false;
            }
        },

        // Undo on the toast: back as they were, not merely back as Inactive.
        async undo() {
            const child = this.toast?.undo;
            if (! child || this.busy) return;

            this.busy = true;
            try {
                const data = await this.post(child.undo, { to: 'previous' });
                this.gone = this.gone.filter(lan => lan !== child.lan);
                this.applyCounts(data.counts);
                this.show({ message: data.message });
            } catch (error) {
                this.show({ message: 'Could not restore ' + child.name + '. Please try again.', undo: child });
            } finally {
                this.busy = false;
            }
        },

        // Unarchive off the Archived tab: back on the roll as Inactive.
        async unarchive(child) {
            if (this.busy) return;

            this.busy = true;
            try {
                const data = await this.post(child.url);
                this.gone.push(child.lan);
                this.applyCounts(data.counts);
                this.show({ message: data.message });
            } catch (error) {
                this.show({ message: 'Could not unarchive ' + child.name + '. Please try again.' });
            } finally {
                this.busy = false;
            }
        },

        show(toast) {
            this.toast = toast;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast = null; }, 12000);
        },

        /*
         * The chips, corrected in place. Each carries data-count naming what
         * it counts; the number is the span inside it. A roll chip that falls
         * to nought is hidden, as the server would not have drawn it; the
         * Archived chip (data-keep) stays, because an empty drawer is still
         * the way to the drawer.
         */
        applyCounts(counts) {
            if (! counts) return;

            this.$root.querySelectorAll('[data-count]').forEach(chip => {
                const key = chip.dataset.count;
                const value = key === 'all' ? counts.all : (key === 'Archived' ? counts.archived : (counts.byStatus?.[key] ?? 0));
                const number = chip.querySelector('span');
                if (number) number.textContent = value;
                if (! chip.hasAttribute('data-keep')) chip.classList.toggle('hidden', value === 0);
            });
        },
    }));
});
</script>
@endsection
