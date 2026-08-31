@extends('layouts.app')
@section('title', 'Holidays')
@section('content')
{{-- One dialog for the whole page rather than one per row: the list runs to
     dozens of days once a year of statutory holidays is seeded, and that many
     hidden modals is a lot of DOM for a control used once in a while. --}}
<div x-data="holidayDialog(@js($restores))" @keydown.escape.window="close()">
    <x-page-header title="Holidays &amp; Closures" subtitle="The days the centre is shut. Set one here and every room's attendance schedule closes for that day."/>

    @if(session('success'))<div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">{{ session('warning') }}</div>@endif

    <div class="mt-7 grid gap-5 xl:grid-cols-3">

        <div class="space-y-5 xl:col-span-1">

            {{-- Setting a one-off. A single date is the common case, so the second
                 date is optional and sits beside it rather than behind a mode
                 switch — a week-long break is the same act, entered by its real
                 last day. --}}
            <section class="glass-card h-fit rounded-2xl p-5">
                <h2 class="text-base font-semibold">Close a day</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Weekends are skipped. Weeks that have already ended cannot be changed.</p>

                <form method="POST" action="{{ route('holidays.store') }}" class="mt-4 space-y-4">
                    @csrf

                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        Date
                        <input type="date" name="closed_on" required value="{{ old('closed_on') }}" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        <x-input-error :messages="$errors->get('closed_on')" />
                    </label>

                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        Last day <span class="font-normal">(optional — for a break)</span>
                        <input type="date" name="ends_on" value="{{ old('ends_on') }}" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        <x-input-error :messages="$errors->get('ends_on')" />
                    </label>

                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        Reason
                        <input type="text" name="reason" maxlength="120" value="{{ old('reason') }}" placeholder="Snow day" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        <x-input-error :messages="$errors->get('reason')" />
                    </label>

                    <button class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">Close these days</button>
                </form>

                {{-- What actually happens, said once and plainly. A director
                     setting a holiday in March needs to know the September week
                     that does not exist yet will still come out closed. --}}
                <div class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-xs leading-relaxed text-slate-500 dark:border-white/10 dark:text-slate-400">
                    <p>Closing a day clears every child's tick on it, in every room, and no one can be scheduled onto it afterwards. Reopening the day puts those ticks back.</p>
                    <p>A week that has not been opened yet reads these dates when it is built, so a holiday set months ahead still comes out closed.</p>
                    <p>Staff shifts are not generated for a closed day, and leave is not charged against one. A child who does turn up can still be signed in.</p>
                </div>
            </section>

            {{-- Holidays that return. Entered as a month and a day, because the
                 year the rule is typed in has nothing to do with the rule. --}}
            <section class="glass-card h-fit rounded-2xl p-5">
                <h2 class="text-base font-semibold">Every year</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Closes the same date {{ \App\Services\HolidayCalendar::HORIZON_YEARS }} years ahead, and keeps rolling forward.</p>

                <form method="POST" action="{{ route('holidays.rules.store') }}" class="mt-4 space-y-4">
                    @csrf

                    <div class="flex gap-3">
                        <label class="flex-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                            Month
                            <select name="month" required class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                                @foreach(range(1, 12) as $month)
                                    <option value="{{ $month }}" @selected(old('month') == $month)>{{ \Illuminate\Support\Carbon::create(2000, $month, 1)->format('F') }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('month')" />
                        </label>
                        <label class="w-24 text-xs font-medium text-slate-500 dark:text-slate-400">
                            Day
                            <input type="number" name="day" min="1" max="31" required value="{{ old('day') }}" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                            <x-input-error :messages="$errors->get('day')" />
                        </label>
                    </div>

                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        Name
                        <input type="text" name="reason" maxlength="120" required value="{{ old('reason') }}" placeholder="Christmas Day" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        <x-input-error :messages="$errors->get('reason')" />
                    </label>

                    <button class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Add annual holiday</button>
                </form>

                <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100 text-sm dark:divide-white/10 dark:border-white/10">
                    @forelse($rules as $rule)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ $rule->reason }}</p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $rule->label() }}
                                    {{-- A moving holiday's rule is not a date, so show the date it
                                         actually lands on next — that is what gets checked. --}}
                                    @if($rule->moves())
                                        @php($next = $rule->dateIn((int) now()->year) ?? $rule->dateIn((int) now()->year + 1))
                                        @if($next) · {{ \Illuminate\Support\Carbon::parse($next)->format('j M Y') }} @endif
                                    @endif
                                    · through {{ $rule->materialised_through ?? '—' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <button type="button" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400"
                                        @click="askEditRule(@js($rule->reason), @js(route('holidays.rules.update', $rule)), @js($rule->type === \App\Models\HolidayRule::FIXED), @js($rule->month), @js($rule->day), @js($rule->label()))">Edit</button>
                                <button type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-800 dark:text-rose-400"
                                        @click="askRuleDelete(@js($rule->reason), @js(route('holidays.rules.destroy', $rule)))">Remove</button>
                            </div>
                        </li>
                    @empty
                        <li class="py-6 text-center text-xs text-slate-500 dark:text-slate-400">No annual holidays yet.</li>
                    @endforelse
                </ul>

                {{-- The form makes fixed dates; the moving ones come from the
                     statutory seeder, which knows how to find them. Saying so
                     beats leaving a director hunting for a control that is not
                     there. --}}
                <p class="mt-3 text-xs leading-relaxed text-slate-500 dark:text-slate-400">This form sets a fixed date. Holidays that move each year — Good Friday, Victoria Day, Labour Day, Thanksgiving — are set up by the statutory holiday seeder and appear in the list above with the date they next fall on.</p>
            </section>
        </div>

        <div class="space-y-5 xl:col-span-2">
            @foreach([['title' => 'Upcoming', 'days' => $upcoming, 'empty' => 'No closures are set. The centre is open every weekday from here on.'], ['title' => 'Past', 'days' => $past, 'empty' => 'No past closures.']] as $group)
                <section class="glass-card overflow-hidden rounded-2xl">
                    <header class="flex items-center justify-between gap-4 border-b border-slate-100 p-5 dark:border-white/10">
                        <h2 class="text-base font-semibold">{{ $group['title'] }}</h2>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $group['days']->count() }}</span>
                    </header>

                    <ul class="divide-y divide-slate-100 text-sm dark:divide-white/10">
                        @forelse($group['days'] as $day)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                                <div class="min-w-0">
                                    <p class="font-semibold">{{ $day->closed_on->format('l, j F Y') }}</p>
                                    <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $day->label() }}@if($day->rule) · repeats every year @elseif($day->creator) · set by {{ $day->creator->name }} @endif
                                        {{-- What reopening would hand back, before the click rather than after it. --}}
                                        @if($day->clearedCount() > 0) · cleared {{ $day->clearedCount() }} scheduled day{{ $day->clearedCount() === 1 ? '' : 's' }} @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    @if($day->rule)
                                        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">Annual</span>
                                    @endif
                                    <span class="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">Closed</span>
                                    <a href="{{ route('attendance.index', ['date' => $day->closed_on->toDateString()]) }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View week</a>
                                    @if($group['title'] === 'Upcoming')
                                        <button type="button" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400"
                                                @click="askEditDay(@js($day->closed_on->format('l, j F Y')), @js($day->closed_on->toDateString()), @js($day->reason), @js(route('holidays.update', $day)))">Edit</button>
                                        <button type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-800 dark:text-rose-400"
                                                @click="askReopen({{ $day->id }}, @js($day->closed_on->format('l, j F Y')), @js($day->label()), @js(route('holidays.destroy', $day)))">Reopen</button>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">{{ $group['empty'] }}</li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
    </div>


    {{-- One dialog, four jobs: reopen a day, drop a rule, rename a day, retime
         a rule. They share a shell because they ask the same shape of question
         — here is what you are about to change, confirm it — and a page with
         four modals on it is the clutter this page does not need. --}}
    <div x-show="showing" x-cloak x-transition.opacity @click="close()"
         class="fixed inset-0 z-50 grid place-items-center bg-slate-950/70 p-5 backdrop-blur-sm">
        <form @click.stop x-show="showing" x-transition.scale.origin.center
              method="POST" :action="action" role="dialog" aria-modal="true" :aria-label="title"
              class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
            @csrf
            <input type="hidden" name="_method" :value="method">

            <header class="border-b border-slate-100 px-6 py-5 dark:border-white/10">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100" x-text="title"></h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400" x-text="subtitle"></p>
            </header>

            <div class="max-h-[50vh] overflow-y-auto px-6 py-5">

                {{-- Dropping a rule is a different question with no roster in it,
                     so it gets the sentence rather than the list. --}}
                <template x-if="kind === 'rule-delete'">
                    <div class="space-y-2.5 text-sm text-slate-600 dark:text-slate-300">
                        <p>Its upcoming closed days are reopened, and any ticks they cleared come back.</p>
                        <p>Days already past are left exactly as they were recorded.</p>
                    </div>
                </template>

                <template x-if="kind === 'reopen'">
                    <div>
                        <template x-if="rows.length === 0">
                            <p class="text-sm text-slate-600 dark:text-slate-300">Nothing was scheduled on this day, so it will simply open — the column stays empty until somebody ticks it.</p>
                        </template>

                        <template x-if="rows.length > 0">
                            <div>
                                <p class="text-sm text-slate-600 dark:text-slate-300">
                                    <span class="font-semibold" x-text="restorableCount"></span>
                                    <span x-text="restorableCount === 1 ? ' child goes back' : ' children go back'"></span>
                                    on the attendance board.
                                </p>

                                <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                                    <template x-for="row in rows" :key="row.name + row.session">
                                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                            <span class="truncate" :class="row.restorable ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400 line-through dark:text-slate-500'" x-text="row.name"></span>
                                            <span class="shrink-0 text-xs" :class="row.restorable ? 'text-slate-400' : 'text-amber-600 dark:text-amber-400'"
                                                  x-text="row.restorable ? sessionLabel(row.session) : 'no longer on the roster'"></span>
                                        </li>
                                    </template>
                                </ul>

                                {{-- Named rather than glossed over: a child who has left,
                                     or whose room now splits the day into AM and PM, has no
                                     box left to tick and will not come back. --}}
                                <p x-show="lostCount > 0" x-cloak class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                                    <span x-text="lostCount"></span> cannot be put back — they have left or changed room since the day was closed.
                                </p>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Editing one closed day. The date is editable too, because
                     "the party is the 19th, not the 18th" is the same thought as
                     a typo in the name — and moving it simply reopens the one and
                     closes the other, ticks following in both directions. --}}
                <template x-if="kind === 'edit-day'">
                    <div class="space-y-4">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Date
                            <input type="date" name="closed_on" x-model="form.closed_on" required
                                   class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        </label>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Reason
                            <input type="text" name="reason" x-model="form.reason" maxlength="120" placeholder="Centre closed"
                                   class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        </label>
                        <p x-show="form.closed_on !== original.closed_on" x-cloak class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
                            Moving the day reopens <span class="font-semibold" x-text="original.closed_on"></span> — its ticks come back — and clears the new date instead.
                        </p>
                    </div>
                </template>

                {{-- Editing an annual holiday. A moving one is a calculation, not
                     a date, so there is nothing to retime by hand: Good Friday is
                     where Easter puts it, and only its name is ours to change. --}}
                <template x-if="kind === 'edit-rule'">
                    <div class="space-y-4">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                            Name
                            <input type="text" name="reason" x-model="form.reason" maxlength="120" required
                                   class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                        </label>

                        <template x-if="ruleFixed">
                            <div class="flex gap-3">
                                <label class="flex-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    Month
                                    <select name="month" x-model="form.month" class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                                        @foreach(range(1, 12) as $month)
                                            <option value="{{ $month }}">{{ \Illuminate\Support\Carbon::create(2000, $month, 1)->format('F') }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="w-24 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    Day
                                    <input type="number" name="day" x-model="form.day" min="1" max="31"
                                           class="mt-1 block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-800 dark:text-slate-100">
                                </label>
                            </div>
                        </template>

                        <p x-show="! ruleFixed" x-cloak class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-white/5 dark:text-slate-300">
                            This holiday moves each year — <span class="font-semibold" x-text="subtitle"></span>. Only its name can be changed here.
                        </p>
                    </div>
                </template>
            </div>

            <footer class="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                <button type="button" @click="close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-200 dark:text-slate-300 dark:hover:bg-white/10">Cancel</button>
                <button class="rounded-xl px-4 py-2 text-sm font-semibold text-white shadow-lg transition"
                        :class="destructive ? 'bg-rose-600 shadow-rose-500/25 hover:bg-rose-700' : 'bg-indigo-600 shadow-indigo-500/25 hover:bg-indigo-700'"
                        x-text="confirmLabel"></button>
            </footer>
        </form>
    </div>
</div>
<script>
    function holidayDialog(restores) {
        return {
            showing: false,
            kind: 'reopen',
            method: 'DELETE',
            destructive: true,
            title: '',
            subtitle: '',
            confirmLabel: 'Reopen',
            action: '',
            rows: [],
            ruleFixed: true,
            form: { closed_on: '', reason: '', month: '', day: '' },
            original: { closed_on: '' },

            askReopen(id, date, reason, action) {
                this.open({
                    kind: 'reopen', method: 'DELETE', destructive: true,
                    title: 'Reopen ' + date + '?', subtitle: reason,
                    confirmLabel: 'Reopen the day', action,
                    // Keyed by closure id, so a day with nothing cleared is an
                    // empty list rather than a missing one.
                    rows: restores[id] ?? [],
                });
            },

            askRuleDelete(reason, action) {
                this.open({
                    kind: 'rule-delete', method: 'DELETE', destructive: true,
                    title: 'Stop ' + reason + ' recurring?',
                    subtitle: 'It will no longer close a day every year.',
                    confirmLabel: 'Remove the holiday', action,
                });
            },

            askEditDay(date, closedOn, reason, action) {
                this.open({
                    kind: 'edit-day', method: 'PUT', destructive: false,
                    title: 'Edit ' + date, subtitle: 'Change the reason, or move it to another day.',
                    confirmLabel: 'Save', action,
                    form: { closed_on: closedOn, reason: reason ?? '', month: '', day: '' },
                    original: { closed_on: closedOn },
                });
            },

            askEditRule(reason, action, fixed, month, day, shape) {
                this.open({
                    kind: 'edit-rule', method: 'PUT', destructive: false,
                    title: 'Edit ' + reason, subtitle: shape,
                    confirmLabel: 'Save', action, ruleFixed: fixed,
                    form: { closed_on: '', reason: reason, month: String(month ?? ''), day: String(day ?? '') },
                });
            },

            /* One way in, so a field left set by the last dialog cannot leak
               into the next one — the reopen list in particular. */
            open(state) {
                this.rows = [];
                this.ruleFixed = true;
                this.form = { closed_on: '', reason: '', month: '', day: '' };
                this.original = { closed_on: '' };
                Object.assign(this, state);
                this.showing = true;
            },

            close() { this.showing = false; },

            get restorableCount() { return this.rows.filter(row => row.restorable).length; },
            get lostCount() { return this.rows.length - this.restorableCount; },

            sessionLabel(session) {
                return session === 'FULL' ? 'all day' : (session === 'AM' ? 'morning' : 'afternoon');
            },
        };
    }
</script>
@endsection
