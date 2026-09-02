<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in | Daycare Management</title>
    @include('layouts.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
    <body class="min-h-screen bg-indigo-400 text-slate-800">
    <main class="grid min-h-screen place-items-center p-5">
        <section class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
            <div class="mt-7">
                <img src="{{ asset('storage/images/littleangel.jpeg') }}" alt="Little Angel" class="mx-auto block h-30 w-auto object-contain">
                <p class="mt-4 text-sm font-semibold uppercase tracking-widest text-indigo-700">Daycare Management</p>
            </div>
            <h1 class="mt-2 text-3xl font-bold text-slate-900">Welcome back</h1>
            <p class="mt-2 text-slate-500">Sign in to access your workspace.</p>

            @if(session('error'))
                <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900">{{ session('error') }}</p>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
                @csrf
                <div><label for="email" class="mb-2 block text-sm font-semibold">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500"><x-input-error :messages="$errors->get('email')" /></div>
                <div><label for="password" class="mb-2 block text-sm font-semibold">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500"><x-input-error :messages="$errors->get('password')" /></div>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input name="remember" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"> Remember me</label>
                <button class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Sign in</button>
            </form>
        </section>
    </main>
</body>
</html>
