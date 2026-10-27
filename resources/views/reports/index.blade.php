@extends('layouts.app')
@section('title', 'Reports')
@section('content')
@if($report === 'week')
    {{-- The weekly sign-in sheet this page was before Phase 8.2, kept as it
         was: the register links to it by date and teachers print it. --}}
    @include('reports.partials.week')
@else
{{--
    Reports — Phase 8.2 of docs/work.md, as Reports.dc.html draws it.

    Pick a report, set the dates, print it or download it. Each sheet is the
    server's (App\Services\Reports); the filters are links, so a report is
    an address somebody can bookmark and print. The UPK-4 sheet carries the
    monthly email to the school district — see reportsPage() at the foot.
--}}
@include('children.partials.mock-style')
@include('reports.partials.style')
<x-kids-background />

@php
    // $extra first: a chip's own value must win over the page's current one.
    // (It was the other way round until 27 Oct 2026, so Room and the month
    // arrows never changed anything — PHP's + keeps the left-hand key.)
    $link = fn (array $extra = []) => route('reports.index', array_filter($extra + ['report' => $report, 'month' => $month, 'room' => $room, 'mode' => $mode === 'marks' ? 'marks' : null, 'range' => $report === 'staff' ? $range : null], fn ($v) => $v !== null && $v !== ''));
    $csvUrl = route('reports.export', array_filter(['report' => $report, 'month' => $month, 'room' => $room, 'range' => $range, 't' => now()->timestamp]));
    $cards = [
        ['key' => 'child', 'name' => 'Child attendance', 'sub' => 'Monthly in/out times and absences, by room', 'admin' => false],
        ['key' => 'staff', 'name' => 'Staff attendance', 'sub' => 'Timecards & payroll hours from the front door clock', 'admin' => true],
        ['key' => 'upk', 'name' => 'Attendance sheet', 'sub' => 'The paper sheet, by room — same layout, filled in for you', 'admin' => true],
        // Parked (Jordan, 27 Oct 2026): the Weekly sign-in sheet card is hidden for now.
        // The sheet itself still opens for ?report=week and for the register's
        // date / classroom links, so nothing that points at it breaks.
        // ['key' => 'week', 'name' => 'Weekly sign-in sheet', 'sub' => 'The daily sheet by room, for the week of any date', 'admin' => false],
    ];
@endphp

<div class="ch" style="display: flex; flex-direction: column; gap: 18px;" x-data="reportsPage(@js(['em' => $em ?? null, 'urls' => ['save' => route('reports.upk-email.save'), 'test' => route('reports.upk-email.test'), 'send' => route('reports.upk-email.send')]]))">
    <header class="noprint" style="display: flex; flex-direction: column; gap: 4px;">
        <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;" class="muted">{{ $isAdmin ? 'Only you' : 'Reports' }}</span>
        <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Reports</h1>
        <span style="font-size: 14px;" class="muted">Pick a report, set the dates, then print it or download it for Excel / payroll.</span>
    </header>

    <div class="noprint" style="display: flex; gap: 10px; flex-wrap: wrap;" data-report-picker>
        @foreach($cards as $card)
            @continue($card['admin'] && ! $isAdmin)
            <a class="rcard {{ $report === $card['key'] ? 'on' : '' }}" href="{{ route('reports.index', ['report' => $card['key'], 'month' => $month]) }}">
                <span style="font-size: 14.5px; font-weight: 700;">{{ $card['name'] }}</span>
                <span style="font-size: 12.5px; line-height: 1.35;" class="muted">{{ $card['sub'] }}</span>
            </a>
        @endforeach
        @can('see-dss')
            <a class="rcard" href="{{ route('dss.index') }}"><span style="font-size: 14.5px; font-weight: 700;">DSS attendance →</span><span style="font-size: 12.5px; line-height: 1.35;" class="muted">Weekly hours vs authorized · KinderConnect export (on the DSS page)</span></a>
        @endcan
    </div>

    <section class="ch-card noprint" style="padding: 14px 18px; display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;" data-filters>
        <div style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
            @if($report !== 'staff')
                {{-- One month at a time (Jordan, 27 Oct 2026: not a row of every month):
                     a month back, the month, a month forward — never past this
                     month — and "This month" as the way back once stepped away. --}}
                @php($at = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth())
                @php($thisMonth = $at->isSameMonth(today()))
                <div class="fg"><span class="lblu">Month</span><div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;" data-month-stepper>
                    <span style="display: inline-flex; align-items: stretch; border: 1px solid #cfd3de; border-radius: 999px; background: #fff; overflow: hidden;">
                        <a href="{{ $link(['month' => $at->copy()->subMonth()->format('Y-m')]) }}" style="display: grid; place-items: center; width: 34px; color: #8a90a0; text-decoration: none;" aria-label="Previous month" data-month-prev>‹</a>
                        <span style="display: flex; align-items: center; gap: 6px; border-left: 1px solid #e7e9f0; border-right: 1px solid #e7e9f0; padding: 0 12px; min-height: 32px; font-size: 12.5px; font-weight: 700; {{ $thisMonth ? '' : 'color: #1b5e91;' }}" data-month-label>
                            <svg style="width: 13px; height: 13px; color: #8a90a0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            {{ $at->format('F Y') }}
                        </span>
                        @if($thisMonth)
                            <span style="display: grid; place-items: center; width: 34px; color: #cfd3de;" aria-disabled="true" title="This month is the latest" data-month-next>›</span>
                        @else
                            <a href="{{ $link(['month' => $at->copy()->addMonth()->format('Y-m')]) }}" style="display: grid; place-items: center; width: 34px; color: #8a90a0; text-decoration: none;" aria-label="Next month" data-month-next>›</a>
                        @endif
                    </span>
                    @if($thisMonth)
                        <span class="chip f on" data-this-month-chip>This month</span>
                    @else
                        <a class="chip f" href="{{ $link(['month' => today()->format('Y-m')]) }}" data-back-to-this-month>↩ This month</a>
                    @endif
                </div></div>
            @endif
            @if($report === 'child')
                <div class="fg"><span class="lblu">Room</span><div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    @if($isAdmin)<a class="chip f {{ $room === null ? 'on' : '' }}" href="{{ $link(['room' => 'All']) }}">All</a>@endif
                    @foreach($rooms as $r)<a class="chip f {{ $room === $r ? 'on' : '' }}" href="{{ $link(['room' => $r]) }}">{{ $r }}</a>@endforeach
                </div></div>
                <div class="fg"><span class="lblu">Show</span><div style="display: flex; gap: 6px;"><a class="chip f {{ $mode === 'times' ? 'on' : '' }}" href="{{ $link(['mode' => null]) }}">In / out times</a><a class="chip f {{ $mode === 'marks' ? 'on' : '' }}" href="{{ $link(['mode' => 'marks']) }}">✓ / A</a></div></div>
            @endif
            @if($report === 'upk')
                {{-- One room at a time: the sheet is the district's UPK-4 form, drawn for any room (27 Oct 2026). --}}
                <div class="fg"><span class="lblu">Room</span><div style="display: flex; gap: 6px; flex-wrap: wrap;" data-sheet-rooms>
                    @foreach($rooms as $r)<a class="chip f {{ $room === $r ? 'on' : '' }}" href="{{ $link(['room' => $r]) }}">{{ $r }}</a>@endforeach
                </div></div>
            @endif
            @if($report === 'staff')
                <div class="fg"><span class="lblu">Dates</span><div style="display: flex; gap: 6px; flex-wrap: wrap;">@foreach($ranges as $key => $r)<a class="chip f {{ $range === $key ? 'on' : '' }}" href="{{ $link(['range' => $key]) }}">{{ $r['label'] }}</a>@endforeach</div></div>
            @endif
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn" onclick="window.print()">🖨 Print / PDF</button>
            <a class="btn pri" href="{{ $csvUrl }}">⬇ Download .csv</a>
        </div>
    </section>

    {{-- ============ CHILD ATTENDANCE ============ --}}
    @if($report === 'child')
        <section class="ch-card sheet" style="padding: 18px 20px; display: flex; flex-direction: column; gap: 12px;" data-child-report>
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <span style="font-size: 18px; font-weight: 700;">Child attendance · {{ $ca['title'] }}</span>
                <span style="font-size: 13px;" class="muted">{{ $ca['sub'] }}</span>
            </div>
            <div style="overflow-x: auto; border: 1px solid #e7e9f0; border-radius: 10px;">
                <table class="ct">
                    <thead><tr><th class="stick" style="min-width: 150px;">Child</th>@foreach($ca['days'] as $d)<th class="dh {{ $d['cls'] }}"><span>{{ $d['dow'] }}</span><b>{{ $d['n'] }}</b></th>@endforeach<th class="tt">Present</th><th class="tt">Absent</th><th class="tt">Hours</th></tr></thead>
                    <tbody>
                        @foreach($ca['rows'] as $r)
                            <tr class="{{ $r['group'] ? 'grp' : '' }}">
                                <td class="stick">
                                    @if($r['group'])<span style="display: inline-block; width: 9px; height: 9px; border-radius: 3px; background: {{ $r['color'] }}; margin-right: 6px;"></span><b>{{ $r['name'] }}</b> <span style="font-weight: 500;" class="muted">· {{ $r['count'] }}</span>
                                    @else<span style="font-weight: 600;">{{ $r['name'] }}</span>@endif
                                </td>
                                @foreach($r['cells'] as $c)<td class="cc {{ $c['cls'] === 'closed' ? 'closedc' : $c['cls'] }}"><span>{{ $c['a'] }}</span><span>{{ $c['b'] }}</span></td>@endforeach
                                <td class="tt">{{ $r['p'] }}</td><td class="tt" style="color: {{ $r['aCol'] ?? '' }};">{{ $r['ab'] }}</td><td class="tt">{{ $r['h'] }}</td>
                            </tr>
                        @endforeach
                        @if($ca['rows'] === [])<tr><td colspan="{{ count($ca['days']) + 4 }}" style="padding: 24px; text-align: center;" class="muted">No children in this month.</td></tr>@endif
                    </tbody>
                </table>
            </div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px;" class="muted"><span><b style="color: #be123c;">A</b> absent</span><span><span class="lg closedc"></span> closed</span><span>Blank = not scheduled / not yet</span><span>Times are first sign-in and last sign-out (from Teacher Attendance).</span></div>
        </section>
    @endif

    {{-- ============ STAFF ATTENDANCE ============ --}}
    @if($report === 'staff')
        <section class="ch-card sheet" style="padding: 18px 20px; display: flex; flex-direction: column; gap: 12px;" data-staff-report>
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <span style="font-size: 18px; font-weight: 700;">Staff attendance · {{ $sa['title'] }}</span>
                <span style="font-size: 13px;" class="muted">From the front door clock · unpaid breaks taken off · {{ $sa['sub'] }}</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                @foreach($sa['kpis'] as $k)<div class="kpi"><span style="font-size: 12px; font-weight: 600;" class="muted">{{ $k['label'] }}</span><span style="font-size: 22px; font-weight: 700; color: {{ $k['col'] }};">{{ $k['val'] }}</span></div>@endforeach
            </div>
            <div style="overflow-x: auto; border: 1px solid #e7e9f0; border-radius: 10px;">
                <table class="ct st">
                    <thead><tr><th class="stick" style="min-width: 130px;">Staff</th>@foreach($sa['days'] as $d)<th class="dh {{ $d['cls'] }}"><span>{{ $d['dow'] }}</span><b>{{ $d['n'] }}</b></th>@endforeach<th class="tt">Hours</th><th class="tt">Breaks</th><th class="tt">Late</th><th class="tt">Edited</th><th class="tt">Over 40</th></tr></thead>
                    <tbody>
                        @foreach($sa['rows'] as $r)
                            <tr>
                                <td class="stick"><span style="font-weight: 600;">{{ $r['name'] }}</span><br><span style="font-size: 11.5px;" class="faint">{{ $r['role'] }}</span></td>
                                @foreach($r['cells'] as $c)<td class="sc {{ $c['cls'] === 'closed' ? 'closedc' : $c['cls'] }}"><span class="t1">{{ $c['a'] }}@if($c['ed']) <span title="Edited by director" style="color: #c2410c;">✎</span>@endif</span><span class="t2">{{ $c['b'] }}</span>@if($c['late'])<span class="t3">{{ $c['late'] }}</span>@endif</td>@endforeach
                                <td class="tt"><b>{{ $r['h'] }}</b></td><td class="tt">{{ $r['br'] }}</td><td class="tt" style="color: {{ $r['lateCol'] }};">{{ $r['late'] }}</td><td class="tt" style="color: {{ $r['edCol'] }};">{{ $r['ed'] }}</td><td class="tt" style="color: {{ $r['otCol'] }};">{{ $r['ot'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px;" class="muted"><span><span style="color: #be123c; font-weight: 700;">late</span> = clocked in more than {{ \App\Http\Controllers\StaffController::LATE_GRACE }} min after the schedule</span><span><span style="color: #c2410c;">✎</span> time fixed by the director (see Staff attendance → History)</span><span>Time off comes from Staff → Time off</span></div>
        </section>
    @endif

    {{-- ============ UPK-4 PAPER SHEET ============ --}}
    @if($report === 'upk' && $up['room'] === 'UPK-4')
        {{-- The monthly email goes to the school district, so it belongs to the UPK-4 sheet only. --}}
        <section class="ch-card noprint" style="padding: 0; overflow: hidden;" data-upk-email>
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 18px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px;" :style="'background:' + (s.on ? '#e3f5ec' : '#f2f4f9')">✉</span>
                    <div style="display: flex; flex-direction: column; gap: 2px;">
                        <span style="font-size: 15px; font-weight: 700;">Monthly email <span class="pill" :class="s.on ? 'ok' : 'mut'" style="margin-left: 6px;" x-text="s.on ? 'On' : 'Off'"></span></span>
                        <span style="font-size: 13px;" class="muted" x-text="summary()"></span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <label class="sw"><input type="checkbox" x-model="s.on" @change="save()"><span></span></label>
                    <button type="button" class="btn" @click="open = ! open" x-text="open ? 'Close' : 'Edit'"></button>
                </div>
            </div>
            <div x-show="open" x-cloak style="border-top: 1px solid #eef0f5; padding: 16px 18px; display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 22px;">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="fg"><span class="lblu">Send to</span>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; border: 1px solid #cfd3de; border-radius: 10px; min-height: 42px; box-sizing: border-box; align-items: center;">
                            <template x-for="a in s.to" :key="a"><span class="em-chip"><span x-text="a"></span> <button type="button" @click="removeTo(a)" aria-label="Remove">×</button></span></template>
                            <input class="em-in" placeholder="Add an email and press Enter" x-model.trim="input" @keydown.enter.prevent="addTo()" @keydown.,.prevent="addTo()" aria-label="Add an email">
                            <button type="button" class="btn sm" @click="addTo()">Add</button>
                        </div>
                        <span x-show="err" x-cloak style="font-size: 12.5px; color: #be123c;" x-text="err"></span>
                    </div>
                    <div class="fg"><span class="lblu">When</span>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                            <button type="button" class="chip f" :class="s.when === 'first' ? 'on' : ''" @click="s.when = 'first'; save()">1st of the month</button>
                            <button type="button" class="chip f" :class="s.when === 'weekday' ? 'on' : ''" @click="s.when = 'weekday'; save()">First weekday</button>
                            <span style="font-size: 13px;" class="muted">at</span>
                            <template x-for="(label, key) in em.times" :key="key"><button type="button" class="chip f" :class="s.time === key ? 'on' : ''" @click="s.time = key; save()" x-text="label"></button></template>
                        </div>
                        <span style="font-size: 12.5px;" class="muted">Sends the month that just ended — on the 1st of <span x-text="em.nextMonth"></span> it sends <span x-text="em.lastMonth"></span>.</span>
                    </div>
                    <div class="fg"><span class="lblu">Attach</span>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 13.5px;">
                            <label class="ckb"><input type="checkbox" checked disabled> Attendance sheet (PDF, 1 page)</label>
                            <label class="ckb"><input type="checkbox" x-model="s.csv" @change="save()"> Spreadsheet (.csv)</label>
                        </div>
                    </div>
                    <div class="fg"><span class="lblu">Before it goes out</span>
                        <label class="ckb" style="font-size: 13.5px;"><input type="checkbox" x-model="s.hold" @change="save()"> If any UPK-4 day still has missing sign-ins, email me first and wait for my OK</label>
                        <span x-show="em.missing > 0" style="font-size: 12.5px; color: #b45309;"><span x-text="em.missing"></span> sign-ins still missing for <span x-text="em.lastMonth"></span> — they print as A until a day is put right on the DSS page.</span>
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div class="fg"><span class="lblu">Email</span>
                        <div style="border: 1px solid #e3e6ee; border-radius: 12px; overflow: hidden; font-size: 13.5px;">
                            <div style="padding: 8px 12px; background: #f7f9fd; border-bottom: 1px solid #eef0f5; display: flex; flex-direction: column; gap: 3px;">
                                <span><span class="muted">From:</span> {{ config('daycare.company.name') }} &lt;<span x-text="em.from"></span>&gt;</span>
                                <span><span class="muted">Subject:</span> <b x-text="em.subject"></b></span>
                            </div>
                            <div style="padding: 10px 12px; line-height: 1.5; white-space: pre-line;" x-text="em.body"></div>
                            <div style="padding: 8px 12px; border-top: 1px solid #eef0f5; display: flex; gap: 8px; flex-wrap: wrap;"><span class="att">📄 <span x-text="em.pdfName"></span></span><span x-show="s.csv" class="att">📊 <span x-text="em.csvName"></span></span></div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn" :disabled="busy" @click="act('test')">Send a test to me</button>
                        <button type="button" class="btn" :disabled="busy || ! s.to.length" @click="act('send')">Send <span x-text="em.lastMonth"></span> now</button>
                    </div>
                    <div x-show="flash" x-cloak style="background: #e3f5ec; color: #166534; border-radius: 10px; padding: 8px 12px; font-size: 13px;">✓ <span x-text="flash"></span></div>
                    <div class="fg"><span class="lblu">Sent</span>
                        <template x-for="(l, i) in s.log" :key="i"><div style="display: flex; justify-content: space-between; gap: 10px; font-size: 13px; padding: 4px 0; border-bottom: 1px solid #f3f4f8;"><span><span x-text="l.when"></span> · <span x-text="l.what"></span></span><span :style="'color:' + l.col + ';white-space:nowrap'" x-text="l.st"></span></div></template>
                        <span x-show="! s.log.length" style="font-size: 13px;" class="faint">Nothing sent yet.</span>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if($report === 'upk')
        {{-- The day cells are live (27 Oct 2026): press one and it cycles ✓ → A → blank → ✓,
             written the way the DSS page writes a day, and the totals redraw from the server. --}}
        <section class="paper sheet" data-upk-sheet x-data="sheetCells(@js(['url' => route('reports.sheet.cell'), 'month' => $month, 'room' => $up['room']]))">
            <div style="text-align: center; font-size: 15px; font-weight: 600; margin-bottom: 6px;">Attendance Sheet</div>
            <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 14px;">
                <div style="display: flex; align-items: flex-end; gap: 26px;">
                    <div style="border: 1.5px solid #222; padding: 6px 14px; font-size: 14px; line-height: 1.45; align-self: flex-start;">A=Absent<br>✓= Present</div>
                    <div class="fld">Month: <span class="ul" style="min-width: 190px;">{{ $up['month'] }} {{ $up['year'] }}</span></div>
                </div>
                <div class="fld">School Site: <span class="ul" style="min-width: 260px;">Little Angels</span></div>
                <div class="fld">Grade: <span class="ul" style="min-width: 80px;" data-sheet-grade>{{ $up['grade'] }}</span></div>
            </div>
            <table class="pt">
                <thead>
                    <tr>
                        <th rowspan="2" style="text-align: left; vertical-align: bottom; width: 210px; padding: 3px 6px;"><span style="font-weight: 600;">Last Name, First Name</span></th>
                        @foreach($up['cols'] as $c)<th class="pd {{ $c['we'] ? 'we' : '' }}">{{ $c['n'] }}</th>@endforeach
                        <th rowspan="2" style="width: 64px; text-align: left; vertical-align: top; padding: 3px 5px; font-weight: 600; line-height: 1.25;">Total Days Absent</th>
                        <th rowspan="2" style="width: 92px; text-align: left; vertical-align: top; padding: 3px 5px; font-weight: 600;">Notes</th>
                    </tr>
                    <tr>@foreach($up['cols'] as $c)<th class="pw {{ $c['we'] ? 'we' : '' }}">{{ $c['dow'] }}</th>@endforeach</tr>
                </thead>
                <tbody>
                    @foreach($up['rows'] as $r)
                        <tr>
                            <td class="pn"><span class="no">{{ $r['no'] }}</span>{{ $r['name'] }}</td>
                            @foreach($r['cells'] as $c)<td class="pc {{ $c['we'] ? 'we' : '' }} {{ $c['e'] ? 'live' : '' }}" @if($c['e']) data-cell="{{ $r['child_id'] }}|{{ $c['d'] }}" role="button" tabindex="0" title="Press to change: ✓ → A → blank → ✓" @click="cycle({{ $r['child_id'] }}, '{{ $c['d'] }}', $el)" @keydown.enter.prevent="cycle({{ $r['child_id'] }}, '{{ $c['d'] }}', $el)" @endif>{{ $c['v'] }}</td>@endforeach
                            <td class="pc" style="text-align: center;" @if($r['child_id']) data-tot="{{ $r['child_id'] }}" @endif>{{ $r['tot'] }}</td>
                            <td class="pc pnote">{{ $r['note'] }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="pn" style="font-weight: 600;">Grand Total</td>
                        @foreach($up['grand'] as $k => $g)<td class="pc {{ $g['we'] ? 'we' : '' }}" style="font-weight: 600;" data-grand="{{ $k }}">{{ $g['v'] }}</td>@endforeach
                        <td class="pc"></td><td class="pc"></td>
                    </tr>
                </tbody>
            </table>
            <div class="noprint" style="margin-top: 12px; font-size: 12px; color: #5f6676;"><span x-show="err" x-text="err" style="color: #be123c; font-weight: 600; margin-right: 8px;"></span>Same layout as the paper sheet, filled in from Teacher Attendance (the door and the register are one record). Press a day to change it: ✓ → A → blank → ✓ (blank is “not scheduled”); the change is written to the register and shows on the DSS page too. “Grand Total” is children present that day. Notes come from the child’s record (start date, withdrawal, enrollment note). <a href="{{ route('reports.export', ['report' => 'upk', 'month' => $month, 'room' => $up['room'], 'format' => 'pdf']) }}" target="_blank" rel="noopener">Open as PDF</a></div>
        </section>
    @endif
</div>

<script>
/*
 * The page. The sheets are the server's and the filters are links; only
 * the monthly email panel is live — its settings save as they change, and
 * the two buttons send.
 */
// The attendance sheet's day cells. One press, one write, the sheet's
// numbers back from the server; the cells and totals are repainted in
// place so the page never reloads under the director's hand.
function sheetCells(opts) { return {
    err: '',
    busy: false,
    async cycle(childId, date, el) {
        if (this.busy) return;
        const now = el.textContent.trim();
        const state = now === '✓' ? 'abs' : (now === 'A' ? 'none' : 'pres');
        this.busy = true;
        this.err = '';
        el.style.opacity = '.4';
        try {
            const res = await fetch(opts.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content},
                body: JSON.stringify({child_id: childId, date, state, month: opts.month, room: opts.room}),
            });
            const data = await res.json();
            if (! res.ok) { this.err = data.message || 'Could not save that day.'; return; }
            this.paint(data);
        } catch (e) {
            this.err = 'Could not save that day.';
        } finally {
            el.style.opacity = '';
            this.busy = false;
        }
    },
    paint(data) {
        const root = this.$root;
        for (const row of data.rows) {
            const tot = root.querySelector('[data-tot="' + row.child_id + '"]');
            if (tot) tot.textContent = row.tot;
            root.querySelectorAll('[data-cell^="' + row.child_id + '|"]').forEach(cell => {
                const date = cell.dataset.cell.split('|')[1];
                const k = this.colIndex(date);
                if (k >= 0) cell.textContent = row.cells[k];
            });
        }
        data.grand.forEach((v, k) => { const g = root.querySelector('[data-grand="' + k + '"]'); if (g) g.textContent = v; });
        const missing = document.querySelector('[data-sheet-missing]');
        if (missing) missing.textContent = data.missing;
    },
    // The sheet's 25 columns, in order, so a date finds its cell in a row.
    dates: @js(array_map(fn ($c) => $c['date'], $up['cols'] ?? [])),
    colIndex(date) { return this.dates.indexOf(date); },
}; }

function reportsPage(opts) { return {
    em: opts.em || {settings: {on: false, to: [], when: 'first', time: '07:00', csv: true, hold: true, log: []}, times: {}, next: '', nextMonth: '', lastMonth: '', subject: '', body: '', pdfName: '', csvName: '', missing: 0, from: ''},
    get s() { return this.em.settings; },
    open: false,
    input: '',
    err: '',
    flash: '',
    busy: false,
    urls: opts.urls,

    summary() {
        if (! this.s.on) return 'Not sending — turn on to email the sheet every month';
        if (! this.s.to.length) return 'Add at least one email address';
        return 'Last month’s sheet goes to ' + this.s.to.length + (this.s.to.length === 1 ? ' person' : ' people') + ' on the ' + (this.s.when === 'first' ? '1st of each month' : 'first weekday of each month') + ' at ' + (this.em.times[this.s.time] || this.s.time) + ' · next: ' + this.em.next + ' (' + this.em.nextMonth + ')';
    },
    addTo() {
        const a = (this.input || '').trim().toLowerCase();
        if (! a) return;
        if (! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a)) { this.err = '“' + a + '” doesn’t look like an email address.'; return; }
        if (! this.s.to.includes(a)) this.s.to.push(a);
        this.input = '';
        this.err = '';
        this.save();
    },
    removeTo(a) {
        this.s.to = this.s.to.filter(x => x !== a);
        this.save();
    },
    async save() {
        this.busy = true;
        this.err = '';
        try {
            const response = await window.postJson(this.urls.save, {on: this.s.on, to: this.s.to, when: this.s.when, time: this.s.time, csv: this.s.csv, hold: this.s.hold});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not save.');
            this.em = data;
        } catch (e) {
            this.err = e.message;
        } finally {
            this.busy = false;
        }
    },
    async act(which) {
        this.busy = true;
        this.flash = '';
        this.err = '';
        try {
            const response = await window.postJson(this.urls[which], {});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(data.message || 'Could not send.');
            this.flash = data.flash;
            this.em = data;
        } catch (e) {
            this.err = e.message;
        } finally {
            this.busy = false;
        }
    },
}; }
</script>
@endif
@endsection
