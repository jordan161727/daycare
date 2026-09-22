{{-- The four jobs this page does, down the side.

     Down rather than across because they are four separate screens rather than
     four steps: nobody works through them in order, and a tab bar across the
     top of a page whose content is a form reads as progress through it. --}}
@php
    $links = [
        ['key' => 'company', 'label' => 'Company', 'route' => 'settings.company', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        ['key' => 'administrators', 'label' => 'Administrators', 'route' => 'settings.administrators', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['key' => 'devices', 'label' => 'Devices', 'route' => 'settings.devices', 'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z'],
        ['key' => 'history', 'label' => 'Login History', 'route' => 'settings.history', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
@endphp

<nav class="flex shrink-0 gap-1 overflow-x-auto pb-2 lg:w-52 lg:flex-col lg:overflow-visible lg:pb-0" aria-label="Settings sections">
    @foreach($links as $link)
        <a href="{{ route($link['route']) }}"
           @class([
               'flex shrink-0 items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
               'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $tab === $link['key'],
               'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' => $tab !== $link['key'],
           ])
           @if($tab === $link['key']) aria-current="page" @endif>
            <svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $link['icon'] }}"/></svg>
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
