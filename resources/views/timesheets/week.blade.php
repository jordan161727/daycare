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

<div>

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
            <a href="{{ route('staff.timesheets.export', request()->only('from', 'to', 'role')) }}"
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
                            <td class="px-4 py-3 font-bold tabular-nums">{{ $row['hours'] }}h</td>

                            @foreach($dates as $date)
                                @php($day = $row['days'][$fmt($date)])
                                <td class="px-3 py-3 text-center {{ $date->isToday() ? 'bg-sky-50/60 dark:bg-sky-500/5' : '' }}">
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
                                        <div class="inline-grid grid-cols-[auto_minmax(3.6rem,auto)_0.375rem] items-center gap-x-1.5 gap-y-1 text-[12px] tabular-nums">
                                            <span class="text-[10px] font-bold uppercase text-slate-400">In</span>
                                            <span class="text-right font-semibold">{{ $day['in'] ?? '—' }}</span>
                                            @if($day['status'])
                                                <span class="h-1.5 w-1.5 rounded-full {{ [
                                                    'on_time' => 'bg-emerald-500',
                                                    'late' => 'bg-rose-500',
                                                    'missing_out' => 'bg-amber-500',
                                                ][$day['status']] }}" title="{{ [
                                                    'on_time' => 'On time',
                                                    'late' => 'Late',
                                                    'missing_out' => 'Missing time out',
                                                ][$day['status']] }}"></span>
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
                &middot; Corrections are made on each day&rsquo;s punch screen, which records who made them.
            </span>
            <span class="flex items-center gap-4">
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> On time</span>
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Missing out</span>
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Late</span>
            </span>
        </div>
    </section>
</div>


@endsection
