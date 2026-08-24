@props(['user', 'size' => 'h-9 w-9', 'text' => 'text-sm'])
{{-- A user's photo, or their initials while they have not uploaded one. --}}
@if($user->avatar_url)
    <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" {{ $attributes->merge(['class' => $size.' shrink-0 rounded-full object-cover']) }}>
@else
    <span {{ $attributes->merge(['class' => $size.' '.$text.' grid shrink-0 place-items-center rounded-full bg-white/55 font-semibold uppercase']) }} aria-hidden="true">{{ $user->initials }}</span>
@endif
