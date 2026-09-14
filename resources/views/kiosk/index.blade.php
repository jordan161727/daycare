<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Check in — Little Angels Day Care Center</title>
    @include('layouts.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- Standalone, not inside layouts.app: there is no sidebar to offer somebody
     standing at a door, and every link on it goes somewhere they may not be. --}}
<body class="kiosk-touch min-h-dvh bg-slate-50 text-slate-900 antialiased">

<a href="#pad" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-slate-900 focus:px-5 focus:py-3 focus:text-white">Skip to the keypad</a>

<x-kids-background strong />

<div x-data="kiosk(@js($sessionSeconds))" x-cloak class="flex min-h-dvh flex-col">
    <main id="pad" class="mx-auto w-full max-w-3xl flex-1 px-[max(1rem,env(safe-area-inset-left))] py-8">

        {{-- ---------------- the keypad ---------------- --}}
        <template x-if="screen === 'pin' || screen === 'phone'">
            <div>
                <h1 class="text-center text-3xl font-semibold tracking-tight sm:text-4xl"
                    x-text="screen === 'phone' ? 'Last 4 digits of your phone number' : 'Enter your PIN'"></h1>
                <p class="mt-3 text-center text-lg text-slate-600"
                   x-text="screen === 'phone' ? 'More than one PIN matched. This confirms which is yours.' : 'Sign in or sign out'"></p>

                {{-- Reserved height, so the pad does not jump down the screen the
                     first time something goes wrong. --}}
                <div class="my-5 flex min-h-16 items-center justify-center text-center" role="alert" aria-live="assertive">
                    <template x-if="message">
                        <p class="inline-flex items-center gap-2 rounded-xl px-4 py-3 text-base font-medium"
                           :class="message.kind === 'bad' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900'"
                           x-text="message.text"></p>
                    </template>
                </div>

                <div class="flex justify-center gap-3" aria-hidden="true">
                    <template x-for="i in target" :key="i">
                        <span class="h-5 w-5 rounded-full border-2 transition"
                              :class="i <= entry.length ? 'border-slate-900 bg-slate-900' : 'border-slate-300'"></span>
                    </template>
                </div>
                <p class="mt-2 text-center text-slate-500" aria-live="polite"
                   x-text="entry.length + ' of ' + target + ' digits entered'"></p>

                <div class="mx-auto mt-6 grid w-full max-w-sm grid-cols-3 gap-3">
                    <template x-for="n in [1,2,3,4,5,6,7,8,9]" :key="n">
                        <button type="button" @click="press(String(n))"
                                class="min-h-20 rounded-2xl border-2 border-slate-300 bg-white text-3xl font-medium transition active:scale-95"
                                x-text="n"></button>
                    </template>
                    <button type="button" @click="entry = ''"
                            class="min-h-20 rounded-2xl border-2 border-slate-200 bg-slate-100 text-base font-medium text-slate-600 transition active:scale-95">Clear</button>
                    <button type="button" @click="press('0')"
                            class="min-h-20 rounded-2xl border-2 border-slate-300 bg-white text-3xl font-medium transition active:scale-95">0</button>
                    <button type="button" @click="entry = entry.slice(0, -1)" aria-label="Delete last digit"
                            class="min-h-20 rounded-2xl border-2 border-slate-200 bg-slate-100 text-2xl font-medium text-slate-600 transition active:scale-95">⌫</button>
                </div>

                <p class="mt-5 text-center text-slate-500">Forgotten your PIN? Please see a staff member.</p>
            </div>
        </template>

        {{-- ---------------- the family ---------------- --}}
        <template x-if="screen === 'family'">
            <div>
                <h1 class="text-center text-3xl font-semibold tracking-tight sm:text-4xl" x-text="'Hello, ' + guardian"></h1>
                <p class="mt-3 text-center text-lg text-slate-600">Tap a child to sign in or out.</p>

                <div class="my-5 flex min-h-16 items-center justify-center text-center" role="alert" aria-live="assertive">
                    <template x-if="message">
                        <p class="inline-flex items-center gap-2 rounded-xl px-4 py-3 text-base font-medium"
                           :class="message.kind === 'bad' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900'"
                           x-text="message.text"></p>
                    </template>
                </div>

                <ul class="grid gap-4 sm:grid-cols-2">
                    <template x-for="child in children" :key="child.id">
                        <li class="rounded-2xl border-2 border-slate-200 bg-white p-4">
                            <div class="flex items-center gap-4">
                                <span class="grid h-20 w-20 shrink-0 place-items-center rounded-full bg-slate-100 text-4xl"
                                      aria-hidden="true" x-text="child.animal"></span>
                                <div class="min-w-0">
                                    <p class="text-2xl font-semibold" x-text="child.name"></p>
                                    <p class="text-slate-500" x-text="child.room"></p>
                                    <p class="mt-1 font-medium"
                                       :class="child.present ? 'text-emerald-700' : 'text-slate-600'"
                                       x-text="child.present ? 'Signed in at ' + child.since : 'Not signed in'"></p>
                                </div>
                            </div>

                            {{-- Three different reasons a tile offers no button, and
                                 each says which: not on the roster today, here but
                                 not yours to collect, or simply the other direction. --}}
                            <template x-if="! child.enrolled">
                                <p class="mt-4 rounded-xl bg-slate-100 p-4 text-center text-slate-600">Not booked in today. Please see a staff member.</p>
                            </template>

                            <template x-if="child.enrolled && child.present && ! child.can_collect">
                                <p class="mt-4 rounded-xl bg-amber-100 p-4 text-center text-amber-900">Please see a staff member to sign out.</p>
                            </template>

                            <template x-if="child.enrolled && (! child.present || child.can_collect)">
                                <button type="button" @click="punch(child)" :disabled="busy === child.id"
                                        class="mt-4 min-h-16 w-full rounded-xl text-xl font-semibold text-white transition disabled:opacity-60"
                                        :class="child.present ? 'bg-slate-800' : 'bg-emerald-700'"
                                        x-text="busy === child.id ? 'Saving…' : (child.present ? 'Sign ' + child.name + ' out' : 'Sign ' + child.name + ' in')"></button>
                            </template>
                        </li>
                    </template>
                </ul>

                <p class="mt-6 text-center text-slate-500" aria-live="polite" x-text="'This screen closes in ' + countdown + ' seconds.'"></p>
            </div>
        </template>

        {{-- ---------------- the receipt ---------------- --}}
        <template x-if="screen === 'done'">
            <div class="py-12 text-center">
                <p class="text-7xl" aria-hidden="true" x-text="result.direction === 'in' ? '☀️' : '👋'"></p>
                <h1 class="mt-6 text-3xl font-semibold tracking-tight sm:text-4xl"
                    x-text="result.name + (result.direction === 'in' ? ' is signed in' : ' is signed out')"></h1>
                <p class="mt-4 text-2xl text-slate-600" x-text="result.time"></p>
                <button type="button" @click="reset()"
                        class="mt-10 min-h-14 rounded-xl bg-slate-800 px-10 text-xl font-medium text-white">Done</button>
                <p class="mt-4 text-slate-500" aria-live="polite" x-text="'Returning in ' + countdown + ' seconds.'"></p>
            </div>
        </template>
    </main>

    <template x-if="screen === 'family'">
        <div class="sticky bottom-0 border-t border-slate-200 bg-white/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <button type="button" @click="reset()"
                    class="mx-auto block min-h-14 w-full max-w-sm rounded-xl border-2 border-slate-300 bg-white text-xl font-medium">Cancel and start over</button>
        </div>
    </template>

    <p class="pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-sm text-slate-400">{{ $device }}</p>
</div>

<script>
    function kiosk(sessionSeconds) {
        return {
            screen: 'pin',
            entry: '',
            pendingPin: null,
            guardian: '',
            children: [],
            message: null,
            busy: null,
            result: null,
            countdown: 0,
            timer: null,

            get target() { return this.screen === 'phone' ? 4 : 6; },

            init() {
                // A physical numpad is the commonest kiosk keyboard, and it is
                // also how this gets tested without a touchscreen.
                window.addEventListener('keydown', event => {
                    if (this.screen !== 'pin' && this.screen !== 'phone') return;
                    if (/^\d$/.test(event.key)) this.press(event.key);
                    if (event.key === 'Backspace') this.entry = this.entry.slice(0, -1);
                    if (event.key === 'Escape') this.entry = '';
                });
            },

            press(digit) {
                if (this.entry.length >= this.target) return;
                this.message = null;
                this.entry += digit;
                if (this.entry.length === this.target) setTimeout(() => this.unlock(), 120);
            },

            async unlock() {
                const body = this.screen === 'phone'
                    ? { pin: this.pendingPin, last4: this.entry }
                    : { pin: this.entry };

                const data = await this.post("{{ route('kiosk.unlock') }}", body);

                if (data.status === 'ok') {
                    this.guardian = data.guardian.name;
                    this.children = data.children;
                    this.go('family');

                    return;
                }

                if (data.status === 'ambiguous') {
                    this.pendingPin = this.entry;
                    this.entry = '';
                    this.screen = 'phone';

                    return;
                }

                // Locked and not-found read almost the same on purpose: somebody
                // at the door should not learn from the wording whether a PIN
                // exists. The difference is only that one says to wait.
                this.message = {
                    kind: 'bad',
                    text: data.status === 'locked'
                        ? 'This PIN is locked for a few minutes. Please see a staff member.'
                        : 'We did not recognise that PIN. Please try again or see a staff member.',
                };
                this.entry = '';
                this.pendingPin = null;
                this.screen = 'pin';
            },

            async punch(child) {
                this.busy = child.id;
                this.message = null;

                const data = await this.post("{{ route('kiosk.punch') }}", {
                    child_id: child.id,
                    direction: child.present ? 'out' : 'in',
                });

                this.busy = null;

                if (data.status === 'ok') {
                    this.children = data.children;
                    this.result = { name: data.child.name, direction: child.present ? 'out' : 'in', time: data.time };
                    this.go('done');

                    return;
                }

                if (data.status === 'expired') { this.reset(); return; }

                this.children = data.children ?? this.children;
                this.message = { kind: 'warn', text: {
                    not_authorised: 'Please see a staff member to sign out.',
                    not_enrolled: 'Not booked in today. Please see a staff member.',
                    already_in: 'Already signed in.',
                    not_in: 'Not signed in yet.',
                }[data.status] ?? 'Please see a staff member.' };
            },

            go(next) {
                clearInterval(this.timer);
                this.screen = next;
                this.entry = '';
                this.countdown = next === 'done' ? 8 : sessionSeconds;

                this.timer = setInterval(() => {
                    this.countdown--;
                    // Time out to the keypad rather than to the family screen:
                    // the next person at the door must never find somebody
                    // else's children already on it.
                    if (this.countdown <= 0) this.reset();
                }, 1000);
            },

            reset() {
                clearInterval(this.timer);
                this.post("{{ route('kiosk.lock') }}", {});
                Object.assign(this, {
                    screen: 'pin', entry: '', pendingPin: null, guardian: '',
                    children: [], message: null, busy: null, result: null, countdown: 0,
                });
            },

            async post(url, body) {
                try {
                    const response = await window.postJson(url, body);

                    return await response.json();
                } catch (error) {
                    return { status: 'error' };
                }
            },
        };
    }
</script>
</body>
</html>
