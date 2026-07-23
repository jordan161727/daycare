<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        @yield('title', 'Daycare Dashboard')
    </title>

    @vite(['resources/css/app.css','resources/js/app.js'])

</head>

<body class="bg-slate-50 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-100" x-data="appShell" :class="{ 'overflow-hidden': mobileOpen }">


<div class="min-h-screen lg:flex">

    {{-- Sidebar --}}
    @include('layouts.sidebar')


    <div class="min-w-0 flex-1">


        {{-- Navbar --}}
        @include('layouts.navbar')


        {{-- Content --}}
        <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">

            @yield('content')

        </main>


    </div>


</div>


</body>
</html>
