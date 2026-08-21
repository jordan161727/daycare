{{-- Standalone rather than inside the app layout: a forced change happens
     before the account can reach anything the sidebar links to, and offering
     that navigation would only produce a page full of dead ends. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Choose a password | Daycare Management</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-indigo-400 text-slate-800">
<main class="grid min-h-screen place-items-center p-5">
    <section class="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <p class="text-sm font-semibold uppercase tracking-widest text-indigo-700">Daycare Management</p>
        <h1 class="mt-2 text-3xl font-bold text-slate-900">Choose your password</h1>

        @if($forced)
            <p class="mt-2 text-slate-500">You are signed in with the temporary password from your welcome email. Pick your own to finish setting up the account.</p>
        @else
            <p class="mt-2 text-slate-500">Update the password you use to sign in.</p>
        @endif

        {{-- Carries the "clocked in at 7:02" from the login through, since a
             forced password change is what a first sign-in lands on. --}}
        @if(session('success'))
            <p class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">{{ session('success') }}</p>
        @endif

        @if(session('error'))
            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900">{{ session('error') }}</p>
        @endif

        <form method="POST" action="{{ route('password.change.update') }}" class="mt-8 space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="current_password" class="mb-2 block text-sm font-semibold">{{ $forced ? 'Temporary password' : 'Current password' }}</label>
                <input id="current_password" name="current_password" type="password" required autofocus autocomplete="current-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
                <x-input-error :messages="$errors->get('current_password')" />
            </div>

            <div>
                <label for="password" class="mb-2 block text-sm font-semibold">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
                <p class="mt-1 text-xs text-slate-500">At least 8 characters, with letters and numbers.</p>
                <x-input-error :messages="$errors->get('password')" />
            </div>

            <div>
                <label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-xl border-0 bg-slate-100 px-4 py-3 ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500">
            </div>

            <button class="w-full rounded-xl bg-indigo-600 px-4 py-3 font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Save password</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
            @csrf
            <button class="text-sm font-semibold text-slate-500 hover:text-slate-700">Sign out</button>
        </form>
    </section>
</main>
</body>
</html>
