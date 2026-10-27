<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Schedule · {{ \Illuminate\Support\Carbon::parse($weekStart)->format('M j') }}</title>
{{-- The week for the wall: one row per person, plain times, nothing to
     click. Opens in its own tab from "Print for staff" and prints itself. --}}
<style>
    body { margin: 24px; font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif; color: #1c2130; font-size: 13px; }
    h1 { margin: 0 0 4px; font-size: 22px; }
    p { margin: 0 0 16px; color: #5f6676; }
    table { width: 100%; border-collapse: collapse; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #5f6676; text-align: left; padding: 8px; border-bottom: 1px solid #cfd3de; }
    td { padding: 8px; border-bottom: 1px solid #e7e9f0; vertical-align: top; }
    td.name { font-weight: 700; white-space: nowrap; } td.name small { display: block; font-weight: 400; color: #8a90a0; font-size: 11px; }
    td.hrs { text-align: right; white-space: nowrap; font-weight: 700; }
    .off { color: #8a90a0; }
    @media print { body { margin: 12mm; } .noprint { display: none; } }
</style>
</head>
<body>
@php($monday = \Illuminate\Support\Carbon::parse($weekStart))
@php($last = $monday->copy()->addDays(count($dates) - 1))
<h1>Staff schedule · {{ $monday->format('M j') }} – {{ $last->format($last->isSameMonth($monday) ? 'j' : 'M j') }}</h1>
<p>{{ $week?->published_at ? 'Published '.$week->published_at->format('D M j, g:i A') : 'Draft — not yet published' }} · Little Angels</p>
<table>
    <thead><tr><th>Teacher</th>@foreach($dates as $date)<th>{{ $date->format('D j') }}</th>@endforeach<th style="text-align: right;">Hours</th></tr></thead>
    <tbody>
        @foreach($teach as $t)
            <tr>
                <td class="name">{{ $t['name'] }}<small>{{ $t['role'] }}</small></td>
                @foreach($dates as $code => $date)
                    <td>
                        @foreach($t['days'][$code]['lines'] as $x)<div><b>{{ $x['time'] }}</b> {{ $x['room'] }}</div>@endforeach
                        @if($t['days'][$code]['off'])<span class="off">{{ $t['days'][$code]['offText'] }}</span>@endif
                    </td>
                @endforeach
                <td class="hrs">{{ $t['hrs'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<p class="noprint" style="margin-top: 16px;"><button onclick="window.print()">Print</button></p>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
