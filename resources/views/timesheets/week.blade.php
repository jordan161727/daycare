@extends('layouts.app')
@section('title', 'Staff attendance')
@section('content')
{{--
    Staff attendance — Phase 6 of docs/work.md, as Main.dc.html draws it.

    Live is the morning question: is everyone here who should be, read from
    today's punches against today's shifts. History is the week — five days
    across, one row per person — with a coloured day wherever something does
    not add up, and the Correct a day panel beside it.

    The grid reads; the panel writes — through the punch endpoints, which
    record who changed what and why. The original punch is always kept. See
    staffAttendance() at the foot.
--}}
@include('children.partials.mock-style')
@include('timesheets.partials.attendance-style')
<x-kids-background />

@php
    use App\Http\Controllers\StaffTimesheetController as Grid;
    use App\Http\Controllers\TimePunchController as Panel;
    use App\Models\TimePunch;

    $fmt = fn ($date) => $date->toDateString();
    $counts = $live['counts'];
    $fixCount = $rows->sum('fix');
    $lateCount = $rows->sum('late');
    $editCount = $rows->sum('edited');

    $keep = array_filter(['role' => $role]) + ['view' => 'hist'];
    $weekBack = $keep + ['from' => $start->copy()->subWeek()->toDateString(), 'to' => $end->copy()->subWeek()->toDateString()];
    $weekOn = $keep + ['from' => $start->copy()->addWeek()->toDateString(), 'to' => $end->copy()->addWeek()->toDateString()];
    $weekLabel = $start->format('M j').' – '.$end->format($start->isSameMonth($end) ? 'j' : 'M j');
    $isThisWeek = $start->isSameDay(today()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)) && $end->isSameDay(today()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDays(4));

    // The reasons the panel offers, in the order the mock-up lists them.
    // Every one is a code the record knows — see TimePunch::REASONS.
    $reasons = collect(['forgot_in', 'forgot_out', 'device', 'wrong_time', 'other'])->mapWithKeys(fn ($code) => [$code => TimePunch::REASONS[$code]]);
@endphp

<div class="ch" x-data="staffAttendance(@js([
        'view' => $view,
        'from' => $start->toDateString(),
        'to' => $end->toDateString(),
        'fixCount' => $fixCount, 'lateCount' => $lateCount, 'editCount' => $editCount,
        'log' => $log,
    ]))" @keydown.escape.window="cancel()" data-view="{{ $view }}" style="display: flex; flex-direction: column; gap: 20px;">

    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;" class="muted">Staff</span>
            <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Staff attendance</h1>
            <span style="font-size: 14px;" class="muted">Punches from the front-desk staff clock · {{ today()->format('l, M j') }}</span>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <div class="vtwrap" role="group" aria-label="View">
                <button type="button" class="vt" :class="view === 'live' ? 'on' : ''" :aria-pressed="view === 'live'" @click="show('live')">Live</button>
                <button type="button" class="vt" :class="view === 'hist' ? 'on' : ''" :aria-pressed="view === 'hist'" @click="show('hist')">History</button>
            </div>
            {{-- The export is the week on screen: same dates, same role. --}}
            <a class="btn" href="{{ route('staff.timesheets.export', array_filter(['role' => $role]) + ['from' => $start->toDateString(), 'to' => $end->toDateString(), 't' => now()->timestamp]) }}">Export</a>
        </div>
    </header>

    @if($truncated)
        <div class="ch-card" style="padding: 12px 16px; background: #fef3c7; border-color: #f3d27a; color: #92400e; font-size: 13.5px;">
            That range is longer than {{ Grid::MAX_DAYS }} days. Showing the first {{ Grid::MAX_DAYS }}, because a column per day past that is a table nobody can read.
        </div>
    @endif

    {{-- ============================================================
         Live: today, this minute.
         ============================================================ --}}
    {{-- Layout in classes, not inline: x-show restores display to '' when
         it shows an element again, which would drop an inline flex. --}}
    <div x-show="view === 'live'" x-cloak class="live-wrap" data-live>
        <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px;" data-live-cards>
            <div class="ch-card stat-card"><span class="l">Clocked in now</span><span class="n">{{ $counts['in'] }} <small>of {{ $counts['due_now'] }} due by now</small></span></div>
            <div class="ch-card stat-card"><span class="l">Late / not in</span><span class="n" style="{{ $counts['late'] > 0 ? 'color: #be123c;' : '' }}">{{ $counts['late'] }}</span></div>
            <div class="ch-card stat-card"><span class="l">Due later today</span><span class="n">{{ $counts['due'] }}</span></div>
            <div class="ch-card stat-card"><span class="l">Off today</span><span class="n">{{ $counts['off'] }}</span></div>
            <div class="ch-card stat-card"><span class="l">Hours so far today</span><span class="n">{{ $counts['hours'] }}</span></div>
        </section>

        <section class="ch-card" style="overflow: hidden;">
            <div style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; border-bottom: 1px solid #e7e9f0;">
                <h2 style="margin: 0; font-size: 17px; font-weight: 700;">Today · live as of <span x-text="now">{{ now()->format('g:i A') }}</span></h2>
                {{-- The pills narrow the list without a round trip: every
                     row is already here. "Done for today" is under All only. --}}
                <div style="display: flex; gap: 6px; flex-wrap: wrap;" role="group" aria-label="Filter staff">
                    @foreach([
                        'all' => ['All', $counts['all'], 'mut'],
                        'in' => ['Clocked in', $counts['in'], 'ok'],
                        'late' => ['Not in', $counts['late'], 'bad'],
                        'due' => ['Due later', $counts['due'], 'mut'],
                        'off' => ['Off', $counts['off'], 'mut'],
                    ] as $key => [$label, $count, $tone])
                        <button type="button" class="pill filter {{ $tone }}" :class="filter === '{{ $key }}' ? 'on' : ''" :aria-pressed="filter === '{{ $key }}'" @click="filter = '{{ $key }}'">{{ $label }} {{ $count }}</button>
                    @endforeach
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="tbl att" data-live-table>
                    <thead><tr><th>Staff</th><th>Room now</th><th>Scheduled</th><th>In</th><th>Out</th><th>Status</th><th style="text-align: right;">Hours</th></tr></thead>
                    <tbody>
                        @foreach($live['rows'] as $s)
                            <tr x-show="filter === 'all' || filter === '{{ $s['key'] }}'" data-live-row="{{ $s['id'] }}" data-live-key="{{ $s['key'] }}">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        @if($s['avatar'])
                                            <img src="{{ $s['avatar'] }}" alt="" class="av" style="width: 30px; height: 30px; object-fit: cover;" loading="lazy" decoding="async">
                                        @else
                                            <span class="av" style="width: 30px; height: 30px; background: #dcebfa; color: #1b5e91;" aria-hidden="true">{{ $s['initials'] }}</span>
                                        @endif
                                        <div style="display: flex; flex-direction: column;">
                                            <a href="{{ route('staff.show', $s['id']) }}" style="font-weight: 600; color: inherit;">{{ $s['name'] }}</a>
                                            <span style="font-size: 12px;" class="muted">{{ $s['role'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $s['room'] }}</td>
                                <td class="muted">{{ $s['sched'] }}</td>
                                <td style="font-weight: 600;">{{ $s['in'] }}</td>
                                <td class="muted">{{ $s['out'] }}</td>
                                <td><span class="pill {{ $s['tone'] }}">{{ $s['status'] }}</span></td>
                                <td style="text-align: right; font-variant-numeric: tabular-nums;">{{ $s['hours'] }}</td>
                            </tr>
                        @endforeach
                        @if($live['rows'] === [])
                            <tr><td colspan="7" style="text-align: center; padding: 24px;" class="muted">Nobody on the team yet.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ============================================================
         History: the week, and the panel that corrects a day.
         ============================================================ --}}
    <div x-show="view === 'hist'" x-cloak class="hist-wrap" data-history>
        <section class="ch-card" style="flex: 999 1 720px; min-width: 0; overflow: hidden;">
            <div style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; border-bottom: 1px solid #e7e9f0;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <a class="btn" href="{{ route('staff.timesheets', $weekBack) }}" aria-label="Previous week" title="Previous week">‹</a>
                    <h2 style="margin: 0; font-size: 17px; font-weight: 700;">Week of {{ $weekLabel }}</h2>
                    <a class="btn" href="{{ route('staff.timesheets', $weekOn) }}" aria-label="Next week" title="Next week">›</a>
                    @unless($isThisWeek)
                        {{-- Back to the ordinary answer in one press. --}}
                        <a class="btn" href="{{ route('staff.timesheets', $keep) }}">This week</a>
                    @endunless
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;" data-week-counts>
                    <span class="pill bad" x-text="fixCount + ' to fix'">{{ $fixCount }} to fix</span>
                    <span class="pill warn" x-text="lateCount + ' late'">{{ $lateCount }} late</span>
                    <span class="pill info" x-text="editCount + ' corrected'">{{ $editCount }} corrected</span>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table class="tbl att" data-week-grid>
                    <thead>
                        <tr>
                            <th>Staff</th>
                            @foreach($dates as $date)
                                <th class="{{ $date->isToday() ? 'today' : '' }}" style="{{ $date->isToday() ? 'color: #1b5e91;' : '' }}">
                                    <span aria-hidden="true">{{ $date->format('D j') }}</span>
                                    <span class="sr-only">{{ $date->format('D M j') }}</span>
                                </th>
                            @endforeach
                            <th style="text-align: right;">Week</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr data-week-row="{{ $row['id'] }}">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        @if($row['avatar'])
                                            <img src="{{ $row['avatar'] }}" alt="" class="av" style="width: 30px; height: 30px; object-fit: cover;" loading="lazy" decoding="async">
                                        @else
                                            <span class="av" style="width: 30px; height: 30px; background: #dcebfa; color: #1b5e91;" aria-hidden="true">{{ $row['initials'] }}</span>
                                        @endif
                                        <div style="display: flex; flex-direction: column;">
                                            <a href="{{ route('staff.show', $row['id']) }}" style="font-weight: 600; color: inherit;" title="{{ $row['role'] }}">{{ $row['name'] }}</a>
                                            {{-- The ID alone: the job is on the Live list and the name's
                                                 hover, and the week's seven columns need the room. --}}
                                            <span style="font-size: 12px; font-variant-numeric: tabular-nums;" class="muted">{{ $row['staff_id'] }}</span>
                                        </div>
                                    </div>
                                </td>
                                @foreach($dates as $date)
                                    @php($day = $row['days'][$fmt($date)])
                                    <td class="day {{ $date->isToday() ? 'today' : '' }}" data-cell="{{ $row['id'] }}|{{ $fmt($date) }}">
                                        @if($day['fixable'])
                                            {{-- A link, not a button, so a page with no script still
                                                 leads to the screen that puts the day right. Painted
                                                 again by cellHtml() after a save, so the two agree. --}}
                                            <a href="{{ route('timesheets.fix', ['user' => $row['id'], 'date' => $fmt($date)]) }}"
                                               @click.prevent="open({{ $row['id'] }}, '{{ $fmt($date) }}')"
                                               :class="isOpen({{ $row['id'] }}, '{{ $fmt($date) }}') ? 'sel' : ''"
                                               class="cell {{ $day['cls'] }}" data-flag="{{ $day['flag'] }}"
                                               title="{{ $day['label'] ?? $day['top'] }} — open {{ $row['name'] }}’s day{{ $day['fine'] ? ' to review' : '' }}"
                                               aria-label="{{ $row['name'] }}, {{ $date->format('D M j') }}: {{ $day['spoken'] }}. Correct this day">
                                                <span class="t">{{ $day['top'] }}</span>
                                                <span class="s">{{ $day['sub'] }}</span>
                                            </a>
                                        @else
                                            <span class="cell {{ $day['cls'] }}" aria-label="{{ $row['name'] }}, {{ $date->format('D M j') }}: {{ $day['spoken'] }}">
                                                <span class="t">{{ $day['top'] }}</span>
                                                <span class="s">{{ $day['sub'] }}</span>
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                                <td style="text-align: right; font-weight: 700; font-variant-numeric: tabular-nums;" data-hours="{{ $row['id'] }}">{{ $row['total'] }}</td>
                            </tr>
                        @endforeach
                        @if($rows->isEmpty())
                            <tr><td colspan="{{ $dates->count() + 2 }}" style="text-align: center; padding: 24px;" class="muted">No staff match that filter.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div style="padding: 10px 20px; border-top: 1px solid #f0f1f5; font-size: 12.5px;" class="faint">
                Click any day to correct it. Amber and red days need a fix; late is more than {{ \App\Services\PaidHours::lateGrace() }} minutes after the scheduled start. Every change keeps the original punch and records who made it and why.
            </div>
        </section>

        {{-- ---- Correct a day ---- --}}
        <aside class="ch-card" style="flex: 1 1 300px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 14px;" data-correct-panel>
            <div x-show="! p && ! loading" style="display: flex; flex-direction: column; gap: 6px;">
                <span style="font-size: 17px; font-weight: 700;">Correct a day</span>
                <span style="font-size: 14px; line-height: 1.5;" class="muted">Click any day to fix it — for example a teacher who forgot to clock in. The original punch is kept and the change is logged with your name.</span>
                <p x-show="flash" x-cloak class="flash ok" x-text="flash"></p>
                <p x-show="error && ! p" x-cloak class="flash bad" x-text="error"></p>
            </div>

            <p x-show="loading" x-cloak style="font-size: 14px;" class="muted">Opening the day…</p>

            <template x-if="p && ! loading">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: flex; flex-direction: column; gap: 2px;">
                        <span style="font-size: 17px; font-weight: 700;"><span x-text="p.staff.name"></span> · <span x-text="p.day_label"></span></span>
                        <span style="font-size: 13px;" class="muted"><span x-text="p.sched ? 'Scheduled ' + p.sched : 'Not scheduled'"></span><span x-show="p.room"> · <span x-text="p.room"></span></span></span>
                    </div>

                    <div class="recorded">
                        <span class="lbl">What the clock recorded</span>
                        <span x-text="p.orig"></span>
                    </div>

                    <div x-show="p.locked" x-cloak class="flash bad">This period has been approved, so the punches are a record now. Reopen the period first if something genuinely has to change.</div>

                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px;">
                        <label class="fld"><span class="lbl">Clock in</span><input class="inp att" type="time" step="60" x-model="edIn" :disabled="p.locked"></label>
                        <label class="fld"><span class="lbl">Clock out</span><input class="inp att" type="time" step="60" x-model="edOut" :disabled="p.locked"></label>
                        <label class="fld"><span class="lbl">Unpaid break (min)</span><input class="inp att" type="number" min="0" max="240" step="5" x-model.number="edBreak" :disabled="p.locked"></label>
                        <label class="fld"><span class="lbl">Counts as</span>
                            <select class="inp att" x-model="countsAs" :disabled="p.locked">
                                @foreach(Panel::COUNTS_AS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                    </div>
                    <label class="fld"><span class="lbl">Reason (required)</span>
                        <select class="inp att" x-model="reason" :disabled="p.locked">
                            <option value="">Choose a reason…</option>
                            @foreach($reasons as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                        </select>
                    </label>
                    <label class="fld"><span class="lbl" x-text="reason === 'other' ? 'Note (required for Other)' : 'Note'"></span><input class="inp att" x-model="note" maxlength="120" placeholder="e.g. confirmed with Lana, arrived 12:00" :disabled="p.locked"></label>

                    {{-- A break or a lunch nobody came back off: the clock refused
                         everything after it, so the day pays nothing. The save
                         closes it, and says so here rather than quietly. --}}
                    <p x-show="dangling()" x-cloak style="font-size: 12.5px; line-height: 1.45;" class="muted" x-text="danglingNote()"></p>

                    <p x-show="error" x-cloak class="flash bad" x-text="error"></p>
                    <p x-show="! error && changes().length === 0 && countsAs === p.counts_as" style="font-size: 12.5px;" class="faint">Change a time, the break, or what the day counts as, then say why.</p>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="btn pri" @click="save()" :disabled="! canSave()" x-text="saving ? 'Saving…' : 'Save correction'"></button>
                        <button type="button" class="btn" @click="cancel()">Cancel</button>
                    </div>
                </div>
            </template>

            <div style="border-top: 1px solid #f0f1f5; padding-top: 12px; display: flex; flex-direction: column; gap: 8px;" data-week-log>
                <span class="lbl">Changes this week</span>
                <template x-if="log.length === 0"><span class="logline muted">No corrections yet.</span></template>
                <template x-for="(l, i) in log" :key="i">
                    <span class="logline"><b x-text="l.who"></b> · <span x-text="l.name"></span>, <span x-text="l.day"></span>: <span x-text="l.what"></span><span x-show="l.reason" class="muted"> (<span x-text="l.reason"></span>)</span></span>
                </template>
            </div>
        </aside>
    </div>
</div>

<script>
/*
 * The page and its panel.
 *
 * The panel turns a day into three fields — in, out, unpaid break — and a
 * word for what it counts as, and turns those back into the clock's own
 * changes on save: move a punch, add one, take a pair off. The server walks
 * the day the changes would produce and refuses the lot if it does not add
 * up, so a half-applied day is not a thing that can exist.
 */
function staffAttendance(opts) { return {
    view: opts.view,
    filter: 'all',
    now: @js(now()->format('g:i A')),
    from: opts.from,
    to: opts.to,
    fixCount: opts.fixCount, lateCount: opts.lateCount, editCount: opts.editCount,
    log: opts.log,

    loading: false,
    saving: false,
    error: '',
    flash: '',
    p: null,          // the day as the server handed it over
    edIn: '', edOut: '', edBreak: 0, countsAs: 'worked',
    reason: '', note: '',

    /**
     * Arriving with a day named: ?open=user|date opens that day's panel at
     * once. It is how a report row, or any link that names a day, lands here
     * with the right thing already on screen rather than on a grid somebody
     * then has to find the day on.
     */
    init() {
        setInterval(() => this.tick(), 30000);

        const wanted = new URLSearchParams(window.location.search).get('open');
        if (! wanted) return;

        const [userId, date] = wanted.split('|');
        if (/^\d+$/.test(userId) && /^\d{4}-\d{2}-\d{2}$/.test(date)) this.open(Number(userId), date);
    },

    tick() {
        const d = new Date(); const h = d.getHours(); const m = String(d.getMinutes()).padStart(2, '0');
        this.now = ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
    },

    show(view) {
        this.view = view;
        const url = new URL(window.location.href);
        if (view === 'hist') url.searchParams.set('view', 'hist'); else url.searchParams.delete('view');
        history.replaceState(null, '', url);
    },

    isOpen(userId, date) {
        return this.p !== null && this.p.staff.id === userId && this.p.date === date;
    },

    url(userId, date) {
        return '/timesheets/panel/' + userId + '/' + date + '?from=' + this.from + '&to=' + this.to;
    },

    async open(userId, date) {
        this.view = 'hist';
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

    cancel() {
        this.p = null;
        this.error = '';
    },

    /** The panel from the server's answer, with nothing carried over. */
    load(payload) {
        this.p = payload;
        const punches = this.punches();
        this.edIn = punches.in ? punches.in.at : '';
        this.edOut = punches.out ? punches.out.at : '';
        this.edBreak = punches.lunchStart && punches.lunchEnd ? this.minutes(punches.lunchEnd.at) - this.minutes(punches.lunchStart.at) : 0;
        this.countsAs = payload.counts_as;
        this.reason = '';
        this.note = '';
    },

    /** The clock-in, the last clock-out, and the first lunch pair on the day. */
    punches() {
        const list = this.p ? this.p.punches : [];
        const inP = list.find(x => x.type === 'IN') || null;
        const outP = [...list].reverse().find(x => x.type === 'OUT') || null;
        const startIndex = list.findIndex(x => x.type === 'LUNCH_START');
        const lunchStart = startIndex === -1 ? null : list[startIndex];
        const lunchEnd = lunchStart ? (list.slice(startIndex + 1).find(x => x.type === 'LUNCH_END') || null) : null;
        return {in: inP, out: outP, lunchStart, lunchEnd};
    },

    /**
     * A break or a lunch started and never ended — the last start of its
     * kind with no end after it. The clock refuses every punch after such a
     * start, so until it is closed the day pays nothing.
     */
    dangling() {
        const list = this.p ? this.p.punches : [];
        for (const kind of ['BREAK', 'LUNCH']) {
            let open = null;
            list.forEach(x => { if (x.type === kind + '_START') open = x; if (x.type === kind + '_END') open = null; });
            if (open) return open;
        }
        return null;
    },

    /** Where the dangling break will be closed: its start plus the usual length. */
    danglingEnd(open) {
        const length = open.type === 'LUNCH_START' ? Math.max(1, Math.round(Number(this.edBreak) || 0) || 30) : (this.p.paid_break_cap || 15);
        return this.hm(this.minutes(open.at) + length);
    },

    danglingNote() {
        const open = this.dangling();
        if (! open) return '';
        const what = open.type === 'LUNCH_START' ? 'lunch' : 'break';
        return 'Went on a ' + what + ' at ' + this.clock(open.at) + ' and never came back off it, so the day pays nothing. Saving adds "Back from ' + what + '" at ' + this.clock(this.danglingEnd(open)) + '.';
    },

    clock(at) {
        const m = this.minutes(at);
        if (m === null) return '';
        const h = Math.floor(m / 60), mm = String(m % 60).padStart(2, '0');
        return ((h % 12) || 12) + ':' + mm + ' ' + (h < 12 ? 'AM' : 'PM');
    },

    minutes(at) {
        if (! at) return null;
        const [h, m] = at.split(':').map(Number);
        return h * 60 + m;
    },

    hm(m) {
        return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
    },

    /**
     * What would be sent: the three fields as the clock's own changes.
     *
     * A time typed over a punch moves it; a time where there was none adds
     * it. The break is the lunch pair: lengthened or shortened by moving its
     * end, taken off at zero, and put in the middle of the day where there
     * was none. The clock-in is never removed — the server refuses that.
     */
    changes() {
        if (! this.p) return [];
        const list = [];
        const q = this.punches();

        if (this.edIn) {
            if (! q.in) list.push({op: 'add', type: 'IN', at: this.edIn});
            else if (this.edIn !== q.in.at) list.push({op: 'move', id: q.in.id, at: this.edIn});
        }
        if (this.edOut) {
            if (! q.out) list.push({op: 'add', type: 'OUT', at: this.edOut});
            else if (this.edOut !== q.out.at) list.push({op: 'move', id: q.out.id, at: this.edOut});
        }

        const wanted = Math.max(0, Math.round(Number(this.edBreak) || 0));
        const had = q.lunchStart && q.lunchEnd ? this.minutes(q.lunchEnd.at) - this.minutes(q.lunchStart.at) : 0;

        // A paid break nobody came back off: closed at the usual length. (A
        // lunch left open is closed by the break field below.)
        const open = this.dangling();
        if (open && open.type === 'BREAK_START') list.push({op: 'add', type: 'BREAK_END', at: this.danglingEnd(open)});
        if (open && open.type === 'LUNCH_START' && wanted === 0) list.push({op: 'add', type: 'LUNCH_END', at: this.danglingEnd(open)});

        if (wanted !== had) {
            if (q.lunchStart && q.lunchEnd) {
                if (wanted === 0) list.push({op: 'remove', id: q.lunchStart.id}, {op: 'remove', id: q.lunchEnd.id});
                else list.push({op: 'move', id: q.lunchEnd.id, at: this.hm(this.minutes(q.lunchStart.at) + wanted)});
            } else if (q.lunchStart && wanted > 0) {
                list.push({op: 'add', type: 'LUNCH_END', at: this.hm(this.minutes(q.lunchStart.at) + wanted)});
            } else if (wanted > 0) {
                const a = this.minutes(this.edIn || (q.in && q.in.at)), b = this.minutes(this.edOut || (q.out && q.out.at));
                if (a !== null && b !== null && b - a > wanted) {
                    const start = a + Math.floor((b - a) / 2) - Math.floor(wanted / 2);
                    list.push({op: 'add', type: 'LUNCH_START', at: this.hm(start)}, {op: 'add', type: 'LUNCH_END', at: this.hm(start + wanted)});
                }
            }
        }

        return list;
    },

    canSave() {
        if (! this.p || this.p.locked || this.saving) return false;
        if (this.changes().length === 0 && this.countsAs === this.p.counts_as) return false;
        if (! this.reason) return false;
        return this.reason !== 'other' || this.note.trim() !== '';
    },

    async save() {
        if (! this.canSave()) return;
        this.saving = true;
        this.error = '';

        try {
            const response = await window.postJson(this.url(this.p.staff.id, this.p.date), {
                reason: this.reason, note: this.note.trim() || null, changes: this.changes(), counts_as: this.countsAs,
            });
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                const firstError = Object.values(data.errors ?? {}).flat()[0];
                throw new Error(firstError || data.message || 'Could not save the correction.');
            }

            this.repaint(data);
            this.logged(data);
            this.p = null;
            this.flash = 'Saved · ' + data.staff.name + ', ' + data.day_label + ' is corrected and logged with your name.';
            setTimeout(() => { this.flash = ''; }, 6000);
        } catch (error) {
            this.error = error.message;
        } finally {
            this.saving = false;
        }
    },

    /** The week's list, with this day's lines at the top. */
    logged(data) {
        const mine = data.history.map(h => ({who: h.who || 'You', name: data.staff.name, day: data.day_label, what: h.what, reason: h.reason, when: h.when}));
        const others = this.log.filter(l => ! (l.name === data.staff.name && l.day === data.day_label));
        this.log = mine.concat(others);
    },

    /* ---- the grid behind, painted from the save's answer ---- */

    repaint(data) {
        const cell = document.querySelector('[data-cell="' + data.staff.id + '|' + data.date + '"]');
        if (cell) cell.innerHTML = this.cellHtml(data);

        const hours = document.querySelector('[data-hours="' + data.staff.id + '"]');
        if (hours) hours.textContent = data.hours_label;

        const count = flag => document.querySelectorAll('[data-week-grid] [data-flag="' + flag + '"]').length;
        this.fixCount = count('fix'); this.lateCount = count('late'); this.editCount = count('edit');
    },

    esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    },

    /** The same cell the Blade draws — the words come from the server with it. */
    cellHtml(data) {
        const c = data.cell, id = data.staff.id, date = data.date, name = this.esc(data.staff.name), day = this.esc(data.day_label);
        const inner = '<span class="t">' + this.esc(c.top) + '</span><span class="s">' + this.esc(c.sub) + '</span>';

        if (! c.fixable) {
            return '<span class="cell ' + this.esc(c.cls) + '" aria-label="' + name + ', ' + day + ': ' + this.esc(c.spoken) + '">' + inner + '</span>';
        }

        return '<a href="/timesheets/fix/' + id + '/' + date + '" @click.prevent="open(' + id + ', \'' + date + '\')" '
            + ':class="isOpen(' + id + ', \'' + date + '\') ? \'sel\' : \'\'" '
            + 'class="cell ' + this.esc(c.cls) + '" data-flag="' + this.esc(c.flag ?? '') + '" '
            + 'title="' + this.esc(c.label ?? c.top) + ' — open ' + name + '’s day' + (c.fine ? ' to review' : '') + '" '
            + 'aria-label="' + name + ', ' + day + ': ' + this.esc(c.spoken) + '. Correct this day">' + inner + '</a>';
    },
}; }
</script>
@endsection
