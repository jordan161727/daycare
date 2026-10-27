@extends('layouts.app')
@section('title', 'Weekly schedule')
@section('content')
{{--
    The Schedule — Phase 7 of docs/work.md, as Schedule.dc.html draws it.

    By teacher is the week as a table: a row per person, bars or plain times.
    By room is one day, room by room, with the coverage strip under each and
    the people not working that day in a tray beside it. The director edits
    in By room: drag a bar to another room, or a name into a room that is
    short, and a card says what that does to both rooms — and which Must
    rule it would break, which cannot be saved — before anything is saved.
    See schedulePage() at the foot; the checks themselves live in
    App\Services\ScheduleEditor.
--}}
@include('children.partials.mock-style')
@include('staff-schedule.partials.style')
<x-kids-background />

@php
    use App\Models\StaffRule;
    use Illuminate\Support\Carbon;

    $monday = Carbon::parse($weekStart);
    $last = $monday->copy()->addDays(count($dates) - 1);
    $rangeLabel = $monday->format('M j').' – '.$last->format($last->isSameMonth($monday) ? 'j' : 'M j');
    $span = max(1, $close - $open);
    $place = fn (int $from, int $to) => sprintf('left:%.2f%%;width:%.2f%%', (max($open, $from) - $open) / $span * 100, (min($close, $to) - max($open, $from)) / $span * 100);
    $link = fn (array $extra = []) => route('staff-schedule.index', array_filter(['week' => $weekStart, 'view' => $view, 'layout' => $layout === 'table' ? 'table' : null, 'day' => $day] + $extra, fn ($v) => $v !== null && $v !== ''));
    $plural = fn (int $n, string $word) => $n.' '.\Illuminate\Support\Str::plural($word, $n);
    $isThisWeek = $monday->isSameDay(today()->startOfWeek(Carbon::MONDAY));
    $hours = range((int) ceil($open / 60), (int) floor($close / 60), 2);
@endphp

<div class="ch" x-data="schedulePage(@js([
        'week' => $weekStart,
        'view' => $view,
        'layout' => $layout,
        'day' => $day,
        'days' => collect($dates)->map(fn ($date, $code) => ['code' => $code, 'label' => $date->format('D j'), 'gap' => (bool) ($gaps[$code] ?? false)])->values(),
        'd' => $dayData,
        'open' => $open, 'close' => $close,
        'colours' => $colours,
        'canEdit' => $isAdmin,
        'published' => (bool) $week?->published_at,
        'edits' => (int) ($week?->edits ?? 0),
        'urls' => ['day' => route('staff-schedule.day'), 'preview' => route('staff-schedule.preview'), 'apply' => route('staff-schedule.apply'), 'timeOff' => route('staff-schedule.time-off'), 'staff' => route('staff.index')],
    ]))" @keydown.escape.window="cancel()" data-view="{{ $view }}" style="display: flex; flex-direction: column; gap: 18px;">

    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;" class="muted">Staff</span>
            <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Schedule · {{ $rangeLabel }}</h1>
            <span style="font-size: 14px;" class="muted" data-status>
                @if($hidden)
                    <span class="pill warn">Not published</span> the director hasn't published this week yet
                @elseif($status)
                    <span class="pill {{ $status['tone'] }}">{{ $status['state'] }}</span> <span x-text="statusText()">{{ $status['text'] }}</span>
                @else
                    <span class="pill mut">No schedule</span> nothing built for this week yet{{ $isAdmin ? ' — generate one, or drag people into rooms' : '' }}
                @endif
            </span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
            <a class="btn" href="{{ $link(['week' => $monday->copy()->subWeek()->toDateString()]) }}">‹ Week</a>
            @unless($isThisWeek)<a class="btn" href="{{ $link(['week' => today()->startOfWeek(Carbon::MONDAY)->toDateString()]) }}">This week</a>@endunless
            <a class="btn" href="{{ $link(['week' => $monday->copy()->addWeek()->toDateString()]) }}">Week ›</a>
            @if($isAdmin)
                {{-- Generate rebuilds the week from the rules on each staff
                     record, and asks first in the app's own dialog rather than
                     the browser's. --}}
                <div x-data="{ confirming: false }" @keydown.escape.window="confirming = false">
                    <form method="POST" action="{{ route('staff-schedule.generate') }}" x-ref="generate">
                        @csrf
                        <input type="hidden" name="week" value="{{ $weekStart }}">
                        <input type="hidden" name="mode" value="{{ $view }}">
                        <button type="button" class="btn" @click="confirming = true">Generate</button>
                    </form>
                    <template x-teleport="body">
                    <div x-show="confirming" x-cloak x-transition.opacity class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/70 p-5 backdrop-blur-sm" @click="confirming = false">
                        <div @click.stop x-show="confirming" x-transition.scale.origin.center role="alertdialog" aria-modal="true" aria-labelledby="generate-title"
                             class="ch" style="width: 100%; max-width: 440px; background: #fff; border-radius: 16px; box-shadow: 0 10px 28px rgba(28,33,48,.16); padding: 22px; display: flex; flex-direction: column; gap: 12px;">
                            <h2 id="generate-title" style="margin: 0; font-size: 17px; font-weight: 700;">{{ $shifts->isEmpty() ? 'Generate this week?' : 'Regenerate this week?' }}</h2>
                            <p style="margin: 0; font-size: 13.5px; line-height: 1.5;" class="muted">{{ $monday->format('D M j') }} – {{ $last->format('D M j, Y') }}, built from the rules on each staff record.</p>
                            @if($shifts->isEmpty())
                                <p style="margin: 0; font-size: 13px; line-height: 1.5; background: #f2f4f9; border-radius: 10px; padding: 10px 12px;" class="muted">There is no schedule for this week yet, so nothing is replaced.</p>
                            @else
                                <p style="margin: 0; font-size: 13px; line-height: 1.5; background: #fef3c7; color: #92400e; border-radius: 10px; padding: 10px 12px;">The {{ $plural($shifts->count(), 'shift') }} on this week, including any moved by hand, {{ $shifts->count() === 1 ? 'is' : 'are' }} replaced with what the current rules produce. The result is a new draft. This cannot be undone.</p>
                            @endif
                            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                <button type="button" class="btn" @click="confirming = false">Cancel</button>
                                <button type="button" class="btn pri" @click="$refs.generate.submit()" x-ref="go" x-init="$watch('confirming', open => open && $nextTick(() => $refs.go.focus()))">{{ $shifts->isEmpty() ? 'Generate schedule' : 'Replace and regenerate' }}</button>
                            </div>
                        </div>
                    </div>
                    </template>
                </div>
                <a class="btn" href="{{ route('staff-schedule.print', ['week' => $weekStart]) }}" target="_blank" rel="noopener">Print for staff</a>
                <form method="POST" action="{{ route('staff-schedule.publish') }}">
                    @csrf
                    <input type="hidden" name="week" value="{{ $weekStart }}">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <button type="submit" class="btn pri" @disabled($shifts->isEmpty() && ! $week)>Publish</button>
                </form>
            @endif
        </div>
    </header>

    @if(session('success'))
        <div class="ch-card" style="padding: 12px 16px; background: #e3f5ec; border-color: #bfe6cf; color: #15803d; font-size: 14px; font-weight: 600;" role="status" data-flash>{{ session('success') }}</div>
    @endif

    @if($hidden)
        <section class="ch-card" style="padding: 40px 20px; text-align: center;">
            <p style="margin: 0; font-size: 15px; font-weight: 600;">This week's schedule isn't published yet.</p>
            <p style="margin: 6px 0 0; font-size: 13.5px;" class="muted">The director is still working on it. It will appear here, and on My Schedule, once it is published.</p>
        </section>
    @else

    {{-- ---- the switches and the legend ---- --}}
    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <div style="display: flex;" role="group" aria-label="View">
            <button type="button" class="vseg" :class="view === 'teacher' ? 'on' : ''" @click="show('teacher')">By teacher</button>
            <button type="button" class="vseg" :class="view === 'room' ? 'on' : ''" @click="show('room')">By room</button>
        </div>
        <div x-show="view === 'teacher'" style="display: flex;" role="group" aria-label="Layout">
            <button type="button" class="vseg" :class="layout === 'bars' ? 'on' : ''" @click="setLayout('bars')">Bars</button>
            <button type="button" class="vseg" :class="layout === 'table' ? 'on' : ''" @click="setLayout('table')">Simple table</button>
        </div>
        <div x-show="view === 'room'" x-cloak style="display: flex; gap: 6px; flex-wrap: wrap;" role="group" aria-label="Day" data-day-chips>
            <template x-for="dd in days" :key="dd.code">
                <button type="button" class="chip day" :class="dd.code === day ? 'on' : ''" @click="pickDay(dd.code)"><span x-text="dd.label"></span><span x-show="dd.gap" class="gapdot" title="Something to look at"></span></button>
            </template>
        </div>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; margin-left: auto;" class="muted">
            @foreach($rooms as $room)
                <span style="display: flex; gap: 5px; align-items: center;"><span class="dot" style="background: {{ $colours[$room][0] ?? '#f2f4f9' }}; border-color: {{ $colours[$room][1] ?? '#cfd3de' }};"></span>{{ $room }}</span>
            @endforeach
            <span>Dotted = moves rooms that day</span>
        </div>
    </div>

    {{-- ============================================================
         By room: one day, every room, and the tray beside it.
         ============================================================ --}}
    <div x-show="view === 'room'" x-cloak class="sch-wrap" data-room-view>
        <div class="sch-main">

            {{-- The pending change: what it does, before it is saved. --}}
            <section x-show="pending" x-cloak class="ch-card pendcard" data-pending>
                <span style="font-size: 16px; font-weight: 700;" x-text="pend ? pend.title : 'Checking…'"></span>
                <div x-show="pend && pend.opts.length" style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <template x-for="o in (pend ? pend.opts : [])" :key="o.k">
                        <button type="button" class="chip" :class="o.on ? 'on' : ''" @click="setOpt(o.k)" x-text="o.label"></button>
                    </template>
                </div>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <template x-for="(m, i) in (pend ? pend.impact : [])" :key="i">
                        <div style="display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; line-height: 1.45;"><span class="pill" :class="m.cls" x-text="m.tag"></span><span x-text="m.text"></span></div>
                    </template>
                </div>
                <p x-show="error" x-cloak class="flash bad" style="margin: 0; padding: 10px 12px; border-radius: 10px; font-size: 13px; background: #ffe4e6; color: #be123c;" x-text="error"></p>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <button type="button" class="btn pri" :disabled="! pend || pend.blocked || busy" @click="apply()" x-text="busy ? 'Saving…' : (pend ? pend.applyLabel : 'Apply')"></button>
                    <button type="button" class="btn" @click="cancel()">Cancel</button>
                    <span x-show="pend && pend.blocked" style="font-size: 13px; color: #be123c;">Blocked by a Must rule.</span>
                </div>
            </section>

            {{-- Time off that runs into the schedule, with the fix on offer. --}}
            <section x-show="d && d.conf.length" x-cloak class="ch-card confcard" data-conflicts>
                <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;"><span style="font-size: 15px; font-weight: 700;">Time off on <span x-text="d ? d.dayShort : ''"></span> clashes with the schedule</span><span style="font-size: 12.5px;" class="muted">Entered in Staff → Time off (or from a bar here)</span></div>
                <template x-for="c in (d ? d.conf : [])" :key="c.id">
                    <div class="conf-row">
                        <div style="flex: 1 1 320px; display: flex; flex-direction: column; gap: 2px;"><span style="font-weight: 700; font-size: 13.5px;" x-text="c.title"></span><span style="font-size: 12.5px;" class="muted" x-text="c.detail"></span></div>
                        <button type="button" class="btn pri" x-show="canEdit" @click="fix(c)" x-text="c.fixLabel"></button>
                    </div>
                </template>
            </section>

            {{-- Who could stand in a room that is short. --}}
            <section x-show="d && d.cover.length && ! pending && ! (d.conf.length)" x-cloak class="ch-card covercard" data-cover>
                <span style="font-size: 15px; font-weight: 700;">Who could cover</span>
                <template x-for="(g, i) in (d ? d.cover : [])" :key="i">
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px;">
                        <span style="min-width: 210px;"><b x-text="g.room"></b> short <span x-text="g.when"></span></span>
                        <template x-for="w in g.who" :key="w.user_id">
                            <button type="button" class="chip" :disabled="! canEdit" @click="add(w.user_id, g.room)"><span x-text="w.name"></span>&nbsp;<span style="font-weight: 500;" class="muted">· <span x-text="w.note"></span></span></button>
                        </template>
                        <span x-show="g.none" style="color: #be123c;">Nobody free who fits the rules — call a substitute.</span>
                    </div>
                </template>
                <span style="font-size: 12px;" class="faint">Only people who are free then and don’t break a Must rule — a room they are never in, a day they can’t work, someone they are never scheduled with.</span>
            </section>

            {{-- The day in one line. --}}
            <section class="ch-card" style="padding: 12px 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;" data-day-status>
                <span style="font-size: 14px; font-weight: 700; margin-right: 4px;" x-text="d ? d.dayName : ''"></span>
                <span x-show="d && d.closed" class="pill mut">Centre closed · <span x-text="d ? d.closed : ''"></span></span>
                <span x-show="d && d.ok" class="pill ok">Every room at ratio</span>
                <template x-for="(i, k) in (d ? d.issues : [])" :key="k"><span class="pill" :class="i.cls" x-text="i.text"></span></template>
            </section>

            <div style="display: flex; align-items: center; gap: 10px; padding: 0 14px;">
                <span style="width: 112px; flex: none;"></span>
                <div class="axis">
                    @foreach($hours as $h)<span style="left: {{ ($h * 60 - $open) / $span * 100 }}%;">{{ StaffRule::compactTime($h * 60) }}</span>@endforeach
                </div>
            </div>

            <template x-for="r in (d ? d.rooms : [])" :key="r.key">
                <section class="rblock" :class="over === r.key ? 'drop' : ''" @dragover.prevent @dragenter.prevent="over = r.key" @dragleave="over === r.key && (over = null)" @drop.prevent="drop(r.key)" :data-room="r.key">
                    <div class="rhead">
                        <span class="dot" :style="'background:' + r.fill + ';border-color:' + r.border"></span>
                        <span style="font-weight: 700; font-size: 15px;" :style="'color:' + r.text" x-text="r.key"></span>
                        <span style="font-size: 13px;" class="muted" x-text="r.kidsLine"></span>
                        <span style="margin-left: auto; display: flex; gap: 6px; flex-wrap: wrap;"><span class="pill" :class="r.capCls" x-text="r.capLine"></span><span class="pill" :class="r.stCls" x-text="r.status"></span></span>
                    </div>
                    <div style="padding: 8px 0 4px;">
                        <template x-for="l in r.lanes" :key="l.id">
                            <div class="lane">
                                <span class="lname" x-text="l.name"></span>
                                <div class="track">
                                    <template x-for="(o, i) in l.offs" :key="i"><span class="offb" :style="pos(o.from, o.to)" x-text="o.label"></span></template>
                                    <button type="button" class="bar" :class="(l.float ? 'float ' : '') + (sel === l.id ? 'sel ' : '') + (l.conf ? 'conf ' : '') + (canEdit ? '' : 'still')"
                                            :style="pos(l.from, l.to) + ';background:' + r.fill + ';border-color:' + r.border + ';color:' + r.text"
                                            :draggable="canEdit" @dragstart="dragStart($event, {kind: 'move', id: l.id, room: r.key})" @dragend="over = null" @click="select(l.id)" :title="l.title" x-text="l.label"></button>
                                </div>
                            </div>
                        </template>
                        <div x-show="r.empty" style="padding: 6px 14px; font-size: 13px;" class="faint">No staff in this room today<span x-show="canEdit"> — drop someone here</span>.</div>
                        <div class="lane" style="padding-top: 6px; padding-bottom: 8px;">
                            <span class="lname" style="font-size: 12px;" class="muted">Coverage</span>
                            <div class="covtrack"><template x-for="(c, i) in r.cov" :key="i"><span class="cs" :class="c.cls" :style="pos(c.from, c.to)" :title="c.tip" x-text="c.t"></span></template></div>
                        </div>
                    </div>
                </section>
            </template>
        </div>

        <aside class="sch-side">
            <section class="ch-card" style="padding: 16px; display: flex; flex-direction: column; gap: 10px;" data-tray>
                <span style="font-size: 15px; font-weight: 700;">Not working <span x-text="d ? d.dayShort : ''"></span></span>
                <span style="font-size: 12.5px;" class="muted" x-text="canEdit ? 'Drag someone into a room to add a shift, or click a name and pick a room.' : 'Who is off today, and why.'"></span>
                <template x-for="t in (d ? d.tray : [])" :key="t.user_id">
                    <button type="button" class="tray" :class="(t.ok ? '' : 'no ') + (trayPick === t.user_id ? 'on' : '')" :draggable="canEdit && t.ok" @dragstart="dragStart($event, {kind: 'add', user_id: t.user_id})" @dragend="over = null" @click="pickTray(t)">
                        <span style="font-weight: 600;" x-text="t.name"></span><span style="margin-left: auto; font-size: 12px;" class="muted" x-text="t.note"></span>
                    </button>
                </template>
                <span x-show="d && d.tray.length === 0" style="font-size: 13px;" class="faint">Everyone is working today.</span>
            </section>

            <section class="ch-card" style="padding: 16px; display: flex; flex-direction: column; gap: 10px;" data-selection>
                <span style="font-size: 15px; font-weight: 700;" x-text="selTitle()"></span>
                <span style="font-size: 13px; line-height: 1.5;" class="muted" x-text="selHelp()"></span>
                <div x-show="canEdit && (selShift() || trayPick)" style="display: flex; flex-wrap: wrap; gap: 6px;">
                    <template x-for="room in moveRooms()" :key="room">
                        <button type="button" class="chip" @click="moveTo(room)" x-text="room"></button>
                    </template>
                </div>
                <div x-show="canEdit && selShift()" x-cloak style="border-top: 1px solid #f0f1f5; padding-top: 10px; display: flex; flex-direction: column; gap: 8px;" data-time-off>
                    <span style="font-size: 13px; font-weight: 700;">Time off for <span x-text="selShift() ? selShift().name : ''"></span> on <span x-text="d ? d.dayShort : ''"></span></span>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <template x-for="k in [['all', 'Out all day'], ['until', 'Out until…'], ['leave', 'Leaving at…']]" :key="k[0]">
                            <button type="button" class="tchip" :class="to.kind === k[0] ? 'on' : ''" @click="to.kind = k[0]" x-text="k[1]"></button>
                        </template>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                        <select class="sinp" x-show="to.kind !== 'all'" x-model.number="to.minute" aria-label="Time">
                            @foreach(range(8 * 60, 17 * 60, 60) as $m)<option value="{{ $m }}">{{ StaffRule::compactTime($m) }}</option>@endforeach
                        </select>
                        <select class="sinp" x-model="to.reason" aria-label="Reason">
                            @foreach(['appointment' => 'Appointment', 'sick' => 'Sick', 'personal' => 'Personal', 'vacation' => 'Vacation', 'family' => 'Family'] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                        <button type="button" class="btn pri" style="min-height: 32px; padding: 5px 12px;" :disabled="busy" @click="saveTimeOff()">Save</button>
                    </div>
                    <p x-show="toError" x-cloak style="margin: 0; font-size: 12.5px; color: #be123c;" x-text="toError"></p>
                    <a :href="selShift() ? urls.staff + '/' + selShift().user_id + '?tab=off' : urls.staff" style="font-size: 12.5px; font-weight: 600;">All of <span x-text="selShift() ? selShift().name : ''"></span>’s time off → Staff</a>
                </div>
            </section>

            <section class="ch-card" style="padding: 16px; display: flex; flex-direction: column; gap: 8px; font-size: 13px; line-height: 1.5;" class="muted">
                <span style="font-size: 15px; font-weight: 700; color: #1c2130;">How the checks work</span>
                <span class="muted">Before a change is saved you see what it does to both rooms: <b style="color: #be123c;">short</b> (below ratio), <b style="color: #1b5e91;">more staff than needed</b>, and any Must rule it would break — those can't be saved. A Prefer rule is only reported.</span>
            </section>
        </aside>
    </div>

    {{-- ============================================================
         By teacher: the week as a table.
         ============================================================ --}}
    <section x-show="view === 'teacher'" class="ch-card" style="overflow: hidden;" data-teacher-view>
        <div style="overflow-x: auto;">
            <table class="tbl sched">
                <thead><tr><th>Teacher</th>@foreach($dates as $date)<th>{{ $date->format('D j') }} <span style="font-weight: 400; text-transform: none; letter-spacing: 0;">{{ StaffRule::compactTime($open) }} – {{ StaffRule::compactTime($close) }}</span></th>@endforeach<th style="text-align: right;">Hours</th></tr></thead>
                <tbody>
                    @foreach($teach as $t)
                        <tr data-teacher-row="{{ $t['id'] }}">
                            <td style="white-space: nowrap;"><div style="display: flex; flex-direction: column;"><a href="{{ route('staff.show', $t['id']) }}" style="font-weight: 700; color: inherit;">{{ $t['name'] }}</a><span style="font-size: 12px;" class="faint">{{ $t['role'] }}</span></div></td>
                            @foreach($dates as $code => $date)
                                @php($dd = $t['days'][$code])
                                <td class="daycell">
                                    <div x-show="layout === 'bars'">
                                        <div class="track">
                                            @foreach($dd['bars'] as $b)
                                                @if($b['off'])
                                                    <span class="offb" style="{{ $place($b['from'], $b['to']) }}" title="{{ $b['title'] }}">{{ $b['label'] }}</span>
                                                @else
                                                    <span class="bar still {{ $b['float'] ? 'float' : '' }}" style="{{ $place($b['from'], $b['to']) }}; background: {{ $b['fill'] }}; border-color: {{ $b['border'] }}; color: {{ $b['text'] }};" title="{{ $b['title'] }}">{{ $b['label'] }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                        @if($dd['off'])<span style="font-size: 12px;" class="faint">{{ $dd['offText'] }}</span>@endif
                                    </div>
                                    {{-- The column layout is a class: x-show clears an inline
                                         display when it shows the box, and the lines would run on. --}}
                                    <div x-show="layout === 'table'" x-cloak class="lines">
                                        @foreach($dd['lines'] as $x)<span style="font-size: 13px; white-space: nowrap;"><b>{{ $x['time'] }}</b> <span style="color: {{ $x['color'] }};">{{ $x['room'] }}</span></span>@endforeach
                                        @if($dd['off'])<span style="font-size: 13px;" class="faint">{{ $dd['offText'] }}</span>@endif
                                    </div>
                                </td>
                            @endforeach
                            <td style="text-align: right; white-space: nowrap;"><span class="pill {{ $t['hCls'] }}">{{ $t['hrs'] }}</span></td>
                        </tr>
                    @endforeach
                    @if($teach === [])
                        <tr><td colspan="{{ count($dates) + 2 }}" style="text-align: center; padding: 28px;" class="muted">Nobody on the team yet. Add staff, then generate the week.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>

<script>
/*
 * The page: which view, which day, and — in By room — the change being made.
 *
 * Every change goes to the server twice: once as a preview, which comes back
 * as the pending card (the options, and what the change does to both rooms),
 * and once to apply, which comes back as the whole day repainted. The checks
 * are the server's — see ScheduleEditor — so a change the rules would refuse
 * is refused whether or not the card said so first.
 */
function schedulePage(opts) { return {
    week: opts.week,
    view: opts.view,
    layout: opts.layout,
    day: opts.day,
    days: opts.days,
    d: opts.d,
    open: opts.open, close: opts.close,
    canEdit: opts.canEdit,
    published: opts.published,
    edits: opts.edits,
    urls: opts.urls,

    pending: null,     // the change being made: {kind, id | user_id, to, opt}
    pend: null,        // the server's reading of it
    sel: null,         // a bar, by shift id
    trayPick: null,    // a name in the tray, by user id
    over: null,        // the room a drag is over
    drag: null,
    busy: false,
    error: '',
    to: {kind: 'until', minute: 15 * 60, reason: 'appointment'},
    toError: '',

    statusText() {
        if (this.edits === 0) return @js($status['text'] ?? '');
        const n = this.edits + (this.edits === 1 ? ' change' : ' changes');
        return this.published ? n + ' since publishing — publish again to share them' : "staff can't see it until you publish · " + n + ' since generating';
    },

    /* ---- where on the lane ---- */
    pos(a, b) {
        const span = this.close - this.open;
        const from = Math.max(this.open, a), to = Math.min(this.close, b);
        return 'left:' + ((from - this.open) / span * 100).toFixed(2) + '%;width:' + (Math.max(0, to - from) / span * 100).toFixed(2) + '%';
    },

    /* ---- views ---- */
    show(view) {
        this.view = view;
        this.remember();
    },
    setLayout(layout) {
        this.layout = layout;
        this.remember();
    },
    remember() {
        const url = new URL(window.location.href);
        url.searchParams.set('view', this.view);
        url.searchParams.set('day', this.day);
        if (this.layout === 'table') url.searchParams.set('layout', 'table'); else url.searchParams.delete('layout');
        history.replaceState(null, '', url);
    },

    async pickDay(code) {
        if (code === this.day && this.d) return;
        this.day = code;
        this.cancel();
        this.sel = null;
        this.trayPick = null;
        this.remember();
        try {
            const response = await fetch(this.urls.day + '?week=' + this.week + '&day=' + code, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not load that day.');
            this.d = await response.json();
        } catch (e) {
            this.error = e.message;
        }
    },

    /* ---- selection ---- */
    selShift() {
        if (! this.d || this.sel === null) return null;
        for (const r of this.d.rooms) {
            const l = r.lanes.find(x => x.id === this.sel);
            if (l) return {...l, room: r.key};
        }
        return null;
    },
    select(id) {
        this.sel = this.sel === id ? null : id;
        this.trayPick = null;
    },
    pickTray(t) {
        if (! this.canEdit || ! t.ok) return;
        this.trayPick = this.trayPick === t.user_id ? null : t.user_id;
        this.sel = null;
    },
    trayPerson() {
        return this.d && this.trayPick ? this.d.tray.find(t => t.user_id === this.trayPick) : null;
    },
    selTitle() {
        const s = this.selShift();
        if (s) return s.name + ' · ' + s.room + ' ' + s.label.replace(' → ', '–');
        const t = this.trayPerson();
        if (t) return 'Add ' + t.name + ' to a room';
        return this.canEdit ? 'Move someone' : 'Shifts';
    },
    selHelp() {
        if (! this.canEdit) return 'Click a bar to see its times. Ask the director to change a shift.';
        if (this.selShift()) return 'Move this shift to another room — the whole shift, or from a time in the afternoon.';
        if (this.trayPerson()) return 'Pick the room. The next card shows what it does to coverage before anything is saved.';
        return 'Drag a bar onto another room, or click a bar and pick a room here.';
    },
    moveRooms() {
        const s = this.selShift();
        if (! this.d) return [];
        return this.d.moveTo.filter(room => ! s || room !== s.room);
    },
    moveTo(room) {
        const s = this.selShift();
        if (s) return this.propose({kind: 'move', id: s.id, to: room});
        if (this.trayPick) return this.propose({kind: 'add', user_id: this.trayPick, to: room});
    },
    add(userId, room) {
        this.propose({kind: 'add', user_id: userId, to: room});
    },
    fix(c) {
        this.propose(c.keep ? {kind: 'trim', id: c.id, from: c.keep[0], to: c.keep[1]} : {kind: 'remove', id: c.id});
    },

    /* ---- drag and drop ---- */
    dragStart(event, payload) {
        if (! this.canEdit) { event.preventDefault(); return; }
        try { event.dataTransfer.setData('text/plain', JSON.stringify(payload)); event.dataTransfer.effectAllowed = 'move'; } catch (e) {}
        this.drag = payload;
    },
    drop(room) {
        const g = this.drag;
        this.drag = null;
        this.over = null;
        if (! g || ! this.canEdit) return;
        if (g.kind === 'move') {
            if (g.room !== room) { this.sel = g.id; this.propose({kind: 'move', id: g.id, to: room}); }
            return;
        }
        this.propose({kind: 'add', user_id: g.user_id, to: room});
    },

    /* ---- the pending change ---- */
    async propose(change) {
        this.pending = change;
        this.pend = null;
        this.error = '';
        await this.preview();
        this.$nextTick(() => document.querySelector('[data-pending]')?.scrollIntoView({behavior: 'smooth', block: 'nearest'}));
    },
    setOpt(k) {
        if (! this.pending) return;
        this.pending = {...this.pending, opt: k};
        this.preview();
    },
    async preview() {
        if (! this.pending) return;
        const mine = this.pending;
        try {
            const response = await window.postJson(this.urls.preview, {week: this.week, day: this.day, ...mine});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not check that change.');
            if (this.pending === mine) { this.pend = data; this.pending = {...mine, opt: data.opt}; }
        } catch (e) {
            this.error = e.message;
        }
    },
    async apply() {
        if (! this.pending || ! this.pend || this.pend.blocked || this.busy) return;
        this.busy = true;
        this.error = '';
        try {
            const response = await window.postJson(this.urls.apply, {week: this.week, day: this.day, ...this.pending});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not save that change.');
            this.d = data;
            this.edits++;
            this.pending = null;
            this.pend = null;
            this.sel = null;
            this.trayPick = null;
            this.refreshGap();
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
        }
    },
    cancel() {
        this.pending = null;
        this.pend = null;
        this.error = '';
    },
    refreshGap() {
        const dd = this.days.find(x => x.code === this.day);
        if (dd && this.d) dd.gap = this.d.issues.length > 0 || this.d.conf.length > 0;
    },

    /* ---- time off from a bar ---- */
    async saveTimeOff() {
        const s = this.selShift();
        if (! s || this.busy) return;
        this.busy = true;
        this.toError = '';
        try {
            const response = await window.postJson(this.urls.timeOff, {week: this.week, day: this.day, user_id: s.user_id, kind: this.to.kind, minute: this.to.kind === 'all' ? null : this.to.minute, reason: this.to.reason});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not save the time off.');
            this.d = data;
            this.refreshGap();
        } catch (e) {
            this.toError = e.message;
        } finally {
            this.busy = false;
        }
    },
}; }
</script>
@endsection
