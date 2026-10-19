@extends('layouts.app')
@section('title', 'Comments')
@section('content')
{{--
    The comments log, as a feed: every note of a day, across every child.

    Opened on today. The presets pick the day — yesterday, the last school
    day (Friday, on a Monday), this week — or a range is typed; the room,
    the kind of note, who wrote it and a word in it narrow the list. Every
    filter is in the address, so the page reloads to filter and a link to
    a day's log can be sent. The export carries the same filters.

    The notes are grouped by day, newest first, a white card a day and a
    row a note: the child's face and name, their room and LAN, the time,
    the words, then the kind of note and who wrote it. Pressing a row opens
    the child's dialog on their Past comments tab, scrolled to that day
    with the comment lit up for a moment; closing it leaves the page as it
    was, filters and scroll and all, because the dialog is on this page.
--}}
@php($isCustom = $filters['preset'] === 'custom')
@php($oneDay = $filters['from'] === $filters['to'])
@php($byDay = $comments->groupBy(fn ($c) => $c->date?->toDateString() ?? ''))
@php($pill = 'inline-flex min-h-9 items-center gap-1 rounded-full border border-[#CFD3DE] bg-white pl-3 pr-2 text-[13px] font-semibold leading-[18px] text-[#1C2130] transition hover:bg-[#F2F4F9] dark:border-white/10 dark:bg-night-900 dark:text-slate-100 dark:hover:bg-white/10')

<div x-data="commentsLog()" @keydown.escape.window="closeChild(); exportOpen = false">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-[26px] font-bold leading-8 tracking-tight">Comments</h1>

        {{-- The same filters, as a file: a small menu, CSV or PDF. --}}
        <div class="relative" data-comments-export @click.outside="exportOpen = false">
            <button type="button" @click="exportOpen = ! exportOpen" :aria-expanded="exportOpen ? 'true' : 'false'" aria-haspopup="menu"
                    class="inline-flex min-h-11 items-center gap-1.5 rounded-full border border-[#CFD3DE] bg-white px-4 text-sm font-semibold text-[#1C2130] transition hover:bg-[#F2F4F9] dark:border-white/10 dark:bg-night-900 dark:text-slate-100 dark:hover:bg-white/10">
                Export
                <svg class="size-3.5 text-[#5F6676]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div x-show="exportOpen" x-cloak role="menu"
                 class="absolute right-0 z-20 mt-2 w-44 overflow-hidden rounded-xl border border-[#E7E9F0] bg-white py-1 shadow-[0_1px_2px_rgba(20,24,40,.04),0_6px_20px_rgba(20,24,40,.08)] dark:border-white/10 dark:bg-night-800">
                <a role="menuitem" href="{{ route('comments.export', array_filter($filters) + ['format' => 'csv', 't' => now()->timestamp]) }}" class="block px-4 py-2.5 text-sm text-[#1C2130] hover:bg-[#F2F4F9] dark:text-slate-100 dark:hover:bg-white/10">Export CSV</a>
                <a role="menuitem" href="{{ route('comments.export', array_filter($filters) + ['format' => 'pdf', 't' => now()->timestamp]) }}" class="block px-4 py-2.5 text-sm text-[#1C2130] hover:bg-[#F2F4F9] dark:text-slate-100 dark:hover:bg-white/10">Export PDF</a>
            </div>
        </div>
    </div>

    {{-- The filters. One form; a change in any box reloads with it. --}}
    <section class="glass-card mt-4 rounded-[20px] p-3 sm:p-4">
        <form method="get" action="{{ route('comments.index') }}" class="flex flex-col gap-3" data-comments-filters>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-0 flex-1 basis-60">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#8A90A0]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <label class="sr-only" for="comments-search">Search comment text or child name</label>
                    <input id="comments-search" name="q" value="{{ $filters['q'] }}" placeholder="Search comment text or child name"
                           class="min-h-11 w-full rounded-full border border-[#CFD3DE] bg-white py-2 pl-10 pr-4 text-[15px] leading-[22px] text-[#1C2130] placeholder:text-[#8A90A0] focus:border-[#1B5E91] focus:ring-2 focus:ring-[#DCEBFA] dark:border-white/10 dark:bg-night-900 dark:text-slate-100">
                </div>

                {{-- The day: a segmented group of presets, each a radio under
                     the hood so the form posts one. Custom opens two fields. --}}
                <div class="inline-flex max-w-full flex-wrap rounded-full bg-[#F2F4F9] p-1 dark:bg-white/10" role="group" aria-label="Date" data-date-presets>
                    @foreach($presets as $key => $label)
                        <label class="inline-flex min-h-9 cursor-pointer items-center whitespace-nowrap rounded-full px-3 text-[13px] font-semibold leading-[18px] transition {{ $filters['preset'] === $key ? 'bg-[#DCEBFA] text-[#1B5E91] shadow-[0_1px_2px_rgba(20,24,40,.06)] dark:bg-sky-500/25 dark:text-sky-100' : 'text-[#5F6676] hover:text-[#1C2130] dark:text-slate-300 dark:hover:text-white' }}">
                            <input type="radio" name="preset" value="{{ $key }}" class="sr-only" @checked($filters['preset'] === $key)
                                   @if($key !== 'custom') onchange="this.form.requestSubmit()" @else x-on:change="custom = true" @endif>
                            {{ $key === 'custom' ? 'Custom…' : $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div x-show="custom" x-cloak class="flex flex-wrap items-end gap-3" data-custom-range>
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.06em] text-[#5F6676] dark:text-slate-400">From</span>
                    <input type="date" name="from" value="{{ $filters['from'] }}" max="{{ $today }}" class="mt-1 min-h-11 rounded-lg border border-[#CFD3DE] bg-white px-3 text-[15px] text-[#1C2130] dark:border-white/10 dark:bg-night-900 dark:text-slate-100">
                </label>
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.06em] text-[#5F6676] dark:text-slate-400">To</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}" max="{{ $today }}" class="mt-1 min-h-11 rounded-lg border border-[#CFD3DE] bg-white px-3 text-[15px] text-[#1C2130] dark:border-white/10 dark:bg-night-900 dark:text-slate-100">
                </label>
                <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-[#1C2130] px-5 text-sm font-semibold text-white transition hover:bg-black dark:bg-white dark:text-slate-900">Apply</button>
            </div>

            {{-- Who, what kind, written by whom — as small pills, each a
                 select dressed as one; and the count on the right. --}}
            <div class="flex flex-wrap items-center gap-2">
                <label class="{{ $pill }}">
                    <span class="text-[#5F6676] dark:text-slate-400">Class</span>
                    <select name="class" onchange="this.form.requestSubmit()" aria-label="Class" class="min-h-9 cursor-pointer appearance-none border-0 bg-transparent py-0 pl-1 pr-6 text-[13px] font-semibold text-[#1C2130] focus:ring-0 dark:text-slate-100">
                        <option value="">All</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room }}" @selected($filters['class'] === $room)>{{ $room }}</option>
                        @endforeach
                    </select>
                    <svg class="pointer-events-none -ml-6 size-3.5 text-[#5F6676]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </label>
                {{-- No Type box (Jordan, 20 Oct 2026): the door writes plain
                     notes, so a kind to sort by would read "General" on every
                     row. The address still takes ?type= for the export. --}}
                <label class="{{ $pill }}">
                    <span class="text-[#5F6676] dark:text-slate-400">Written by</span>
                    <select name="author" onchange="this.form.requestSubmit()" aria-label="Written by" class="min-h-9 cursor-pointer appearance-none border-0 bg-transparent py-0 pl-1 pr-6 text-[13px] font-semibold text-[#1C2130] focus:ring-0 dark:text-slate-100">
                        <option value="">Anyone</option>
                        @foreach($authors as $author)
                            <option value="{{ $author->id }}" @selected($filters['author'] === $author->id)>{{ $author->name }}</option>
                        @endforeach
                    </select>
                    <svg class="pointer-events-none -ml-6 size-3.5 text-[#5F6676]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </label>

                {{-- "4 comments · 4 children" --}}
                <p class="ml-auto text-[13px] leading-[18px] text-[#5F6676] dark:text-slate-400" data-comments-summary>
                    <span class="sr-only">{{ $when }}: </span>{{ $comments->count() }} {{ Str::plural('comment', $comments->count()) }} · {{ $children }} {{ Str::plural('child', $children) }}
                </p>
            </div>

            <noscript><button type="submit" class="rounded-full bg-[#1C2130] px-5 py-2 text-sm font-semibold text-white">Apply</button></noscript>
        </form>
    </section>

    {{-- The feed: a card a day, a row a note, newest first. --}}
    <div class="mt-5 space-y-6" data-comments-feed>
        @forelse($byDay as $iso => $onDay)
            @php($day = \Illuminate\Support\Carbon::parse($iso))
            @php($relative = $iso === $today ? 'Today' : ($iso === \Illuminate\Support\Carbon::parse($today)->subDay()->toDateString() ? 'Yesterday' : null))
            <section aria-labelledby="day-{{ $iso }}" data-comments-day="{{ $iso }}">
                <h2 id="day-{{ $iso }}" class="px-1 text-xs font-semibold uppercase tracking-[0.06em] text-[#8A90A0] dark:text-slate-500">
                    {{ $relative ? $relative.' · ' : '' }}{{ $day->format('D, M j') }}
                </h2>
                <ul class="mt-2 divide-y divide-[#E7E9F0] rounded-[20px] bg-white shadow-[0_1px_2px_rgba(20,24,40,.04),0_6px_20px_rgba(20,24,40,.04)] dark:divide-white/10 dark:bg-night-900">
                    @foreach($onDay as $comment)
                        @php($child = $comment->child)
                        @php($type = $comment->type())
                        @php($author = $comment->author)
                        @php($byline = ($author?->name ?? 'Staff').' · '.(($comment->author_role ?? $author?->role) === 'teacher' && $author?->classroom ? $author->classroom.' teacher' : \App\Models\AttendanceComment::roleLabel($comment->author_role ?? $author?->role)))
                        <li data-comment-row="{{ $comment->id }}">
                            <div class="flex gap-3 px-4 py-3.5 sm:px-5 {{ $child ? 'cursor-pointer transition hover:bg-[#F6F7FB] dark:hover:bg-white/5' : '' }}"
                                 @if($child) role="button" tabindex="0" data-open-comment
                                 @click="openPast({{ $child->id }}, @js($comment->date?->toDateString()), {{ $comment->id }})"
                                 @keydown.enter.prevent="openPast({{ $child->id }}, @js($comment->date?->toDateString()), {{ $comment->id }})" @endif>
                                <span class="mt-0.5 shrink-0">
                                    @if($child)
                                        <x-child-avatar :child="$child" size="h-9 w-9" />
                                    @else
                                        <span class="grid h-9 w-9 place-items-center rounded-full bg-[#F2F4F9] text-[#8A90A0]">—</span>
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline gap-2">
                                        <p class="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                            @if($child)
                                                <span class="truncate text-[15px] font-semibold leading-[22px] text-[#4C5A9E] dark:text-indigo-300" data-open-child>{{ $child->displayName() }}</span>
                                                <span class="text-[13px] leading-[18px] text-[#8A90A0] dark:text-slate-500">{{ $child->classroom ?: 'Unassigned' }} · {{ $child->lan }}</span>
                                            @else
                                                <span class="text-[15px] font-semibold leading-[22px] text-[#8A90A0]">Child removed</span>
                                            @endif
                                        </p>
                                        <span class="ml-auto inline-flex shrink-0 items-center gap-1 text-[13px] leading-[18px] tabular-nums text-[#5F6676] dark:text-slate-400">
                                            {{ $comment->created_at->timezone(config('app.timezone'))->format('g:i A') }}
                                            @if($child)<svg class="size-3.5 text-[#8A90A0]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>@endif
                                        </span>
                                    </div>
                                    <p class="mt-0.5 whitespace-pre-line text-[15px] leading-[22px] text-[#1C2130] dark:text-slate-100">{{ $comment->body }}</p>
                                    <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-[18px] text-[#5F6676] dark:text-slate-400">
                                        <span>{{ $byline }}</span>
                                        @if($comment->wasEdited())<span class="text-[#8A90A0]">· edited</span>@endif
                                    </p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="rounded-[20px] bg-white px-4 py-12 text-center text-[15px] text-[#5F6676] shadow-[0_1px_2px_rgba(20,24,40,.04),0_6px_20px_rgba(20,24,40,.04)] dark:bg-night-900 dark:text-slate-400" data-comments-empty>
                No comments {{ $oneDay ? 'on '.$when : 'between '.$when }}{{ $filters['q'] !== '' ? ' match “'.$filters['q'].'”' : '' }}.
            </p>
        @endforelse
    </div>

    {{-- The child's dialog: their face, their room, and their comments by
         day. Read-only here — the clock is the door screen's. --}}
    <template x-if="dialogOpen && child">
        <div class="fixed inset-0 z-50 grid place-items-center sm:p-6" role="dialog" aria-modal="true" :aria-labelledby="'child-name-' + child.id">
            <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="closeChild()" aria-hidden="true"></div>
            <div class="glass-card relative flex h-dvh max-h-dvh w-full max-w-none flex-col overflow-y-auto overscroll-contain rounded-none p-5 pt-6 text-center sm:h-auto sm:max-h-[calc(100dvh-3rem)] sm:max-w-[560px] sm:rounded-[20px] sm:p-8" data-card-dialog>
                <button type="button" @click="closeChild()" class="absolute right-4 top-4 grid h-11 w-11 place-items-center rounded-full bg-[#F2F4F9] text-lg text-[#5F6676] hover:bg-[#E7E9F0] dark:bg-white/10 dark:hover:bg-white/20 sm:right-5 sm:top-5" aria-label="Close">×</button>

                <span class="mx-auto block h-28 w-28 shrink-0 overflow-hidden rounded-full outline outline-2 outline-[#E7E9F0] dark:outline-white/10" x-html="(child.avatar || '').replace('h-24 w-24', 'h-28 w-28')"></span>
                <h2 class="mt-4 text-2xl font-bold" :id="'child-name-' + child.id" x-text="child.display"></h2>
                <p class="mt-0.5 text-sm text-[#5F6676] dark:text-slate-400" x-text="child.room + ' · ' + child.lan"></p>

                <div class="mx-auto mt-4 inline-flex rounded-full bg-[#F2F4F9] p-1 dark:bg-white/10" role="tablist" aria-label="Past comments">
                    <span role="tab" aria-selected="true" class="min-h-10 rounded-full bg-white px-4 text-sm font-semibold leading-10 text-[#1C2130] shadow-[0_1px_2px_rgba(20,24,40,.08),0_2px_6px_rgba(20,24,40,.06)] dark:bg-night-800 dark:text-white">Past comments</span>
                </div>

                @include('attendance.partials.past-comments')
            </div>
        </div>
    </template>
</div>

@include('attendance.partials.past-comments-js')

<script>
function commentsLog() { return {
    // The children on the page, by id: name, room, LAN and avatar, so the
    // dialog needs no request for them.
    children: @js($comments->pluck('child')->filter()->unique('id')->mapWithKeys(fn ($child) => [$child->id => [
        'id' => $child->id,
        'display' => $child->displayName(),
        'first_name' => $child->first_name,
        'room' => $child->classroom ?: 'Unassigned',
        'lan' => $child->lan,
        'avatar' => $avatars[$child->id] ?? '',
    ]])),
    custom: @js($isCustom),
    exportOpen: false,
    child: null,
    dialogOpen: false,
    tab: 'past',
    ...pastCommentsMixin({ childrenUrl: @js(url('/children')), today: @js($today) }),

    /** The row's child, on the day of the row's comment, with it lit up. */
    openPast(childId, date, commentId) {
        this.child = this.children[childId] ?? null;
        if (! this.child) return;
        this.resetPast();
        this.dialogOpen = true;
        this.showPast(date, commentId);
    },

    /** Back to the log as it was: the page never left. */
    closeChild() {
        this.dialogOpen = false;
        this.resetPast();
    },
}}
</script>
@endsection
