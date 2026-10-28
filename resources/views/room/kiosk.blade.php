<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Room tablet &middot; {{ config('app.name') }}</title>
    @include('layouts.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    The tablet that hangs in a classroom (HANDOFF §5.3).

    Same PIN as the front door, a different question: "Coming into PreK?". It
    makes room check-ins — the thing the ratios are counted from — and it never
    clocks anybody out. The room it is in is said in the header on every
    screen, so a tablet carried into the wrong room is obvious from the door.

    Four screens: the keypad; the person's room screen (the big button for this
    room, the other rooms, a break); the done screen; and, behind the gear, the
    set-up screen where a four-digit room code from Settings → Rooms & hours
    tells the tablet which room it now is.

    Softer than the clock — a wash of the room's own colour behind a white
    card — because it is read by somebody already in a room with children,
    not by somebody at the door with a coat on. Still no balloons.
--}}
<body class="kiosk-touch min-h-dvh text-slate-900 antialiased" style="background: linear-gradient(160deg, #eef2fb 0%, #f7f3fb 55%, #fdf6ec 100%)">

<div x-data="roomTablet()" x-init="focusEntry()" class="flex min-h-dvh flex-col">

    {{-- ---- the masthead: which room this is, always ---- --}}
    <header class="border-b border-slate-200/70 bg-white/70 px-5 py-3 backdrop-blur">
        <div class="mx-auto flex max-w-xl items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <img src="{{ asset('images/littleangels-logo.png') }}" alt="" width="531" height="228" class="h-7 w-auto shrink-0">
                <span class="min-w-0 border-l border-slate-200 pl-3">
                    <span class="block truncate text-[12px] font-semibold leading-tight">Room tablet · <b data-tablet-room x-text="room ?? 'no room yet'"></b></span>
                    <span class="block truncate text-[11px] leading-tight text-slate-500">{{ $device?->name ?? 'Not paired' }}</span>
                </span>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                <span class="text-right">
                    <span class="block text-[22px] font-bold leading-none tabular-nums" x-text="now"></span>
                    <span class="block text-[11px] leading-tight text-slate-500" x-text="today"></span>
                </span>
                @if($device)
                    {{-- The gear: the owner's exact request. Key the room code
                         here and the tablet is that room. --}}
                    <button type="button" @click.stop="openSetup()" aria-label="Set up this tablet" title="Set up this tablet" data-gear
                            class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white text-slate-500 transition active:bg-slate-50">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    </button>
                @endif
            </div>
        </div>
    </header>

    @if(! $device)
        <main class="flex flex-1 items-center justify-center p-5">
            <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
                <h1 class="text-xl font-bold">This tablet is not set up yet</h1>
                <p class="mt-2 text-slate-600">An administrator needs to pair it from Settings → Devices before it can take anyone.</p>
            </div>
        </main>
    @else
        <main class="flex flex-1 items-center justify-center p-5" @click="focusEntry()">
            <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="h-1.5" :style="`background: ${colour}`"></div>

                {{-- ---- set-up: which room is this? ---- --}}
                <template x-if="step === 'setup'">
                    <div class="p-7 text-center" data-setup>
                        <h1 class="text-xl font-bold">Set up this tablet</h1>
                        <p class="mt-1 text-[13px] leading-snug text-slate-500">Key the 4-digit room code.<br>Right now this tablet is <b class="text-slate-900" x-text="room ?? 'no room'"></b>.</p>

                        <div class="mx-auto mt-5 flex justify-center gap-3">
                            <template x-for="i in 4" :key="i">
                                <span class="h-4 w-4 rounded-full border-2 border-indigo-300" :class="code.length >= i ? 'bg-indigo-700 border-indigo-700' : ''"></span>
                            </template>
                        </div>

                        <p x-show="problem" x-cloak class="mt-4 rounded-xl bg-rose-50 px-4 py-2.5 text-[14px] font-semibold text-rose-800 ring-1 ring-rose-200" x-text="problem?.title"></p>

                        <div class="mx-auto mt-5 grid max-w-xs grid-cols-3 gap-2">
                            <template x-for="key in ['1','2','3','4','5','6','7','8','9']" :key="key">
                                <button type="button" @click="pressCode(key)" class="min-h-14 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:bg-indigo-50" x-text="key"></button>
                            </template>
                            <button type="button" @click="code = ''" class="min-h-14 rounded-xl border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-500 transition active:scale-95">Clear</button>
                            <button type="button" @click="pressCode('0')" class="min-h-14 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:bg-indigo-50">0</button>
                            <button type="button" @click="code = code.slice(0, -1)" class="min-h-14 rounded-xl border border-slate-200 bg-slate-50 text-xl font-semibold text-slate-500 transition active:scale-95">⌫</button>
                        </div>

                        <button type="button" @click="reset()" class="mt-5 min-h-12 w-full rounded-xl border border-slate-200 text-[13px] font-semibold text-slate-500 transition active:bg-slate-50">Cancel</button>
                    </div>
                </template>

                {{-- ---- the keypad ---- --}}
                <template x-if="step === 'pin'">
                    <div class="p-7">
                        <div class="text-center">
                            <h1 class="text-xl font-bold" data-keypad-title>Coming into <span x-text="room ?? 'this room'"></span>?</h1>
                            <p class="mt-1 text-[13px] text-slate-500">Same PIN as the front door</p>
                        </div>

                        <p x-show="! room" x-cloak class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-center text-[13px] text-amber-900 ring-1 ring-amber-200" data-no-room>
                            This tablet has not been told which room it is in. Tap ⚙ and key the room code from Settings → Rooms &amp; hours.
                        </p>

                        <form @submit.prevent="identify()" autocomplete="off" class="mt-5">
                            <input x-ref="entry" x-model="entry" type="text" inputmode="numeric"
                                   name="kiosk-entry" autocomplete="off" autocorrect="off" spellcheck="false"
                                   data-1p-ignore data-lpignore="true" data-form-type="other"
                                   style="-webkit-text-security: disc; text-security: disc;"
                                   aria-label="Key your PIN"
                                   class="w-full rounded-xl border border-slate-300 bg-slate-50 px-6 py-4 text-center text-3xl tracking-[0.5em] text-slate-900 placeholder:tracking-[0.3em] placeholder:text-slate-300 focus:border-indigo-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-100"
                                   placeholder="••••">

                            <div class="mt-4 grid grid-cols-3 gap-2">
                                <template x-for="key in ['1','2','3','4','5','6','7','8','9']" :key="key">
                                    <button type="button" @click="press(key)" class="min-h-14 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:bg-indigo-50" x-text="key"></button>
                                </template>
                                <button type="button" @click="entry = ''" class="min-h-14 rounded-xl border border-slate-200 bg-white text-sm font-semibold uppercase tracking-wide text-slate-400 transition active:scale-95">Clear</button>
                                <button type="button" @click="press('0')" class="min-h-14 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-700 transition active:scale-95 active:bg-indigo-50">0</button>
                                {{-- ⌫ takes one digit back (QA spec A3, 28 Oct 2026). --}}
                                <button type="button" @click="entry = entry.slice(0, -1)" aria-label="Delete one digit" data-key-back class="min-h-14 rounded-xl border border-slate-200 bg-white text-2xl font-semibold text-slate-500 transition active:scale-95">⌫</button>
                                <button type="submit" :disabled="entry.length < 4 || sending" class="col-span-3 min-h-12 rounded-xl bg-indigo-600 text-sm font-bold uppercase tracking-wide text-white transition active:scale-95 disabled:bg-slate-200 disabled:text-slate-400">Enter</button>
                            </div>
                        </form>

                        <p x-show="problem" x-cloak class="mt-4 rounded-xl px-4 py-3 text-center"
                           :class="problem?.kind === 'bad' ? 'bg-rose-50 text-rose-900 ring-1 ring-rose-200' : 'bg-amber-50 text-amber-900 ring-1 ring-amber-200'">
                            <span class="block font-bold" x-text="problem?.title"></span>
                            <span class="text-[13px]" x-text="problem?.detail"></span>
                        </p>
                    </div>
                </template>

                {{-- ---- one person: this room, another room, or a break ---- --}}
                <template x-if="step === 'rooms' && staff">
                    <div class="p-6" data-room-screen>
                        <h2 class="text-xl font-bold">Hi <span x-text="staff.first_name"></span></h2>

                        {{-- RULE: a room tap from a break ends the break. Said
                             before the tap, in bold, because it is a payroll
                             fact being made by a tap on a room. --}}
                        <p x-show="staff.on_break" x-cloak class="mt-2 rounded-xl bg-amber-50 px-3 py-2.5 text-[14px] leading-snug text-amber-900 ring-1 ring-amber-200" data-break-banner>
                            You’re on a break. Picking a room <b>ends your break</b>.
                        </p>

                        <p class="mt-2 text-[13px] text-slate-500" x-text="staff.now"></p>

                        <button x-show="staff.can_here" type="button" @click="punch('check_in', room)" :disabled="sending" data-check-in-here
                                class="mt-3 flex min-h-[4.5rem] w-full flex-col items-start justify-center rounded-xl bg-emerald-700 px-5 text-left text-white transition active:scale-[0.98] active:bg-emerald-800 disabled:opacity-40">
                            <span class="text-xl font-bold">Check into <span x-text="room"></span></span>
                            <span class="text-[13px] font-medium opacity-90" x-text="staff.here_sub"></span>
                        </button>

                        <p x-show="staff.is_here" x-cloak class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[14px]" data-already-here>
                            You’re already checked into <b x-text="room"></b>.
                        </p>

                        <p class="mt-4 text-[11px] font-bold uppercase tracking-wider text-slate-400">Or another room</p>
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            <template x-for="other in staff.rooms" :key="other.room">
                                <button type="button" @click="other.is_here ? null : punch('check_in', other.room)" :disabled="sending || other.is_here"
                                        class="flex min-h-14 flex-col items-start gap-0.5 rounded-xl border-2 border-slate-200 bg-white px-3 py-2 text-left transition active:scale-[0.98]"
                                        :class="other.is_here ? 'border-dashed bg-slate-50' : ''"
                                        :style="`border-left: 8px solid ${other.colour}`"
                                        :data-room-tile="other.room">
                                    <span class="text-[15px] font-bold" x-text="other.room"></span>
                                    <span class="text-[12px] text-slate-500" x-text="other.is_here ? 'You’re here' : other.sub"></span>
                                </button>
                            </template>
                        </div>

                        {{-- Start Break under another name. --}}
                        <button x-show="staff.can_break" type="button" @click="punch('break_start')" :disabled="sending" data-take-break
                                class="mt-3 flex w-full flex-col items-start rounded-xl bg-amber-600 px-4 py-3 text-left text-white transition active:scale-[0.98] active:bg-amber-700 disabled:opacity-40">
                            <span class="text-[16px] font-bold">Taking a break</span>
                            <span class="text-[13px] font-medium opacity-90">Same as Start Break at the door</span>
                        </button>

                        {{-- RULE: room tablets cannot clock anyone out. --}}
                        <p class="mt-3 text-center text-[12px] text-slate-400">Clock out at the front door.</p>

                        <button type="button" @click="reset()" class="mt-2 min-h-12 w-full rounded-xl border border-slate-200 text-[13px] font-semibold text-slate-500 transition active:bg-slate-50">Cancel</button>
                    </div>
                </template>

                {{-- ---- the confirmation ---- --}}
                <template x-if="step === 'done' && done">
                    <div class="p-10 text-center" data-done>
                        <span class="mx-auto grid h-14 w-14 place-items-center rounded-full text-2xl font-bold ring-1" aria-hidden="true"
                              :class="done.tone === 'brk' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'">✓</span>
                        <p class="mt-4 text-2xl font-bold" x-text="done.title"></p>
                        <p class="mt-2 text-[14px] leading-snug text-slate-600" x-text="done.sub"></p>
                        <p x-show="done.warning" x-cloak class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-left text-[13px] leading-snug text-amber-900 ring-1 ring-amber-200" data-short-warning>
                            ⚠ <span x-text="done.warning"></span>
                        </p>
                        <button type="button" @click="reset()" class="mt-7 min-h-12 w-full rounded-xl bg-indigo-600 text-[13px] font-semibold text-white transition active:bg-indigo-700">Done</button>
                    </div>
                </template>
            </div>
        </main>
    @endif
</div>

<script>
function roomTablet() { return {
    room: @js($device?->classroom),
    entry: '',
    code: '',
    sending: false,
    staff: null,
    ticket: null,
    done: null,
    problem: null,
    step: 'pin',   // 'pin' | 'rooms' | 'done' | 'setup'
    now: '',
    today: '',
    timer: null,

    init() {
        this.tick();
        setInterval(() => this.tick(), 10000);
        this.holdScreenAwake();
    },

    get colour() {
        return @js(collect(\App\Services\ScheduleEditor::COLOURS)->map(fn ($c) => $c[1])->all())[this.room] ?? '#6366f1';
    },

    async holdScreenAwake() {
        if (@js(\App\Models\Setting::bool('kiosk.allow_sleep', false)) || ! ('wakeLock' in navigator)) return;

        const take = async () => { try { await navigator.wakeLock.request('screen'); } catch {} };
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') take(); });
        take();
    },

    tick() {
        // The centre's time, as on the front door clock.
        const at = new Date();
        const zone = @js(config('app.timezone'));
        this.now = at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: zone });
        this.today = at.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', timeZone: zone });
    },

    press(key) {
        this.entry += key;
        if (this.entry.length === 4) this.identify();
    },

    focusEntry() {
        if (this.step !== 'pin') return;
        this.$nextTick(() => this.$refs.entry?.focus());
    },

    /* ---- the gear ---- */

    openSetup() {
        clearTimeout(this.timer);
        this.step = 'setup';
        this.code = '';
        this.problem = null;
        this.hold(60000);
    },

    pressCode(key) {
        this.code = (this.code + key).slice(0, 4);
        if (this.code.length === 4) this.setup();
    },

    async setup() {
        if (this.sending) return;
        this.sending = true;
        this.problem = null;

        try {
            const response = await window.postJson(@js(route('room.kiosk.setup')), { code: this.code });
            const data = await response.json();

            if (data.status === 'ok') {
                this.room = data.room;
                this.done = {
                    tone: 'ok',
                    title: `This tablet is now ${data.room}`,
                    sub: `Teachers who key their PIN here are checked into ${data.room}. The office sees it in Settings → Rooms & hours.`,
                    warning: null,
                };
                this.step = 'done';
                this.hold(8000);
            } else {
                this.problem = data.status === 'bad_code'
                    ? { kind: 'bad', title: 'That’s not a room code' }
                    : this.describe(data.status);
                this.hold(60000);
            }
        } catch {
            this.problem = this.describe('error');
        } finally {
            this.sending = false;
            this.code = '';
        }
    },

    /* ---- who is standing there ---- */

    async identify() {
        const value = this.entry.trim();
        if (value.length < 4 || this.sending) return;

        this.sending = true;
        this.problem = null;

        try {
            const body = /^\d{4}$/.test(value) ? { pin: value } : { card: value };
            const response = await window.postJson(@js(route('room.kiosk.identify')), body);
            const data = await response.json();

            if (data.status === 'done') {
                // Clocked in for them, placed here, nothing to pick.
                this.done = data.done;
                this.step = 'done';
                this.hold(10000);
            } else if (data.status === 'ok') {
                this.staff = data.staff;
                this.ticket = data.ticket;
                this.step = 'rooms';
                this.hold({{ \App\Http\Controllers\StaffClockController::TICKET_SECONDS }} * 1000);
            } else {
                this.problem = this.describe(data.status);
                this.hold(6000);
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

    async punch(action, room = null) {
        if (this.sending) return;
        this.sending = true;

        try {
            const response = await window.postJson(@js(route('room.kiosk.punch')), { ticket: this.ticket, action, room });
            const data = await response.json();

            if (data.status === 'ok') {
                this.done = data.done;
                this.staff = null;
                this.step = 'done';
                this.hold(6000);
                return;
            }

            this.staff = data.staff ?? null;
            this.step = this.staff ? 'rooms' : 'pin';
            this.problem = this.describe(data.status);
            this.hold(6000);
        } catch {
            this.problem = this.describe('error');
            this.hold(6000);
        } finally {
            this.sending = false;
        }
    },

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
        this.code = '';
        this.step = 'pin';
        this.focusEntry();
    },

    describe(status) {
        return {
            not_found: { kind: 'soft', title: 'Not recognised', detail: 'Try again, or ask the office.' },
            locked:    { kind: 'soft', title: 'Not recognised', detail: 'Try again shortly, or ask the office.' },
            ambiguous: { kind: 'bad',  title: 'Ask the office',  detail: 'Two people share that PIN — use the front door scanner.' },
            expired:   { kind: 'soft', title: 'That took too long', detail: 'Key your PIN again to carry on.' },
            stale:     { kind: 'soft', title: 'Something changed', detail: 'Your day moved on — here it is again.' },
            already_here: { kind: 'soft', title: 'You’re already here', detail: 'Nothing to change.' },
            no_room:   { kind: 'bad',  title: 'No room yet', detail: 'Tap ⚙ and key the room code first.' },
            unpaired:  { kind: 'bad',  title: 'Tablet not set up', detail: 'An administrator needs to pair this device.' },
        }[status] ?? { kind: 'bad', title: 'Something went wrong', detail: 'Try again in a moment.' };
    },
}; }
</script>
</body>
</html>
