{{-- The Schedule cell: the hours the child is contracted for, and under them
     the hours their classroom runs.

     The class time comes from the room's own schedule, which is where it is
     set and the one place it is set. The generated staff week is not consulted:
     that is shift patterns and handovers, and a class time read out of it moves
     every time the rota does. --}}
@php($room = $roomSchedules[$child->classroom] ?? null)

<div>
    <span class="whitespace-nowrap">{{ $child->scheduleLabel() ?? '—' }}</span>

    @if($room?->hoursLabel())
        <span class="mt-0.5 block whitespace-nowrap text-xs text-slate-400 dark:text-slate-500" title="{{ $child->classroom }} runs these hours">Class {{ $room->hoursLabel() }}</span>
    @endif
</div>
