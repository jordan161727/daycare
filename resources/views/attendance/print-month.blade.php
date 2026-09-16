<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attendance — {{ $monthLabel }}</title>
@include('layouts.favicon')
{{--
    The month on one page.

    The weekly sheet is a clipboard by the door. This is the sheet the month is
    reconciled from — every weekday of the month across a single page, rooms
    down it, so a child's whole month is one row you read left to right.

    Printing the month as five weekly sheets was the obvious thing and the wrong
    one: a month of one child came out as five pages each carrying one row. The
    geometry here is what makes it a month rather than a stack of weeks.

    Unlike the weekly sheet, this one arrives filled in. That difference is the
    point of it: the clipboard by the door must be blank, because a box printed
    already filled is one nobody could fill and one nobody could correct — but
    nobody fills this page in. It is what the month is reconciled from, and a
    reconciliation that omits what actually happened is a form you would have to
    sit beside the screen to use.

      ■  on white   expected, and came
      ☐  on white   expected, and did not — the days a month is reconciled over
      ■  on tint    came on a day nobody booked — a drop-in, and still billable
      ☐  on tint    not expected, and did not come
      –  on grey    the centre was shut, or the child was not yet on the roll

    Colours are forced to print, because the tint IS the schedule and the fill
    IS the attendance.
--}}
<style>
/* The weekly sheet's palette, unchanged: the two are read side by side and a
   grey that means one thing on one of them must mean it on the other. */
:root{
    --ink:#1b1b1b;
    --ink-soft:#555;
    --ink-faint:#999;
    --accent:#1b1b1b;
    --line:#cfcfcf;
    --shade:#d9d9d9;
    --closed:#ececec;
    --band:#e4e4e4;
    --band-ink:#1b1b1b;
    --row:#f4f4f4;
}
@page{size:11in 8.5in;margin:0.28in;}
*{box-sizing:border-box;}
body{font-family:Arial,Helvetica,sans-serif;color:var(--ink);margin:0;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.sheet{position:relative;border:1px solid var(--line);border-radius:4px;padding:10px 12px 8px;}
.reg{position:absolute;width:11px;height:11px;background:var(--accent);}
.reg.tl{top:5px;left:5px}.reg.tr{top:5px;right:5px}.reg.bl{bottom:5px;left:5px}.reg.br{bottom:5px;right:5px}
.hd{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin:2px 4px 7px;}
.brand{display:flex;align-items:center;gap:11px;min-width:0;}
.brand img{height:34px;width:auto;flex:none;filter:grayscale(1);}
.title{font-size:16px;font-weight:bold;}
.sub{font-size:9.5px;color:var(--ink-soft);margin-top:2px;}
.stamp{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:8px;color:var(--ink-faint);text-align:right;line-height:1.4;white-space:nowrap;}
.legend{display:flex;flex-wrap:wrap;gap:4px 18px;font-size:9.5px;color:var(--ink-soft);margin:0 4px 7px;align-items:center;}

/* Twenty-two day columns and a name. Fixed layout or the browser gives the
   name column everything and squeezes the boxes to nothing. */
table.grid{width:100%;border-collapse:collapse;table-layout:fixed;}
.grid th,.grid td{border:0.5px solid var(--line);padding:0;text-align:center;font-size:8px;height:15px;}
.grid .nm{text-align:left;padding-left:4px;font-size:8.5px;width:15%;border-left:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.hrs{color:var(--ink-soft);font-weight:normal;font-variant-numeric:tabular-nums;}

/* The week band. A date is found by counting along a row, and the band is what
   stops that count starting from the far left of the month every time. */
tr.wk th{font-size:8px;font-weight:bold;color:var(--band-ink);background:var(--row);padding:1px 0;}
tr.wk th.spacer{background:#fff;border-left:none;}
tr.dh th{font-size:7.5px;color:var(--ink-soft);font-weight:bold;padding:1px 0;line-height:1.1;}
tr.dh th .dow{display:block;font-size:6.5px;color:var(--ink-faint);font-weight:normal;}
th.closed,td.cl{background:var(--closed);color:var(--ink-faint);}

tr.band td{background:var(--band);color:var(--band-ink);font-weight:bold;font-size:9px;text-align:left;padding:2px 5px;border-left:none;}
.bx{display:inline-block;width:10px;height:10px;border:1.2px solid var(--accent);border-radius:2px;background:#fff;vertical-align:middle;}
.bx.ns{border-color:#b6b6b6;}
/* Came. The fill is the attendance; the tint underneath is still the schedule,
   so a drop-in reads as both at once rather than losing one of them. */
.bx.on{background:var(--accent);}
.bx.ns.on{background:#5a5a5a;border-color:#5a5a5a;}
.tint{display:inline-block;background:var(--shade);border-radius:2px;padding:1px 3px;line-height:1;}
td.off{background:var(--shade);}
/* The half a split room's second row carries, so a pair reads as one child. */
.half{color:var(--ink-faint);font-size:7px;padding-left:6px;}

tr.sch td{background:var(--row);font-weight:bold;font-size:8px;color:var(--band-ink);}
tr.sch td.nm{color:var(--ink-soft);font-weight:normal;font-style:italic;}
tr.att td{font-size:8px;font-weight:bold;color:var(--accent);}
tr.att td.nm{color:var(--ink-soft);font-weight:normal;font-style:italic;}
/* Fewer came than were expected. Not an error — a child can be off sick — but
   it is the cell somebody has to be able to find without reading every column.
   A ruled box rather than a colour: this page has no colours left to spend, and
   an outline survives a copier that flattens every grey it is given. */
tr.att td.short{box-shadow:inset 0 0 0 1.5px var(--ink);font-weight:bold;}
tr.vf td{height:14px;}
tr.vf td.nm{color:var(--ink-faint);font-size:7.5px;}
.vbx{display:inline-block;width:9px;height:9px;border:1px solid var(--ink-faint);border-radius:2px;background:#fff;}

.foot{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:7px 4px 0;font-size:9px;color:var(--ink-soft);}
.foot b{color:var(--ink);}
.empty{padding:40px 0;text-align:center;font-size:11px;color:var(--ink-soft);}

.bar{display:flex;align-items:center;gap:10px;padding:10px 16px;background:var(--ink);color:#f4f4f4;font-size:13px;}
.bar a,.bar button{font:inherit;font-size:13px;font-weight:600;border-radius:999px;padding:6px 14px;border:1px solid #666;background:transparent;color:#f4f4f4;cursor:pointer;text-decoration:none;}
.bar button{background:#fff;color:var(--ink);border-color:#fff;}
.bar button.ghost{background:transparent;color:#f4f4f4;border-color:#666;}
/* The one thing the page cannot do for them: pick the destination. It says so
   only after Save is pressed, when it is the next thing they are looking at. */
.bar .said{color:#ffd48a;font-size:12px;}
.bar .hint{margin-left:auto;color:#aaa;font-size:12px;}
@media screen{
    body{background:#d6d9de;}
    .sheet{width:10.44in;max-width:calc(100% - 24px);margin:18px auto 24px;background:#fff;box-shadow:0 8px 30px rgb(0 0 0 / .18);}
}
@media print{ .bar{display:none;} }
</style>
</head>
<body>
<div class="bar">
    <a href="{{ route('attendance.index', ['date' => $weekStart]) }}">‹ Back to attendance</a>
    <button type="button" onclick="sendToPrinter()">Print</button>
    <button type="button" class="ghost" onclick="saveAsPdf()">Save as PDF</button>
    <span class="said" id="saveHint" hidden>Choose <b>Save as PDF</b> under Destination.</span>
    <a href="{{ route('attendance.print', ['date' => $selectedDate]) }}">Just this week instead</a>
    <span class="hint">Landscape · Letter · one page · {{ $days->count() }} days</span>
</div>

@php($closedNotes = collect($closed)->map(fn ($reason, $date) => \Illuminate\Support\Carbon::parse($date)->format('M j').' = '.$reason))

<div class="sheet">
<span class="reg tl"></span><span class="reg tr"></span><span class="reg bl"></span><span class="reg br"></span>

<div class="hd">
    <div class="brand">
        <img src="{{ asset('images/littleangels-logo.png') }}" alt="Little Angels Day Care Center" width="531" height="228">
        <div>
            <div class="title">Little Angels Day Care — {{ $monthLabel }}</div>
            <div class="sub">{{ $days->count() }} open days · <b>{{ $monthAttended }}</b> of {{ $monthTotal }} expected days attended{{ $closedNotes->isNotEmpty() ? ' · closed '.$closedNotes->implode(' · ') : '' }}</div>
        </div>
    </div>
    <div class="stamp">{{ $monthLabel }}<br>printed {{ now()->format('Y/m/d H:i') }}</div>
</div>

<div class="legend">
    <span><span class="bx on"></span> expected, and came</span>
    <span><span class="bx"></span> expected, did not come</span>
    <span><span class="tint"><span class="bx ns on"></span></span> drop-in — nobody booked it, still billable</span>
    <span><span class="tint"><span class="bx ns"></span></span> not expected</span>
    <span><span style="display:inline-block;width:10px;text-align:center;color:var(--ink-faint)">–</span> centre closed, or not yet on the roll</span>
</div>

@if(empty($rooms))
    <p class="empty">Nothing is scheduled in {{ $monthLabel }} yet. Open the weeks on the attendance page and tick the days first.</p>
@else
<table class="grid">
    {{-- Two header rows: the week a column belongs to, then the day itself. A
         month is twenty-two columns wide, and counting along from the far left
         every time is how the wrong day gets signed. --}}
    <tr class="wk">
        <th class="nm spacer"></th>
        @foreach($weeks as $monday => $weekDays)
            <th colspan="{{ count($weekDays) }}">{{ \Illuminate\Support\Carbon::parse($monday)->format('M j') }}</th>
        @endforeach
    </tr>
    <tr class="dh">
        <th class="nm"></th>
        @foreach($days as $day)
            <th @class(['closed' => isset($closed[$day->toDateString()])])>
                <span class="dow">{{ $day->format('D')[0] }}</span>{{ $day->format('j') }}
            </th>
        @endforeach
    </tr>

    @foreach($rooms as $room)
        <tr class="band"><td colspan="{{ $days->count() + 1 }}">{{ $room['name'] }} · {{ $room['members'] }}{{ $room['split'] ? ' · AM+PM' : '' }}</td></tr>

        @foreach($room['rows'] as $row)
            <tr>
                {{-- A split room writes the name once and lets the second row
                     carry the half alone, so a pair reads as one child. --}}
                <td class="nm">@if($row['repeat'])<span class="half">{{ $row['session'] }}</span>@else{{ $row['name'] }}@if($row['hours'] ?? null) <span class="hrs">{{ $row['hours'] }}</span>@endif
                    @if($row['session']) <span class="half">{{ $row['session'] }}</span>@endif @endif</td>
                @foreach($days as $day)
                    @php($state = $row['cells'][$day->toDateString()])
                    @if($state === 'closed' || $state === 'out')
                        <td class="cl">–</td>
                    @else
                        {{-- The tint says what was booked and the fill says what
                             happened, so a drop-in carries both marks at once. --}}
                        <td @class(['off' => in_array($state, ['off', 'dropin'], true)])>
                            <span @class([
                                'bx',
                                'ns' => in_array($state, ['off', 'dropin'], true),
                                'on' => in_array($state, ['came', 'dropin'], true),
                            ])></span>
                        </td>
                    @endif
                @endforeach
            </tr>
        @endforeach

        <tr class="sch">
            <td class="nm">scheduled</td>
            @foreach($days as $day)
                @php($iso = $day->toDateString())
                @if(isset($closed[$iso]))
                    <td class="cl">–</td>
                @else
                    <td>{{ $room['counts'][$iso] ?: '' }}</td>
                @endif
            @endforeach
        </tr>

        {{-- What happened, under what was expected. The two rows together are
             the whole reason this page exists: a day where they disagree is a
             day somebody has to account for. --}}
        <tr class="att">
            <td class="nm">attended</td>
            @foreach($days as $day)
                @php($iso = $day->toDateString())
                @if(isset($closed[$iso]))
                    <td class="cl">–</td>
                @else
                    <td @class(['short' => $room['attended'][$iso] < $room['counts'][$iso]])>{{ $room['attended'][$iso] ?: '' }}</td>
                @endif
            @endforeach
        </tr>

        {{-- One box a week rather than one a day. Twenty-two verify boxes a room
             is a row nobody would tick; a week is the unit a month is actually
             checked in. --}}
        <tr class="vf">
            <td class="nm">verify ✓</td>
            @foreach($weeks as $monday => $weekDays)
                <td colspan="{{ count($weekDays) }}"><span class="vbx"></span></td>
            @endforeach
        </tr>
    @endforeach
</table>
@endif

<div class="foot">
    <div>
        Scheduled days by week —
        @foreach($weekTotals as $monday => $total)
            {{ \Illuminate\Support\Carbon::parse($monday)->format('M j') }} <b>{{ $total }}</b>@if(! $loop->last) · @endif
        @endforeach
        · month <b>{{ $monthTotal }}</b> expected, <b>{{ $monthAttended }}</b> attended
    </div>
    <div>
        @foreach($unscheduledRooms as $room){{ $room['room'] }} ({{ $room['count'] }})@if(! $loop->last), @endif @endforeach
        @if(! empty($unscheduledRooms)) not scheduled this month · @endif
        verified by ____________
    </div>
</div>
</div>

<script>
    // The heading reads well at the top of a window and badly in a folder.
    const HEADING = document.title;
    const FILENAME = 'little-angels-attendance-{{ \Illuminate\Support\Str::slug($monthLabel) }}';
    const hint = document.getElementById('saveHint');

    // Chrome and Edge suggest the document title as the filename; Firefox and
    // Safari ignore it, and lose nothing by being offered a better one.
    function openDialog(title) {
        document.title = title;
        window.print();
    }

    function sendToPrinter() {
        hint.hidden = true;
        openDialog(HEADING);
    }

    function saveAsPdf() {
        // The destination is the user's to choose — the page cannot set it, so
        // it says which one, and the hint stays up for the second attempt if
        // they dismiss the dialog having missed it the first time.
        hint.hidden = false;
        openDialog(FILENAME);
    }

    // Whichever way it ended, the window is a window again.
    window.addEventListener('afterprint', () => { document.title = HEADING; });

    // The menu on the attendance page said "Print", so the dialog opens on
    // arrival rather than making the same click twice. Cancel leaves the sheet
    // on screen with the bar above it, Print and Save both a click away.
    window.addEventListener('load', sendToPrinter);
</script>
</body>
</html>
