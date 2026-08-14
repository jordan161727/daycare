{{-- Hour marks above the lanes. Without them a bar is a coloured rectangle
     with no way to read a time off it. --}}
<div class="mb-1 flex items-center gap-3">
    <div class="w-32 shrink-0"></div>
    <div class="relative h-4 flex-1 text-[10px] text-slate-400">
        @foreach($hours as $hour)
            <span class="absolute -translate-x-1/2 tabular-nums" style="left:{{ number_format((($hour * 60) - $open) / $span * 100, 4) }}%">
                {{ $hour > 12 ? $hour - 12 : $hour }}{{ $hour >= 12 ? 'p' : 'a' }}
            </span>
        @endforeach
    </div>
    {{-- Matches the hours column the teacher lanes carry, so the marks line up
         with the bars rather than sitting a few percent to their right. --}}
    @if($trailing ?? true)<div class="w-12 shrink-0"></div>@endif
</div>
