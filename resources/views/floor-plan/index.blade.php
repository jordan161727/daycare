@extends('layouts.app')

@section('title', 'Floor plan')

@section('content')
@php($rooms = collect($plan['rooms'])->keyBy('room'))
@php($tz = config('app.timezone'))
@php($short = $plan['counters']['short'])
{{-- The building, room by room — Phase 4.2 of docs/work.md, drawn as the
     client's FloorPlan.dc.html draws it: the same header, the six counters
     in an auto-fit grid, the twelve-column map with the client's cell
     placement and styles, the legend, and the room panel on the right.
     Everything on it is read from what the door, the register, the staff
     clock and the roster already say (App\Services\FloorPlan); nothing is
     written here. The client's styles are carried over, scoped to .fp. --}}
<style>
    .fp { color: #1c2130; }
    .fp .fp-card { background: #fff; border: 1px solid #e7e9f0; border-radius: 16px; }
    .dark .fp .fp-card { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); color: #e2e8f0; }
    .fp .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; border-radius: 999px; padding: 3px 10px; white-space: nowrap; }
    .fp .ok { background: #e3f5ec; color: #15803d; } .fp .bad { background: #be123c; color: #fff; } .fp .spare { background: #dcebfa; color: #1b5e91; } .fp .mut { background: #f2f4f9; color: #5f6676; } .fp .warn { background: #fef3c7; color: #b45309; }
    .fp .room { font: inherit; text-align: left; cursor: pointer; color: #1c2130; background: #fff; border: 2px solid #cfd3de; border-radius: 6px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; min-width: 0; box-sizing: border-box; }
    .fp .room:hover { border-color: #5b8fd6; }
    .fp .room.sel { border-color: #1b5e91; box-shadow: 0 0 0 3px #b9d6f5; }
    .fp .room.short { background: #fff7f8; border-color: #f43f5e; }
    .fp .svc { background: repeating-linear-gradient(135deg, #f6f7fa 0, #f6f7fa 8px, #eef0f5 8px, #eef0f5 16px); border: 1px solid #e7e9f0; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #8a90a0; text-align: center; padding: 6px; }
    .fp .seat { width: 12px; height: 12px; border-radius: 3px; box-sizing: border-box; }
    .fp .s-in { background: #2f6fb0; } .fp .s-come { background: #fff; border: 2px solid #5b8fd6; } .fp .s-free { background: #eef0f5; }
    .fp .chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 999px; background: #f2f4f9; white-space: nowrap; }
    .fp .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .fp .d-in { background: #10b981; } .fp .d-due { background: #cfd3de; } .fp .d-late { background: #f43f5e; } .fp .d-moved { background: #5b8fd6; }
    .fp .kv { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; padding: 8px 0; border-bottom: 1px solid #f0f1f5; }
    .fp .kv span:first-child { color: #5f6676; }
    .fp .btn { font: inherit; font-size: 13px; font-weight: 600; border-radius: 10px; padding: 9px 14px; border: 1px solid #cfd3de; background: #fff; color: #1c2130; cursor: pointer; min-height: 38px; }
    .fp .btn.pri { background: #2f6fb0; border-color: #2f6fb0; color: #fff; }
    .fp .tab { font: inherit; font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 999px; border: 0; background: transparent; color: #5f6676; cursor: pointer; min-height: 36px; text-decoration: none; display: inline-flex; align-items: center; }
    .fp .tab.on { background: #1c2130; color: #fff; }
    .fp .muted { color: #5f6676; }
    .fp .toggle { display: inline-flex; gap: 2px; background: #fff; border: 1px solid #e7e9f0; border-radius: 999px; padding: 2px; }
    .fp .stat { background: #f7f9fd; border-radius: 12px; padding: 12px; }
    .fp .stat.lead { background: #f1f6fd; }
    .fp .tipbox { border: 1px solid #e7e9f0; background: #f7f9fd; border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
    .fp .tipbox.short { border-color: #f9c2cb; background: #fff7f8; }
    .fp .flash { border: 1px solid #a7e3c4; background: #e3f5ec; color: #15803d; }
    .fp .ok-text { color: #15803d; } .fp .bad-text { color: #be123c; }
    /* Dark mode (QA #9, 12 Nov 2026): the client's styles are all light hex,
       so the heading stayed rgb(28,33,48) on a dark page and the rooms stayed
       white. The same .dark overrides the children's pages carry
       (children/partials/mock-style), on the app's slate/night tokens. */
    .dark .fp { color: #e2e8f0; }
    .dark .fp .muted, .dark .fp .kv span:first-child { color: #94a3b8; }
    .dark .fp .room { background: rgb(15 23 42); color: #e2e8f0; border-color: rgb(255 255 255 / .18); }
    .dark .fp .room:hover { border-color: #5b8fd6; }
    .dark .fp .room.sel { border-color: #7fb2e5; box-shadow: 0 0 0 3px rgb(91 143 214 / .35); }
    .dark .fp .room.short { background: rgb(76 5 25); border-color: #f43f5e; }
    .dark .fp .svc { background: repeating-linear-gradient(135deg, rgb(30 41 59) 0, rgb(30 41 59) 8px, rgb(15 23 42) 8px, rgb(15 23 42) 16px); border-color: rgb(255 255 255 / .1); color: #94a3b8; }
    .dark .fp .s-come { background: transparent; } .dark .fp .s-free { background: rgb(255 255 255 / .14); }
    .dark .fp .chip, .dark .fp .mut { background: rgb(30 41 59); color: #cbd5e1; }
    .dark .fp .ok { background: rgb(16 185 129 / .18); color: #6ee7b7; } .dark .fp .spare { background: rgb(56 189 248 / .18); color: #7dd3fc; } .dark .fp .warn { background: rgb(245 158 11 / .18); color: #fcd34d; }
    .dark .fp .kv { border-color: rgb(255 255 255 / .08); }
    .dark .fp .btn { background: rgb(30 41 59); color: #e2e8f0; border-color: rgb(255 255 255 / .15); }
    .dark .fp .btn.pri { background: #2f6fb0; border-color: #2f6fb0; color: #fff; }
    .dark .fp .tab { color: #cbd5e1; } .dark .fp .tab.on { background: #fff; color: #1c2130; }
    .dark .fp .toggle { background: rgb(15 23 42); border-color: rgb(255 255 255 / .1); }
    .dark .fp .stat { background: rgb(30 41 59); } .dark .fp .stat.lead { background: rgb(27 94 145 / .3); }
    .dark .fp .tipbox { background: rgb(30 41 59); border-color: rgb(255 255 255 / .1); }
    .dark .fp .tipbox.short { background: rgb(190 18 60 / .14); border-color: rgb(244 63 94 / .45); }
    .dark .fp .flash { background: rgb(16 185 129 / .15); border-color: rgb(16 185 129 / .4); color: #6ee7b7; }
    .dark .fp .ok-text { color: #34d399; } .dark .fp .bad-text { color: #fb7185; }
</style>

<div class="fp" x-data="floorPlan(@js($rooms->values()->all()), @js($open), @js($plan['updated']->timezone($tz)->format('g:i A')))" style="display: flex; flex-direction: column; gap: 20px;">
    @if(session('floor'))
        <div class="flash" style="display: flex; align-items: center; gap: 10px; border-radius: 12px; padding: 10px 14px; font-size: 14px; font-weight: 600;" data-floor-flash><span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>{{ session('floor') }}</div>
    @endif
    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <span class="muted" style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;">{{ $plan['view'] === 'afternoon' ? 'Afternoon · as booked' : 'Live' }}</span>
            <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Floor plan</h1>
            <span class="muted" style="font-size: 14px;">{{ $plan['at']->timezone($tz)->format('l, M j') }} · who is in which room {{ $plan['view'] === 'afternoon' ? 'at '.$plan['at']->timezone($tz)->format('g:i A') : 'right now' }} · tap a room for details</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            {{-- Morning is this minute; afternoon is the day as booked at the
                 hour School Age is back, so three o'clock is seen before it comes. --}}
            <span class="toggle" data-view-toggle>
                <a href="{{ route('floor-plan.index') }}" class="tab {{ $plan['view'] === 'morning' ? 'on' : '' }}" @if($plan['view'] === 'morning') aria-current="page" @endif>Morning</a>
                <a href="{{ route('floor-plan.index', ['view' => 'afternoon']) }}" class="tab {{ $plan['view'] === 'afternoon' ? 'on' : '' }}" @if($plan['view'] === 'afternoon') aria-current="page" @endif>Afternoon</a>
            </span>
            {{-- Says so when the screen has lost the connection or has not
                 redrawn for a while (QA I5), rather than "Live" over old numbers. --}}
            <span class="pill" :class="online && ! stale ? 'ok' : 'warn'" style="font-size: 13px; padding: 6px 12px;" data-live-label><span style="width: 8px; height: 8px; border-radius: 50%;" :style="{ background: online && ! stale ? '#10b981' : '#f59e0b' }"></span><span x-text="! online ? 'Offline · last updated ' + updated : (stale ? 'Not updating · last updated ' + updated : 'Live · updated ' + updated)">Live · updated {{ $plan['updated']->timezone($tz)->format('g:i A') }}</span></span>
        </div>
    </header>

    {{-- The six counters, for the view the tiles are drawn for (QA #8, 12
         Nov 2026): the afternoon's cards say what three o'clock holds, in
         words that say so, rather than this minute's under its tiles. --}}
    @php($hour = $plan['at']->timezone($tz)->format('g:i A'))
    <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;" data-floor-counters data-counters-view="{{ $plan['view'] }}">
        @foreach($plan['view'] === 'afternoon' ? [
            ['In at '.$hour, $plan['counters']['in'], 'Booked to be in the rooms then', '', 'in'],
            ['Back from school', $plan['counters']['returning'], 'At school now · counted in at '.$hour, '', 'returning'],
            ['Checked out', $plan['counters']['out'], 'Picked up for the day', '', 'out'],
            ['Still to come', $plan['counters']['expected'], 'Not arrived yet · counted in at '.$hour, '', 'expected'],
            ['Staff on the schedule', $plan['counters']['staff'], 'Rostered in a room at '.$hour, '', 'staff'],
            ['Rooms below ratio', $short, 'Need another adult at '.$hour, $short ? 'bad' : 'ok', 'short'],
        ] : [
            ['Checked in', $plan['counters']['in'], 'In the building now', '', 'in'],
            ['Checked out · returning', $plan['counters']['returning'], 'At school, back later today', '', 'returning'],
            ['Checked out', $plan['counters']['out'], 'Picked up for the day', '', 'out'],
            ['Expected', $plan['counters']['expected'], 'Scheduled, not arrived yet', '', 'expected'],
            ['Staff on the floor', $plan['counters']['staff'], 'Clocked in, in a room', '', 'staff'],
            ['Rooms below ratio', $short, 'Need another adult', $short ? 'bad' : 'ok', 'short'],
        ] as [$title, $value, $note, $tone, $key])
            <div class="fp-card" style="padding: 14px 18px; display: flex; flex-direction: column; gap: 2px;" data-counter="{{ $key }}">
                <span style="font-size: 13px; font-weight: 600;">{{ $title }}</span>
                <span class="{{ $tone ? $tone.'-text' : '' }}" style="font-size: 28px; font-weight: 700;" data-counter-value>{{ $value }}</span>
                <span class="muted" style="font-size: 12px;">{{ $note }}</span>
            </div>
        @endforeach
    </section>

    <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
        {{-- The map: the client's wall plan, cell for cell. Twelve columns,
             six rows at the client's heights. UPK-4 runs the top and is
             joined to School Age beneath (one room, two groups); Infant
             beside School Age; the front door, hallway and office across the
             middle; Transition and Toddler on the left of the two rows below,
             the hallway, bathrooms and kitchen to their right; PreK along the
             foot beside the staff bathroom. --}}
        <section class="fp-card" style="flex: 999 1 560px; min-width: 0; padding: 18px; display: flex; flex-direction: column; gap: 12px;" data-floor-map>
            <div style="overflow-x: auto;">
                <div style="min-width: 600px; max-width: 760px; margin: 0 auto; display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); grid-template-rows: 218px 218px 64px 200px 200px 218px; gap: 6px; padding: 8px; background: #1c2130; border-radius: 10px;">
                    @php($cell = fn (array $r, string $place, string $xs = '') => view('floor-plan.room', ['r' => $r, 'place' => $place, 'xs' => $xs])->render())
                    {!! $cell($rooms['UPK-4'], 'grid-column: 1 / 13; grid-row: 1;', 'border-bottom-width: 0; border-bottom-left-radius: 0; border-bottom-right-radius: 0;') !!}
                    {!! $cell($rooms['School Age'], 'grid-column: 1 / 7; grid-row: 2;', 'margin-top: -6px; border-top-width: 0; border-top-left-radius: 0; border-top-right-radius: 0;') !!}
                    {!! $cell($rooms['Infant'], 'grid-column: 7 / 13; grid-row: 2;') !!}
                    {!! $cell($rooms['Transition'], 'grid-column: 1 / 8; grid-row: 4;') !!}
                    {!! $cell($rooms['Toddler'], 'grid-column: 1 / 8; grid-row: 5;') !!}
                    {!! $cell($rooms['PreK'], 'grid-column: 1 / 11; grid-row: 6;') !!}
                    <div class="svc" style="grid-column: 1 / 5; grid-row: 3;">Front door · fire panel</div>
                    <div class="svc" style="grid-column: 5 / 10; grid-row: 3;">Hallway</div>
                    <div class="svc" style="grid-column: 10 / 13; grid-row: 3;">Office</div>
                    <div class="svc" style="grid-column: 8 / 10; grid-row: 4 / 6; writing-mode: vertical-rl;">Hallway</div>
                    <div style="grid-column: 10 / 13; grid-row: 4; display: grid; grid-template-rows: repeat(2, minmax(0, 1fr)); gap: 6px;"><div class="svc">Girls bathroom</div><div class="svc">Boys bathroom</div></div>
                    <div class="svc" style="grid-column: 10 / 13; grid-row: 5;">Kitchen</div>
                    <div class="svc" style="grid-column: 11 / 13; grid-row: 6;">Staff bathroom</div>
                </div>
            </div>
            <div class="muted" style="display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; justify-content: center;">
                <span style="display: flex; gap: 6px; align-items: center;"><span class="seat s-in"></span>Child in</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="seat s-come"></span>Booked, not in yet</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="seat s-free"></span>Free place</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="dot d-in"></span>Clocked in</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="dot d-late"></span>Late</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="dot d-moved"></span>Moved here</span>
                <span style="display: flex; gap: 6px; align-items: center;"><span class="dot d-due"></span>Due later</span>
            </div>
        </section>

        {{-- The room tapped, in detail. Opens on the room that needs looking at. --}}
        <aside class="fp-card" style="flex: 1 1 300px; min-width: 0; padding: 20px; display: flex; flex-direction: column; gap: 14px;" data-room-panel>
            <template x-if="room">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 2px;"><span style="font-size: 22px; font-weight: 700;" x-text="room.room"></span><span class="muted" style="font-size: 13px;" x-text="room.ages + ' · capacity ' + room.capacity + ' · ratio 1:' + room.ratio"></span></div>
                        <span class="pill" :class="pillClass(room.tone)" x-text="room.status"></span>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px;">
                        <div class="stat lead"><div class="muted" style="font-size: 12px;">Checked in</div><div style="font-size: 24px; font-weight: 700;" x-text="room.in"></div></div>
                        <div class="stat"><div class="muted" style="font-size: 12px;">Expected</div><div style="font-size: 24px; font-weight: 700;" x-text="room.booked"></div></div>
                        <div class="stat"><div class="muted" style="font-size: 12px;">Checked out · returning</div><div style="font-size: 24px; font-weight: 700;" x-text="room.returning"></div></div>
                        <div class="stat"><div class="muted" style="font-size: 12px;">Checked out</div><div style="font-size: 24px; font-weight: 700;" x-text="room.out"></div></div>
                    </div>
                    <div style="display: flex; flex-direction: column;">
                        <div class="kv"><span>Staff needed</span><span style="font-weight: 700;" x-text="room.in ? room.needed + ' (' + room.in + ' ÷ ' + room.ratio + ', rounded up)' : '0 — no children in'"></span></div>
                        <div class="kv"><span>Staff in the room</span><span style="font-weight: 700;" x-text="room.staffIn"></span></div>
                        {{-- Free now: capacity less the children in, never the bookings (QA, 16 Nov 2026). --}}
                        <div class="kv"><span>Places free</span><span style="font-weight: 700;" x-text="room.placesFree + ' of ' + room.capacity + (room.sharedWith ? ' · shared with ' + room.sharedWith.join(', ') : '')"></span></div>
                        <div class="kv"><span>Enrolled in this room</span><span style="font-weight: 700;" x-text="room.enrolled"></span></div>
                    </div>
                    {{-- More enrolled or expected than places: warned, never blocked. --}}
                    <template x-if="room.capacityWarning">
                        <div class="tipbox short" data-room-capacity-warning><span style="font-size: 13px; line-height: 1.5;" x-text="'⚠ ' + room.capacityWarning + '. Sign-in is not blocked.'"></span></div>
                    </template>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <span style="font-size: 13px; font-weight: 700;">Teachers</span>
                        <template x-for="s in room.staff" :key="s.id">
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 14px;"><span class="dot" :class="dotClass(s)" style="width: 9px; height: 9px;"></span><span style="font-weight: 600;" x-text="s.name"></span><span class="muted" style="margin-left: auto; font-size: 13px;" x-text="staffLine(s)"></span></div>
                        </template>
                        <template x-if="! room.staff.length"><span class="muted" style="font-size: 13px;" x-text="room.tone === 'away' ? room.returning + ' checked out · returning at ' + room.returnLabel : 'No staff'"></span></template>
                    </div>
                    <template x-if="room.tip">
                        <div class="tipbox" :class="room.tone === 'short' ? 'short' : ''" data-room-tip>
                            <span style="font-size: 13px; line-height: 1.5;" x-text="room.tip.text"></span>
                            {{-- The move is real: it writes a cover on today's roster
                                 (App\Services\FloorCover) and the page comes back with the
                                 room open. Director only — teachers read the advice. --}}
                            @if(auth()->user()->isAdmin())
                                <template x-if="room.tip.action && room.tip.move">
                                    <form method="POST" action="{{ route('floor-plan.move') }}" style="display: flex; align-self: flex-start;" data-room-move>
                                        @csrf
                                        <input type="hidden" name="user_id" :value="room.tip.move.user_id">
                                        <input type="hidden" name="to" :value="room.tip.move.to">
                                        <input type="hidden" name="for" :value="room.tip.move.for ?? ''">
                                        <button type="submit" class="btn pri" x-text="room.tip.action" :title="'Puts ' + room.tip.action.replace(/^Move /, '') + ' on today\'s schedule from now'"></button>
                                    </form>
                                </template>
                            @else
                                <template x-if="room.tip.action"><span class="muted" style="font-size: 12px;" x-text="'Ask the director to ' + room.tip.action.charAt(0).toLowerCase() + room.tip.action.slice(1) + '.'"></span></template>
                            @endif
                        </div>
                    </template>
                    <a :href="'{{ route('attendance.index') }}?' + slug(room.room)" style="font-size: 13px; font-weight: 600;" x-text="'Open ' + room.room + ' on the register →'"></a>
                </div>
            </template>
        </aside>
    </div>
</div>

<script>
// The map's one piece of state: which room is open on the right. The rooms
// come down with the page; a tap only picks one of them. Opens on the room
// named in the address (the one a move was just made for), else the room
// that is short, else the first.
function floorPlan(rooms, open, updated) { return {
    rooms,
    updated,
    online: navigator.onLine,
    stale: false,
    loadedAt: Date.now(),
    room: rooms.find(r => r.room === open) ?? rooms.find(r => r.tone === 'short') ?? rooms[0] ?? null,
    pick(name) { this.room = this.rooms.find(r => r.room === name) ?? this.room; },
    pillClass(tone) { return { covered: 'ok', short: 'bad', spare: 'spare', away: 'mut', empty: 'mut' }[tone] ?? 'mut'; },
    dotClass(s) { return s.status === 'late' ? 'd-late' : (s.status === 'due' ? 'd-due' : (s.moved ? 'd-moved' : 'd-in')); },
    staffLine(s) {
        if (s.status === 'in') return (s.in ? 'In ' + s.in : 'In') + (s.moved ? ' · from ' + s.home : '') + (s.until ? ' · until ' + s.until : '');
        if (s.status === 'late') return 'Due ' + s.due + ' · not in';
        return 'Due ' + (s.dueFull || s.due);
    },
    slug(room) { return room.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''); },
    init() {
        // Live: the page redraws itself every minute while it is on screen —
        // but not while offline, where a reload would lose the plan for an
        // error page. The label says offline, and stale once the numbers are
        // more than two minutes old (a reload that never came back).
        window.addEventListener('online', () => { this.online = true; window.location.reload(); });
        window.addEventListener('offline', () => { this.online = false; });
        setInterval(() => {
            this.online = navigator.onLine;
            this.stale = Date.now() - this.loadedAt > 120000;
            if (this.online && ! document.hidden) window.location.reload();
        }, 60000);
    },
}; }
</script>
@endsection
