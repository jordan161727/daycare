<div x-show="mobileOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/50 desktop:hidden" @click="mobileOpen = false"></div>

<aside :class="[collapsed ? 'desktop:w-22' : 'desktop:w-68', mobileOpen ? 'translate-x-0' : '-translate-x-full desktop:translate-x-0']" class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col overflow-hidden bg-[#B6C3E6] text-slate-900 shadow-2xl transition-all duration-300 desktop:sticky desktop:top-0 desktop:h-screen">
    <div class="flex h-20 items-center justify-between px-5">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/20">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9z"/></svg>
            </span>
            <span x-show="!collapsed" class="min-w-0"><span class="block text-lg font-bold">Daycare</span><span class="block text-xs text-slate-600">Management</span></span>
        </a>
        <button @click="collapsed = !collapsed" class="hidden rounded-lg p-2 text-slate-700 hover:bg-white/55 desktop:block" aria-label="Collapse sidebar"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <button @click="mobileOpen = false" class="rounded-lg p-2 text-2xl leading-none text-slate-700 desktop:hidden" aria-label="Close menu">×</button>
    </div>

    <nav class="flex-1 space-y-1 px-4 py-4">
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm10 8h8V3h-8v18zM3 21h8v-6H3v6z"/></svg><span x-show="!collapsed">Dashboard</span></a>
        <a href="{{ route('attendance.index') }}" class="nav-link {{ request()->routeIs('attendance.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Class Attendance</span><span x-show="!collapsed" class="ml-auto rounded-full bg-white/20 px-2 py-0.5 text-xs">Today</span></a>
        <a href="{{ route('children.index') }}" class="nav-link {{ request()->routeIs('children.index') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zm-5-6a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Children</span></a>
        @if(auth()->user()->isAdmin() || auth()->user()->role === 'teacher')
            <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg><span x-show="!collapsed">Reports</span></a>
            <a href="{{ route('staff-schedule.index') }}" class="nav-link {{ request()->routeIs('staff-schedule.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><span x-show="!collapsed">Week Schedule</span></a>
            @if(! auth()->user()->isAdmin())
                {{-- Their own week, above the whole floor's, because it is the
                     one they open daily. A director has no shifts of their own,
                     so it would only ever be an empty page for them. --}}
                <a href="{{ route('staff-schedule.mine') }}" class="nav-link {{ request()->routeIs('staff-schedule.mine') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg><span x-show="!collapsed">My Schedule</span></a>
            @endif
            {{-- Your own balances and requests. Everybody has leave, including
                 the director — they simply cannot sign off their own. --}}
            <a href="{{ route('leave.index') }}" class="nav-link {{ request()->routeIs('leave.index') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2zm4-7h6"/></svg><span x-show="!collapsed">My Leave</span></a>
            @if(config('daycare.timesheet.clock.enabled'))
                <a href="{{ route('clock.index') }}" class="nav-link {{ request()->routeIs('clock.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l2.5 2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Time Clock</span></a>
            @endif
        @endif
        @if(auth()->user()->isAdmin())
        <a href="{{ route('teachers.index') }}" class="nav-link {{ request()->routeIs('teachers.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m17-10a4 4 0 010 8m-3-12a4 4 0 010 8M9 11a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Teachers</span></a>
        @php($waiting = rescue(fn () => \App\Models\LeaveRequest::where('status', 'pending')->count(), 0, false))
        <a href="{{ route('leave.requests') }}" class="nav-link {{ request()->routeIs('leave.requests') || request()->routeIs('leave.balances') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg><span x-show="!collapsed">Leave Requests</span>@if($waiting > 0)<span x-show="!collapsed" class="ml-auto rounded-full bg-amber-500 px-2 py-0.5 text-xs text-white">{{ $waiting }}</span>@endif</a>
        <a href="{{ route('timesheets.index') }}" class="nav-link {{ request()->routeIs('timesheets.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Payroll Prep</span></a>
        <a href="{{ route('payroll.index') }}" class="nav-link {{ request()->routeIs('payroll.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg><span x-show="!collapsed">Payroll</span></a>
        <a href="{{ route('children.import.form') }}" class="nav-link {{ request()->routeIs('children.import.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 3v12m0 0l-4-4m4 4l4-4"/></svg><span x-show="!collapsed">Import Children</span></a>
        @endif
    </nav>
    <div class="border-t border-white/15 p-4">
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 rounded-xl p-2 transition hover:bg-white/55 {{ request()->routeIs('profile.*') ? 'bg-white/70' : '' }}" title="My profile"><x-user-avatar :user="auth()->user()" /><span x-show="!collapsed" class="min-w-0"><span class="block truncate text-sm font-semibold">{{ auth()->user()->name }}</span><span class="block text-xs text-slate-600">{{ ucfirst(auth()->user()->role) }}</span></span></a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link mt-2 w-full"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m4 8H5a2 2 0 01-2-2V6a2 2 0 012-2h6"/></svg><span x-show="!collapsed">Logout</span></button></form>
    </div>
</aside>
