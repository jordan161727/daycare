@props(['title', 'eyebrow' => null, 'subtitle' => null])
{{-- The page header the current pages draw (Staff, Reports, Messages): a small
     uppercase eyebrow, a 28px title and one muted line under it. In Tailwind
     here, for pages that do not load the Children mock style. x-page-header
     is the older, smaller one with the "Daycare Management" eyebrow (QA,
     17 Nov 2026: Holidays, Import children and Profile moved to this). --}}
<header {{ $attributes->merge(['class' => 'flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="flex min-w-0 flex-col gap-1">
        @if($eyebrow)<span class="text-xs font-semibold uppercase tracking-[.08em] text-[#5f6676] dark:text-slate-400">{{ $eyebrow }}</span>@endif
        <h1 class="m-0 text-[28px] font-bold leading-tight text-[#1c2130] dark:text-slate-100">{{ $title }}</h1>
        @if($subtitle)<span class="text-sm text-[#5f6676] dark:text-slate-400">{{ $subtitle }}</span>@endif
    </div>
    {{ $slot }}
</header>
