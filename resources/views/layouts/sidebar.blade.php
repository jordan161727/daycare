<div x-show="mobileOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/50 desktop:hidden" @click="mobileOpen = false"></div>

{{-- Narrower than it was: the labels are smaller now, so the old 17rem was
     paid for by the page beside it and bought nothing. --}}
<aside :class="[collapsed ? 'desktop:w-20' : 'desktop:w-60', mobileOpen ? 'translate-x-0' : '-translate-x-full desktop:translate-x-0']" class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col overflow-hidden bg-[#B6C3E6] text-slate-900 shadow-2xl dark:bg-night-900 dark:text-slate-100 transition-all duration-300 desktop:sticky desktop:top-0 desktop:h-screen">
    {{-- Deep enough for the logo to be read rather than merely acknowledged.
         It costs the nav sixteen pixels, which that list can spare — it already
         scrolls on its own.

         Collapsed, the logo goes and the toggle centres in the rail. There is
         no room for both: the rail is eighty pixels wide, and a mark beside the
         toggle overflowed it rather than sitting in it. --}}
    <div :class="collapsed ? 'desktop:justify-center' : ''" class="flex h-16 items-center justify-between px-3 lg:h-24">
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
        <a x-show="!collapsed" href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 justify-center" aria-label="Little Angels Day Care Center — dashboard">
            <img src="{{ asset('images/littleangels-logo.png') }}"
                 alt="Little Angels Day Care Center"
                 width="531" height="228"
                 class="h-12 w-auto shrink-0 drop-shadow-sm lg:h-16 dark:brightness-0 dark:invert">
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
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm10 8h8V3h-8v18zM3 21h8v-6H3v6z"/></svg><span x-show="!collapsed">Dashboard</span></a>
        <a href="{{ route('attendance.index') }}" class="nav-link {{ request()->routeIs('attendance.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Class Attendance</span><span x-show="!collapsed" class="ml-auto rounded-full bg-white/25 px-1.5 py-0.5 text-[10px] font-semibold">Today</span></a>
        <a href="{{ route('children.index') }}" class="nav-link {{ request()->routeIs('children.index') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zm-5-6a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Children</span></a>
        @if(auth()->user()->isAdmin() || auth()->user()->role === 'teacher')
            <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg><span x-show="!collapsed">Reports</span></a>
            <a href="{{ route('staff-schedule.index') }}" class="nav-link {{ request()->routeIs('staff-schedule.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><span x-show="!collapsed">Week Schedule</span></a>
            @if(! auth()->user()->isAdmin())
                {{-- Their own week, above the whole floor's, because it is the
                     one they open daily. A director has no shifts of their own,
                     so it would only ever be an empty page for them. --}}
                <a href="{{ route('staff-schedule.mine') }}" class="nav-link {{ request()->routeIs('staff-schedule.mine') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg><span x-show="!collapsed">My Schedule</span></a>
            @endif
            {{-- Your own balances and requests. Everybody has leave, including
                 the director — they simply cannot sign off their own. --}}
            <a href="{{ route('leave.index') }}" class="nav-link {{ request()->routeIs('leave.index') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7h6"/></svg><span x-show="!collapsed">My Leave</span></a>
            @if(config('daycare.timesheet.clock.enabled'))
                <a href="{{ route('clock.index') }}" class="nav-link {{ request()->routeIs('clock.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l2.5 2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Time Clock</span></a>
            @endif
        @endif
        @if(auth()->user()->isAdmin())
        <a href="{{ route('teachers.index') }}" class="nav-link {{ request()->routeIs('teachers.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m17-10a4 4 0 010 8m-3-12a4 4 0 010 8M9 11a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Teachers</span></a>
        <a href="{{ route('room-schedule.index') }}" class="nav-link {{ request()->routeIs('room-schedule.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/></svg><span x-show="!collapsed">Room Schedules</span></a>
        <a href="{{ route('holidays.index') }}" class="nav-link {{ request()->routeIs('holidays.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-6l2 2 4-4"/></svg><span x-show="!collapsed">Holidays</span></a>
        @php($waiting = rescue(fn () => \App\Models\LeaveRequest::where('status', 'pending')->count(), 0, false))
        <a href="{{ route('leave.requests') }}" class="nav-link {{ request()->routeIs('leave.requests') || request()->routeIs('leave.balances') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg><span x-show="!collapsed">Leave Requests</span>@if($waiting > 0)<span x-show="!collapsed" class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $waiting }}</span>@endif</a>
        <a href="{{ route('timesheets.index') }}" class="nav-link {{ request()->routeIs('timesheets.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Payroll Prep</span></a>
        <a href="{{ route('payroll.index') }}" class="nav-link {{ request()->routeIs('payroll.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg><span x-show="!collapsed">Payroll</span></a>
        <a href="{{ route('children.import.form') }}" class="nav-link {{ request()->routeIs('children.import.*') ? 'nav-link-active' : '' }}"><svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 3v12m0 0l-4-4m4 4l4-4"/></svg><span x-show="!collapsed">Import Children</span></a>
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
                    <span class="block text-[11px] leading-tight text-slate-600 dark:text-slate-400">{{ ucfirst(auth()->user()->role) }}</span>
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
