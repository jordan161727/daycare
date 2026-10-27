<div x-show="mobileOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/50 desktop:hidden" @click="mobileOpen = false"></div>

{{-- Narrower than it was: the labels are smaller now, so the old 17rem was
     paid for by the page beside it and bought nothing. --}}
<aside :class="[collapsed ? 'desktop:w-16' : 'desktop:w-48', mobileOpen ? 'translate-x-0' : '-translate-x-full desktop:translate-x-0']" class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col overflow-hidden bg-[#B6C3E6] text-slate-900 shadow-2xl dark:bg-night-900 dark:text-slate-100 transition-all duration-300 desktop:sticky desktop:top-0 desktop:h-screen">
    {{-- Deep enough for the logo to be read rather than merely acknowledged.
         It costs the nav sixteen pixels, which that list can spare — it already
         scrolls on its own.

         Collapsed, the logo goes and the toggle centres in the rail. There is
         no room for both: the rail is eighty pixels wide, and a mark beside the
         toggle overflowed it rather than sitting in it. --}}
    <div :class="collapsed ? 'desktop:justify-center' : ''" class="flex h-20 items-center justify-between px-3 lg:h-28">
        {{-- flex-1 so the logo centres in the space the toggle leaves, rather
             than sitting hard against the left edge.

             The logo alone: it already says the centre's name, so the
             "Daycare / Management" wordmark that used to sit beside a house
             icon would only repeat it. No tile behind it — the PNG carries its
             own transparency — just a soft drop-shadow, which follows the
             artwork's alpha and lifts the pale angels off the sidebar without
             drawing a box around them.

             Dark mode inverts it to a white mark. The lettering is outlined in
             near-black, and on the deep purple "Day Care Center" all but
             disappears otherwise. --}}
        <a x-show="!collapsed" href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5" aria-label="{{ $companyName }} — dashboard">
            <img src="{{ asset('images/littleangels-logo.png') }}"
                 alt="{{ $companyName }}"
                 width="531" height="228"
                 class="h-12 w-auto shrink-0 drop-shadow-sm lg:h-16 dark:brightness-0 dark:invert">
            {{-- The app's name, under the centre's: the logo says whose it is,
                 this says what it is. --}}
            <span class="text-xs font-bold tracking-wide text-slate-700 dark:text-slate-200" data-wordmark>Angel Nest</span>
        </a>
        <button @click="collapsed = !collapsed" class="hidden rounded-lg p-2 text-slate-700 hover:bg-white/55 dark:text-slate-300 dark:hover:bg-white/10 desktop:block" aria-label="Collapse sidebar"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <button @click="mobileOpen = false" class="rounded-lg p-2 text-2xl leading-none text-slate-700 dark:text-slate-300 desktop:hidden" aria-label="Close menu">×</button>
    </div>

    {{-- Scrolls on its own, so however many links a role collects they never
         push the account block below off the screen — which is what used to
         happen, and is why the way out could not be reached at all. --}}
    {{-- min-h-0 is the whole of why this scrolls. A flex child will not shrink
         below the height of its own content unless it is told it may, so
         flex-1 and overflow-y-auto alone left the nav as tall as its links and
         went on pushing the account block below off the bottom of the screen —
         the scrollbar never appeared because there was nothing to overflow. --}}
    <nav class="sidebar-scroll min-h-0 flex-1 space-y-0.5 overflow-y-auto overscroll-contain px-3 py-2">
        {{-- The menu as Phase 1.1 of docs/work.md orders it (24 Oct 2026):
             the day's screens, then Staff, Families and Only you as sections,
             then Reports and Settings. Modules the mock-ups mark NEW wear the
             badge; Messages wears its unread count; DSS shows only to whoever
             may see it. Every link keeps the role check it always had. The
             office's other pages — payroll, leave, holidays, imports — sit
             under an Office section at the foot so nothing is lost. --}}
        @php($isAdmin = auth()->user()->isAdmin())
        {{-- Parked (Jordan, 26 Oct 2026): the NEW badges are off the menu. The
             span is kept here so a link can carry it again by swapping $new back. --}}
        {{-- @php($new = '<span x-show="!collapsed" class="ml-auto rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">New</span>') --}}
        @php($new = '')
        @php($section = fn (string $label) => '<p x-show="!collapsed" class="px-3 pb-1 pt-4 text-[10px] font-bold uppercase tracking-[0.14em] text-white/45">'.$label.'</p>')
        @php($icon = fn (string $d) => '<svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="'.$d.'"/></svg>')
        @php($link = fn (string $route, string $active, string $label, string $d, string $badge = '') => '<a href="'.route($route).'" class="nav-link '.(request()->routeIs(...explode('|', $active)) ? 'nav-link-active' : '').'">'.$icon($d).'<span x-show="!collapsed">'.$label.'</span>'.$badge.'</a>')

        {!! $link('dashboard', 'dashboard', 'Dashboard', 'M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z') !!}
        {!! $link('floor-plan.index', 'floor-plan.*', 'Floor plan', 'M4 5a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm0 7h16M12 4v16', $new) !!}
        {!! $link('attendance.index', 'attendance.*', 'Director Attendance', 'M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z') !!}
        {!! $link('check-in.index', 'check-in.*', 'Teacher Attendance', 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z') !!}
        {!! $link('children.index', 'children.index', 'Children', 'M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zm-5-6a4 4 0 100-8 4 4 0 000 8z') !!}

        {!! $section('Staff') !!}
        @if($isAdmin)
            {{-- The Staff page (Phase 5). The kiosks are not on the menu:
                 the clock and the door screen live in their own tab on the
                 wall, signed out, and a link here would only open a screen
                 the office never uses from a desk. --}}
            {!! $link('staff.index', 'staff.index|staff.show|teachers.*', 'Staff', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', $new) !!}
            {!! $link('staff.timesheets', 'staff.timesheets', 'Staff attendance', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', $new) !!}
        @endif
        {!! $link('staff-schedule.index', 'staff-schedule.index', 'Schedule', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', $new) !!}
        {{-- Parked (Jordan, 26 Oct 2026): the menu shows the mock-up's links
             only. The pages stay live at their addresses; flip the @if to
             bring a link back. --}}
        @if(false)
        @unless($isAdmin)
            {{-- Their own week, above the whole floor's, because it is the one
                 they open daily. A director has no shifts of their own. --}}
            {!! $link('staff-schedule.mine', 'staff-schedule.mine', 'My Schedule', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z') !!}
        @endunless
        {!! $link('leave.index', 'leave.index', 'My Leave', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z') !!}
        @if(config('daycare.timesheet.clock.enabled'))
            {!! $link('clock.index', 'clock.*', 'Time Clock', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z') !!}
        @endif
        @endif

        {!! $section('Families') !!}
        @php($unread = \App\Models\MessageThread::unreadFor(auth()->user()))
        {!! $link('messages.index', 'messages.*', 'Messages', 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z', $unread > 0 ? '<span x-show="!collapsed" class="ml-auto rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white" data-unread>'.$unread.'</span>' : '') !!}
        {{-- Parked (Jordan, 26 Oct 2026) — see above. --}}
        @if(false)
        {{-- The comments log: every note of a day across every child. --}}
        {!! $link('comments.index', 'comments.*', 'Comments', 'M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z') !!}
        @endif

        @can('see-dss')
            {!! $section('Only you') !!}
            {!! $link('dss.index', 'dss.*', 'DSS', 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', '<span x-show="!collapsed" class="ml-auto rounded-full bg-violet-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">Private</span>') !!}
        @endcan

        <div class="pt-3"></div>
        {!! $link('reports.index', 'reports.*', 'Reports', 'M4 19V9m5 10V5m5 14v-7m5 7V3', $new) !!}
        @if($isAdmin)
            {!! $link('settings.children', 'settings.*', 'Settings', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z') !!}

            {{-- Parked (Jordan, 26 Oct 2026) — see above. The office's
                 other pages, kept together at the foot. --}}
            @if(false)
            {!! $section('Office') !!}
            {!! $link('staff.reports', 'staff.reports*', 'Staff reports', 'M4 19V9m5 10V5m5 14v-7m5 7V3') !!}
            {!! $link('room-schedule.index', 'room-schedule.*', 'Room Schedules', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z') !!}
            {!! $link('holidays.index', 'holidays.*', 'Holidays', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z') !!}
            @php($waiting = rescue(fn () => \App\Models\LeaveRequest::where('status', 'pending')->count(), 0, false))
            {!! $link('leave.requests', 'leave.requests', 'Leave Requests', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', $waiting > 0 ? '<span x-show="!collapsed" class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">'.$waiting.'</span>' : '') !!}
            {!! $link('timesheets.index', 'timesheets.*', 'Payroll Prep', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2') !!}
            {!! $link('payroll.index', 'payroll.*', 'Payroll', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z') !!}
            {!! $link('children.import.form', 'children.import.*', 'Import Children', 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12') !!}
            @endif
        @endif
    </nav>

    {{-- Who you are and the way out, at the foot of the sidebar. Outside the
         scrolling nav above, so it is pinned there and stays on the screen
         however far the links run. --}}
    <div class="shrink-0 border-t border-white/20 px-3 py-3 dark:border-white/10">
        <div :class="collapsed ? 'flex-col gap-1.5' : 'gap-2'" class="flex items-center rounded-xl bg-white/40 p-2 dark:bg-white/5">
            <a href="{{ route('profile.edit') }}" class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg transition hover:opacity-80" title="My profile">
                <x-user-avatar :user="auth()->user()" size="h-8 w-8" text="text-xs" />
                <span x-show="!collapsed" class="min-w-0">
                    <span class="block truncate text-[13px] font-semibold leading-tight">{{ auth()->user()->name }}</span>
                    <span class="block text-[11px] leading-tight text-slate-600 dark:text-slate-400">{{ auth()->user()->isAdmin() ? 'Admin' : ucfirst(auth()->user()->role) }}</span>
                </span>
            </a>
            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button class="grid h-8 w-8 place-items-center rounded-lg text-slate-700 transition hover:bg-white/70 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" title="Log out" aria-label="Log out">
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m4 8H5a2 2 0 01-2-2V6a2 2 0 012-2h6"/></svg>
                </button>
            </form>
        </div>
    </div>
</aside>
