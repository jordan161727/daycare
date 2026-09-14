<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', 'Daycare Dashboard')
    </title>

    @include('layouts.favicon')


    @vite(['resources/css/app.css','resources/js/app.js'])

</head>

<body class="bg-slate-50 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100" x-data="appShell" :class="{ 'overflow-hidden': mobileOpen }">


<div class="min-h-screen desktop:flex">

    {{-- Sidebar --}}
    @include('layouts.sidebar')


    <div class="min-w-0 flex-1">


        {{-- Navbar --}}
        @include('layouts.navbar')


        {{-- Content --}}
        {{-- Flush to the bar above it. Every page opens with a card that
             carries its own padding, so any gap here was space on top of
             space — and the bar is blurred rather than filled, so content
             sliding under it on scroll needs no runway to do it in. --}}
        <main class="px-4 pb-5 pt-0 sm:px-6 lg:px-8 lg:pb-6">

            @if(session('error'))
                <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                    <span class="text-lg" aria-hidden="true">!</span>
                    <p class="font-medium">{{ session('error') }}</p>
                </div>
            @endif

            @yield('content')

        </main>


    </div>


</div>


</body>
</html>
