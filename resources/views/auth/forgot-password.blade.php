{{-- Forgot password? from the sign-in page. Standalone like change-password:
     nobody is signed in, so there is no sidebar to offer. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forgot password | Daycare Management</title>
    @include('layouts.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-indigo-400 text-slate-800">
<main class="grid min-h-screen place-items-center p-5">
    <section class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">Daycare Management</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">Forgot your password?</h1>
        <p class="mt-2 text-slate-500">Type the email you sign in with. We’ll send you a link to choose a new password.</p>

        @if(session('status'))
            <p class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800" data-reset-sent>{{ session('status') }}</p>
        @endif

        @if(session('error'))
            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900">{{ session('error') }}</p>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5" x-data="{ sending: false }" @submit="sending = true">
            @csrf

            <div>
                <label for="email" class="mb-2 block text-sm font-semibold">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
                <x-input-error :messages="$errors->get('email')" />
            </div>

            <button :disabled="sending" class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-80">
                <span x-text="sending ? 'Sending…' : 'Email me a link'">Email me a link</span>
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-slate-500">
            No email arriving? Ask the director to send you a temporary password.
        </p>
        <p class="mt-3 text-center">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700 hover:underline">Back to sign in</a>
        </p>
    </section>
</main>
</body>
</html>
