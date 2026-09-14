@props(['state', 'size' => 'text-base'])
{{-- The day's weather, as a child reads it.

     The sheet's four states are already colour-coded, and colour alone is the
     thing a colour-blind parent cannot use. A weather mark says the same thing
     a second way, in a vocabulary a four-year-old already has:

       sunny    here
       rainbow  here on a day nobody planned for — a surprise, not a fault
       cloudy   expected, not arrived yet
       umbrella the centre is shut

     Keyed on the sheet's own state names — present, unplanned, scheduled, off —
     rather than a second vocabulary that would have to be kept in step with
     $boxStates by hand.

     `off` gets nothing at all. An icon for "no news" is clutter on a grid sixty
     rows deep, and an empty box already says it. --}}
@php($weather = [
    'present' => ['☀️', 'here'],
    'unplanned' => ['🌈', 'here, not scheduled'],
    'scheduled' => ['☁️', 'expected'],
    'closed' => ['🌧️', 'centre closed'],
])
@php($mark = $weather[$state] ?? null)

@if($mark)
    <span {{ $attributes->class([$size, 'select-none leading-none']) }}
          role="img" aria-label="{{ $mark[1] }}">{{ $mark[0] }}</span>
@endif
