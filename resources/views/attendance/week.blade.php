@extends('layouts.app')
@section('title', 'Director Attendance')
@section('content')
{{--
    Director Attendance — Phase 3 of docs/work.md, as DirectorAttendance.dc.html
    draws it. The week as a grid: a row per child, a cell per day, tapped
    through not scheduled → absent → coming, and the times typed into the
    cell. Linked to Teacher Attendance: today's times come from the check-ins
    there, and a time fixed here is the same row, so it shows there too.
    See attendanceWeek() at the foot; the rules live in App\Services\AttendanceWeek.
--}}
@include('children.partials.mock-style')
@include('attendance.partials.week-style')
<x-kids-background />

@php
    // $extra first: a chip's or an arrow's own value must win over the page's
    // current one. It was the other way round until 27 Oct 2026, so a room
    // chip and the week arrows pointed back at the page they were on.
    $link = fn (array $extra = []) => route('attendance.index', array_filter($extra + ['date' => $date, 'room' => $room, 'q' => $q], fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="ch" x-data="attendanceWeek(@js(['w' => $w, 'room' => $room, 'q' => $q, 'url' => route('attendance.week.cell')]))" @keydown.escape.window="edit = null" style="display: flex; flex-direction: column; gap: 14px;">

    <section class="ch-card" style="padding: 14px 18px; display: flex; flex-direction: column; gap: 12px;">
        <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; margin-right: 6px;">Attendance</h1>
            {{-- The week control, drawn as Teacher Attendance draws its day (26 Oct 2026). --}}
            @include('attendance.partials.week-picker')
            <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; position: relative;">
                <input class="inp round" style="width: 200px;" placeholder="Search name or LAN" aria-label="Search name or LAN" x-model.trim="search">
                <a class="btn" href="{{ route('attendance.print', ['date' => $w['weekStart']]) }}">Print</a>
                <button type="button" class="btn" @click="recent = ! recent">Recent 10</button>
                <div x-show="recent" x-cloak class="recent" @click.outside="recent = false" data-recent>
                    <span class="lbl">Today’s latest sign-ins</span>
                    @forelse($recent as $r)
                        <div style="display: flex; justify-content: space-between; gap: 10px;"><span><b>{{ $r['name'] }}</b> <span class="faint">· {{ $r['room'] }}</span></span><span style="font-variant-numeric: tabular-nums;">{{ $r['in'] }}{{ $r['out'] ? ' – '.$r['out'] : '' }}</span></div>
                    @empty
                        <span class="muted">Nobody has signed in yet today.</span>
                    @endforelse
                </div>
                <div style="position: relative;" x-data="{ more: false }">
                    <button type="button" class="btn" aria-label="More options" @click="more = ! more">⋯</button>
                    <div x-show="more" x-cloak class="menu" @click.outside="more = false">
                        <a class="mi" href="{{ route('attendance.classic', ['date' => $date]) }}">Classic register (cards, projection, room cover)</a>
                        <a class="mi" href="{{ route('attendance.month-sheet') }}">Month sheet</a>
                        <a class="mi" href="{{ route('check-in.index') }}">Teacher Attendance</a>
                    </div>
                </div>
            </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;" data-room-chips>
            @foreach($w['chips'] as $c)
                <a class="chip {{ $c['on'] ? 'on' : '' }}" href="{{ $link(['room' => $c['name'] === 'All' ? null : $c['name']]) }}"><span style="width: 9px; height: 9px; border-radius: 3px; background: {{ $c['color'] }};"></span>{{ $c['name'] }} <span style="opacity: .7; font-weight: 500;">{{ $c['inN'] }}/{{ $c['n'] }}</span></a>
            @endforeach
            <span style="margin-left: auto; display: inline-flex; gap: 12px; font-size: 13px; flex-wrap: wrap;" class="muted" data-summary>
                <span><b style="color: #1c2130;" x-text="w.sum.enr"></b> enrolled</span><span><b style="color: #1b5e91;" x-text="w.sum.exp"></b> expected</span><span><b style="color: #15803d;" x-text="w.sum.inN"></b> in</span><span><b style="color: #b45309;" x-text="w.sum.come"></b> still to come</span><span><b style="color: #be123c;" x-text="w.sum.notIn"></b> not in</span>
            </span>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center; padding-top: 10px; border-top: 1px solid #f0f1f5;">
            <span style="font-size: 12px; font-weight: 700;">Tap a day to change it:</span>
            <span class="lg"><span class="sw s-none"><span class="dotm"></span></span>Not scheduled</span>
            <span style="color: #b5bac6;">→</span>
            <span class="lg"><span class="sw s-abs"></span>Absent</span>
            <span style="color: #b5bac6;">→</span>
            <span class="lg"><span class="sw s-exp"></span>Coming</span>
            <span style="color: #b5bac6; font-size: 12px;">→ back to the dot</span>
            <span style="width: 1px; height: 16px; background: #e7e9f0;"></span>
            <span class="lg"><span class="sw s-in"></span>Times recorded</span>
            <span class="lg"><span class="sw s-bill"></span>Not one of their days · still billable</span>
            <span class="lg"><span style="width: 9px; height: 9px; border-radius: 50%; background: #f59e0b;"></span>Time fixed by hand</span>
            <span class="lg">✎ edit times in the box · Enter saves · Esc cancels · ↑/↓ 1 min (Shift 1 hr)</span>
        </div>
    </section>

    <div class="linked">
        <span style="font-weight: 700;">Linked to Teacher Attendance.</span>
        <span>Today’s in and out times come from check-ins there, and anything you fix here shows there too.</span>
        <a href="{{ route('check-in.index') }}" style="margin-left: auto; font-weight: 700;">Open check-in →</a>
    </div>

    <section class="ch-card" style="overflow: hidden;">
        <div style="overflow-x: auto;">
            <table class="tbl att" data-week-grid>
                <thead><tr>
                    {{-- LAN and Student sort the rows; press again to turn them round. --}}
                    <th style="padding-left: 14px;"><button type="button" class="sortb" :class="sort === 'lan' ? 'on' : ''" @click="sortBy('lan')" :aria-sort="sort === 'lan' ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'" title="Sort by LAN" data-sort-lan>LAN <span x-text="sort === 'lan' ? (dir === 'asc' ? '↑' : '↓') : '↕'"></span></button></th>
                    <th><button type="button" class="sortb" :class="sort === 'name' ? 'on' : ''" @click="sortBy('name')" :aria-sort="sort === 'name' ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'" title="Sort by name" data-sort-name>Student <span x-text="sort === 'name' ? (dir === 'asc' ? '↑' : '↓') : '↕'"></span></button></th>
                    <th>Classroom</th><th>DOB · Age</th><th>Hours</th>
                    <template x-for="d in w.days" :key="d.date"><th class="day" :class="d.today ? 'today' : ''"><span x-text="d.dow"></span><br><span style="font-size: 12.5px; letter-spacing: 0; text-transform: none; color: #1c2130;" x-text="d.label"></span><span x-show="d.closed" style="display: block; font-size: 10px; text-transform: none; letter-spacing: 0;" class="faint" x-text="d.closed"></span></th></template>
                </tr></thead>
                <tbody>
                    <template x-for="c in shown()" :key="c.id">
                        <tr :data-child="c.id">
                            <td style="font-variant-numeric: tabular-nums; padding-left: 14px;" class="faint" x-text="c.lan"></td>
                            <td><div style="display: flex; align-items: center; gap: 8px;"><span class="av sm" style="background: #dcebfa; color: #1b5e91;" x-text="c.ini"></span><a :href="c.url" style="font-weight: 600; color: inherit;" x-text="c.name"></a></div></td>
                            <td><span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 9px; height: 9px; border-radius: 3px;" :style="'background:' + c.roomColor"></span><span x-text="c.room"></span></span></td>
                            <td style="font-variant-numeric: tabular-nums; font-size: 12.5px; line-height: 1.3;" class="muted"><span x-text="c.dob"></span><br><span style="font-size: 11.5px;" class="faint" x-text="c.age"></span></td>
                            <td style="font-size: 12px; white-space: normal; max-width: 92px; line-height: 1.3;" class="muted" x-text="c.hours"></td>
                            <template x-for="x in c.cells" :key="x.date">
                                <td :class="x.today ? 'today' : ''">
                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                        <template x-for="p in x.parts" :key="p.session">
                                            <div style="position: relative;">
                                                <div x-show="! isEditing(c.id, x.date, p.session)" class="cellw" :class="p.cls">
                                                    <button type="button" class="main" :disabled="! p.tappable || busy" :title="p.tip" @click="tap(c, x.date, p)">
                                                        <span x-show="p.lbl" class="am" x-text="p.lbl"></span>
                                                        <span x-show="p.st === 'none' && ! p.in && p.cls === 's-none'" class="dotm"></span>
                                                        <span x-text="p.text"></span>
                                                    </button>
                                                    <button type="button" x-show="p.editable && p.st !== 'abs'" class="pen" @click="openEdit(c, x.date, p)" aria-label="Edit times">✎</button>
                                                    <span x-show="p.edited" class="edt" :title="'Fixed by hand' + (p.editedBy ? ' · ' + p.editedBy : '')"></span>
                                                </div>
                                                <div x-show="isEditing(c.id, x.date, p.session)" x-cloak class="inl">
                                                    <input x-model.trim="edit.in" :placeholder="p.placeholder || 'in'" aria-label="Time in" @keydown="key($event, 'in')" x-ref="editIn">
                                                    <span style="font-size: 11px;" class="faint">–</span>
                                                    <input x-model.trim="edit.out" placeholder="out" aria-label="Time out" @keydown="key($event, 'out')">
                                                    <button type="button" class="ok" @click="saveEdit()" aria-label="Save">✓</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="shown().length === 0"><td colspan="10" style="padding: 24px; text-align: center;" class="muted">No children match.</td></tr>
                </tbody>
            </table>
        </div>
        <div style="padding: 12px 18px; font-size: 13px; border-top: 1px solid #f0f1f5; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between;" class="muted">
            <span>Showing <span x-text="shown().length"></span> of <span x-text="w.total"></span> children · always in edit mode</span>
            <span :style="error ? 'color:#be123c' : ''" x-text="error || lastChange"></span>
        </div>
    </section>
</div>

<script>
/*
 * The page. The grid is the server's (AttendanceWeek::build) and comes back
 * whole after every tap or save, so the chips and the summary are never the
 * page's own arithmetic. A tap sends the next state; the pen opens the two
 * time boxes in the cell.
 */
function attendanceWeek(opts) { return {
    w: opts.w,
    room: opts.room,
    search: opts.q || '',
    url: opts.url,
    edit: null,          // {childId, date, session, in, out, label}
    recent: false,
    busy: false,
    error: '',
    lastChange: 'Changes save as you tap',

    // Sort: by name (the roll's order) or by LAN, either way up (27 Oct 2026).
    sort: 'name',
    dir: 'asc',
    sortBy(key) {
        this.dir = this.sort === key && this.dir === 'asc' ? 'desc' : 'asc';
        this.sort = key;
    },
    shown() {
        const q = this.search.toLowerCase();
        const rows = this.w.rows.filter(c => ! q || c.search.includes(q));
        if (this.sort === 'lan') {
            rows.sort((a, b) => String(a.lan).localeCompare(String(b.lan), undefined, {numeric: true}) * (this.dir === 'asc' ? 1 : -1));
        } else if (this.dir === 'desc') {
            rows.reverse();
        }
        return rows;
    },
    isEditing(childId, date, session) {
        return this.edit && this.edit.childId === childId && this.edit.date === date && this.edit.session === session;
    },
    label(c, date, p) {
        const d = this.w.days.find(x => x.date === date);
        return c.first + ' ' + c.last + ', ' + (d ? d.dow + ' ' + d.label : date) + (p.lbl ? ' ' + p.lbl : '');
    },
    async send(body, label) {
        this.busy = true;
        this.error = '';
        try {
            const response = await window.postJson(this.url, body);
            const data = await response.json().catch(() => ({}));
            if (! response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] || data.message || 'Could not save.');
            // The whole week comes back unfiltered; keep the room the page is on.
            this.w = {...data, rows: this.room ? data.rows.filter(r => r.room === this.room) : data.rows};
            this.lastChange = label;
            return true;
        } catch (e) {
            this.error = e.message;
            return false;
        } finally {
            this.busy = false;
        }
    },
    tap(c, date, p) {
        if (! p.tappable || this.busy) return;
        const names = {none: 'not scheduled', abs: 'absent', pres: 'coming'};
        // Coming from Absent puts the remembered times back; from Not scheduled the times were never off.
        const kept = p.next === 'pres' && p.back ? ' (' + p.back + ' back)' : (p.next === 'pres' && p.in ? ' (kept ' + p.in + (p.out ? '–' + p.out : '') + ')' : '');
        this.edit = null;
        this.send({child_id: c.id, date, session: p.session, state: p.next}, 'Last change: ' + this.label(c, date, p) + ' → ' + names[p.next] + kept);
    },
    openEdit(c, date, p) {
        this.edit = {childId: c.id, date, session: p.session, in: p.in || '', out: p.out || '', state: p.st === 'none' ? 'none' : 'pres', label: this.label(c, date, p)};
        this.$nextTick(() => { const el = this.$refs.editIn; if (el) el.focus(); });
    },
    async saveEdit() {
        if (! this.edit || this.busy) return;
        const e = this.edit;
        const ok = await this.send({child_id: e.childId, date: e.date, session: e.session, state: e.state, in: e.in || null, out: e.out || null}, 'Saved: ' + e.label + ' ' + (e.in || '—') + '–' + (e.out || '—'));
        if (ok) this.edit = null;
    },
    key(event, field) {
        const k = event.key;
        if (k === 'Enter') { event.preventDefault(); this.saveEdit(); return; }
        if (k === 'Escape') { this.edit = null; return; }
        if (k === 'ArrowUp' || k === 'ArrowDown') {
            event.preventDefault();
            const step = (event.shiftKey ? 60 : 1) * (k === 'ArrowUp' ? 1 : -1);
            this.edit[field] = this.shift(this.edit[field] || (field === 'in' ? '8:00a' : '5:00p'), step);
        }
    },
    /** "8:05a" plus or minus some minutes, the way the mock-up nudges a time. */
    shift(t, m) {
        const mm = /(\d+)(?::(\d+))?\s*([ap])?/i.exec(t || '');
        if (! mm) return t;
        const ap = (mm[3] || '').toLowerCase();
        let H = (+mm[1] % 12) + (ap === 'p' || (! ap && +mm[1] < 7) ? 12 : 0), M = (+(mm[2] || 0)) + m;
        H += Math.floor(M / 60); M = ((M % 60) + 60) % 60; H = ((H % 24) + 24) % 24;
        return (((H + 11) % 12) + 1) + ':' + String(M).padStart(2, '0') + (H >= 12 ? 'p' : 'a');
    },
}; }
</script>
@endsection
