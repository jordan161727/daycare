{{-- One room on the map, as the client's FloorPlan.dc.html draws it: the
     name with its ages and ratio and the status pill; the count in with
     "in · cap" and the staff line to the right; a seat per place, ten to a
     row (in · booked not in yet · free); a chip per teacher with a coloured
     dot, or the away text when nobody is rostered. Pressing it opens the
     room beside the map. `$r` is the room's row from App\Services\FloorPlan,
     `$place` its cell on the grid, `$xs` any extra style the cell needs
     (UPK-4 and School Age are joined). --}}
@php($pill = ['covered' => 'ok', 'short' => 'bad', 'spare' => 'spare', 'away' => 'mut', 'empty' => 'mut'][$r['tone']])
@php($seats = max($r['capacity'], $r['in'] + $r['booked']))
@php($perRow = min(10, max(1, $r['capacity'])))
@php($staffLine = $r['in'] > 0 ? $r['staffIn'].' staff · need '.$r['needed'] : ($r['tone'] === 'away' ? $r['returning'].' checked out · returning at '.$r['returnLabel'] : ''))
<button type="button" class="room {{ $r['tone'] === 'short' ? 'short' : '' }}" :class="room && room.room === @js($r['room']) ? 'sel' : ''"
        style="{{ $place }} {{ $xs ?? '' }}" @click="pick(@js($r['room']))" :aria-pressed="room && room.room === @js($r['room']) ? 'true' : 'false'"
        data-room="{{ $r['room'] }}" data-room-tone="{{ $r['tone'] }}">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; width: 100%;">
        <div style="display: flex; flex-direction: column; gap: 1px; min-width: 0;"><span style="font-size: 16px; font-weight: 700;">{{ $r['room'] }}</span><span class="muted" style="font-size: 12px;">{{ $r['ages'] }}@if($r['room'] === 'UPK-4') · shares with School Age @elseif($r['room'] === 'School Age') · same room as UPK-4 @endif · 1:{{ $r['ratio'] }}</span></div>
        <span class="pill {{ $pill }}">{{ $r['status'] }}</span>
    </div>
    <div style="display: flex; align-items: baseline; gap: 6px;"><span style="font-size: 26px; font-weight: 700;">{{ $r['in'] }}</span><span class="muted" style="font-size: 13px;">in · cap {{ $r['capacity'] }}</span><span class="muted" style="font-size: 13px; margin-left: auto;">{{ $staffLine }}</span></div>
    <div style="display: flex; flex-wrap: wrap; gap: 3px; max-width: {{ $perRow * 15 }}px;" aria-label="{{ $r['in'] }} in, {{ $r['booked'] }} booked and not in yet, {{ $r['free'] }} free" data-room-dots>
        @for($i = 0; $i < $seats; $i++)
            <span class="seat {{ $i < $r['in'] ? 's-in' : ($i < $r['in'] + $r['booked'] ? 's-come' : 's-free') }}"></span>
        @endfor
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 4px;" data-room-staff>
        @forelse($r['staff'] as $s)
            <span class="chip"><span class="dot {{ $s['status'] === 'late' ? 'd-late' : ($s['status'] === 'due' ? 'd-due' : ($s['moved'] ? 'd-moved' : 'd-in')) }}"></span>{{ $s['name'] }}</span>
        @empty
            <span style="font-size: 12px; color: #8a90a0;">{{ $r['tone'] === 'away' ? $r['returning'].' checked out · returning at '.$r['returnLabel'] : 'No staff' }}</span>
        @endforelse
    </div>
</button>
