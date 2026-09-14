{{-- The Schedule cell: the child's own arrangement, and only theirs.

     The hours they are contracted for, and under them the days they come. Both
     are set on their record at registration.

     The room's own hours used to sit under these. They came off because down a
     column of sixty children they were the same line sixty times — every child
     in a room shares them, so the one thing the column could not tell you was
     which child was different. The room's hours are still set and read on the
     Room Schedules page, where a room is the subject rather than the backdrop. --}}
<div>
    <span class="whitespace-nowrap">{{ $child->scheduleLabel() ?? '—' }}</span>

    {{-- Which days, under the hours of the day. The two together are the whole
         arrangement, and the roster is where it is compared child to child. --}}
    @if($child->scheduleDaysLabel())
        <span class="mt-0.5 block whitespace-nowrap text-xs font-medium text-slate-600 dark:text-slate-300">{{ $child->scheduleDaysLabel() }}</span>
    @endif
</div>
