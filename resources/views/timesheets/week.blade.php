@extends('layouts.app')
@section('title', 'Staff timesheets')
@section('content')
{{--
    The week, read every morning.

    Five days across and one row per person, because the question this answers
    is "is everybody here, and did yesterday go in properly" — which is read
    across a row and down a column, not out of a list.

    It reads and never writes. Every correction goes through the punch screens,
    which record who changed what and why; a grid somebody can type into is a
    grid with no audit behind it.
--}}
@php($fmt = fn ($date) => $date->toDateString())

{{-- The grid reads; the panel writes — through the punch endpoints, which
     record who changed what and why. See timesheetGrid() at the foot. --}}
<div x-data="timesheetGrid()" @keydown.escape.window="close()">

    {{-- relative z-20 is what keeps the date picker on top of the grid.

         .glass-card carries backdrop-blur, and a backdrop-filter creates a
         stacking context — so the panel's own z-40 only ever competed inside
         this card. The table below is a sibling card with the same automatic
         level, and being later in the document it won, painting straight over
         an open calendar. Lifting this card lifts everything in it. --}}
    <section class="glass-card relative z-20 rounded-2xl p-5">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold tracking-tight">Staff Timesheets</h1>

            {{-- Any range, by name or by calendar. The role filter rides along
                 so choosing a period does not quietly drop it. --}}
            <x-date-range
                :from="$start->toDateString()"
                :to="$end->toDateString()"
                :action="route('staff.timesheets')"
                :keep="array_filter(['role' => $role])" />

            {{-- Back to the ordinary answer in one press, from wherever the
                 range has wandered to. --}}
            <a href="{{ route('staff.timesheets', array_filter(['role' => $role])) }}"
               class="rounded-full bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-700 transition hover:bg-sky-200 dark:bg-sky-400/15 dark:text-sky-300 dark:hover:bg-sky-400/25">This week</a>

            {{-- Beside the range rather than over the table, because what it
                 exports is the range: the two belong to each other, and from
                 the table's own header it read as being about the rows.

                 Excel and CSV were two buttons for one idea, and the choice
                 between them is not one a director has an opinion about — the
                 spreadsheet opens either. ?format=csv still works for anyone
                 who does care. The role filter rides along too, so the file is
                 the grid on screen and not some other week. --}}
            <a href="{{ route('staff.timesheets.export', request()->only('from', 'to', 'role') + ['t' => now()->timestamp]) }}"
               class="rounded-full border border-slate-200 px-4 py-2 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Export</a>
        </div>

        @if($truncated)
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                That range is longer than {{ \App\Http\Controllers\StaffTimesheetController::MAX_DAYS }} days. Showing the first {{ \App\Http\Controllers\StaffTimesheetController::MAX_DAYS }}, because a column a day past that is a table nobody can read.
            </p>
        @endif

        {{-- The three numbers about today, and nothing else.

             There was a row of role chips above them. It offered "Admin" and
             "Teacher", which is the account type rather than the job, and the
             Role column repeats it on every row — a filter whose options are
             already visible sixteen times is a row of buttons that only takes
             up the space. ?role= still narrows the page and the export for
             anyone who wants it. --}}
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <p class="ml-auto text-xs text-slate-500 dark:text-slate-400">
                <span class="font-semibold">{{ $counts['staff'] }}</span> staff &middot;
                <span class="font-semibold text-emerald-600">{{ $counts['in'] }}</span> clocked in &middot;
                <span class="font-semibold">{{ $counts['out'] }}</span> clocked out &middot;
                <span class="font-semibold text-slate-400">{{ $counts['not_in'] }}</span> not in
            </p>
        </div>
    </section>

    <section class="glass-card mt-5 overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[64rem] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-[11px] uppercase tracking-wide text-slate-400 dark:border-white/10">
                        <th class="px-4 py-3 font-semibold">ID</th>
                        <th class="px-4 py-3 font-semibold">Staff</th>
                        <th class="px-4 py-3 font-semibold">Role</th>
                        <th class="px-4 py-3 font-semibold">Rate</th>
                        <th class="px-4 py-3 font-semibold">Hours</th>
                        @foreach($dates as $date)
                            {{-- Today's column is tinted the whole way down: it
                                 is the one being punched into and the one a
                                 correction is allowed on. --}}
                            <th class="px-4 py-3 text-center font-semibold {{ $date->isToday() ? 'bg-sky-50 dark:bg-sky-500/10' : '' }}">
                                <span class="block {{ $date->isToday() ? 'text-sky-700 dark:text-sky-300' : '' }}">{{ $date->format('D') }}</span>
                                <span class="block text-[13px] font-bold normal-case {{ $date->isToday() ? 'text-sky-900 dark:text-sky-100' : 'text-slate-700 dark:text-slate-200' }}">{{ $date->format('M j') }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse($rows as $row)
                        <tr class="transition hover:bg-slate-50/60 dark:hover:bg-white/5">
                            <td class="px-4 py-3 tabular-nums text-slate-400">{{ $row['staff_id'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">{{ $row['initials'] }}</span>
                                    <span class="min-w-0">
                                        <a href="{{ route('teachers.show', $row['id']) }}" class="block truncate font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ $row['name'] }}</a>
                                        <span class="block truncate text-[11px] text-slate-400">{{ $row['email'] }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $row['role'] }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-500">{{ filled($row['rate']) ? '$'.$row['rate'].'/hr' : '—' }}</td>
                            <td class="px-4 py-3 font-bold tabular-nums"><span data-hours="{{ $row['id'] }}">{{ $row['hours'] }}h</span></td>

                            @foreach($dates as $date)
                                @php($day = $row['days'][$fmt($date)])
                                <td class="px-3 py-3 text-center {{ $date->isToday() ? 'bg-sky-50/60 dark:bg-sky-500/5' : '' }}"
                                    data-cell="{{ $row['id'] }}|{{ $fmt($date) }}"
                                    :class="isOpen({{ $row['id'] }}, '{{ $fmt($date) }}') ? 'ring-2 ring-inset ring-indigo-400 dark:ring-indigo-500' : ''">
                                    @if($day['empty'])
                                        {{-- Nothing happened. A dot here would
                                             be a judgement about a day off. --}}
                                        <span class="text-slate-300 dark:text-slate-600">·</span>
                                    @else
                                        {{-- One grid for both lines, not two
                                             separately centred rows. Centring
                                             each row on its own put the times
                                             out of line, because IN and OUT are
                                             not the same width and only IN
                                             carries a dot. Shared tracks line
                                             the times up, and the dot has a
                                             column of its own on both rows so
                                             its presence cannot shift them. --}}
                                        {{-- The dot's column is a finger wide, not a dot wide: the
                                             dot stays six pixels, the thing you click is twenty. --}}
                                        <div class="inline-grid grid-cols-[auto_minmax(3.6rem,auto)_1.25rem] items-center gap-x-1 gap-y-0.5 text-[12px] tabular-nums">
                                            <span class="text-[10px] font-bold uppercase text-slate-400">In</span>
                                            <span class="text-right font-semibold">{{ $day['in'] ?? '—' }}</span>
                                            @if($day['status'])
                                                {{-- A day that went wrong leads to the
                                                     screen that puts it right. A day that
                                                     went fine is a dot and nothing more:
                                                     making every cell a link would bury
                                                     the handful that need one. --}}
                                                {{-- Every dot opens the panel. Green and the ring
                                                     open it to review; amber and red open it to put
                                                     the day right, and keep their link so a page
                                                     with no script still leads somewhere.
                                                     Painted again by cellHtml() below after a save,
                                                     so the two have to agree. --}}
                                                {{-- Links, not buttons, all four: the grid holds
                                                     nothing that edits in place, only ways through
                                                     to the screens that record who and why. --}}
                                                @if($day['status'] === \App\Http\Controllers\StaffTimesheetController::ON_TIME)
                                                    <a href="{{ route('timesheets.fix', ['user' => $row['id'], 'date' => $fmt($date)]) }}" @click.prevent="open({{ $row['id'] }}, '{{ $fmt($date) }}')" class="grid h-5 w-5 place-items-center rounded-full transition hover:bg-emerald-100 focus-visible:ring-2 focus-visible:ring-emerald-400 dark:hover:bg-emerald-500/20" title="On time — open {{ $row['name'] }}&rsquo;s {{ $date->format('D j M') }} to review" aria-label="On time: review {{ $row['name'] }} on {{ $date->format('D j M') }}"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span></a>
                                                @elseif($day['status'] === \App\Http\Controllers\StaffTimesheetController::EDITED)
                                                    <a href="{{ route('timesheets.fix', ['user' => $row['id'], 'date' => $fmt($date)]) }}" @click.prevent="open({{ $row['id'] }}, '{{ $fmt($date) }}')" class="grid h-5 w-5 place-items-center rounded-full transition hover:bg-emerald-100 focus-visible:ring-2 focus-visible:ring-emerald-400 dark:hover:bg-emerald-500/20" title="Edited — put right by hand; open {{ $row['name'] }}&rsquo;s {{ $date->format('D j M') }} to review" aria-label="Edited: review {{ $row['name'] }} on {{ $date->format('D j M') }}"><span class="h-1.5 w-1.5 rounded-full border-[1.5px] border-emerald-500 bg-transparent"></span></a>
                                                @else
                                                    <a href="{{ route('timesheets.fix', ['user' => $row['id'], 'date' => $fmt($date)]) }}"
                                                       @click.prevent="open({{ $row['id'] }}, '{{ $fmt($date) }}')"
                                                       class="grid h-5 w-5 place-items-center rounded-full transition focus-visible:ring-2 {{ $day['status'] === \App\Http\Controllers\StaffTimesheetController::LATE ? 'hover:bg-rose-100 focus-visible:ring-rose-400 dark:hover:bg-rose-500/20' : 'hover:bg-amber-100 focus-visible:ring-amber-400 dark:hover:bg-amber-500/20' }}"
                                                       title="{{ $day['status'] === \App\Http\Controllers\StaffTimesheetController::LATE ? 'Late' : 'Missing time out' }} — open {{ $row['name'] }}&rsquo;s {{ $date->format('D j M') }} to put it right"
                                                       aria-label="{{ $day['status'] === \App\Http\Controllers\StaffTimesheetController::LATE ? 'Late' : 'Missing time out' }}: correct {{ $row['name'] }} on {{ $date->format('D j M') }}"><span class="h-1.5 w-1.5 rounded-full {{ $day['status'] === \App\Http\Controllers\StaffTimesheetController::LATE ? 'bg-rose-500' : 'bg-amber-500' }}"></span></a>
                                                @endif
                                            @else
                                                <span></span>
                                            @endif

                                            <span class="text-[10px] font-bold uppercase text-slate-400">Out</span>
                                            {{-- An amber dash rather than a
                                                 blank: somebody went home
                                                 without clocking out, and the
                                                 blank read as "not in yet". --}}
                                            <span class="text-right font-semibold {{ $day['out'] ? '' : 'text-amber-600' }}">{{ $day['out'] ?? '—' }}</span>
                                            <span></span>
                                        </div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-12 text-center text-slate-500">No staff match this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
            <span>
                Showing <span class="font-semibold">{{ $rows->count() }}</span> staff
                &middot; <b class="font-semibold text-slate-700 dark:text-slate-200">To edit a day, click its dot</b> &mdash; the small circle beside the In time. Click an amber or red dot to put that day right (a missed clock-out, a break nobody punched, a time that needs moving); a green one just to review it. Every change records who made it and why.
            </span>
            <span class="flex items-center gap-4">
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> On time</span>
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Missing out</span>
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Late</span>
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full border-[1.5px] border-emerald-500"></span> Edited</span>
            </span>
        </div>
    </section>

    {{-- ============================================================
         The edit panel.

         One day of one person, opened from its dot. Fix the punches — move,
         fill, add a pair, take a pair off — and the panel checks the day as
         it is typed, the way the clock will check it when it is saved. Then
         one reason, one save. Every change lands on the record as a void
         and a replacement; the panel is a door onto that trail, not a new
         one.

         The day stays lit on the grid behind, and is repainted from the
         save's answer so the dot and the hours change without a reload.
         ============================================================ --}}
    <div x-show="panel" x-cloak class="fixed inset-0 z-40 bg-slate-900/30 backdrop-blur-[1px]" @click="close()" aria-hidden="true"></div>

    <aside x-show="panel" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-night-900"
           role="dialog" aria-modal="true" :aria-label="p ? p.staff.name + ', ' + p.date_label : 'Edit day'">

        <template x-if="loading">
            <p class="p-6 text-sm text-slate-500">Opening the day…</p>
        </template>

        <template x-if="! loading && p">
            <div class="flex min-h-0 flex-1 flex-col">
                {{-- Who, when, and how the day stands. --}}
                <header class="border-b border-slate-200 px-5 py-4 dark:border-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold" x-text="p.staff.name"></h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                <span x-text="p.staff.staff_id"></span> · <span x-text="p.staff.role"></span> · <span x-text="p.date_label"></span>
                            </p>
                        </div>
                        <button type="button" @click="close()" class="rounded-lg px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10" aria-label="Close">✕</button>
                    </div>
                    <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide" :class="statusClass()" x-text="statusLabel()"></span>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <template x-if="p.locked">
                        <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-white/10 dark:bg-slate-800 dark:text-slate-300">
                            🔒 This period has been approved, so the punches are a record now. Reopen the period first if something genuinely has to change.
                        </div>
                    </template>

                    {{-- The totals, live: they follow the rows as they are typed. --}}
                    <dl class="grid grid-cols-4 gap-2 text-center">
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Paid</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" :class="check().errors.size ? 'text-rose-600' : ''" x-text="paidLabel()"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Breaks</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="check().totals.paidBreak + 'm'"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Lunch</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="check().totals.unpaidBreak + 'm'"></dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-2 py-2 dark:bg-white/5">
                            <dt class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Scheduled</dt>
                            <dd class="mt-0.5 text-sm font-bold tabular-nums" x-text="hours(p.totals.scheduled)"></dd>
                        </div>
                    </dl>

                    {{-- The day as a bar: green is on the clock, amber a break, grey lunch. --}}
                    <div class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-white/10" aria-hidden="true">
                        <template x-for="segment in timeline()" :key="segment.key">
                            <span :style="'width:' + segment.width + '%'" :class="{working: 'bg-emerald-400', break: 'bg-amber-400', lunch: 'bg-slate-400'}[segment.kind]"></span>
                        </template>
                    </div>

                    {{-- The punches. One row each; a missing one is a row with no time. --}}
                    <ol class="mt-4 space-y-1.5">
                        <template x-for="row in rows" :key="row.key">
                            <li class="rounded-xl border px-3 py-2" :class="rowClass(row)">
                                <div class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold" x-text="row.label"></span>
                                    <input type="time" step="60" x-model="row.at" :disabled="p.locked"
                                           class="w-[7.2rem] rounded-lg border border-slate-200 bg-white px-2 py-1 text-sm tabular-nums dark:border-white/10 dark:bg-slate-800"
                                           :aria-label="row.label + ' time'">
                                    {{-- Only a break or a lunch comes off. The clock-in can be
                                         moved, never removed; the clock-out likewise. --}}
                                    <button type="button" x-show="canRemove(row)" @click="remove(row)" :disabled="p.locked" class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-bold text-slate-500 hover:border-rose-300 hover:text-rose-600 dark:border-white/10" :aria-label="'Remove ' + row.label">−</button>
                                </div>
                                <p class="mt-1 text-[11px]" :class="row.error ? 'text-rose-700 dark:text-rose-300' : 'text-slate-500 dark:text-slate-400'">
                                    <template x-if="row.error"><span x-text="row.error"></span></template>
                                    <template x-if="! row.error && row.missing && ! row.at">
                                        <span>
                                            <span x-text="p.today && row.type === 'OUT' ? 'Still on the clock' : 'Not punched — enter a time'"></span>
                                            {{-- The one-click fixes: the rostered end for a missing
                                                 clock-out; the usual length for a break or lunch
                                                 nobody came back off — the paid cap for a break,
                                                 half an hour for lunch. --}}
                                            <template x-if="! p.today && p.shift && row.type === 'OUT'">
                                                <button type="button" @click="row.at = p.shift.ends_at" class="ml-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Use scheduled <span x-text="clock(p.shift.ends_at)"></span></button>
                                            </template>
                                            <template x-if="row.type === 'BREAK_END' || row.type === 'LUNCH_END'">
                                                <button type="button" @click="row.at = afterStart(row)" class="ml-1 font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Use <span x-text="usualLength(row)"></span> min · <span x-text="clock(afterStart(row))"></span></button>
                                            </template>
                                        </span>
                                    </template>
                                    <template x-if="! row.error && row.id && row.at !== row.original">
                                        <span>was <span x-text="clock(row.original)"></span> · <button type="button" @click="row.at = row.original" class="font-semibold text-indigo-600 hover:underline dark:text-indigo-300">restore</button></span>
                                    </template>
                                    <template x-if="! row.error && row.id && row.at === row.original && row.edited">
                                        <span>corrected earlier</span>
                                    </template>
                                </p>
                            </li>
                        </template>
                    </ol>

                    <div class="mt-2 flex gap-2" x-show="! p.locked">
                        <button type="button" @click="addPair('BREAK')" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">+ Break</button>
                        <button type="button" @click="addPair('LUNCH')" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">+ Lunch</button>
                    </div>

                    {{-- Why. Appears once anything has changed, and is required. --}}
                    <div class="mt-5" x-show="changes().length > 0" x-cloak>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Reason</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            <template x-for="(label, code) in p.reasons" :key="code">
                                <button type="button" @click="reason = code" :class="reason === code ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200' : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/10'" class="rounded-full border px-2.5 py-1 text-xs font-semibold" x-text="label"></button>
                            </template>
                        </div>
                        <input type="text" x-model="note" maxlength="120" :placeholder="reason === 'other' ? 'What happened — required' : 'Note (optional)'"
                               class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm dark:border-white/10 dark:bg-slate-800"
                               :class="reason === 'other' && ! note.trim() ? 'border-amber-400' : ''">
                    </div>

                    {{-- What went before. Newest first; the entry just written is the one being looked for. --}}
                    <div class="mt-5">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Change history</p>
                        <template x-if="p.history.length === 0"><p class="mt-1 text-xs text-slate-400">Nothing has been changed on this day.</p></template>
                        <ul class="mt-1.5 space-y-1.5">
                            <template x-for="(entry, index) in p.history" :key="index">
                                <li class="rounded-lg bg-slate-50 px-3 py-1.5 text-xs dark:bg-white/5">
                                    <span class="font-semibold" x-text="entry.who || 'Someone'"></span>
                                    <span class="text-slate-400"> · <span x-text="entry.when"></span></span>
                                    <span class="text-slate-500 dark:text-slate-400"> · <span x-text="entry.reason"></span></span>
                                    <span class="block tabular-nums text-slate-700 dark:text-slate-200" x-text="entry.what"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <footer class="border-t border-slate-200 px-5 py-3 dark:border-white/10">
                    <p x-show="error" x-cloak class="mb-2 rounded-lg bg-rose-50 px-3 py-1.5 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-200" x-text="error"></p>
                    <p x-show="flash" x-cloak class="mb-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200" x-text="flash"></p>
                    <div class="flex items-center justify-between gap-2">
                        <button type="button" @click="undoAll()" x-show="changes().length > 0" class="text-xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-white">Undo all</button>
                        <span class="flex-1"></span>
                        <button type="button" @click="close()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold hover:bg-slate-50 dark:border-white/10 dark:hover:bg-white/10">Cancel</button>
                        <button type="button" @click="save()" :disabled="! canSave()" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40" x-text="saving ? 'Saving…' : 'Save changes'"></button>
                    </div>
                </footer>
            </div>
        </template>
    </aside>
</div>

<script>
/*
 * The grid and its panel.
 *
 * The rules a row is checked against are the clock's own — TimeClock::NEXT
 * and walk() — written out once more here so the row turns red as it is
 * typed rather than after Save is pressed. The server walks the day again on
 * save and is the one that decides; this is the affordance, that is the rule.
 */
function timesheetGrid() { return {
    panel: false,
    loading: false,
    saving: false,
    error: '',
    flash: '',
    p: null,          // the day as the server handed it over
    rows: [],
    removed: [],      // ids of punches taken off since the panel opened
    reason: '',
    note: '',
    from: @js($start->toDateString()),
    to: @js($end->toDateString()),

    /**
     * Arriving with a day named: ?open=user|date opens that day's panel at
     * once. It is how a report row, or any link that names a day, lands here
     * with the right thing already on screen rather than on a grid somebody
     * then has to find the dot on.
     */
    init() {
        const wanted = new URLSearchParams(window.location.search).get('open');
        if (! wanted) return;

        const [userId, date] = wanted.split('|');
        if (/^\d+$/.test(userId) && /^\d{4}-\d{2}-\d{2}$/.test(date)) this.open(Number(userId), date);
    },

    // What each state allows next, and what a day left in it is missing.
    NEXT: {off: ['IN'], working: ['LUNCH_START', 'BREAK_START', 'OUT'], lunch: ['LUNCH_END'], break: ['BREAK_END']},
    MISSING: {working: 'OUT', lunch: 'LUNCH_END', break: 'BREAK_END'},
    AFTER: {IN: 'working', OUT: 'off', LUNCH_START: 'lunch', LUNCH_END: 'working', BREAK_START: 'break', BREAK_END: 'working'},

    isOpen(userId, date) {
        return this.panel && this.p && this.p.staff.id === userId && this.p.date === date;
    },

    url(userId, date) {
        return '/timesheets/panel/' + userId + '/' + date + '?from=' + this.from + '&to=' + this.to;
    },

    async open(userId, date) {
        this.panel = true;
        this.loading = true;
        this.error = '';
        this.flash = '';

        try {
            const response = await fetch(this.url(userId, date), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not open that day.');

            this.load(await response.json());
        } catch (error) {
            this.error = error.message;
            this.p = null;
        } finally {
            this.loading = false;
        }
    },

    close() {
        this.panel = false;
    },

    /** The panel from the server's answer, with nothing carried over. */
    load(payload) {
        this.p = payload;
        this.removed = [];
        this.reason = '';
        this.note = '';
        this.rows = this.build(payload);
    },

    build(p) {
        const rows = p.punches.map(punch => ({
            key: 'p' + punch.id, id: punch.id, type: punch.type, label: punch.label,
            at: punch.at, original: punch.at, edited: punch.edited, missing: false, pair: null, error: '',
        }));

        // A break or a lunch is a pair, and comes off as one. A start with no
        // end — somebody went on a break and forgot to come back off it — gets
        // its end drawn in as an empty row right after it, so the fix is a
        // time typed into the gap rather than a red clock-out further down
        // with no way to put a punch in front of it.
        let pair = 0;
        for (let index = 0; index < rows.length; index++) {
            const row = rows[index];
            if (row.type !== 'BREAK_START' && row.type !== 'LUNCH_START') continue;

            const endType = row.type.replace('START', 'END');
            const end = rows.slice(index + 1).find(other => other.type === endType && other.pair === null);
            row.pair = 'x' + (++pair);

            if (end) {
                end.pair = row.pair;
            } else {
                rows.splice(index + 1, 0, {key: row.pair + 'end', id: null, type: endType, label: p.labels[endType], at: '', original: '', edited: false, missing: true, pair: row.pair, error: ''});
            }
        }

        // Still working at the end of the day and never clocked out: the
        // missing clock-out, as an empty row. (A day left on a break or at
        // lunch has already had its end drawn in above.)
        if (p.state === 'working') {
            rows.push({key: 'missing', id: null, type: 'OUT', label: p.labels.OUT, at: '', original: '', edited: false, missing: true, pair: null, error: ''});
        }

        return rows;
    },

    /* ---- checking the day as it is typed ---- */

    minutes(at) {
        if (! at) return null;
        const [h, m] = at.split(':').map(Number);
        return h * 60 + m;
    },

    /**
     * Walk the rows in the order they are shown. A time earlier than the row
     * before it, or a punch the state does not allow, marks the row; the
     * totals come from the same pass so they always agree with the marks.
     */
    check() {
        const errors = new Set();
        let state = 'off', since = null, worked = 0, paidBreak = 0, unpaidBreak = 0, previous = null;
        const cap = this.p ? this.p.paid_break_cap : 15;

        this.rows.forEach(row => {
            row.error = '';
            const at = this.minutes(row.at);
            if (at === null) return;

            if (previous !== null && at < previous) {
                row.error = 'Earlier than the punch before it';
            } else if (! this.NEXT[state].includes(row.type)) {
                row.error = {off: 'The day is not on the clock here', working: 'Already on the clock', lunch: 'Still on lunch', break: 'Still on a break'}[state];
            }

            if (row.error) { errors.add(row.key); previous = at; return; }

            if (row.type === 'OUT' || row.type === 'LUNCH_START' || row.type === 'BREAK_START') worked += Math.max(0, at - since);
            if (row.type === 'LUNCH_END') unpaidBreak += Math.max(0, at - since);
            if (row.type === 'BREAK_END') { const length = Math.max(0, at - since); paidBreak += Math.min(length, cap); unpaidBreak += Math.max(0, length - cap); }

            state = this.AFTER[row.type];
            since = at;
            previous = at;
        });

        return {errors, totals: {worked: worked + paidBreak, paidBreak, unpaidBreak, open: state !== 'off'}};
    },

    rowClass(row) {
        if (row.error) return 'border-rose-300 bg-rose-50 dark:border-rose-500/40 dark:bg-rose-500/10';
        if (row.missing && ! row.at) return 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10';
        if (row.id && row.at !== row.original) return 'border-indigo-300 bg-indigo-50/60 dark:border-indigo-500/40 dark:bg-indigo-500/10';
        if (! row.id) return 'border-indigo-300 bg-indigo-50/60 dark:border-indigo-500/40 dark:bg-indigo-500/10';
        return 'border-slate-200 dark:border-white/10';
    },

    paidLabel() {
        const totals = this.check().totals;
        return this.hours(totals.worked) + (totals.open ? '+' : '');
    },

    hours(minutes) {
        return (Math.round((minutes || 0) / 6) / 10).toFixed(1) + 'h';
    },

    clock(at) {
        const m = this.minutes(at);
        if (m === null) return '';
        const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, '0');
        return ((h % 12) || 12) + ':' + mm + ' ' + (h < 12 ? 'AM' : 'PM');
    },

    timeline() {
        const timed = this.rows.filter(row => this.minutes(row.at) !== null && ! row.error);
        if (timed.length < 2) return [];
        const start = this.minutes(timed[0].at), end = this.minutes(timed[timed.length - 1].at), span = Math.max(1, end - start);
        const segments = [];
        let state = 'off';
        timed.forEach((row, index) => {
            const next = timed[index + 1];
            state = this.AFTER[row.type];
            if (! next || state === 'off') return;
            segments.push({key: row.key, kind: state, width: (this.minutes(next.at) - this.minutes(row.at)) / span * 100});
        });
        return segments;
    },

    /* ---- editing ---- */

    /** The usual length of a break or a lunch, for the one-click end. */
    usualLength(row) {
        return row.type === 'LUNCH_END' ? 30 : (this.p ? this.p.paid_break_cap : 15);
    },

    /** The end this row's start would usually have: the start plus the usual length. */
    afterStart(row) {
        const start = this.rows.find(other => other.pair === row.pair && other !== row);
        const from = start ? this.minutes(start.at) : null;
        if (from === null) return '';
        const m = from + this.usualLength(row);
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    },

    canRemove(row) {
        return row.pair !== null && ! this.p.locked;
    },

    remove(row) {
        this.rows.filter(other => other.pair === row.pair).forEach(other => { if (other.id) this.removed.push(other.id); });
        this.rows = this.rows.filter(other => other.pair !== row.pair);
    },

    /** A start and an end, just before the clock-out. */
    addPair(kind) {
        const length = kind === 'LUNCH' ? 30 : 15;
        const outIndex = this.rows.findIndex(row => row.type === 'OUT');
        const at = outIndex === -1 ? this.rows.length : outIndex;
        const before = this.rows[at - 1], out = this.rows[at];

        const endAt = out && out.at ? this.minutes(out.at) : (before && before.at ? this.minutes(before.at) + length : null);
        const floor = before && before.at ? this.minutes(before.at) : 0;
        const startAt = endAt === null ? null : Math.max(floor, endAt - length);

        const hm = m => m === null ? '' : String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
        const pair = 'n' + Date.now();

        this.rows.splice(at, 0,
            {key: pair + 's', id: null, type: kind + '_START', label: this.p.labels[kind + '_START'], at: hm(startAt), original: '', edited: false, missing: false, pair, error: ''},
            {key: pair + 'e', id: null, type: kind + '_END', label: this.p.labels[kind + '_END'], at: hm(endAt), original: '', edited: false, missing: false, pair, error: ''},
        );
    },

    /** What would be sent: moves, additions, removals. */
    changes() {
        const list = [];
        this.rows.forEach(row => {
            if (row.id && row.at && row.at !== row.original) list.push({op: 'move', id: row.id, at: row.at});
            if (! row.id && row.at) list.push({op: 'add', type: row.type, at: row.at});
        });
        this.removed.forEach(id => list.push({op: 'remove', id}));
        return list;
    },

    canSave() {
        if (! this.p || this.p.locked || this.saving) return false;
        if (this.changes().length === 0 || this.check().errors.size > 0) return false;
        if (! this.reason) return false;
        return this.reason !== 'other' || this.note.trim() !== '';
    },

    undoAll() {
        this.rows = this.build(this.p);
        this.removed = [];
        this.reason = '';
        this.note = '';
        this.error = '';
    },

    async save() {
        if (! this.canSave()) return;
        this.saving = true;
        this.error = '';

        try {
            const response = await window.postJson(this.url(this.p.staff.id, this.p.date), {
                reason: this.reason, note: this.note.trim() || null, changes: this.changes(),
            });
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                const firstError = Object.values(data.errors ?? {}).flat()[0];
                throw new Error(firstError || data.message || 'Could not save the day.');
            }

            this.load(data);
            this.repaint(data);
            this.flash = 'Saved · logged to change history';
            setTimeout(() => { this.flash = ''; }, 4000);
        } catch (error) {
            this.error = error.message;
        } finally {
            this.saving = false;
        }
    },

    /* ---- the grid behind, painted from the save's answer ---- */

    repaint(p) {
        const cell = document.querySelector('[data-cell="' + p.staff.id + '|' + p.date + '"]');
        if (cell) cell.innerHTML = this.cellHtml(p);

        const hours = document.querySelector('[data-hours="' + p.staff.id + '"]');
        if (hours) hours.textContent = p.hours + 'h';
    },

    esc(value) {
        return String(value ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
    },

    /** The same cell the Blade draws — see the dots in the table above. */
    cellHtml(p) {
        const c = p.cell, name = this.esc(p.staff.name), day = this.esc(p.date_label), id = p.staff.id, date = p.date;
        if (c.empty) return '<span class="text-slate-300 dark:text-slate-600">·</span>';

        // The words come from the server with the cell — see cell() on the
        // controller — so this script names no state and cannot drift from
        // what the Blade above says about the same colour.
        const link = '<a href="/timesheets/fix/' + id + '/' + date + '" @click.prevent="open(' + id + ', \'' + date + '\')" ';
        const label = this.esc(c.label);
        const fine = c.status === 'on_time' || c.status === 'edited';
        let dot = '<span></span>';
        if (fine) {
            const shape = c.status === 'edited' ? 'border-[1.5px] border-emerald-500 bg-transparent' : 'bg-emerald-500';
            dot = link + 'class="grid h-5 w-5 place-items-center rounded-full transition hover:bg-emerald-100 focus-visible:ring-2 focus-visible:ring-emerald-400 dark:hover:bg-emerald-500/20" title="' + label + ' — open ' + name + '’s ' + day + ' to review"><span class="h-1.5 w-1.5 rounded-full ' + shape + '"></span></a>';
        } else if (c.status) {
            const late = c.status === 'late';
            const hover = late ? 'hover:bg-rose-100 focus-visible:ring-rose-400 dark:hover:bg-rose-500/20' : 'hover:bg-amber-100 focus-visible:ring-amber-400 dark:hover:bg-amber-500/20';
            dot = link + 'class="grid h-5 w-5 place-items-center rounded-full transition focus-visible:ring-2 ' + hover + '" title="' + label + ' — open ' + name + '’s ' + day + ' to put it right"><span class="h-1.5 w-1.5 rounded-full ' + (late ? 'bg-rose-500' : 'bg-amber-500') + '"></span></a>';
        }

        return '<div class="inline-grid grid-cols-[auto_minmax(3.6rem,auto)_1.25rem] items-center gap-x-1 gap-y-0.5 text-[12px] tabular-nums">'
            + '<span class="text-[10px] font-bold uppercase text-slate-400">In</span><span class="text-right font-semibold">' + this.esc(c.in ?? '—') + '</span>' + dot
            + '<span class="text-[10px] font-bold uppercase text-slate-400">Out</span><span class="text-right font-semibold' + (c.out ? '' : ' text-amber-600') + '">' + this.esc(c.out ?? '—') + '</span><span></span>'
            + '</div>';
    },

    statusLabel() {
        return {missing_out: 'Missing punch', late: 'Late in', edited: 'Edited', on_time: 'On time'}[this.p?.cell?.status] || 'No punches';
    },

    statusClass() {
        return {
            missing_out: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
            late: 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200',
            edited: 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-400 dark:bg-emerald-500/10 dark:text-emerald-200',
            on_time: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200',
        }[this.p?.cell?.status] || 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300';
    },
}; }
</script>

@endsection
