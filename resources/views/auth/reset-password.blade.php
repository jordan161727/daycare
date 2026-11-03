{{-- Where the Forgot password? email link lands. Standalone like
     change-password: nobody is signed in yet. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Choose a new password | Daycare Management</title>
    @include('layouts.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-indigo-400 text-slate-800">
<main class="grid min-h-screen place-items-center p-5">
    <section class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">Daycare Management</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">Choose a new password</h1>
        <p class="mt-2 text-slate-500">Your old password stops working once you save this one.</p>

        <form method="POST" action="{{ route('password.store') }}" class="mt-8 space-y-5" x-data="{ sending: false }" @submit="sending = true">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="mb-2 block text-sm font-semibold">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="email" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
                <x-input-error :messages="$errors->get('email')" />
                @if($errors->has('email'))
                    <a href="{{ route('password.request') }}" class="mt-1 inline-block text-sm font-semibold text-indigo-600 hover:underline">Send me a new link</a>
                @endif
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-semibold">New password</label>
                <input id="password" name="password" type="password" required autofocus autocomplete="new-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
                <p class="mt-1 text-xs text-slate-500">At least 8 characters, with letters and numbers.</p>
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
            </div>

            <button :disabled="sending" class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-80">
                <span x-text="sending ? 'Saving…' : 'Save password'">Save password</span>
            </button>
        </form>

        <p class="mt-6 text-center">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-700 hover:underline">Back to sign in</a>
        </p>
    </section>
</main>
</body>
</html>
