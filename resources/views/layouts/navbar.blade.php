{{-- No fill at all, so the page's background runs straight up through the bar:
     on the attendance sheet and the roster the drifting colour carries across
     the top of the screen instead of stopping at a strip.

     The blur is what makes that safe, and it is doing the opposite of what it
     looks like. Blur wrecks text — a table scrolling underneath smears into
     unreadable mush — but a blob is already a soft gradient, so blurring it
     again barely changes it. Heavy blur with no tint therefore hides exactly
     what needs hiding and keeps exactly what we want to see.

     No bottom border either: a hard rule drawn across the top of the animation
     is the seam this was meant to remove. --}}
{{-- One line, and only as tall as that line needs.

     It used to greet you by the hour and stack your name under that, which cost
     two lines and eighty pixels at the top of every page — on the attendance
     sheet, eighty pixels is two children you cannot see. The greeting told you
     nothing you did not already know from a window and a clock. --}}
<header class="sticky top-0 z-30 flex h-10 items-center justify-between px-4 backdrop-blur-xl sm:px-6 lg:h-12 lg:px-8">

    <div class="flex items-center gap-3"><button @click="mobileOpen = true" class="rounded-xl p-2 text-slate-600 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800 desktop:hidden" aria-label="Open menu"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button><h1 class="text-sm font-bold sm:text-base">{{ auth()->user()->name ?? 'Teacher' }} <span aria-hidden="true">👋</span></h1></div>
    <div class="flex items-center gap-2 sm:gap-4"><button @click="dark = !dark" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="Toggle dark mode"><svg x-show="!dark" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36l-.7.7M6.34 17.66l-.7.7m12.72 0l-.7-.7M6.34 6.34l-.7-.7M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg><svg x-show="dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg></button>@if(request()->routeIs('attendance.index'))<button type="button" @click="$dispatch('attendance-key')" class="rounded-xl p-2 text-slate-500 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="Show or hide the key" title="What the marks on the sheet mean"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg></button>@endif<button class="relative rounded-xl p-2 text-slate-500 hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-slate-800"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m2 0v1a1 1 0 002 0v-1m-2 0h2"/></svg><span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-slate-50 dark:ring-slate-950"></span></button></div>
</header>
