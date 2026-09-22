<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Staff time clock &middot; {{ config('app.name') }}</title>
    @include('layouts.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    The staff time clock on the wall.

    Formal, because it is an employment record being made. The door kiosk a few
    feet away has a drawn sky and a rabbit on it, and rightly — a four-year-old
    stands in front of that one. Balloons behind somebody's clock-out at the end
    of a ten-hour shift were the wrong note.

    The design is three things:

    **A masthead that never moves.** The centre, the terminal, the date and a
    running clock, on a dark band across the top. It is the only part of the
    screen that is the same in every state, so the eye has somewhere to come
    back to and the room has a clock on the wall whatever the screen is doing.

    **One card, centred, holding whatever the state is.** Always the same width
    and the same seat on the page, so moving between states is the contents
    changing rather than the furniture.

    **An accent rule on the card that carries the state's colour.** The status
    is said three times over — a coloured rule, a worded chip, and the buttons
    themselves — because this is read from six feet away by somebody who is
    holding a coat.

    Three states, one action set each. A tile that is impossible is absent, not
    greyed out: somebody at half past seven reads what is there and taps it, and
    a disabled button they have to work out the reason for gets tapped anyway.
    There is no path from a break straight to clocked out — a break ends back on
    the clock, so leaving is End Break then Clock Out, two presses that are two
    true facts.

    Everything returns to the welcome screen on its own. Nobody should have to
    dismiss a screen for the person behind them, and nobody should read the
    previous person's hours.
--}}
<body class="kiosk-touch min-h-dvh bg-slate-100 text-slate-900 antialiased">

<div x-data="staffClock()" x-init="focusEntry()" class="flex min-h-dvh flex-col">

    {{-- ---- the masthead ---- --}}
    <header class="bg-slate-900 px-5 py-3 text-white">
        <div class="mx-auto flex max-w-xl items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/littleangels-logo.png') }}" alt="" width="531" height="228"
                     class="h-7 w-auto shrink-0 brightness-0 invert">
                <span class="min-w-0 border-l border-white/20 pl-3">
                    <span class="block truncate text-[12px] font-semibold leading-tight">Staff time clock</span>
                    <span class="block truncate text-[11px] leading-tight text-slate-400">{{ $device?->name ?? 'Not paired' }}</span>
                </span>
            </div>

            <div class="shrink-0 text-right">
                {{-- The wall clock. Tabular so the minute changing does not
                     shuffle the digits beside it. --}}
                <span class="block text-[22px] font-bold leading-none tabular-nums" x-text="now"></span>
                <span class="block text-[11px] leading-tight text-slate-400" x-text="today"></span>
            </div>
        </div>
    </header>

    @if(! $device)
        <main class="flex flex-1 items-center justify-center p-5">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                <h1 class="text-xl font-bold">This screen is not set up yet</h1>
                <p class="mt-2 text-slate-600">An administrator needs to pair it from Devices before it can take punches.</p>
            </div>
        </main>
    @else
        <main class="flex flex-1 items-center justify-center p-5" @click="focusEntry()">
            <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

                {{-- The accent rule. Indigo at rest, and the state's own colour
                     once somebody is on screen — the first thing seen from
                     across the room, before any word is read. --}}
                <div class="h-1.5"
                     :class="staff
                        ? { working: 'bg-emerald-600', break: 'bg-amber-500', lunch: 'bg-amber-500', off: 'bg-slate-300' }[staff.state]
                        : (done ? 'bg-emerald-600' : 'bg-indigo-600')"></div>

                {{-- ---- the welcome screen ---- --}}
                <template x-if="! staff && ! done">
                    <div class="p-7">
                        <div class="text-center">
                            <h1 class="text-xl font-bold">Scan your card</h1>
                            <p class="mt-1 text-[13px] text-slate-500">or key your 4-digit PIN below</p>
                        </div>

                        <form @submit.prevent="identify()" autocomplete="off" class="mt-6">
                            {{-- One field for both: a scanner is a keyboard
                                 that types fast and presses Enter, so the same
                                 box takes the card and the PIN and tells them
                                 apart by what arrives. --}}
                            {{-- Masked by CSS rather than type="password": a real password
                                 field makes the browser read the kiosk as a login form and
                                 offer to save the PIN. --}}
                            <input x-ref="entry" x-model="entry" type="text" inputmode="numeric"
                                   name="kiosk-entry" autocomplete="off" autocorrect="off" spellcheck="false"
                                   data-1p-ignore data-lpignore="true" data-form-type="other"
                                   style="-webkit-text-security: disc; text-security: disc;"
                                   aria-label="Scan your card or key your PIN"
                                   class="w-full rounded-xl border border-slate-300 bg-slate-50 px-6 py-4 text-center text-3xl tracking-[0.5em] text-slate-900 placeholder:tracking-[0.3em] placeholder:text-slate-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-100"
                                   placeholder="••••">

                            <div class="mt-4 grid grid-cols-3 gap-2">
                                <template x-for="key in ['1','2','3','4','5','6','7','8','9']" :key="key">
                                    <button type="button" @click="press(key)"
                                            class="min-h-16 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:border-indigo-300 active:bg-indigo-50"
                                            x-text="key"></button>
                                </template>
                                <button type="button" @click="entry = ''"
                                        class="min-h-16 rounded-xl border border-slate-200 bg-white text-sm font-semibold uppercase tracking-wide text-slate-400 transition active:scale-95 active:bg-slate-50">Clear</button>
                                <button type="button" @click="press('0')"
                                        class="min-h-16 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:border-indigo-300 active:bg-indigo-50">0</button>
                                <button type="submit" :disabled="entry.length < 4 || sending"
                                        class="min-h-16 rounded-xl bg-indigo-600 text-sm font-bold uppercase tracking-wide text-white transition active:scale-95 active:bg-indigo-700 disabled:bg-slate-200 disabled:text-slate-400">Enter</button>
                            </div>
                        </form>

                        {{-- Amber to try again, rose to go and ask somebody —
                             the two tones the door kiosk uses for the same two
                             jobs. --}}
                        <p x-show="problem" x-cloak class="mt-4 rounded-xl px-4 py-3 text-center"
                           :class="problem.kind === 'bad' ? 'bg-rose-50 text-rose-900 ring-1 ring-rose-200' : 'bg-amber-50 text-amber-900 ring-1 ring-amber-200'">
                            <span class="block font-bold" x-text="problem.title"></span>
                            <span class="text-[13px]" x-text="problem.detail"></span>
                        </p>
                    </div>
                </template>

                {{-- ---- one person, and only what they can do ---- --}}
                <template x-if="staff && ! done">
                    <div>
                        {{-- Who, on a tinted shoulder so the name and the state
                             read as one block rather than as two lines. --}}
                        <div class="flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-6 py-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-white text-[15px] font-bold text-slate-700 ring-1 ring-slate-200" x-text="staff.initials"></span>
                            <div class="min-w-0 flex-1 text-left">
                                <p class="truncate text-[17px] font-bold leading-tight" x-text="staff.name"></p>
                                <p class="text-[12px] leading-tight text-slate-500" x-text="staff.staff_id"></p>
                            </div>
                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-bold"
                                  :class="{
                                      working: 'bg-emerald-100 text-emerald-800',
                                      break: 'bg-amber-100 text-amber-900',
                                      lunch: 'bg-amber-100 text-amber-900',
                                      off: 'bg-slate-200 text-slate-600',
                                  }[staff.state]">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                <span x-text="staff.state_label"></span>
                            </span>
                        </div>

                        <div class="p-6">
                            {{-- The time that is about to be recorded, said
                                 large, because it is the fact being made. --}}
                            <p class="text-center text-[13px] uppercase tracking-[0.15em] text-slate-400" x-text="staff.since ?? 'Not started today'"></p>

                            <div class="mt-5 space-y-2.5">
                                <template x-for="action in staff.actions" :key="action.action">
                                    <button type="button" @click="punch(action.action)" :disabled="sending"
                                            class="min-h-[4.5rem] w-full rounded-xl text-xl font-bold text-white transition active:scale-[0.98] disabled:opacity-40"
                                            :class="{
                                                go: 'bg-emerald-700 active:bg-emerald-800',
                                                hold: 'bg-amber-600 active:bg-amber-700',
                                                stop: 'bg-rose-700 active:bg-rose-800',
                                            }[action.tone]"
                                            x-text="action.label"></button>
                                </template>

                                {{-- A state with nothing to press is one the
                                     office has to sort out. Said plainly rather
                                     than left as a screen with no way on. --}}
                                <p x-show="staff.actions.length === 0" x-cloak class="rounded-xl bg-amber-50 p-4 text-center text-amber-900 ring-1 ring-amber-200">
                                    There is nothing to press from here. Please see the office to put your day right.
                                </p>
                            </div>
                        </div>

                        {{-- The two facts underneath, on a ruled foot: what they
                             last did, and what the day has come to. --}}
                        <dl class="grid grid-cols-2 divide-x divide-slate-100 border-t border-slate-100 text-left">
                            <div class="px-6 py-3.5">
                                <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Last activity</dt>
                                <dd class="mt-0.5 text-[13px] text-slate-700" x-text="staff.last_activity ?? 'None yet'"></dd>
                            </div>
                            <div class="px-6 py-3.5">
                                <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Hours today</dt>
                                <dd class="mt-0.5 text-[13px] font-semibold tabular-nums text-slate-700" x-text="hoursLine(staff.worked_minutes)"></dd>
                            </div>
                        </dl>

                        <div class="border-t border-slate-100 p-3">
                            <button type="button" @click="reset()"
                                    class="min-h-12 w-full rounded-xl text-[13px] font-semibold text-slate-400 transition active:bg-slate-50">
                                Not <span x-text="firstName"></span>? Start over
                            </button>
                        </div>
                    </div>
                </template>

                {{-- ---- the confirmation ---- --}}
                <template x-if="done">
                    <div class="p-10 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-50 text-2xl font-bold text-emerald-700 ring-1 ring-emerald-200" aria-hidden="true">✓</span>
                        <p class="mt-4 text-2xl font-bold" x-text="done.title"></p>
                        <p class="mt-1 text-3xl font-extrabold tabular-nums text-emerald-700" x-text="done.at"></p>
                        <p class="mt-3 text-[14px] text-slate-600" x-text="done.note"></p>

                        <button type="button" @click="reset()"
                                class="mt-7 min-h-12 w-full rounded-xl border border-slate-200 text-[13px] font-semibold text-slate-500 transition active:bg-slate-50">
                            Done
                        </button>
                    </div>
                </template>
            </div>
        </main>
    @endif
</div>

<script>
function staffClock() { return {
    entry: '',
    sending: false,
    staff: null,
    ticket: null,
    done: null,
    problem: null,
    now: '',
    today: '',
    timer: null,

    init() {
        this.tick();
        setInterval(() => this.tick(), 10000);
    },

    tick() {
        const at = new Date();
        this.now = at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        this.today = at.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    },

    get firstName() {
        return (this.staff?.name ?? '').split(' ')[0];
    },

    press(key) {
        this.entry += key;

        // Four digits is a whole PIN, so the keypad does not also make somebody
        // reach for Enter.
        if (this.entry.length === 4) this.identify();
    },

    focusEntry() {
        // Kept focused so a scanner's keystrokes always land somewhere,
        // however the screen was last touched.
        this.$nextTick(() => this.$refs.entry?.focus());
    },

    /* ---- who is standing there ---- */

    async identify() {
        const value = this.entry.trim();

        if (value.length < 4 || this.sending) return;

        this.sending = true;
        this.problem = null;

        try {
            // Four digits is a PIN; anything longer came off a scanner.
            const body = /^\d{4}$/.test(value) ? { pin: value } : { card: value };
            const response = await window.postJson(@js(route('clock.kiosk.identify')), body);
            const data = await response.json();

            if (data.status !== 'ok') {
                this.problem = this.describe(data.status);
                this.hold(6000);
            } else {
                this.staff = data.staff;
                this.ticket = data.ticket;
                // Walked away without pressing: the screen belongs to the next
                // person in the queue, not to whoever scanned and left.
                this.hold({{ \App\Http\Controllers\StaffClockController::TICKET_SECONDS }} * 1000);
            }
        } catch {
            this.problem = this.describe('error');
            this.hold(6000);
        } finally {
            this.sending = false;
            this.entry = '';
        }
    },

    /* ---- the button they pressed ---- */

    async punch(action) {
        if (this.sending) return;

        this.sending = true;

        try {
            const response = await window.postJson(@js(route('clock.kiosk.punch')), {
                ticket: this.ticket,
                action,
            });
            const data = await response.json();

            if (data.status === 'ok') {
                this.done = {
                    title: data.title,
                    at: data.at,
                    note: `${data.staff.name} — ${this.noteFor(data.action)}`,
                };
                this.staff = null;
                this.hold(5000);

                return;
            }

            // Stale or expired: the state moved under them, so show it again
            // rather than claiming something happened.
            this.staff = data.staff ?? null;
            this.problem = this.describe(data.status);
            this.hold(6000);
        } catch {
            this.problem = this.describe('error');
            this.hold(6000);
        } finally {
            this.sending = false;
        }
    },

    noteFor(action) {
        return {
            clock_in: 'have a good shift.',
            clock_out: 'see you next time.',
            break_start: 'enjoy your break.',
            break_end: 'welcome back.',
            lunch_end: 'welcome back.',
        }[action] ?? '';
    },

    /* ---- everything clears itself ---- */

    hold(ms) {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.reset(), ms);
    },

    reset() {
        clearTimeout(this.timer);
        this.staff = null;
        this.ticket = null;
        this.done = null;
        this.problem = null;
        this.entry = '';
        this.focusEntry();
    },

    describe(status) {
        /* "Not found" and "locked" say almost the same thing, deliberately:
           somebody at a lobby screen should not be able to learn from the
           wording whether a number is in use.

           kind decides the colour: amber to try again, rose to go and ask
           somebody — the same two tones the door kiosk uses. */
        return {
            not_found: { kind: 'soft', title: 'Not recognised', detail: 'Try again, or ask the office.' },
            locked:    { kind: 'soft', title: 'Not recognised', detail: 'Try again shortly, or ask the office.' },
            ambiguous: { kind: 'bad',  title: 'Ask the office',  detail: 'Two people share that PIN — scan your card instead.' },
            expired:   { kind: 'soft', title: 'That took too long', detail: 'Scan again to carry on.' },
            stale:     { kind: 'soft', title: 'Something changed', detail: 'Your day moved on — here it is again.' },
            unpaired:  { kind: 'bad',  title: 'Screen not set up', detail: 'An administrator needs to pair this device.' },
        }[status] ?? { kind: 'bad', title: 'Something went wrong', detail: 'Try again in a moment.' };
    },

    hoursLine(minutes) {
        if (! minutes) return '0h 00m';

        const hours = Math.floor(minutes / 60);

        return `${hours}h ${String(minutes % 60).padStart(2, '0')}m`;
    },
}; }
</script>
</body>
</html>
