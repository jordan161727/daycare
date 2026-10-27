{{-- The settings sections, down the side.

     Down rather than across because they are separate screens rather than
     steps: nobody works through them in order, and a tab bar across the top
     of a page whose content is a form reads as progress through it.

     Drawn as one brand-blue panel with the centre's name at the top and a
     white pill on the open section (Jordan's mock, 28 Oct 2026), in the
     app's own palette: indigo-700 is the sidebar's blue a shade deeper, so
     white text reads on it. --}}
@php
    // All five tabs live again at Jordan's request (22 Oct 2026). Company,
    // Administrators and Devices were parked 16–22 Oct; the pages answered
    // at their addresses throughout, they simply were not offered.
    // Company parked again (Jordan, 27 Oct 2026: "in settings remove company").
    // The page still answers at /settings/company; it is just not offered.
    $links = [
        // ['key' => 'company', 'label' => 'Company', 'route' => 'settings.company', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        ['key' => 'children', 'label' => 'Children', 'route' => 'settings.children', 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ['key' => 'rooms', 'label' => 'Rooms & hours', 'route' => 'settings.rooms', 'icon' => 'M4 5a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm0 7h16M12 4v16'],
        ['key' => 'administrators', 'label' => 'Administrators', 'route' => 'settings.administrators', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['key' => 'devices', 'label' => 'Devices', 'route' => 'settings.devices', 'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z'],
        ['key' => 'history', 'label' => 'Login history', 'route' => 'settings.history', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
@endphp

<nav class="shrink-0 rounded-2xl bg-indigo-700 p-3 text-white dark:bg-night-900 lg:w-52 lg:p-3" aria-label="Settings sections" data-settings-nav>
    <p class="hidden px-3 pb-3 text-sm font-bold tracking-tight lg:block">Little Angels</p>
    <div class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
        @foreach($links as $link)
            <a href="{{ route($link['route']) }}"
               @class([
                   'flex min-h-[40px] shrink-0 items-center gap-2.5 rounded-full px-4 py-2 text-sm font-semibold transition',
                   'bg-white text-indigo-900 shadow-sm dark:text-night-950' => $tab === $link['key'],
                   'text-white/85 hover:bg-white/15 hover:text-white' => $tab !== $link['key'],
               ])
               @if($tab === $link['key']) aria-current="page" @endif>
                <svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $link['icon'] }}"/></svg>
                {{ $link['label'] }}
            </a>
        @endforeach
    </div>
</nav>
