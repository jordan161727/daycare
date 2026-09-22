<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in | Daycare Attendance</title>
    @include('layouts.favicon')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="appShell" class="min-h-screen bg-[#f5f7fc] text-slate-800 dark:bg-night-950 dark:text-slate-100">
    {{-- Two columns on a laptop, one on anything smaller. The picture is the
         half that goes: on a phone the form fills the screen, and the brand row
         above it carries the mark the panel would have shown. --}}
    <div class="grid min-h-screen lg:grid-cols-[54fr_46fr]">
        <x-sky-panel class="hidden lg:block">
            {{-- No lockup here any more: the mark sits above the form instead,
                 on every screen size rather than only on a phone.

                 It was on a pane of light glass in this corner because the
                 logo is pale blue lettering outlined in near-black, drawn to
                 sit on white — the panel needed a white card behind it to keep
                 the outline from going muddy against the sky. Above the form
                 it is already on the page's own background and needs none of
                 that, and the picture is left to be a picture. --}}
            <div class="max-w-[640px] rounded-3xl bg-slate-800/30 p-8 ring-1 ring-white/20 backdrop-blur-md dark:bg-night-950/45 xl:p-10">
                @php
                    /*
                     * The line under the headline, in four tones.
                     *
                     * The same product reads differently depending on who is
                     * standing at the screen: a parent wants to know the child
                     * arrived, a teacher wants the sheet gone, a director wants
                     * something to hand licensing. Rather than a sentence that
                     * half-serves all three, the alternates are kept here and
                     * one is chosen — swapping tone is one word on the line
                     * below, and nothing is lost by trying another.
                     *
                     * The default is deliberately the shortest thing on the
                     * panel. It is the only line under the headline, and a
                     * panel that says one thing well is read; one that lists
                     * everything it can do is skimmed past on the way to the
                     * password field.
                     *
                     * Written in the view rather than in config/daycare.php on
                     * purpose: that file is licensing rules and operating
                     * hours, and copy filed among them is copy nobody finds.
                     */
                    $tone = 'default';

                    $subheads = [
                        'default' => 'Every hello, remembered.',
                        'parent' => 'Every check-in, check-out, and authorized pickup, recorded the moment it happens. You&rsquo;ll always know they made it.',
                        'staff' => 'One tap logs the arrival. No sign-in sheet, no end-of-day reconciliation, no wondering who&rsquo;s still in the building.',
                        'compliance' => 'Live headcounts, accurate ratios, and an attendance record that&rsquo;s ready whenever licensing asks.',
                        'short' => 'One tap in. One tap out. Everyone accounted for.',
                    ];
                @endphp

                <p class="text-[11px] font-bold uppercase tracking-[0.22em] text-white/80">Daycare Attendance</p>
                <h2 class="mt-4 text-[40px] font-extrabold leading-[1.08] text-white xl:text-[52px]">Little arrivals. Big peace of mind.</h2>
                {{-- Set larger than an ordinary paragraph: it is the only line under the
                     headline now, and at 17px a short one reads as a caption
                     that lost its picture rather than as a second thought. The
                     longer alternates still sit comfortably at this size. --}}
                <p class="mx-auto mt-5 max-w-[440px] text-[19px] leading-relaxed text-white/85 xl:text-[20px]">{!! $subheads[$tone] !!}</p>

            </div>

            {{-- Text rather than links on purpose: there is no privacy page, no
                 support desk and no status board to point at yet, and a footer
                 of three dead anchors is worse than a caption. Make them
                 anchors the day the pages exist. --}}
            {{-- The panel's second row, so it sits on the floor without an auto
                 margin — which would have eaten the space the words above are
                 centred in. --}}
            <div class="flex w-fit gap-6 rounded-2xl bg-slate-800/25 px-6 py-3 text-sm font-medium text-white/85 ring-1 ring-white/20 backdrop-blur-md dark:bg-night-950/45">
                <span>Privacy</span>
                <span>Support</span>
                <span>Status</span>
            </div>
        </x-sky-panel>

        <main class="flex items-center justify-center px-6 py-14 sm:px-10">
            {{-- The column arrives in four beats — mark, heading, form, the two
                 ways in — rather than all at once. The delays are written here
                 because they are composition, not style: they say the order the
                 eye is walked down the page. Somebody who has asked for less
                 motion gets none of it; see .auth-rise. --}}
            <div class="w-full max-w-[500px]">
                {{-- The mark, on every size now rather than only on a phone —
                     it used to be a stand-in for the panel's lockup and is the
                     only copy since that one came off.

                     Centred over the column, and no caption: the logo is a
                     wordmark that already reads "Little Angels / Day Care
                     Center", so a line repeating it would be the name twice. A
                     shade larger from lg up, where it is carrying the whole
                     brand on its own. --}}
                <img src="{{ asset('images/littleangels-logo.png') }}"
                     alt="Little Angels Day Care Center"
                     width="531" height="228" class="auth-rise mx-auto mb-8 h-12 w-auto lg:h-14">

                <div class="auth-rise flex items-center justify-between gap-4">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">Daycare Attendance</p>
                    <button type="button" @click="dark = !dark" class="rounded-xl p-2 text-slate-500 transition hover:bg-slate-200 hover:text-slate-700 dark:text-slate-300 dark:hover:bg-white/10 dark:hover:text-white" :aria-label="dark ? 'Use light mode' : 'Use dark mode'">
                        <svg x-show="!dark" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.36-6.36l-.7.7M6.34 17.66l-.7.7m12.72 0l-.7-.7M6.34 6.34l-.7-.7M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <svg x-show="dark" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.8A9 9 0 1111.2 3 7 7 0 0021 12.8z"/></svg>
                    </button>
                </div>
                <h1 class="auth-rise mt-3 text-[38px] font-extrabold leading-[1.05] tracking-tight text-slate-900 dark:text-white sm:text-[46px]" style="animation-delay:.06s">Welcome back</h1>
                <p class="auth-rise mt-3 text-[16px] text-slate-500 dark:text-slate-400" style="animation-delay:.06s">Sign in to open today&rsquo;s roster.</p>

                @if(session('error'))
                    <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('error') }}</p>
                @endif

                {{-- A sign-in is a round trip to the server, and on a centre's
                     broadband that is a second or two of nothing happening. The
                     button says it is working and stops taking clicks, which is
                     also what keeps a double-tap from posting twice. --}}
                <form method="POST" action="{{ route('login.store') }}" class="auth-rise mt-8" style="animation-delay:.12s"
                      x-data="{ sending: false }" @submit="sending = true">
                    @csrf

                    <label for="email" class="block text-[15px] font-semibold text-slate-700 dark:text-slate-200">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                           class="auth-field mt-2 w-full rounded-[14px] border border-slate-200 bg-white px-4 py-[19px] text-[15px] text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 dark:border-white/10 dark:bg-night-900 dark:text-white dark:placeholder:text-slate-500">
                    <x-input-error :messages="$errors->get('email')" />

                    {{-- Both the reveal and the reset hint hang off this one
                         Alpine scope. There is no reset route to link to — an
                         administrator issues a new temporary password — so the
                         mockup's link is a disclosure that says exactly that
                         rather than an anchor that 404s. --}}
                    <div x-data="{ show: false, hint: false }" class="mt-5">
                        <div class="flex items-baseline justify-between gap-4">
                            <label for="password" class="block text-[15px] font-semibold text-slate-700 dark:text-slate-200">Password</label>
                            <button type="button" @click="hint = !hint" :aria-expanded="hint ? 'true' : 'false'" aria-controls="password-hint"
                                    class="text-[15px] font-medium text-indigo-600 hover:text-indigo-700 hover:underline">Forgot password?</button>
                        </div>

                        <div class="relative mt-2">
                            <input id="password" name="password" type="password" :type="show ? 'text' : 'password'" required autocomplete="current-password"
                                   class="auth-field w-full rounded-[14px] border border-slate-200 bg-white py-[19px] pl-4 pr-20 text-[15px] text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 dark:border-white/10 dark:bg-night-900 dark:text-white dark:placeholder:text-slate-500">
                            <button type="button" @click="show = !show" :aria-pressed="show ? 'true' : 'false'"
                                    class="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-lg bg-slate-100 px-3 py-1.5 text-[13px] font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-white/10 dark:text-slate-200 dark:hover:bg-white/15"
                                    x-text="show ? 'Hide' : 'Show'">Show</button>
                        </div>

                        <p id="password-hint" x-show="hint" x-cloak x-transition.opacity class="mt-2 rounded-xl bg-slate-100 p-3 text-sm text-slate-600 dark:bg-white/10 dark:text-slate-300">
                            Passwords are reset by an administrator &mdash; ask them to issue you a new temporary one.
                        </p>

                        <x-input-error :messages="$errors->get('password')" />
                    </div>

                    <label class="mt-5 flex w-fit items-center gap-2.5 text-[15px] text-slate-600 dark:text-slate-300">
                        <input name="remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-indigo-600"> Keep me signed in on this device
                    </label>

                    <button :disabled="sending" class="mt-7 flex w-full items-center justify-center gap-2.5 rounded-[14px] bg-indigo-600 px-4 py-[20px] text-[16px] font-semibold text-white shadow-md shadow-indigo-600/25 transition hover:bg-indigo-700 active:scale-[.99] disabled:cursor-wait disabled:opacity-80 disabled:hover:bg-indigo-600">
                        <span class="auth-spinner" x-show="sending" x-cloak aria-hidden="true"></span>
                        <span x-text="sending ? 'Signing in…' : 'Sign in'">Sign in</span>
                    </button>
                </form>

                <div class="auth-rise my-6 flex items-center gap-4 text-sm text-slate-400" style="animation-delay:.18s">
                    <span class="h-px flex-1 bg-slate-200 dark:bg-white/10"></span>
                    or
                    <span class="h-px flex-1 bg-slate-200 dark:bg-white/10"></span>
                </div>

                {{-- The door tablet's own way in. It is outside the auth group —
                     nobody signs in at a door — so this is a plain link, and the
                     guardian's PIN is what authenticates on the other side. --}}
                <a href="{{ route('kiosk.index') }}"
                   class="auth-rise block w-full rounded-[14px] border border-slate-200 bg-white/70 px-4 py-[20px] text-center text-[16px] font-semibold text-slate-700 transition hover:-translate-y-0.5 hover:border-slate-300 hover:bg-white hover:shadow-md hover:shadow-slate-300/40 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10 dark:hover:shadow-none" style="animation-delay:.18s">Open check-in kiosk</a>

                <p class="auth-rise mt-8 text-center text-[15px] text-slate-500 dark:text-slate-400" style="animation-delay:.24s">Need access? <span class="font-semibold text-indigo-600 dark:text-indigo-300">Ask your administrator</span></p>
            </div>
        </main>
    </div>
</body>
</html>
