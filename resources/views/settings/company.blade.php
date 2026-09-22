@extends('layouts.app')
@section('title', 'Company settings')
@section('content')
{{-- The settings a director may change without a deploy.

     Each one changes something everybody sees, so each says what it does in a
     line under it rather than in a tooltip: a toggle whose effect you have to
     hover to learn is one people flip to find out. --}}

<div class="mx-auto max-w-5xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-bold tracking-tight">Company Settings</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Manage your company settings and preferences.</p>

        @if(session('status'))
            <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('settings.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')

            <section class="glass-card rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Company Name</h2>
                <label class="mt-4 block">
                    <span class="sr-only">Company name</span>
                    <input name="company_name" maxlength="120" value="{{ old('company_name', $companyName) }}"
                           placeholder="{{ config('daycare.company.name') }}"
                           class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <x-input-error :messages="$errors->get('company_name')" />
                </label>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Shown on the sign-in page, the kiosk and every printed sheet. Left empty, it falls back to the default.</p>
            </section>

            <section class="glass-card rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Attendance Mode</h2>

                {{-- Not two versions of one thing: a centre that only takes an
                     arrival has no honest way to produce hours, so the second
                     mode stops the screens asking for a clock-out rather than
                     flagging its absence as a fault. --}}
                <div class="mt-4 space-y-3">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" name="attendance_mode" value="{{ \App\Http\Controllers\SettingController::TIME_TRACKING }}"
                               @checked($mode === \App\Http\Controllers\SettingController::TIME_TRACKING)
                               class="mt-0.5 border-slate-300 dark:border-white/20">
                        <span>
                            <span class="block text-sm font-semibold">Attendance and Time Tracking</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Staff clock in when they arrive and clock out when they leave. Hours come from the difference.</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="radio" name="attendance_mode" value="{{ \App\Http\Controllers\SettingController::ATTENDANCE_ONLY }}"
                               @checked($mode === \App\Http\Controllers\SettingController::ATTENDANCE_ONLY)
                               class="mt-0.5 border-slate-300 dark:border-white/20">
                        <span>
                            <span class="block text-sm font-semibold">Attendance Only</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Staff only clock in when they arrive. The kiosk stops offering breaks and clock-out, and a day with no clock-out is no longer flagged.</span>
                        </span>
                    </label>
                </div>

                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    Hours already recorded are kept either way. Attendance Only changes what is asked for from today, not what a past fortnight says.
                </p>
            </section>

            <section class="glass-card rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Attendance Kiosk</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The tablet staff punch at. These apply to every paired device.</p>

                <div class="mt-4 space-y-3">
                    <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-slate-50 px-4 py-3 dark:bg-white/5">
                        <span>
                            <span class="block text-sm font-semibold">Enable Scanner</span>
                            {{-- A card reader is a keyboard that types fast and
                                 presses Enter, which is why turning it off is a
                                 matter of what the kiosk expects rather than of
                                 hardware. --}}
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Accept a card from a barcode or QR reader. Off, the kiosk takes PINs only.</span>
                        </span>
                        <input type="checkbox" name="scanner" value="1" @checked($scanner) class="h-5 w-9 shrink-0 cursor-pointer rounded-full border-slate-300 dark:border-white/20">
                    </label>

                    <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-slate-50 px-4 py-3 dark:bg-white/5">
                        <span>
                            <span class="block text-sm font-semibold">Allow Device Sleep</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Let the tablet screen turn off when nobody is using it. Off, the kiosk holds the screen awake so it is ready at shift change.</span>
                        </span>
                        <input type="checkbox" name="allow_sleep" value="1" @checked($sleep) class="h-5 w-9 shrink-0 cursor-pointer rounded-full border-slate-300 dark:border-white/20">
                    </label>
                </div>
            </section>

            <div class="flex justify-end">
                <button class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Save Settings</button>
            </div>
        </form>
    </div>
</div>
@endsection
