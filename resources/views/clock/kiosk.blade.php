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
    The staff time clock on the wall — the front door iPad (HANDOFF §5.2).

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

    Four screens, and one set of buttons per state. Keypad → the person's card
    → (for a clock-in, or "Going somewhere else?" after a break) the room
    picker → the done screen. A tile that is impossible is absent, not greyed
    out. There is no path from a break straight to clocked out — a break ends
    back on the clock, in the room it was taken from, so leaving is End Break
    then Clock Out, two presses that are two true facts.

    Everything returns to the welcome screen on its own. Nobody should have to
    dismiss a screen for the person behind them, and nobody should read the
    previous person's hours.
--}}
<body class="kiosk-touch min-h-dvh bg-[#f4f6fb] text-[#1c2130] antialiased">

<div x-data="staffClock()" x-init="focusEntry()" class="flex min-h-dvh flex-col">

    {{-- ---- the masthead ----
         As the StaffFlow mock-up draws it (Jordan, 29 Oct 2026: "in front
         door I want the exact UI"): a dark band, "Staff time clock" over
         the screen's name, and the time on the right. --}}
    <header class="bg-[#1d2333] px-[18px] py-3.5 text-white">
        <div class="mx-auto flex max-w-xl items-center justify-between gap-4">
            <div class="flex min-w-0 flex-col">
                <span class="truncate text-base font-bold leading-tight">Staff time clock</span>
                <span class="truncate text-xs leading-tight text-[#aab3c8]">{{ $device?->name ?? 'Not paired' }}</span>
            </div>
            {{-- The wall clock. Tabular so the minute changing does not
                 shuffle the digits beside it. --}}
            <span class="shrink-0 text-lg font-semibold tabular-nums" x-text="now" data-clock-now></span>
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
        {{-- The keypad sits straight on the page near the top, as in the
             mock-up; once somebody is on screen the card below takes over. --}}
        <main class="flex flex-1 justify-center p-[18px]" :class="staff || done ? 'items-center' : 'items-start'" @click="focusEntry()">

            {{-- ---- the welcome screen: the mock-up's keypad ---- --}}
            <template x-if="! staff && ! done">
                <div class="flex w-full max-w-md flex-col items-center gap-3.5 pt-2.5" data-clock-welcome>
                    @php($scanner = \App\Models\Setting::bool('kiosk.scanner', true))
                    <h1 class="text-[22px] font-bold" x-text="greeting"></h1>
                    {{-- Has to match what the screen will accept: with the
                         scanner off, "Scan your card" is an instruction that fails. --}}
                    <p class="text-center text-sm text-[#5f6676]">{{ $scanner ? 'Scan your card or key your 4-digit PIN' : 'Key your 4-digit PIN' }}</p>

                    <form @submit.prevent="identify()" autocomplete="off" class="flex w-full flex-col items-center gap-3.5">
                        {{-- One field for both, out of sight: a scanner is a
                             keyboard that types fast and presses Enter, so it
                             lands here and Enter submits; four keyed digits
                             submit on their own. The dots say how many are in.
                             inputmode none keeps the iPad's own keyboard away. --}}
                        <input x-ref="entry" x-model="entry" type="text" inputmode="none"
                               name="kiosk-entry" autocomplete="off" autocorrect="off" spellcheck="false"
                               data-1p-ignore data-lpignore="true" data-form-type="other"
                               aria-label="Scan your card or key your PIN"
                               class="pointer-events-none absolute h-px w-px opacity-0">

                        <div class="flex gap-3" aria-hidden="true" data-pin-dots>
                            <template x-for="i in 4" :key="i">
                                <span class="h-[15px] w-[15px] rounded-full border-2" :class="entry.length >= i ? 'border-[#1b5e91] bg-[#1b5e91]' : 'border-[#9fb0d6]'"></span>
                            </template>
                        </div>

                        <div class="grid w-full max-w-[300px] grid-cols-3 gap-2.5">
                            <template x-for="key in ['1','2','3','4','5','6','7','8','9']" :key="key">
                                <button type="button" @click="press(key)"
                                        class="h-14 rounded-[14px] border border-[#d5dbea] bg-white text-[22px] font-semibold text-[#1c2130] transition active:bg-[#eef1fb]"
                                        x-text="key"></button>
                            </template>
                            <button type="button" @click="entry = ''"
                                    class="h-14 rounded-[14px] border border-[#d5dbea] bg-[#f5f7fc] text-sm font-semibold text-[#5f6676] transition active:bg-[#eef1fb]">Clear</button>
                            <button type="button" @click="press('0')"
                                    class="h-14 rounded-[14px] border border-[#d5dbea] bg-white text-[22px] font-semibold text-[#1c2130] transition active:bg-[#eef1fb]">0</button>
                            {{-- ⌫ takes one digit back (QA spec A3, 28 Oct 2026). --}}
                            <button type="button" @click="entry = entry.slice(0, -1)" aria-label="Delete one digit" data-key-back
                                    class="h-14 rounded-[14px] border border-[#d5dbea] bg-[#f5f7fc] text-sm font-semibold text-[#5f6676] transition active:bg-[#eef1fb]">⌫</button>
                        </div>
                    </form>

                    {{-- Amber to try again, rose to go and ask somebody —
                         the two tones the door kiosk uses for the same two
                         jobs. --}}
                    <p x-show="problem" x-cloak class="w-full max-w-[300px] rounded-xl px-4 py-3 text-center"
                       :class="problem?.kind === 'bad' ? 'bg-rose-50 text-rose-900 ring-1 ring-rose-200' : 'bg-amber-50 text-amber-900 ring-1 ring-amber-200'">
                        {{-- ?. because x-show only hides: Alpine still reads
                             these while problem is null, and threw on every
                             load until 28 Oct 2026. --}}
                        <span class="block font-bold" x-text="problem?.title"></span>
                        <span class="text-[13px]" x-text="problem?.detail"></span>
                    </p>
                </div>
            </template>

            <div x-show="staff || done" x-cloak class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

                {{-- The accent rule: the state's own colour once somebody is
                     on screen — the first thing seen from across the room,
                     before any word is read. --}}
                <div class="h-1.5"
                     :class="staff
                        ? { working: 'bg-emerald-600', break: 'bg-amber-500', lunch: 'bg-amber-500', off: 'bg-slate-300' }[staff.state]
                        : (done ? (done.tone === 'stop' ? 'bg-rose-600' : done.tone === 'hold' ? 'bg-amber-500' : 'bg-emerald-600') : 'bg-indigo-600')"></div>

                {{-- ---- one person, and only what they can do ---- --}}
                <template x-if="staff && ! done && step === 'card'">
                    <div>
                        {{-- Who, on a tinted shoulder so the name and the state
                             read as one block rather than as two lines. --}}
                        <div class="flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-6 py-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-white text-[15px] font-bold text-slate-700 ring-1 ring-slate-200" x-text="staff.initials"></span>
                            <div class="min-w-0 flex-1 text-left">
                                <p class="truncate text-[17px] font-bold leading-tight" x-text="staff.name"></p>
                                <p class="text-[12px] leading-tight text-slate-500" x-text="staff.staff_id"></p>
                            </div>
                            {{-- The pill names the four-state model's word for
                                 them: Not on the clock / Working / On break. --}}
                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[12px] font-bold" data-presence-pill
                                  :class="{
                                      working: 'bg-emerald-100 text-emerald-800',
                                      break: 'bg-amber-100 text-amber-900',
                                      lunch: 'bg-amber-100 text-amber-900',
                                      off: 'bg-slate-200 text-slate-600',
                                  }[staff.state]">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                <span x-text="staff.presence_label"></span>
                            </span>
                        </div>

                        <div class="p-6">
                            {{-- The one sentence that is always about *now*:
                                 on the clock since when and in which room, on
                                 break since when and from where, or not on
                                 the clock today. --}}
                            <p class="text-center text-[14px] text-slate-700" data-presence-line x-text="staff.line"></p>

                            {{-- What the office planned for them today: the
                                 shift, and any change the Staff page holds for
                                 the date. The note reaches the person at the
                                 clock, not only the director who wrote it. --}}
                            <p x-show="staff.today" x-cloak class="mt-3 rounded-xl bg-sky-50 px-4 py-2.5 text-center text-[13px] text-sky-900 ring-1 ring-sky-200" data-today-line x-text="staff.today"></p>

                            <div class="mt-5 space-y-2.5">
                                <template x-for="action in staff.actions" :key="action.action">
                                    <button type="button" @click="action.picks_room ? pickRoom('in') : punch(action.action)" :disabled="sending"
                                            class="flex min-h-[4.5rem] w-full flex-col items-start justify-center rounded-xl px-5 text-left text-white transition active:scale-[0.98] disabled:opacity-40"
                                            :class="{
                                                go: 'bg-emerald-700 active:bg-emerald-800',
                                                hold: 'bg-amber-600 active:bg-amber-700',
                                                stop: 'bg-rose-700 active:bg-rose-800',
                                            }[action.tone]">
                                        <span class="text-xl font-bold" x-text="action.label"></span>
                                        {{-- The small line under the big word says what
                                             else the press does: which room it leaves,
                                             which room it goes back to. --}}
                                        <span class="text-[13px] font-medium opacity-90" x-text="action.hint"></span>
                                    </button>
                                </template>

                                {{-- RULE: from a break the only button is End
                                     Break, back to the last room. Somewhere
                                     else is a link, not a button, so the
                                     common case is one press. --}}
                                <button x-show="staff.presence === 'on_break'" x-cloak type="button" @click="pickRoom('brk')"
                                        class="min-h-10 w-full text-[14px] font-semibold text-indigo-700 underline underline-offset-2" data-elsewhere>
                                    Going somewhere else?
                                </button>

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
                                <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Worked so far</dt>
                                <dd class="mt-0.5 text-[13px] font-semibold tabular-nums text-slate-700" x-text="hoursLine(staff.worked_minutes)"></dd>
                            </div>
                        </dl>

                        <div class="border-t border-slate-100 p-3">
                            <button type="button" @click="reset()"
                                    class="min-h-12 w-full rounded-xl text-[13px] font-semibold text-slate-400 transition active:bg-slate-50">
                                Not <span x-text="firstName"></span>? Cancel
                            </button>
                        </div>
                    </div>
                </template>

                {{-- ---- the room picker ---- --}}
                <template x-if="staff && ! done && step === 'rooms'">
                    <div class="p-6" data-room-picker>
                        <h2 class="text-xl font-bold" x-text="mode === 'in' ? 'Which room are you going to?' : 'Where are you going after your break?'"></h2>
                        <p class="mt-1 text-[13px] text-slate-500">Your scheduled room is first. You can change rooms later at any room tablet.</p>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            {{-- One tile per room, the scheduled one first and
                                 marked, each saying what the room holds right
                                 now — so a person can see a short room and
                                 choose it. --}}
                            <template x-for="room in staff.rooms" :key="room.room">
                                <button type="button" @click="punch(mode === 'in' ? 'clock_in' : 'break_end', room.room)" :disabled="sending"
                                        class="flex min-h-[4.5rem] flex-col items-start gap-0.5 rounded-xl border-2 border-slate-200 bg-white px-3 py-2.5 text-left transition active:scale-[0.98] disabled:opacity-40"
                                        :class="room.mine ? 'border-emerald-600 ring-4 ring-emerald-100' : ''"
                                        :style="`border-left: 8px solid ${room.colour}`"
                                        :data-room-tile="room.room">
                                    <span class="text-[16px] font-bold" x-text="room.room"></span>
                                    <span class="text-[12px] text-slate-500" x-text="room.sub"></span>
                                    <span x-show="room.mine" class="mt-0.5 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800">Your schedule</span>
                                </button>
                            </template>
                        </div>

                        {{-- Admin, kitchen, meetings: on the clock, in nobody's ratio. --}}
                        <button type="button" @click="punch(mode === 'in' ? 'clock_in' : 'break_end', 'office')" :disabled="sending"
                                class="mt-2 flex w-full flex-col items-start rounded-xl border-2 border-slate-200 bg-white px-3 py-2.5 text-left transition active:scale-[0.98] disabled:opacity-40"
                                style="border-left: 8px solid #9aa3b5" data-room-tile="office">
                            <span class="text-[15px] font-bold">Office / no room</span>
                            <span class="text-[12px] text-slate-500">Admin, kitchen, meetings — not counted in ratios</span>
                        </button>

                        <button type="button" @click="step = 'card'"
                                class="mt-3 min-h-12 w-full rounded-xl border border-slate-200 text-[13px] font-semibold text-slate-500 transition active:bg-slate-50">
                            Cancel
                        </button>
                    </div>
                </template>

                {{-- ---- the confirmation ---- --}}
                <template x-if="done">
                    <div class="p-10 text-center">
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full text-2xl font-bold ring-1" aria-hidden="true"
                              :class="done.tone === 'stop' ? 'bg-rose-50 text-rose-700 ring-rose-200' : done.tone === 'hold' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'">✓</span>
                        <p class="mt-4 text-2xl font-bold" x-text="done.title"></p>
                        <p class="mt-1 text-3xl font-extrabold tabular-nums" x-text="done.at"
                           :class="done.tone === 'stop' ? 'text-rose-700' : done.tone === 'hold' ? 'text-amber-700' : 'text-emerald-700'"></p>
                        <p class="mt-3 text-[14px] text-slate-600" x-text="done.note"></p>

                        {{-- RULE: a short room is warned about, never blocked.
                             The office has already been told by the time this
                             is on screen. --}}
                        <p x-show="done.warning" x-cloak class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-left text-[13px] leading-snug text-amber-900 ring-1 ring-amber-200" data-short-warning>
                            ⚠ <span x-text="done.warning"></span>
                        </p>

                        <button type="button" @click="reset()"
                                class="mt-7 min-h-12 w-full rounded-xl border border-slate-200 text-[13px] font-semibold text-slate-500 transition active:bg-slate-50">
                            Done
                        </button>
                        <p class="mt-2 text-[11px] text-slate-400">Back to the keypad in a few seconds</p>
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
    step: 'card',   // 'card' or 'rooms', once somebody is on screen
    mode: 'in',     // why the picker is open: a clock-in, or somewhere else after a break
    now: '',
    today: '',
    greeting: 'Good morning',   // by the centre's hour, set in tick()
    timer: null,

    init() {
        this.tick();
        setInterval(() => this.tick(), 10000);
        this.holdScreenAwake();
    },

    /**
     * Keep the tablet's screen on, unless the centre has said not to.
     *
     * A kiosk that has gone to sleep at shift change is a queue at the door
     * while somebody hunts for the power button. The lock is dropped by the
     * browser whenever the page is hidden, so it is taken again when the tab
     * comes back — otherwise the first sleep is permanent.
     *
     * Wrapped throughout: the API needs a secure context and is missing on
     * older tablets, and a kiosk must not fail to take a punch because it
     * could not keep its own screen on.
     */
    async holdScreenAwake() {
        if (@js(\App\Models\Setting::bool('kiosk.allow_sleep', false)) || ! ('wakeLock' in navigator)) return;

        const take = async () => {
            try { await navigator.wakeLock.request('screen'); } catch {}
        };

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') take();
        });

        take();
    },

    tick() {
        // In the centre's time, not the device's: an iPad left on another
        // zone showed 3:58 AM Wednesday over a punch stamped 3:58 PM Tuesday.
        const at = new Date();
        const zone = @js(config('app.timezone'));
        this.now = at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: zone });
        this.today = at.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', timeZone: zone });
        const hour = Number(at.toLocaleString('en-US', { hour: 'numeric', hourCycle: 'h23', timeZone: zone }));
        this.greeting = hour < 12 ? 'Good morning' : (hour < 17 ? 'Good afternoon' : 'Good evening');
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
                this.step = 'card';
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

    /* ---- the room picker ---- */

    pickRoom(mode) {
        this.mode = mode;
        this.step = 'rooms';
    },

    /* ---- the button they pressed ---- */

    async punch(action, room = null) {
        if (this.sending) return;

        this.sending = true;

        try {
            const response = await window.postJson(@js(route('clock.kiosk.punch')), {
                ticket: this.ticket,
                action,
                room,
            });
            const data = await response.json();

            if (data.status === 'ok') {
                this.done = {
                    tone: { clock_out: 'stop', break_start: 'hold' }[data.action] ?? 'go',
                    title: this.titleFor(data.action, data),
                    at: data.at,
                    note: this.noteFor(data.action, data),
                    warning: data.warning,
                };
                this.staff = null;
                this.step = 'card';
                this.hold(6000);

                return;
            }

            // Stale or expired: the state moved under them, so show it again
            // rather than claiming something happened.
            this.staff = data.staff ?? null;
            this.step = 'card';
            this.problem = this.describe(data.status);
            this.hold(6000);
        } catch {
            this.problem = this.describe('error');
            this.hold(6000);
        } finally {
            this.sending = false;
        }
    },

    titleFor(action, data) {
        return {
            clock_in: 'You’re clocked in',
            clock_out: 'See you tomorrow',
            break_start: 'Enjoy your break',
            break_end: 'Welcome back',
            lunch_end: 'Welcome back',
        }[action] ?? data.title;
    },

    noteFor(action, data) {
        const place = data.room ? `Checked into ${data.room}` : (data.placed ? 'Working in the office (no room)' : null);
        const hours = this.hoursLine(data.staff?.worked_minutes ?? 0);

        return {
            clock_in: place ? `${place} at ${data.at}.` : 'Have a good shift.',
            clock_out: `Clocked out at ${data.at}. ${hours} today.`,
            break_start: `When you’re back, End Break returns you to ${data.staff?.last_room ?? 'the office'}.`,
            break_end: `Break ended. ${place ? place : 'Back in the office'} at ${data.at}.`,
            lunch_end: `Lunch ended. ${place ? place : 'Back in the office'} at ${data.at}.`,
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
        this.step = 'card';
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
