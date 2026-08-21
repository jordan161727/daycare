<header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200/70 lg:h-20 bg-slate-50/85 px-4 backdrop-blur-xl dark:border-white/10 dark:bg-slate-950/85 sm:px-6 lg:px-8">
    @php
    $hour = now()->hour;
    if ($hour < 12) {
        $greeting = 'Good morning';
    } elseif ($hour < 18) {
        $greeting = 'Good afternoon';
    } else {
        $greeting = 'Good evening';
    }
@endphp
    <div class="flex items-center gap-3"><button @click="mobileOpen = true" class="rounded-xl p-2 text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800 desktop:hidden" aria-label="Open menu"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button><div><p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $greeting }},</p><h1 class="font-bold sm:text-lg">{{ auth()->user()->name ?? 'Teacher' }} <span aria-hidden="true">👋</span></h1></div></div>
    <div class="flex items-center gap-2 sm:gap-4"><button @click="dark = !dark" class="rounded-xl p-2.5 text-slate-500 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="Toggle dark mode"><svg x-show="!dark" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36l-.7.7M6.34 17.66l-.7.7m12.72 0l-.7-.7M6.34 6.34l-.7-.7M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg><svg x-show="dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg></button><button class="relative rounded-xl p-2.5 text-slate-500 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m2 0v1a1 1 0 002 0v-1m-2 0h2"/></svg><span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-slate-50 dark:ring-slate-950"></span></button></div>
</header>
