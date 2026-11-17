@props([
    // One row of Child::alertList().
    'alert',
    // Show the kind's name ("Allergy:") before the words.
    'label' => true,
    // Cut the words to this many characters (the list's narrow column).
    'limit' => null,
    // A full-width line rather than a chip (the check-in dialog, the record).
    'block' => false,
])
{{-- A child's alert, the same on every screen (QA, 16 Nov 2026): its own
     colour and icon per kind — allergy, medical, medication, court order,
     note — and a solid chip with "Severe" on it when it is. The styles are
     in <x-alert-styles>, pulled in once per page. --}}
@php
    $type = $alert['type'] ?? 'note';
    $severity = $alert['severity'] ?? null;
    $severe = strtolower((string) $severity) === 'severe';
    $text = $limit ? \Illuminate\Support\Str::limit($alert['text'], $limit) : $alert['text'];
    $title = ($alert['label'] ?? 'Note').': '.$alert['text'].($severity ? ' ('.$severity.')' : '');
@endphp
<x-alert-styles />
<span {{ $attributes->merge(['class' => 'na-alert na-alert--'.$type.($severe ? ' na-alert--severe' : '').($block ? ' na-alert-block' : '')]) }}
      title="{{ $title }}" data-alert-chip="{{ $type }}"@if($severe) data-alert-severe @endif>
    {!! \App\Models\Child::alertIcon($type) !!}
    <span>{{ $label ? $alert['label'].': '.$text : $text }}@if($severity && ! $severe) · {{ $severity }}@endif</span>
    @if($severe)<span class="na-alert-sev">Severe</span>@endif
</span>
