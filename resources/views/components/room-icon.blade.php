@props(['room', 'size' => 'text-base'])
{{-- The animal a room is known by.

     The map itself lives in ClassroomAssignment, beside the rooms it names, so
     the sheet's JavaScript rows and this component cannot disagree about which
     animal a room has.

     Emoji rather than an icon set. Lucide and Phosphor are line and duotone
     icons — monochrome by design, which is the opposite of what a kiosk tile
     wants — and Flaticon's colourful packs are files to host and a licence to
     honour. An emoji is full colour, costs no request, needs no attribution,
     and is already how this app writes the wave on the dashboard.

     aria-hidden with the room named in the title: a screen reader saying
     "elephant" before every room name would be noise, and the room name is
     already there in text wherever this sits. --}}
<span {{ $attributes->class([$size, 'select-none leading-none']) }}
      aria-hidden="true" title="{{ $room }}">{{ \App\Services\ClassroomAssignment::animal($room) }}</span>
