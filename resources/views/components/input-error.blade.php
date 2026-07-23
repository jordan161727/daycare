@props(['messages'])
@if($messages)
    <p {{ $attributes->merge(['class' => 'mt-1.5 text-xs font-medium text-rose-600']) }}>{{ $messages[0] }}</p>
@endif
