@props(['child', 'size' => 'h-9 w-9', 'shape' => 'rounded-full'])
{{-- A child's photograph, or an illustrated portrait while there is none.

     The stand-in is a face rather than the initial letter, because a roster of
     twenty letters all looks the same and a room of faces does not — it is the
     difference between reading the row and recognising it. It was a flat
     cartoon drawn in SVG for a while; the centre asked for something more like
     a portrait, so it is now one of twelve illustrations on a single sprite
     (public/images/portraits.webp) — one request for the whole roll, cached
     after the first page.

     Which portrait a child gets follows what the record says. The twelve are
     grouped by how they read and the group is chosen from the `gender` column
     — never from the name, which cannot be read reliably in any language and
     once got girls drawn with a boy's crop. A child whose gender nobody has
     recorded is drawn from all twelve, which is the honest picture of a record
     that does not say. Within the group the choice comes from the LAN and
     name, so it is the same face on every page and after every deploy — an
     avatar that changed on each render would be worse than the letter. --}}
@php($name = trim(($child->first_name ?? '').' '.($child->last_name ?? '')))
@php($photo = $child->photoUrl())

@php($seed = crc32(($child->lan ?? '').'|'.$name))

@if($photo)
    {{-- Lazily, and decoded off the main thread. A roll of sixty photographs
     is sixty requests, each one served by PHP because these are private
     files; fetched eagerly they compete with the rows nobody has scrolled
     to yet, and the first screen fills in last. --}}
<img src="{{ $photo }}" alt="{{ $name }}" loading="lazy" decoding="async" {{ $attributes->class([$size, $shape, 'shrink-0 bg-slate-100 object-cover dark:bg-slate-800']) }}>
@else
    {{-- One of twelve illustrated portraits, from a single sprite so a roll
         of sixty faces is one request, cached after the first page.

         Which one follows what the record says, exactly as the drawn face
         did before it. The portraits are grouped by how they read — six that
         read as girls, six as boys — and the group is chosen from the
         `gender` column, never guessed from the name. A child whose record
         does not say is drawn from all twelve. Within the group the choice
         comes from the LAN and name, so it is the same face on every page
         and after every deploy.

         Four columns and three rows, so a face is found by its column across
         a 400% background and its row down a 300% one. --}}
    @php($groups = [
        'Girl' => [1, 3, 4, 6, 9, 11],
        'Boy' => [0, 2, 5, 7, 8, 10],
    ])
    @php($pool = $groups[$child->gender ?? ''] ?? range(0, 11))
    @php($portrait = $pool[$seed % count($pool)])
    @php($column = $portrait % 4)
    @php($row = intdiv($portrait, 4))

    <span role="img" aria-label="{{ $name }}" data-child-avatar="{{ $portrait }}"
          style="background-image:url('{{ asset('images/portraits.webp') }}');background-size:400% 300%;background-position:{{ round($column * 100 / 3, 3) }}% {{ $row * 50 }}%"
          {{ $attributes->class([$size, $shape, 'block shrink-0 bg-slate-100 bg-no-repeat dark:bg-slate-800']) }}></span>
@endif
