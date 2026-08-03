<div x-show="mobileOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="mobileOpen = false"></div>

<aside :class="[collapsed ? 'lg:w-22' : 'lg:w-68', mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0']" class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col overflow-hidden bg-[#B6C3E6] text-slate-900 shadow-2xl transition-all duration-300 lg:sticky lg:top-0 lg:h-screen">
    <div class="flex h-20 items-center justify-between px-5">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-white/15 ring-1 ring-white/20">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 11l9-8 9 8v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9z"/></svg>
            </span>
            <span x-show="!collapsed" class="min-w-0"><span class="block text-lg font-bold">Daycare</span><span class="block text-xs text-slate-600">Management</span></span>
        </a>
        <button @click="collapsed = !collapsed" class="hidden rounded-lg p-2 text-slate-700 hover:bg-white/55 lg:block" aria-label="Collapse sidebar"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <button @click="mobileOpen = false" class="rounded-lg p-2 lg:hidden" aria-label="Close menu">×</button>
    </div>

    <nav class="flex-1 space-y-1 px-4 py-4">
        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h8V3H3v10zm10 8h8V3h-8v18zM3 21h8v-6H3v6z"/></svg><span x-show="!collapsed">Dashboard</span></a>
        <a href="{{ route('attendance.index') }}" class="nav-link {{ request()->routeIs('attendance.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span x-show="!collapsed">Attendance</span><span x-show="!collapsed" class="ml-auto rounded-full bg-white/20 px-2 py-0.5 text-xs">Today</span></a>
        <a href="{{ route('children.index') }}" class="nav-link {{ request()->routeIs('children.index') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1m-4 6H2v-2a4 4 0 014-4h3a4 4 0 014 4v2zm-5-6a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Children</span></a>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('teachers.index') }}" class="nav-link {{ request()->routeIs('teachers.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2m17-10a4 4 0 010 8m-3-12a4 4 0 010 8M9 11a4 4 0 100-8 4 4 0 000 8z"/></svg><span x-show="!collapsed">Teachers</span></a>
        <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg><span x-show="!collapsed">Reports</span></a>
        <a href="{{ route('children.import.form') }}" class="nav-link {{ request()->routeIs('children.import.*') ? 'nav-link-active' : '' }}"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 3v12m0 0l-4-4m4 4l4-4"/></svg><span x-show="!collapsed">Import Children</span></a>
        @endif
    </nav>
    <div class="border-t border-white/15 p-4">
        <div class="flex items-center gap-3 rounded-xl p-2"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/55 font-semibold">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span x-show="!collapsed" class="min-w-0"><span class="block truncate text-sm font-semibold">{{ auth()->user()->name }}</span><span class="block text-xs text-slate-600">{{ ucfirst(auth()->user()->role) }}</span></span></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link mt-2 w-full"><svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m4 8H5a2 2 0 01-2-2V6a2 2 0 012-2h6"/></svg><span x-show="!collapsed">Logout</span></button></form>
    </div>
</aside>
