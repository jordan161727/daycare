<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $rangeLabel ? 'Attendance — '.$rangeLabel : 'Weekly attendance — week of '.$sheets->first()['dates']->first()->format('M j, Y') }}</title>
@include('layouts.favicon')
{{--
    The paper register. One landscape Letter page, the whole centre, the week's
    schedule printed into the boxes.

    Its own stylesheet, not the app's. This is a form that gets photocopied and
    written on with a pen, and it is specified in points and inches by the
    people who fill it in — the app's 15px root and its dark theme have no
    business here. The rules that matter are the marks:

      ☐  on white   scheduled — left blank it means no-show, once verified
      ■  on white   came — staff fill the box
      ☐  on grey    not scheduled — the box interior stays white so it CAN be
                    filled: a child who turns up unplanned is marked in it
      ■  on grey    drop-in — that unplanned arrival, filled

    So the schedule is pre-printed as shading, and staff only ever fill a box.
    Nothing on this page is drawn from the sign-ins: a box already black is one
    nobody could fill and one nobody could correct.

    Colours are forced to print (print-color-adjust) because the grey IS the
    information — a browser that "saves ink" by dropping backgrounds would
    print every child as scheduled every day.
--}}
<style>
/*
    Black and white. Every value below is one of ten variables, so the whole
    form recolours in one edit and nothing is left behind on a stray hex — which
    is what let this page go from sky to grey without touching the markup.

    The greys are chosen by lightness rather than by taste, because on this page
    the greys ARE the information: the "not scheduled" shading is the schedule,
    and the closed column is a fact about the centre. They stay far enough apart
    to survive a photocopy of a photocopy, which is where most of these end up.
*/
:root{
    --ink:#1b1b1b;      /* body and names */
    --ink-soft:#555;    /* captions, day dates */
    --ink-faint:#999;   /* the verify row, the stamp */
    --accent:#1b1b1b;   /* registration marks, a filled box */
    --line:#cfcfcf;     /* every rule on the page */
    --shade:#d9d9d9;    /* not scheduled */
    --closed:#ececec;   /* the centre was shut */
    --band:#e4e4e4;     /* a room's name bar */
    --band-ink:#1b1b1b;
    --row:#f4f4f4;      /* the scheduled-count row */
}
@page{size:11in 8.5in;margin:0.3in;}
*{box-sizing:border-box;}
html,body{height:100%;}
body{font-family:Arial,Helvetica,sans-serif;color:var(--ink);margin:0;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
.sheet{position:relative;border:1px solid var(--line);border-radius:4px;padding:12px 16px 10px;min-height:7.85in;}
.reg{position:absolute;width:11px;height:11px;background:var(--accent);}
.reg.tl{top:5px;left:5px}.reg.tr{top:5px;right:5px}.reg.bl{bottom:5px;left:5px}.reg.br{bottom:5px;right:5px}
.hd{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin:2px 4px 8px;}
.brand{display:flex;align-items:center;gap:11px;min-width:0;}
.brand img{height:38px;width:auto;flex:none;filter:grayscale(1);}
.brand-text{min-width:0;}
.title{font-size:17px;font-weight:bold;}
.sub{font-size:10px;color:var(--ink-soft);margin-top:2px;}
.stamp{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:8px;color:var(--ink-faint);text-align:right;line-height:1.4;padding-top:2px;white-space:nowrap;}
.legend{display:flex;flex-wrap:wrap;gap:5px 20px;font-size:10px;color:var(--ink-soft);margin:0 4px 9px;align-items:center;}
.legend .slot{margin-right:4px}
.cols{width:100%;border-collapse:collapse;}
.cols>tbody>tr>td{vertical-align:top;border:none;padding:0 8px;width:33.333%;}
.cols>tbody>tr>td:first-child{padding-left:0}.cols>tbody>tr>td:last-child{padding-right:0}
table.grid{width:100%;border-collapse:collapse;table-layout:fixed;}
.grid th,.grid td{border:0.5px solid var(--line);padding:0;text-align:center;font-size:9px;height:19px;}
.grid td.nm,.grid th.nm{text-align:left;padding-left:4px;font-size:9.5px;width:35%;border-left:none;}
.hrs{color:var(--ink-soft);font-weight:normal;font-variant-numeric:tabular-nums;}
.wide .grid td.nm,.wide .grid th.nm{width:26%;}
tr.dh th{font-size:8px;color:var(--ink-soft);font-weight:bold;padding:1px 0;line-height:1.05;}
th.d .dt{display:block;font-size:7px;color:var(--ink-faint);font-weight:normal;}
th.d .ap{display:block;font-size:7px;color:var(--ink-faint);font-weight:normal;letter-spacing:3px;}
th.d.closed,td.cl{background:var(--closed);color:var(--ink-faint);}
tr.band td{background:var(--band);color:var(--band-ink);font-weight:bold;font-size:10px;text-align:left;padding:3px 5px;border-left:none;}
.slot{display:inline-flex;align-items:center;justify-content:center;width:26px;height:16px;border-radius:2px;}
.slot.am{width:18px;}
.slot.ns{background:var(--shade);}
.pair{display:inline-flex;gap:4px;justify-content:center;}
.bx{width:14px;height:14px;border:1.4px solid var(--accent);border-radius:2px;background:#fff;}
.bx.ns{border:1.4px solid #b6b6b6;background:#fff;}
.slot.am .bx{width:13px;height:13px;}
tr.sch td{background:var(--row);font-weight:bold;font-size:9.5px;color:var(--band-ink);}
tr.sch td.nm{color:var(--ink-soft);font-weight:normal;font-style:italic;}
tr.sch .sl{color:var(--ink-faint);font-weight:normal;margin:0 1px;}
tr.vf td{height:17px;} tr.vf td.nm{color:var(--ink-faint);font-size:8.5px;}
.vbx{display:inline-block;width:12px;height:12px;border:1px solid var(--ink-faint);border-radius:2px;background:#fff;}
.foot{display:flex;justify-content:space-between;align-items:center;margin:9px 4px 0;font-size:10px;color:var(--ink-soft);}
.foot b{color:var(--ink);}
.empty{padding:40px 0;text-align:center;font-size:11px;color:var(--ink-soft);}
.break{break-after:page;}

/* On a screen the page sits on a grey desk with a bar above it; on paper there is
   only the page. The bar is the way back and the way to the printer for anyone
   who dismissed the dialog that opens on arrival. */
.bar{display:flex;align-items:center;gap:10px;padding:10px 16px;background:var(--ink);color:#f4f4f4;font-size:13px;}
.bar a,.bar button{font:inherit;font-size:13px;font-weight:600;border-radius:999px;padding:6px 14px;border:1px solid #666;background:transparent;color:#f4f4f4;cursor:pointer;text-decoration:none;}
.bar button{background:#fff;color:var(--ink);border-color:#fff;}
.bar button.ghost{background:transparent;color:#f4f4f4;border-color:#666;}
/* The one thing the page cannot do for them: pick the destination. It says so
   only after Save is pressed, when it is the next thing they are looking at. */
.bar .said{color:#ffd48a;font-size:12px;}
.bar .hint{margin-left:auto;color:#aaa;font-size:12px;}
@media screen{
    body{background:#d6d9de;min-height:100%;}
    .sheet{width:10.4in;max-width:calc(100% - 24px);margin:18px auto 24px;background:#fff;box-shadow:0 8px 30px rgb(0 0 0 / .18);}
}
@media print{
    .bar{display:none;}
    .sheet{border-color:#333;}
}
</style>
</head>
<body>
<div class="bar">
    <a href="{{ route('attendance.index', ['date' => $weekStart]) }}">‹ Back to attendance</a>
    <button type="button" onclick="sendToPrinter()">Print</button>
    <button type="button" class="ghost" onclick="saveAsPdf()">Save as PDF</button>
    <span class="said" id="saveHint" hidden>Choose <b>Save as PDF</b> under Destination.</span>
    {{-- The other range, one click away: having arrived at the wrong one, the
         fix should not be going back to the sheet to start again. --}}
    @if($range === 'week')
        <a href="{{ route('attendance.print', ['date' => $selectedDate, 'range' => 'month']) }}">Whole month instead</a>
    @else
        <a href="{{ route('attendance.print', ['date' => $selectedDate]) }}">Just this week instead</a>
    @endif
    <span class="hint">Landscape · Letter · {{ $sheets->count() === 1 ? 'one page' : $sheets->count().' pages' }}</span>
</div>

{{-- One page per week. A month is the same sheet several times over: twenty-two
     weekday columns will not fit across a page, and a register nobody can write
     on is not a register. --}}
@foreach($sheets as $page)
@php($closedNotes = collect($page['dates'])->filter(fn ($date) => isset($page['closed'][$date->toDateString()]))->map(fn ($date) => $date->format('D').' = '.$page['closed'][$date->toDateString()].' (closed)'))
@php($sameMonth = $page['dates']->first()->format('M') === $page['dates']->last()->format('M'))
@php($weekLabel = $sameMonth
    ? $page['dates']->first()->format('M j').'–'.$page['dates']->last()->format('j, Y')
    : $page['dates']->first()->format('M j').' – '.$page['dates']->last()->format('M j, Y'))

<div @class(['sheet', 'break' => ! $loop->last])>
<span class="reg tl"></span><span class="reg tr"></span><span class="reg bl"></span><span class="reg br"></span>
{{-- The centre's own mark, so a sheet on a clipboard is theirs at a glance and
     a page that gets faxed or filed carries its origin. Width and height on the
     tag: this page is printed the moment it loads, and a logo still measuring
     itself would push the header down mid-render. --}}
<div class="hd">
    <div class="brand">
        <img src="{{ asset('images/littleangels-logo.png') }}" alt="{{ $companyName }}" width="531" height="228">
        <div class="brand-text">
            <div class="title">{{ $companyName }} — weekly attendance</div>
            <div class="sub">Week of {{ $weekLabel }} · schedule pre-printed · fill a box only when the child arrives{{ $closedNotes->isNotEmpty() ? ' · '.$closedNotes->implode(' · ') : '' }}</div>
        </div>
    </div>
    {{-- The reference carried a QR mark here. A pattern that scans to nothing is
         worse than none, so until the centre says what it should open, the corner
         carries the week and the moment this copy was printed — which is what
         tells two sheets on the same clipboard apart. --}}
    <div class="stamp">week {{ $weekStart }}<br>printed {{ now()->format('Y/m/d H:i') }}</div>
</div>
<div class="legend">
    <span><span class="slot"><span class="bx"></span></span> scheduled — blank = no-show (once verified)</span>
    <span><span class="slot"><span class="bx" style="background:var(--accent)"></span></span> came (fill it)</span>
    <span><span class="slot ns"><span class="bx ns"></span></span> not scheduled</span>
    <span><span class="slot ns"><span class="bx ns" style="background:var(--accent);border-color:var(--accent)"></span></span> drop-in</span>
</div>

@if(collect($page['columns'])->every(fn ($column) => empty($column['rooms'])))
    <p class="empty">Nothing is scheduled for this week yet. Open the week on the attendance page and tick the days first.</p>
@else
<table class="cols"><tbody><tr>
@foreach($page['columns'] as $column)
    <td @class(['wide' => $column['wide']])>
    @if(! empty($column['rooms']))
    <table class="grid">
    @foreach($column['rooms'] as $room)
        <tr class="band"><td colspan="6">{{ $room['name'] }} · {{ count($room['rows']) }}{{ $room['split'] ? ' · AM+PM' : '' }}</td></tr>
        <tr class="dh">
            <th class="nm"></th>
            @foreach($page['dates'] as $date)
                @php($iso = $date->toDateString())
                @if(isset($page['closed'][$iso]))
                    <th class="d closed">{{ $date->format('D') }}<span class="dt">closed</span></th>
                @else
                    <th class="d">{{ $date->format('D') }}<span class="dt">{{ $date->format('M j') }}</span>@if($room['split'])<span class="ap">AM PM</span>@endif</th>
                @endif
            @endforeach
        </tr>
        @foreach($room['rows'] as $row)
            <tr>
                <td class="nm">{{ $row['name'] }}@if($row['hours'] ?? null) <span class="hrs">{{ $row['hours'] }}</span>@endif</td>
                @foreach($page['dates'] as $date)
                    @php($boxes = $row['cells'][$date->toDateString()])
                    @if($boxes === null)
                        <td class="cl">–</td>
                    @elseif(count($boxes) > 1)
                        {{-- Two boxes, morning then afternoon. Each is shaded or
                             not on its own: a child who comes only after school
                             has a grey morning beside a white afternoon. --}}
                        <td><span class="pair">@foreach($boxes as $on)<span @class(['slot am', 'ns' => ! $on])><span @class(['bx', 'ns' => ! $on])></span></span>@endforeach</span></td>
                    @else
                        @php($on = reset($boxes))
                        <td><span @class(['slot', 'ns' => ! $on])><span @class(['bx', 'ns' => ! $on])></span></span></td>
                    @endif
                @endforeach
            </tr>
        @endforeach
        <tr class="sch">
            <td class="nm">{{ $room['split'] ? 'sched AM/PM' : 'scheduled' }}</td>
            @foreach($page['dates'] as $date)
                @php($iso = $date->toDateString())
                @if(isset($page['closed'][$iso]))
                    <td class="cl">–</td>
                @elseif($room['split'])
                    <td class="ct">{{ $room['counts'][$iso]['AM'] }}<span class="sl">/</span>{{ $room['counts'][$iso]['PM'] }}</td>
                @else
                    <td class="ct">{{ $room['counts'][$iso]['FULL'] }}</td>
                @endif
            @endforeach
        </tr>
        {{-- One box per day, ticked by whoever checks the column against the
             room at the end of the day. Until it is ticked a blank box above
             means nothing; once it is, a blank box means the child did not come. --}}
        <tr class="vf">
            <td class="nm">verify ✓</td>
            @foreach($page['dates'] as $date)
                @if(isset($page['closed'][$date->toDateString()]))
                    <td class="cl"></td>
                @else
                    <td><span class="vbx"></span></td>
                @endif
            @endforeach
        </tr>
    @endforeach
    </table>
    @endif
    </td>
@endforeach
</tr></tbody></table>
@endif

<div class="foot">
    <div>
        Center scheduled per day —
        @foreach($page['dates']->reject(fn ($date) => isset($page['closed'][$date->toDateString()])) as $date)
            {{ $date->format('D') }} <b>{{ $page['dayTotals'][$date->toDateString()] }}</b>@if(! $loop->last) · @endif
        @endforeach
    </div>
    <div>
        @foreach($page['unscheduledRooms'] as $room){{ $room['room'] }} ({{ $room['count'] }})@if(! $loop->last), @endif @endforeach
        @if(! empty($page['unscheduledRooms'])) not scheduled this week · @endif
        verified by ____________
    </div>
</div>
</div>

@endforeach

<script>
    // The heading reads well at the top of a window and badly in a folder.
    const HEADING = document.title;
    const FILENAME = 'little-angels-attendance-{{ \Illuminate\Support\Str::slug($rangeLabel ?: 'week of '.$sheets->first()['dates']->first()->format('M j Y')) }}';
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
