@extends('layouts.app')
@section('title', 'Children')
@section('content')
{{-- The Children list — Phase 2.1 of docs/work.md, as Children.dc.html
     draws it: the "Records to finish" banner, the status tabs with counts,
     the room and pay chips, and one row a child with their schedule, who
     pays, who the guardian is, the alerts and how far the record is from
     done. The Withdrawn and Archived tabs show how a child left instead.

     The page's own state (the search box, the archive dialog and the toast
     with Undo on it) is the childrenRoll component at the foot, kept from
     before; the chip counts are corrected in place when a row goes. --}}
@include('children.partials.mock-style')
<x-kids-background />

@php($isAdmin = auth()->user()->isAdmin())
@php($archivedTab = $status === \App\Models\Child::ARCHIVED)
@php($leftTab = $status === 'Inactive' || $archivedTab)
@php($keep = array_filter(['sort' => $sort, 'direction' => $direction, 'room' => $room, 'pay' => $pay, 'gap' => $gap]))
@php($nextDirection = fn ($column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc')
@php($sortUrl = fn ($column) => route('children.index', array_filter(['sort' => $column, 'direction' => $nextDirection($column), 'status' => $status, 'room' => $room, 'pay' => $pay, 'gap' => $gap])))
@php($arrow = fn ($column) => $sort === $column ? ($direction === 'asc' ? ' ↑' : ' ↓') : '')
@php($roomColours = ['Infant' => '#8cc4b2', 'Transition' => '#e0b577', 'Toddler' => '#b3a3dc', 'PreK' => '#9dc585', 'UPK-4' => '#e8a0b8', 'School Age' => '#92b4df'])

<div class="ch" x-data="childrenRoll({ csrf: @js(csrf_token()), archivedTab: @js($archivedTab) })" @keydown.escape.window="confirming = null" style="display: flex; flex-direction: column; gap: 14px;">

    {{-- Records to finish: what is still missing across the active roll, and
         a press lists just those children. Director's job, so director only. --}}
    @if($isAdmin && $gapCounts->isNotEmpty())
        <div class="gap" data-records-to-finish>
            <b>Records to finish:</b>
            @foreach($gapCounts as $key => $count)
                <a href="{{ route('children.index', array_filter(['status' => $status, 'room' => $room, 'pay' => $pay, 'gap' => $gap === $key ? null : $key])) }}" class="gapb {{ $gap === $key ? 'on' : '' }}" data-gap="{{ $key }}">{{ $count }} {{ strtolower(\App\Models\Child::GAPS[$key]) }}</a>
            @endforeach
            <span style="margin-left: auto; font-size: 12.5px;">Click one to list just those children</span>
        </div>
    @endif

    <section class="ch-card" style="overflow: hidden;">
        <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 12px; border-bottom: 1px solid #e7e9f0;">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                <h1 style="margin: 0; font-size: 22px; font-weight: 700; margin-right: 6px;">{{ $isAdmin ? 'Children' : 'My Students' }}</h1>

                {{-- The roll by status, as tabs with a count apiece. Every
                     status is drawn, as the mock has it, so the tab is where
                     it was last time. Archived is off the roll: its count is
                     never added into All, and the tab is a setting (Settings
                     › Children) because a centre that has never archived
                     anybody should not be offered an empty drawer. --}}
                <div style="display: flex; gap: 2px; background: #f2f4f9; border-radius: 999px; padding: 3px; flex-wrap: wrap;" class="dark:!bg-white/10" data-status-tabs>
                    @foreach(['Active', 'Pending', 'Inactive'] as $option)
                        <a href="{{ route('children.index', $keep + ['status' => $option]) }}" class="seg {{ $status === $option ? 'on' : '' }}" data-count="{{ $option }}" data-keep @if($status === $option) aria-current="true" @endif>{{ \App\Models\Child::statusLabel($option) }} <span class="ml-0.5 opacity-60">{{ $statusCounts[$option] ?? 0 }}</span></a>
                    @endforeach
                    @if($showArchived)
                        <a href="{{ route('children.index', $keep + ['status' => \App\Models\Child::ARCHIVED]) }}" class="seg {{ $archivedTab ? 'on' : '' }}" data-count="Archived" data-keep @if($archivedTab) aria-current="true" @endif>Archived <span class="ml-0.5 opacity-60">{{ $archivedCount }}</span></a>
                    @endif
                    <a href="{{ route('children.index', $keep) }}" class="seg {{ $status === '' ? 'on' : '' }}" data-count="all" data-keep @if($status === '') aria-current="true" @endif>All <span class="ml-0.5 opacity-60">{{ $rollCount }}</span></a>
                </div>

                <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    {{-- The same box the registers have: the 🔍 glyph, a bare × to clear, Escape clears. --}}
                    <label class="relative block">
                        <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
                        <input x-ref="childrenSearch" x-model="search" @keydown.escape="search = ''" class="inp round" style="width: 230px; min-height: 36px; padding-left: 32px; padding-right: 28px;" placeholder="Search name, LAN or parent" aria-label="Search children">
                        <button type="button" x-show="search" x-cloak @click="search = ''; $refs.childrenSearch?.focus()"
                                class="absolute right-1.5 top-1/2 grid h-5 w-5 -translate-y-1/2 place-items-center text-base leading-none text-slate-400 transition hover:text-slate-700 dark:hover:text-slate-200"
                                aria-label="Clear search">×</button>
                    </label>
                    {{-- The roll as a spreadsheet: carries the filter and the sort,
                         because it is built from the same query as the table.
                         Not on the Archived tab — the archived are off the roll. --}}
                    @if(! $archivedTab)
                        <a href="{{ route('children.export', request()->only('sort', 'direction', 'status', 'room', 'pay', 'gap') + ['t' => now()->timestamp]) }}" class="btn" title="Download this list as an Excel file">Export</a>
                    @endif
                    @if($isAdmin)
                        <a href="{{ route('children.create') }}" class="btn pri">+ Add child</a>
                    @endif
                </div>
            </div>

            {{-- Room chips, then the pay chips, counting the tab that is open. --}}
            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;" data-room-chips>
                <a href="{{ route('children.index', array_filter(['status' => $status, 'pay' => $pay, 'gap' => $gap, 'sort' => $sort, 'direction' => $direction])) }}" class="chip {{ $room === '' ? 'on' : '' }}"><span style="width: 9px; height: 9px; border-radius: 3px; background: #c3cbe0;"></span>All <span style="opacity: .7; font-weight: 500;">{{ $payCounts['all'] }}</span></a>
                @foreach($roomCounts as $name => $count)
                    <a href="{{ route('children.index', array_filter(['status' => $status, 'pay' => $pay, 'gap' => $gap, 'sort' => $sort, 'direction' => $direction, 'room' => $room === $name ? null : $name])) }}" class="chip {{ $room === $name ? 'on' : '' }}" data-room-chip="{{ $name }}"><span style="width: 9px; height: 9px; border-radius: 3px; background: {{ $roomColours[$name] ?? '#c3cbe0' }};"></span>{{ $name }} <span style="opacity: .7; font-weight: 500;">{{ $count }}</span></a>
                @endforeach
                <span style="width: 1px; height: 22px; background: #e7e9f0; margin: 0 6px;"></span>
                <span style="font-size: 11px; font-weight: 700; color: #8a90a0; letter-spacing: .06em;">PAY</span>
                @foreach(['' => 'All', 'dss' => 'DSS', 'private' => 'Private pay'] as $key => $label)
                    <a href="{{ route('children.index', array_filter(['status' => $status, 'room' => $room, 'gap' => $gap, 'sort' => $sort, 'direction' => $direction, 'pay' => $key])) }}" class="chip {{ $pay === $key ? 'on' : '' }}" data-pay-chip="{{ $key ?: 'all' }}">{{ $label }} <span style="opacity: .7; font-weight: 500;">{{ $payCounts[$key ?: 'all'] }}</span></a>
                @endforeach
            </div>
        </div>

        @if(session('success'))<div style="margin: 12px 20px 0; border-radius: 12px; border: 1px solid #b9e4cc; background: #e3f5ec; padding: 10px 14px; font-size: 13.5px; font-weight: 500; color: #166534;">{{ session('success') }}</div>@endif

        @if($archivedTab)
            {{-- Said once, above the drawer, because the tabs a few lines up
                 have just been read and none of them includes these children. --}}
            <p style="margin: 12px 20px 0; border-radius: 12px; border: 1px solid #e7e9f0; background: #f7f9fd; padding: 10px 14px; font-size: 13.5px;" class="muted">
                Archived children are not counted in All, Active or Withdrawn and don't appear in search or Export.
            </p>
        @endif

        <div style="overflow-x: auto;">
            @if($leftTab)
                {{-- How each child left. --}}
                <table class="tbl" data-withdrawn-table>
                    <thead><tr><th>LAN</th><th>Child</th><th>Last room</th><th>Last day</th><th>Reason</th><th>Note</th><th>{{ $archivedTab ? 'Archived' : 'Could return' }}</th><th></th></tr></thead>
                    <tbody>
                        @forelse($children as $child)
                            @php($url = route('children.show', $child))
                            <tr class="row" x-show="!gone.includes(@js($child->lan)) && @js(strtolower($child->first_name.' '.$child->last_name.' '.$child->lan)).includes(search.toLowerCase())" @click="if (! $event.target.closest('a, button, form')) window.location = @js($url)">
                                <td class="faint" style="font-variant-numeric: tabular-nums;">{{ $child->lan }}</td>
                                <td><div style="display: flex; align-items: center; gap: 10px;"><span class="av"><x-child-avatar :child="$child" /></span><a href="{{ $url }}" style="font-weight: 600; color: #1c2130;" class="dark:!text-slate-100">{{ $child->last_name }}, {{ $child->first_name }}@if(filled($child->nickname))<span style="font-weight: 500; color: #8a90a0;"> ({{ $child->nickname }})</span>@endif</a></div></td>
                                <td><span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 9px; height: 9px; border-radius: 3px; background: {{ $roomColours[$child->classroom] ?? '#c3cbe0' }};"></span>{{ $child->classroom ?: '—' }}</span></td>
                                <td style="font-weight: 600;">{{ $child->withdrawn_on?->format('M j, Y') ?? ($child->archived_at?->format('M j, Y') ?? '—') }}</td>
                                <td>@if(filled($child->withdrawal_reason))<span class="pill mut">{{ $child->withdrawal_reason }}</span>@else<span class="faint">—</span>@endif</td>
                                <td style="white-space: normal; max-width: 320px; font-size: 13px;" class="muted">{{ $child->withdrawal_note ?: '' }}</td>
                                <td>{{ $archivedTab ? ('Archived '.($child->archived_at?->format('M j, Y') ?? '')) : ($child->could_return ?: '—') }}</td>
                                <td style="text-align: right;">
                                    @if($isAdmin)
                                        @if($archivedTab)
                                            <button type="button" @click="unarchive({ lan: @js($child->lan), name: @js($child->first_name.' '.$child->last_name), url: @js(route('children.unarchive', $child)) })" class="btn sm" aria-label="Restore {{ $child->first_name }} {{ $child->last_name }}">Restore</button>
                                        @else
                                            <form method="POST" action="{{ route('children.reenroll', $child) }}" style="display: inline;">@csrf<button type="submit" class="btn sm" aria-label="Re-enroll {{ $child->first_name }} {{ $child->last_name }}">Re-enroll</button></form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="padding: 48px 12px; text-align: center;" class="muted">{{ $archivedTab ? 'No archived children.' : 'Nobody on the roll is withdrawn right now.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="tbl" data-children-table>
                    <thead>
                        <tr>
                            {{-- LAN and the name frozen against a sideways scroll: on a
                                 tablet the roll is wider than the screen, and a row read
                                 with the name off-screen is a row about nobody. --}}
                            <th class="sortable {{ $sort === 'lan' ? 'on' : '' }} sticky left-0 z-20 w-[4rem] bg-white dark:bg-slate-900" style="width: 4rem; min-width: 4rem; box-sizing: border-box;"><a href="{{ $sortUrl('lan') }}">LAN{{ $arrow('lan') }}</a></th>
                            <th class="sortable {{ $sort === 'last_name' ? 'on' : '' }} sticky left-[4rem] z-20 bg-white dark:bg-slate-900"><a href="{{ $sortUrl('last_name') }}">Child{{ $arrow('last_name') }}</a></th>
                            <th class="sortable {{ $sort === 'classroom' ? 'on' : '' }}"><a href="{{ $sortUrl('classroom') }}">Room{{ $arrow('classroom') }}</a></th>
                            <th class="sortable {{ $sort === 'dob' ? 'on' : '' }}"><a href="{{ $sortUrl('dob') }}" title="Date of birth, month/day/year — earliest first">DOB{{ $arrow('dob') }}</a></th>
                            <th class="sortable {{ $sort === 'age' ? 'on' : '' }}"><a href="{{ $sortUrl('age') }}" title="Youngest first">Age{{ $arrow('age') }}</a></th>
                            <th class="sortable {{ $sort === 'schedule' ? 'on' : '' }}"><a href="{{ $sortUrl('schedule') }}" title="Earliest drop-off first">Schedule{{ $arrow('schedule') }}</a></th>
                            <th>Pay</th>
                            <th>Guardian</th>
                            <th class="sortable {{ $sort === 'alerts' ? 'on' : '' }}"><a href="{{ $sortUrl('alerts') }}" title="Children with an alert first">Alerts{{ $arrow('alerts') }}</a></th>
                            <th class="sortable {{ $sort === 'status' ? 'on' : '' }}"><a href="{{ $sortUrl('status') }}">Record{{ $arrow('status') }}</a></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($children as $child)
                            @php($url = route('children.show', $child))
                            @php($guardian = $child->guardianLink())
                            @php($missing = $child->missingItems())
                            @php($alerts = $child->alertList())
                            <tr class="row" data-child-row="{{ $child->lan }}"
                                x-show="!gone.includes(@js($child->lan)) && @js(strtolower($child->first_name.' '.$child->last_name.' '.$child->classroom.' '.$child->lan.' '.($guardian?->person?->name ?? ''))).includes(search.toLowerCase())"
                                @click="if (! $event.target.closest('a, button, form')) window.location = @js($url)">
                                <td class="faint sticky left-0 z-10 w-[4rem] bg-white dark:bg-slate-900" style="font-variant-numeric: tabular-nums; width: 4rem; min-width: 4rem; box-sizing: border-box;">{{ $child->lan }}</td>
                                <td class="sticky left-[4rem] z-10 bg-white dark:bg-slate-900">
                                    {{-- The same avatar-and-name block the attendance
                                         sheet draws, in the same classes, so the two
                                         tables cannot drift. --}}
                                    <div class="att-person" style="display: flex; align-items: center; gap: 10px;">
                                        <span class="att-avatar av"><x-child-avatar :child="$child" /></span>
                                        <a href="{{ $url }}" class="att-name dark:!text-slate-100" style="font-weight: 600; color: #1c2130;">{{ $child->last_name }}, {{ $child->first_name }}@if(filled($child->nickname))<span style="font-weight: 500; color: #8a90a0;"> ({{ $child->nickname }})</span>@endif</a>
                                    </div>
                                </td>
                                {{-- The room's colour square, as the mock-up has it, and the
                                     animal the rest of the app knows the room by. --}}
                                <td><span style="display: inline-block; width: 9px; height: 9px; border-radius: 3px; background: {{ $roomColours[$child->classroom] ?? '#c3cbe0' }}; margin-right: 6px; vertical-align: 1px;"></span><x-room-icon :room="$child->classroom" size="text-sm" /> {{ $child->classroom ?: '—' }}</td>
                                <td class="muted" style="font-variant-numeric: tabular-nums;">{{ $child->ageLabel() ?? '—' }}</td>
                                <td class="muted">{{ $child->ageInWords() ?? '—' }}</td>
                                <td>
                                    <div style="display: flex; flex-direction: column; gap: 3px;">
                                        @php($days = $child->scheduleDays() ?? [])
                                        <span title="{{ $child->scheduleDaysLabel() ?? 'Days not set' }}">@foreach(\App\Models\Child::WEEKDAYS as $n => $name)<span class="d {{ in_array($n, $days, true) ? 'y' : 'n' }}">{{ $name[0] }}</span>@endforeach</span>
                                        {{-- Sheet-size times ("7:30a – 5:00p"): the column is read down sixty rows. --}}
                                        <span style="font-size: 12px; white-space: nowrap;" class="muted">{{ $child->scheduleLabel() ? \App\Models\Child::timeShort($child->drop_off_time).' – '.\App\Models\Child::timeShort($child->pick_up_time) : 'Hours not agreed' }}</span>
                                    </div>
                                </td>
                                <td><span class="pill {{ $child->isDss() ? 'info' : 'mut' }}">{{ $child->isDss() ? 'DSS' : 'Private pay' }}</span></td>
                                <td style="font-size: 13px;">
                                    <div style="display: flex; flex-direction: column;">
                                        <span style="font-weight: 600;">{{ $guardian?->person?->name ?? '—' }}</span>
                                        <span style="font-size: 12px;" class="muted">{{ $guardian?->person?->cell ?? ($guardian ? '' : 'Add a guardian') }}</span>
                                    </div>
                                </td>
                                <td>
                                    @foreach($alerts as $alert)
                                        <span class="pill {{ $alert['type'] }}" title="{{ $alert['label'] }}: {{ $alert['text'] }}" style="margin-right: 4px;">! {{ \Illuminate\Support\Str::limit($alert['text'], 40) }}</span>
                                    @endforeach
                                </td>
                                <td>
                                    @if($child->isPending())
                                        <span class="pill info" style="margin-right: 4px;">{{ $child->enrolled_on ? 'Starts '.$child->enrolled_on->format('M j') : 'Pending' }}</span>
                                    @endif
                                    @if($missing === [])
                                        <span class="pill ok">Complete</span>
                                    @else
                                        <span class="pill miss" title="{{ implode(' · ', $missing) }}">{{ array_values($missing)[0] }}{{ count($missing) > 1 ? ' +'.(count($missing) - 1) : '' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" style="padding: 48px 12px; text-align: center;" class="muted">{{ $status === '' && $room === '' && $pay === '' && $gap === '' ? 'No children have been added yet.' : ($status !== '' && $room === '' && $pay === '' && $gap === '' ? 'Nobody on the roll is '.strtolower(\App\Models\Child::statusLabel($status)).' right now.' : 'Nobody matches these filters.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
        <div style="padding: 12px 20px; font-size: 13px; border-top: 1px solid #f0f1f5; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between;" class="muted">
            <span>Showing {{ $children->count() }} of {{ $rollCount + ($archivedTab ? $archivedCount : 0) }} · click a row to open the child's record · Archive lives inside the record</span>
            @if($isAdmin)<a href="{{ route('children.import.form') }}">Import children →</a>@endif
        </div>
    </section>

    @if($isAdmin)
        {{-- The toast with Undo on it, for a Restore off the Archived tab. --}}
        <div x-show="toast" x-cloak role="status" aria-live="polite"
             class="fixed bottom-6 left-1/2 z-50 flex w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-white dark:text-slate-900">
            <span class="min-w-0 flex-1" x-text="toast?.message"></span>
            <button type="button" x-show="toast?.undo" @click="undo()" :disabled="busy" class="inline-flex min-h-[44px] shrink-0 items-center rounded-lg px-3 text-sm font-bold text-indigo-300 transition hover:text-white dark:text-indigo-600">Undo</button>
            <button type="button" @click="toast = null" aria-label="Dismiss" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-xl leading-none text-white/70 transition hover:text-white dark:text-slate-500">×</button>
        </div>
    @endif
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('childrenRoll', ({ csrf, archivedTab }) => ({
        search: '',
        // LANs of rows that have gone since the page loaded — restored off
        // the Archived tab. Hidden rather than removed, so Undo can put one
        // straight back.
        gone: [],
        confirming: null,
        toast: null,
        toastTimer: null,
        busy: false,
        archivedTab,

        async post(url, body = {}) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            if (! response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        },

        // Undo on the toast: back as they were.
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
            } finally { this.busy = false; }
        },

        // Restore off the Archived tab: back on the roll as Withdrawn.
        async unarchive(child) {
            if (this.busy) return;
            this.busy = true;
            try {
                const data = await this.post(child.url);
                this.gone.push(child.lan);
                this.applyCounts(data.counts);
                this.show({ message: data.message });
            } catch (error) {
                this.show({ message: 'Could not restore ' + child.name + '. Please try again.' });
            } finally { this.busy = false; }
        },

        show(toast) {
            this.toast = toast;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast = null; }, 12000);
        },

        // The tabs, corrected in place. Each carries data-count naming what
        // it counts; the number is the span inside it.
        applyCounts(counts) {
            if (! counts) return;
            this.$root.querySelectorAll('[data-count]').forEach(chip => {
                const key = chip.dataset.count;
                const value = key === 'all' ? counts.all : (key === 'Archived' ? counts.archived : (counts.byStatus?.[key] ?? 0));
                const number = chip.querySelector('span');
                if (number) number.textContent = value;
            });
        },
    }));
});
</script>
@endsection
