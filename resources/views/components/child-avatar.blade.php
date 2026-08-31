@props(['child', 'size' => 'h-9 w-9', 'shape' => 'rounded-full'])
{{-- A child's photograph, or a drawn one while there is none.

     The stand-in is a flat cartoon face rather than the initial letter, because
     a roster of twenty letters all looks the same and a room of drawn faces
     does not — it is the difference between reading the row and recognising it.

     Drawn here as inline SVG rather than pulled from an icon set: it is a
     handful of shapes, it costs no request, it needs no licence, and it can be
     coloured per child.

     Which face a child gets follows what the record says. The hairstyles are
     grouped by gender and the group is chosen from the `gender` column — never
     from the name, which cannot be read reliably in any language and got girls
     drawn with a boy's crop. A child whose gender nobody has recorded is drawn
     from the styles that read as either, which is the honest picture of a
     record that does not say. Within the group the choice comes from the LAN
     and name, so it is the same face on every page and after every deploy — an
     avatar that changed on each render would be worse than the letter. --}}
@php($name = trim(($child->first_name ?? '').' '.($child->last_name ?? '')))
@php($photo = $child->photoUrl())

@php($seed = crc32(($child->lan ?? '').'|'.$name))

@if($photo)
    <img src="{{ $photo }}" alt="{{ $name }}" {{ $attributes->class([$size, $shape, 'shrink-0 object-cover']) }}>
@else
    {{-- Skin, hair and clothes are drawn from separate slices of the one number
         so two children who share a hairstyle rarely share everything else. --}}
    @php($skin = ['#f7d7bd', '#f0c49b', '#dda476', '#b57746', '#8a5433'][$seed % 5])
    @php($hair = ['#3c2a21', '#6b4423', '#a9642f', '#26303f', '#d0a04a', '#1c1512'][intdiv($seed, 5) % 6])
    @php($outfit = ['#b6c3e6', '#93a4d1', '#f4b6c2', '#a5d6c1', '#f6d08a', '#c9b6e6'][intdiv($seed, 30) % 6])
    @php($backdrop = ['#eef2fb', '#e7ecf8', '#fdeef1', '#eaf6f1', '#fdf4e3', '#f2edfb'][intdiv($seed, 30) % 6])
    @php($styles = match ($child->gender) {
        'Girl' => ['bunches', 'topknot', 'long', 'curls'],
        'Boy' => ['bowl', 'sweep', 'crop', 'hat'],
        default => ['bowl', 'curls', 'hat', 'sweep'],
    })
    @php($style = $styles[intdiv($seed, 180) % count($styles)])
    @php($ink = '#2f2a26')

    <svg viewBox="0 0 64 64" role="img" aria-label="{{ $name }}" data-child-avatar="{{ $style }}" {{ $attributes->class([$size, $shape, 'shrink-0 overflow-hidden']) }}>
        <rect width="64" height="64" fill="{{ $backdrop }}"/>
        {{-- Shoulders, then neck, then head: painted back to front so each
             overlaps the one behind it without any clipping paths. --}}
        <path d="M10 64c0-12.5 9.9-19.5 22-19.5S54 51.5 54 64Z" fill="{{ $outfit }}"/>
        @if($style === 'long')
            {{-- Behind the head, so it falls past the shoulders. --}}
            <path d="M15.5 27a16.5 16.5 0 0 1 33 0v19h-33Z" fill="{{ $hair }}"/>
        @endif
        <rect x="28.5" y="35" width="7" height="10" rx="3.5" fill="{{ $skin }}"/>
        <circle cx="17.6" cy="29" r="3" fill="{{ $skin }}"/>
        <circle cx="46.4" cy="29" r="3" fill="{{ $skin }}"/>
        <circle cx="32" cy="27" r="14.5" fill="{{ $skin }}"/>

        @switch($style)
            @case('hat')
                {{-- The one in a woolly hat, for the same reason a class photo
                     has one: it makes that child findable at a glance. --}}
                <path d="M32 11A14.5 14.5 0 0 0 17.5 25.5h29A14.5 14.5 0 0 0 32 11Z" fill="{{ $outfit }}"/>
                <rect x="15.5" y="24" width="33" height="4.5" rx="2.25" fill="{{ $hair }}"/>
                <circle cx="32" cy="8.5" r="3.2" fill="{{ $hair }}"/>
                @break

            @case('crop')
                {{-- Short back and sides: no fringe, so it reads apart from the
                     bowl cut at nine pixels as well as at ninety. --}}
                <path d="M32 12.5A14.5 14.5 0 0 0 17.6 25.4c3.2-3.4 8.3-5.1 14.4-5.1s11.2 1.7 14.4 5.1A14.5 14.5 0 0 0 32 12.5Z" fill="{{ $hair }}"/>
                @break

            @default
                <path d="M32 12.5A14.5 14.5 0 0 0 17.5 27h29A14.5 14.5 0 0 0 32 12.5Z" fill="{{ $hair }}"/>
        @endswitch

        @switch($style)
            @case('bunches')
                <circle cx="14.5" cy="28.5" r="5" fill="{{ $hair }}"/>
                <circle cx="49.5" cy="28.5" r="5" fill="{{ $hair }}"/>
                @break

            @case('topknot')
                <circle cx="32" cy="9.5" r="5" fill="{{ $hair }}"/>
                @break

            @case('curls')
                <circle cx="22" cy="15" r="6" fill="{{ $hair }}"/>
                <circle cx="32" cy="11" r="6.5" fill="{{ $hair }}"/>
                <circle cx="42" cy="15" r="6" fill="{{ $hair }}"/>
                @break

            @case('sweep')
                <ellipse cx="38.5" cy="15.5" rx="12" ry="6.5" fill="{{ $hair }}" transform="rotate(-9 38.5 15.5)"/>
                @break

            @case('long')
                {{-- The half that falls in front of the shoulders. --}}
                <path d="M17.6 26c-.6 6-.4 12 .4 18h4.5c-1.4-6-1.6-12-1.1-18Z" fill="{{ $hair }}"/>
                <path d="M46.4 26c.6 6 .4 12-.4 18h-4.5c1.4-6 1.6-12 1.1-18Z" fill="{{ $hair }}"/>
                @break
        @endswitch

        <circle cx="26.6" cy="27.2" r="1.9" fill="{{ $ink }}"/>
        <circle cx="37.4" cy="27.2" r="1.9" fill="{{ $ink }}"/>
        <circle cx="22.4" cy="32" r="2.6" fill="#ef9a9a" opacity=".5"/>
        <circle cx="41.6" cy="32" r="2.6" fill="#ef9a9a" opacity=".5"/>
        <path d="M27.6 33.4c1.6 2.3 7.2 2.3 8.8 0" fill="none" stroke="{{ $ink }}" stroke-width="1.8" stroke-linecap="round"/>
    </svg>
@endif
