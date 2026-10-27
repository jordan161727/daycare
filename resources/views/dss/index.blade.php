@extends('layouts.app')
@section('title', 'DSS attendance')
@section('content')
{{--
    DSS attendance — Phase 8.1 of docs/work.md, as Ray.dc.html draws it.

    The county's month: a row per child, a weekday per column, the times in
    each cell and the week's hours against the hours authorised. Everything
    on the grid comes from the server as data (App\Services\DssAttendance);
    the page draws it, handles the selection and the keys, and sends each
    change back as a list of cells to put a state on. The grid comes back
    whole after every change, so the totals are never the page's own
    arithmetic. See dssPage() at the foot.
--}}
@include('children.partials.mock-style')
@include('dss.partials.style')
<x-kids-background />

<div class="ch" x-data="dssPage(@js([
        'data' => $data,
        'rooms' => $filter['rooms'],
        'sub' => $filter['sub'],
        'q' => $filter['q'],
        'urls' => ['grid' => route('dss.grid'), 'apply' => route('dss.apply'), 'preview' => route('dss.preview'), 'export' => route('dss.export')],
    ]))" style="display: flex; flex-direction: column; gap: 12px;">

    <section class="ch-card" style="padding: 14px 18px; display: flex; flex-direction: column; gap: 12px;">
        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700;">DSS attendance</h1>
            <span class="pill vio">Only visible to {{ $viewer }}</span>
            <div style="display: flex; align-items: center; gap: 4px; margin-left: 8px;">
                <button type="button" class="btn" aria-label="Previous month" @click="go(d.prev)">‹</button>
                <span style="font-weight: 700; padding: 0 8px;" x-text="d.label"></span>
                <button type="button" class="btn" aria-label="Next month" @click="go(d.next)">›</button>
            </div>
            <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <input class="inp" style="width: 190px; border-radius: 999px;" placeholder="Search name, LAN or CIN" aria-label="Search" x-model.trim="q" @input.debounce.300ms="reload()">
                <button type="button" class="btn" :class="sub ? 'dark' : ''" @click="sub = ! sub; rooms = rooms; reload()">Subsidy only · <span x-text="d.subsidyCount"></span></button>
                <button type="button" class="btn pri" @click="openExport()">Export for KinderConnect</button>
            </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
            <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-right: 2px;" class="muted">Class</span>
            <button type="button" class="chip cl" :class="rooms.length === 0 ? 'on' : ''" @click="rooms = []; reload()"><span style="width: 9px; height: 9px; border-radius: 3px; background: #c3cbe0;"></span>All <span style="opacity: .7; font-weight: 500;" x-text="d.total"></span></button>
            <template x-for="r in d.rooms" :key="r.name">
                <button type="button" class="chip cl" :class="rooms.includes(r.name) ? 'on' : ''" @click="toggleRoom(r.name)"><span style="width: 9px; height: 9px; border-radius: 3px;" :style="'background:' + r.color"></span><span x-text="r.name"></span> <span style="opacity: .7; font-weight: 500;" x-text="r.n"></span></button>
            </template>
            <span style="margin-left: auto; font-size: 12.5px;" class="muted">Pick one or more classes · the grid, totals and the export all follow this filter</span>
        </div>
    </section>

    {{-- ---- Export for KinderConnect ---- --}}
    <section x-show="ex.open" x-cloak class="ch-card exportcard" data-export>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span style="font-size: 16px; font-weight: 700;">Export for KinderConnect</span>
            <span style="font-size: 13px;" class="muted">Days that have already happened only · <span x-text="ex.range"></span></span>
            <button type="button" class="btn" style="margin-left: auto;" @click="ex.open = false">Close</button>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: 13px;">
            <span class="muted">Range</span>
            <button type="button" class="chip cl" :class="ex.rangeKey === 'past' ? 'on' : ''" @click="ex.rangeKey = 'past'; preview()">Month to yesterday</button>
            <button type="button" class="chip cl" :class="ex.rangeKey === 'week' ? 'on' : ''" @click="ex.rangeKey = 'week'; preview()">This week so far</button>
            <span style="width: 1px; height: 18px; background: #e7e9f0; margin: 0 4px;"></span>
            <button type="button" class="chip cl" :class="ex.abs ? 'on' : ''" @click="ex.abs = ! ex.abs; preview()">Include absences</button>
        </div>
        <div style="display: flex; flex-direction: column; gap: 6px;">
            <template x-for="(w, i) in ex.checks" :key="i"><div style="display: flex; gap: 10px; align-items: center; font-size: 13px;"><span class="pill" :class="w.cls" x-text="w.tag"></span><span x-text="w.text"></span></div></template>
        </div>
        <pre class="csv" x-text="ex.preview"></pre>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a class="btn pri" :href="exportUrl('csv')">Download .csv · <span x-text="ex.rows"></span> rows</a>
            <a class="btn" :href="exportUrl('json')">Download .json (for a browser script)</a>
            <button type="button" class="btn" @click="copyCsv()" x-text="ex.copied ? 'Copied ✓' : 'Copy to clipboard'"></button>
            <span style="font-size: 12.5px;" class="faint">One row per visit — School Age children get separate AM and PM rows.</span>
        </div>
    </section>

    {{-- ---- the toolbar ---- --}}
    <section class="ch-card bar" style="padding: 8px 12px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;" data-toolbar>
        <span style="font-size: 12.5px; font-weight: 700; white-space: nowrap; padding-right: 4px;" :title="selTip()" x-text="selShort()"></span>
        <span x-show="showDay()" class="grp" style="background: #f7f9fd;">
            <span class="gl muted">IN</span><input class="inp t" x-model.trim="t.in" aria-label="Time in" @keydown.enter="applyTimes()">
            <span class="gl muted">OUT</span><input class="inp t" x-model.trim="t.out" aria-label="Time out" @keydown.enter="applyTimes()">
        </span>
        <span x-show="showAmPm()" x-cloak class="grp" style="background: #eef4fb;">
            <span class="gl" style="color: #24487e;">AM</span><input class="inp t" x-model.trim="t.amIn" aria-label="AM in"><span class="faint">–</span><input class="inp t" x-model.trim="t.amOut" aria-label="AM out">
            <span class="gl" style="color: #24487e; margin-left: 4px;">PM</span><input class="inp t" x-model.trim="t.pmIn" aria-label="PM in"><span class="faint">–</span><input class="inp t" x-model.trim="t.pmOut" aria-label="PM out">
            <button type="button" class="btn s" style="min-height: 28px;" @click="t.amIn = ''; t.amOut = ''" title="Came for the afternoon only.">PM only</button>
        </span>
        <button type="button" class="btn s dark" :disabled="! sel || busy" @click="applyTimes()">Apply</button>
        <span class="sep"></span>
        <button type="button" class="btn s" :disabled="! sel || busy" @click="op('agreed')" title="Fill with the agreed hours from the child’s record (H)">Agreed hours</button>
        <button type="button" class="btn s red" :disabled="! sel || busy" @click="op('abs')" title="Absent (A)">Absent</button>
        <button type="button" class="btn s" :disabled="! sel || busy" @click="op('none')" title="Not scheduled (N)">Not sched.</button>
        <button type="button" class="btn s" :disabled="! sel || busy" @click="op('clear')" title="Clear times (Del)">Clear</button>
        <span class="sep"></span>
        <button type="button" class="btn s" :disabled="! sel" @click="copy()" title="Ctrl+C">Copy</button>
        <button type="button" class="btn s" :disabled="! sel || ! clip || busy" @click="paste()" title="Ctrl+V">Paste<span x-show="clip" x-text="clip ? ' (' + clip.length + '×' + clip[0].length + ')' : ''"></span></button>
        <button type="button" class="btn s" :disabled="! sel || busy" @click="repeatDay()" title="Copy each child’s first selected day across the rest of the selection">Repeat day →</button>
        <span x-show="error" x-cloak style="font-size: 12.5px; color: #be123c;" x-text="error"></span>
        <button type="button" class="btn s" style="margin-left: auto;" :disabled="! hist.length || busy" @click="undo()" :title="hist.length ? 'Last: ' + hist[hist.length - 1].label + ' (Ctrl+Z)' : ''">↶ Undo</button>
    </section>

    {{-- ---- the grid ---- --}}
    <section class="ch-card" style="overflow: hidden;">
        <div class="gridwrap" tabindex="0" @keydown="onKey($event)" @mousedown="$el.focus({preventScroll: true})" data-grid>
            <table class="grid">
                <thead><tr>
                    <th class="stk" style="text-align: left; padding-left: 10px; min-width: 250px;" @click="selectAll()">CHILD · AGREED HOURS · ENROLLMENT NOTE</th>
                    <template x-for="(col, c) in cols()" :key="col.key">
                        <th :class="col.wk ? 'wk' : (col.today ? 'today' : (col.closed ? 'closed' : ''))" :title="col.tip" @click="col.wk || col.closed ? null : selectCol(col.c)"><span x-text="col.dow"></span><br><span style="font-size: 12px; color: #1c2130;" x-text="col.n"></span></th>
                    </template>
                    <th style="padding: 6px 10px; text-align: right; cursor: default;">DAYS</th>
                    <th style="padding: 6px 10px; text-align: right; cursor: default;">HOURS</th>
                    <th style="padding: 6px 10px; text-align: right; cursor: default;">AGREED</th>
                </tr></thead>
                <tbody>
                    <template x-for="(r, ri) in d.rows" :key="r.id">
                        <tr :data-child="r.id">
                            <td class="stk" style="cursor: pointer;" @click="selectRow(ri)">
                                <div style="display: flex; align-items: center; gap: 8px; min-width: 230px;">
                                    <span style="width: 4px; height: 26px; border-radius: 2px; flex: none;" :style="'background:' + r.color"></span>
                                    <div style="display: flex; flex-direction: column; min-width: 0;">
                                        <span style="font-weight: 700; font-size: 13px;"><span x-text="r.last + ', ' + r.first"></span> <span style="font-weight: 500; font-size: 11.5px;" class="faint" x-text="r.lan"></span></span>
                                        <span style="font-size: 11.5px;" class="muted"><span x-text="r.agreed"></span> · <span x-text="r.daysTxt"></span> <span :style="'color:' + r.cinColor">· <span x-text="r.cin"></span></span></span>
                                        <span x-show="r.wd" style="font-size: 11.5px; font-weight: 600; color: #be123c; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" :title="r.wd">⏏ <span x-text="r.wd"></span></span>
                                        <span x-show="r.note" style="font-size: 11.5px; font-weight: 600; color: #6d28d9; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" :title="'Enrollment note: ' + r.note">✎ <span x-text="r.note"></span></span>
                                    </div>
                                </div>
                            </td>
                            <template x-for="col in cols()" :key="col.key">
                                <td :class="col.wk ? 'wk' : ''">
                                    <template x-if="! col.wk">
                                        <div class="cell" :class="cellCls(r, ri, col)" :title="r.cells[col.date].tip" @click="pick(ri, col.c, $event.shiftKey, r.cells[col.date])">
                                            <template x-for="(l, li) in r.cells[col.date].lines" :key="li"><span :class="l.cls" x-text="l.t"></span></template>
                                        </div>
                                    </template>
                                    <template x-if="col.wk">
                                        <div class="wkc" :class="r.weeks[col.week].cls" :title="r.weeks[col.week].tip"><b x-text="r.weeks[col.week].big"></b><span x-text="r.weeks[col.week].small"></span></div>
                                    </template>
                                </td>
                            </template>
                            <td style="padding: 0 10px; text-align: right; font-weight: 600;" x-text="r.nDays"></td>
                            <td style="padding: 0 10px; text-align: right; font-weight: 700;" x-text="r.hrs"></td>
                            <td style="padding: 0 10px; text-align: right;"><span class="pill" :class="r.diffCls" x-text="r.diff"></span></td>
                        </tr>
                    </template>
                    <tr x-show="d.rows.length === 0"><td :colspan="cols().length + 4" style="padding: 28px; text-align: center;" class="muted">No children match this filter.</td></tr>
                </tbody>
            </table>
        </div>
        <div style="padding: 10px 16px; border-top: 1px solid #f0f1f5; display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; align-items: center;" class="muted">
            <span>Click a cell · <span class="kbd">Shift</span>+click or <span class="kbd">Shift</span>+arrows to select a block · click a date or a child to select the column / row</span>
            <span><span class="kbd">Ctrl</span>+<span class="kbd">C</span> / <span class="kbd">Ctrl</span>+<span class="kbd">V</span> copy and paste · <span class="kbd">H</span> agreed hours · <span class="kbd">A</span> absent · <span class="kbd">Del</span> clear</span>
            <span style="margin-left: auto;">Showing <span x-text="d.rows.length"></span> children · every change is written to the register with your name</span>
        </div>
    </section>
</div>

<script>
/*
 * The page. The grid is the server's (DssAttendance::month); this keeps the
 * selection, the clipboard and the undo stack, and sends each change as a
 * list of cells to put a state on. Undo is the previous state of exactly
 * those cells, sent back the same way.
 */
function dssPage(opts) { return {
    d: opts.data,
    rooms: opts.rooms,
    sub: opts.sub,
    q: opts.q,
    urls: opts.urls,
    sel: null,          // {r0, c0, r1, c1} over the day columns (week columns excluded)
    clip: null,
    hist: [],
    busy: false,
    error: '',
    t: {in: '8:00a', out: '5:00p', amIn: '7:15a', amOut: '8:30a', pmIn: '3:00p', pmOut: '6:00p'},
    ex: {open: false, rangeKey: 'past', abs: true, range: '', checks: [], preview: '', rows: 0, copied: false},

    /* ---- columns: the days, with a week column after each Friday ---- */
    cols() {
        const out = [];
        let c = 0;
        this.d.days.forEach((day, i) => {
            out.push({key: day.date, date: day.date, c: c++, dow: day.dow, n: day.d, today: day.today, closed: !! day.closed, wk: false, tip: day.closed ? day.closed : 'Select the whole day', week: day.week});
            const next = this.d.days[i + 1];
            if (! next || next.week !== day.week) {
                const w = this.d.weeks.find(x => x.key === day.week);
                out.push({key: 'w' + day.week, wk: true, dow: 'WEEK', n: w.from + '–' + w.to, week: day.week, tip: 'Mon–Fri total (weekends not counted)'});
            }
        });
        return out;
    },
    days() { return this.d.days; },

    /* ---- query ---- */
    params(extra) {
        const p = new URLSearchParams();
        p.set('month', this.d.month);
        this.rooms.forEach(r => p.append('rooms[]', r));
        p.set('sub', this.sub ? '1' : '0');
        if (this.q) p.set('q', this.q);
        Object.entries(extra || {}).forEach(([k, v]) => p.set(k, v));
        return p;
    },
    async reload(month) {
        this.sel = null;
        this.error = '';
        try {
            const p = this.params(month ? {month} : {});
            const response = await fetch(this.urls.grid + '?' + p, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not load the month.');
            this.d = await response.json();
            history.replaceState(null, '', '?' + p);
            if (this.ex.open) this.preview();
        } catch (e) {
            this.error = e.message;
        }
    },
    go(month) { this.reload(month); },
    toggleRoom(name) {
        this.rooms = this.rooms.includes(name) ? this.rooms.filter(r => r !== name) : [...this.rooms, name];
        this.reload();
    },

    /* ---- selection ---- */
    box() {
        const S = this.sel;
        return S ? {r0: Math.min(S.r0, S.r1), r1: Math.max(S.r0, S.r1), c0: Math.min(S.c0, S.c1), c1: Math.max(S.c0, S.c1)} : null;
    },
    inSel(ri, c) {
        const b = this.box();
        return b && ri >= b.r0 && ri <= b.r1 && c >= b.c0 && c <= b.c1;
    },
    cellCls(r, ri, col) {
        const cell = r.cells[col.date];
        return cell.cls + (this.inSel(ri, col.c) ? ' sel' : '') + (this.sel && this.sel.r1 === ri && this.sel.c1 === col.c ? ' anc' : '') + (cell.future ? ' fut' : '');
    },
    selCells() {
        const b = this.box();
        const out = [];
        if (! b) return out;
        for (let r = b.r0; r <= b.r1; r++) for (let c = b.c0; c <= b.c1; c++) {
            const row = this.d.rows[r], day = this.d.days[c];
            if (! row || ! day) continue;
            const cell = row.cells[day.date];
            if (! cell.editable) continue;
            out.push({r, c, row, date: day.date, cell});
        }
        return out;
    },
    pick(ri, c, extend, cell) {
        if (! cell.editable) return;
        if (extend && this.sel) { this.sel = {r0: this.sel.r0, c0: this.sel.c0, r1: ri, c1: c}; return; }
        this.sel = {r0: ri, c0: c, r1: ri, c1: c};
        this.prefill(this.d.rows[ri], cell);
    },
    prefill(row, cell) {
        if (cell.st !== 'pres' && cell.st !== 'plan' && cell.st !== 'open') return;
        const segs = cell.segs.length ? cell.segs : row.agreedSegs;
        if (! segs.length) return;
        if (row.split) {
            const am = segs.length > 1 ? segs[0] : null, pm = segs[segs.length - 1];
            this.t.amIn = am ? (am.in || '') : ''; this.t.amOut = am ? (am.out || '') : '';
            this.t.pmIn = pm.in || ''; this.t.pmOut = pm.out || '';
        } else {
            this.t.in = segs[0].in || ''; this.t.out = segs[0].out || '';
        }
    },
    selectRow(ri) { this.sel = {r0: ri, c0: 0, r1: ri, c1: this.d.days.length - 1}; },
    selectCol(c) { this.sel = {r0: 0, c0: c, r1: this.d.rows.length - 1, c1: c}; },
    selectAll() { this.sel = {r0: 0, c0: 0, r1: this.d.rows.length - 1, c1: this.d.days.length - 1}; },
    selShort() { const n = this.selCells().length; return ! this.sel ? 'No selection' : n + (n === 1 ? ' cell' : ' cells'); },
    selTip() { const b = this.box(); return ! b ? 'Click a cell to start' : (b.r1 - b.r0 + 1) + ' children × ' + (b.c1 - b.c0 + 1) + ' days'; },
    showAmPm() { return this.selCells().some(x => x.row.split) || (! this.sel && this.rooms.includes('School Age')); },
    showDay() { return ! this.sel || this.selCells().some(x => ! x.row.split); },

    /* ---- changes ---- */
    stateOf(cell) {
        if (cell.st === 'pres') return {st: 'pres', segs: cell.segs.map(g => ({in: g.in, out: g.out}))};
        if (cell.st === 'abs' || cell.st === 'none') return {st: cell.st};
        return {st: 'clear'};
    },
    async send(ops, label) {
        if (! ops.length || this.busy) return;
        this.busy = true;
        this.error = '';
        const before = ops.map(o => ({child_id: o.child_id, date: o.date, state: this.stateOf(this.d.rows.find(r => r.id === o.child_id).cells[o.date])}));
        try {
            const response = await window.postJson(this.urls.apply + '?' + this.params(), {ops});
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not save.');
            this.d = data;
            if (label !== null) this.hist = [...this.hist, {ops: before, label}].slice(-20);
            if (this.ex.open) this.preview();
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
        }
    },
    applyTimes() {
        const one = this.selCells().length === 1;
        const ops = this.selCells().map(x => {
            if (! x.row.split) return {child_id: x.row.id, date: x.date, state: {st: 'pres', segs: [{in: this.t.in, out: this.t.out || null}]}};
            const segs = [];
            if (this.t.amIn && (one || x.row.agreedSegs.length > 1)) segs.push({in: this.t.amIn, out: this.t.amOut || null});
            if (this.t.pmIn) segs.push({in: this.t.pmIn, out: this.t.pmOut || null});
            return {child_id: x.row.id, date: x.date, state: segs.length ? {st: 'pres', segs} : {st: 'clear'}};
        });
        this.send(ops.filter(o => ! this.isFuture(o)), 'Set times');
    },
    isFuture(o) { return o.state.st === 'pres' && o.date > this.d.today; },
    op(kind) {
        const ops = this.selCells().map(x => {
            if (kind === 'agreed') return {child_id: x.row.id, date: x.date, state: x.row.agreedSegs.length ? {st: 'pres', segs: x.row.agreedSegs.map(g => ({in: g.in, out: g.out}))} : {st: 'clear'}};
            return {child_id: x.row.id, date: x.date, state: {st: kind}};
        }).filter(o => ! this.isFuture(o));
        this.send(ops, {agreed: 'Agreed hours', abs: 'Absent', none: 'Not scheduled', clear: 'Clear times'}[kind]);
    },
    copy() {
        const b = this.box();
        if (! b) return;
        const clip = [];
        for (let r = b.r0; r <= b.r1; r++) { const row = []; for (let c = b.c0; c <= b.c1; c++) { const rr = this.d.rows[r], day = this.d.days[c]; row.push(rr && day ? this.stateOf(rr.cells[day.date]) : {st: 'clear'}); } clip.push(row); }
        this.clip = clip;
    },
    paste() {
        let b = this.box();
        if (! b || ! this.clip) return;
        const h = this.clip.length, w = this.clip[0].length;
        if (b.r0 === b.r1 && b.c0 === b.c1) b = {r0: b.r0, c0: b.c0, r1: Math.min(this.d.rows.length - 1, b.r0 + h - 1), c1: Math.min(this.d.days.length - 1, b.c0 + w - 1)};
        const ops = [];
        for (let r = b.r0; r <= b.r1; r++) for (let c = b.c0; c <= b.c1; c++) {
            const row = this.d.rows[r], day = this.d.days[c];
            if (! row || ! day || ! row.cells[day.date].editable) continue;
            const src = this.clip[(r - b.r0) % h][(c - b.c0) % w];
            ops.push({child_id: row.id, date: day.date, state: JSON.parse(JSON.stringify(src))});
        }
        this.sel = {r0: b.r0, c0: b.c0, r1: b.r1, c1: b.c1};
        this.send(ops.filter(o => ! this.isFuture(o)), 'Paste');
    },
    repeatDay() {
        const b = this.box();
        if (! b) return;
        const ops = [];
        for (let r = b.r0; r <= b.r1; r++) {
            const row = this.d.rows[r];
            if (! row) continue;
            let src = null;
            for (let c = b.c0; c <= b.c1; c++) {
                const day = this.d.days[c];
                if (! day || ! row.cells[day.date].editable) continue;
                if (! src) { src = this.stateOf(row.cells[day.date]); continue; }
                ops.push({child_id: row.id, date: day.date, state: JSON.parse(JSON.stringify(src))});
            }
        }
        this.send(ops.filter(o => ! this.isFuture(o)), 'Repeat first day');
    },
    undo() {
        if (! this.hist.length) return;
        const h = this.hist[this.hist.length - 1];
        this.hist = this.hist.slice(0, -1);
        this.send(h.ops, null);
    },
    onKey(e) {
        const k = e.key, mod = e.ctrlKey || e.metaKey;
        if (mod && (k === 'c' || k === 'C')) { e.preventDefault(); this.copy(); return; }
        if (mod && (k === 'v' || k === 'V')) { e.preventDefault(); this.paste(); return; }
        if (mod && (k === 'z' || k === 'Z')) { e.preventDefault(); this.undo(); return; }
        if (! this.sel) return;
        if (k.indexOf('Arrow') === 0) {
            e.preventDefault();
            const dr = k === 'ArrowDown' ? 1 : k === 'ArrowUp' ? -1 : 0, dc = k === 'ArrowRight' ? 1 : k === 'ArrowLeft' ? -1 : 0;
            const r = Math.max(0, Math.min(this.d.rows.length - 1, this.sel.r1 + dr)), c = Math.max(0, Math.min(this.d.days.length - 1, this.sel.c1 + dc));
            this.sel = e.shiftKey ? {r0: this.sel.r0, c0: this.sel.c0, r1: r, c1: c} : {r0: r, c0: c, r1: r, c1: c};
            if (! e.shiftKey) this.prefill(this.d.rows[r], this.d.rows[r].cells[this.d.days[c].date]);
            return;
        }
        if (k === 'a' || k === 'A') this.op('abs');
        else if (k === 'h' || k === 'H') this.op('agreed');
        else if (k === 'n' || k === 'N') this.op('none');
        else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); this.op('clear'); }
    },

    /* ---- export ---- */
    openExport() { this.ex.open = true; this.ex.copied = false; this.preview(); },
    exParams(extra) { return this.params({range: this.ex.rangeKey, abs: this.ex.abs ? '1' : '0', ...extra}); },
    exportUrl(format) { return this.urls.export + '?' + this.exParams({format, t: Date.now()}); },
    async preview() {
        try {
            const response = await fetch(this.urls.preview + '?' + this.exParams(), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (! response.ok) throw new Error('Could not build the export.');
            Object.assign(this.ex, await response.json());
        } catch (e) {
            this.error = e.message;
        }
    },
    async copyCsv() {
        try {
            const response = await fetch(this.exportUrl('csv'), {credentials: 'same-origin'});
            await navigator.clipboard.writeText(await response.text());
            this.ex.copied = true;
        } catch (e) {
            this.error = 'Could not copy — download the file instead.';
        }
    },
}; }
</script>
@endsection
