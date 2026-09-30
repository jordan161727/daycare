@props(['child', 'size' => 'h-9 w-9', 'shape' => 'rounded-full'])
{{-- A child's photograph, or an illustrated portrait while there is none.

     The stand-in is a face rather than the initial letter, because a roster of
     twenty letters all looks the same and a room of faces does not — it is the
     difference between reading the row and recognising it. It was a flat
     cartoon drawn in SVG for a while; the centre asked for something more like
     a portrait, so it is now one of twelve illustrations on a single sprite
     (public/images/portraits.webp) — one request for the whole roll, cached
     after the first page.

     Which portrait a child gets follows what the record says — see
     App\Services\Portrait. The twelve are grouped by how they read and the
     group is chosen from the `gender` column, or from "girl" or "boy" in the
     description — never from the name, which cannot be read reliably in any
     language and once got girls drawn with a boy's crop. The rest of the
     description ("blonde long", "brunet short hair") picks the closest
     portrait in the group; the LAN and name settle what is left, so it is the
     same face on every page and after every deploy. A child whose record says
     neither is drawn the plain grey silhouette, which is the honest picture of
     a record that does not say. --}}
@php($name = trim(($child->first_name ?? '').' '.($child->last_name ?? '')))
@php($photo = $child->photoUrl())

@if($photo)
    {{-- Lazily, and decoded off the main thread. A roll of sixty photographs
     is sixty requests, each one served by PHP because these are private
     files; fetched eagerly they compete with the rows nobody has scrolled
     to yet, and the first screen fills in last. --}}
<img src="{{ $photo }}" alt="{{ $name }}" loading="lazy" decoding="async" {{ $attributes->class([$size, $shape, 'shrink-0 bg-slate-100 object-cover dark:bg-slate-800']) }}>
@else
    @php($portrait = \App\Services\Portrait::pick($child))

    @if($portrait === null)
        {{-- Nothing on the record says girl or boy, so no face is guessed:
             the plain grey silhouette every directory falls back to. --}}
        <span role="img" aria-label="{{ $name }}" data-child-avatar="none"
              {{ $attributes->class([$size, $shape, 'block shrink-0 overflow-hidden']) }} style="background-color:#a6a6a6">
            <svg viewBox="0 0 100 100" class="h-full w-full" aria-hidden="true" focusable="false">
                <circle cx="50" cy="40" r="17" fill="#eef4f3"/>
                <path d="M18 100c0-22 14-36 32-36s32 14 32 36z" fill="#eef4f3"/>
            </svg>
        </span>
    @else
        {{-- One of twelve illustrated portraits, from a single sprite so a roll
             of sixty faces is one request, cached after the first page. Four
             columns and three rows, so a face is found by its column across a
             400% background and its row down a 300% one. --}}
        @php($column = $portrait % 4)
        @php($row = intdiv($portrait, 4))

        <span role="img" aria-label="{{ $name }}" data-child-avatar="{{ $portrait }}"
              style="background-image:url('{{ asset('images/portraits.webp') }}');background-size:400% 300%;background-position:{{ round($column * 100 / 3, 3) }}% {{ $row * 50 }}%"
              {{ $attributes->class([$size, $shape, 'block shrink-0 bg-slate-100 bg-no-repeat dark:bg-slate-800']) }}></span>
    @endif
@endif
