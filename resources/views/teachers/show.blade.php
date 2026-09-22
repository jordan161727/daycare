@extends('layouts.app')

@section('title', $teacher->name)

@php
    use App\Models\StaffRule;

    /*
     * The times a rule may be set to, as H:i => "7:00 AM".
     *
     * All three numbers come from config rather than the view, so a centre
     * that opens at six gets six o'clock without anybody editing markup.
     *
     * Half-hourly by default. Eleven hours at a quarter of an hour came to
     * forty-five options — a list to hunt through rather than read, and the
     * quarters were nearly never the one wanted. Twenty-three fit on a screen.
     */
    $clockTimes = collect(range(config('daycare.open'), config('daycare.close'), config('daycare.time_step', 30)))
        ->mapWithKeys(fn (int $minutes) => [
            sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) =>
                \Illuminate\Support\Carbon::today()->addMinutes($minutes)->format('g:i A'),
        ]);

    $profile = [
        'Employment' => $teacher->employment,
        'Room' => $teacher->title,
        'Legal name (payroll)' => $teacher->legal_name,
        'Phone' => $teacher->phone,
        'Email' => $teacher->email,
        'Emergency contact' => $teacher->emergency_contact,
        'Emergency phone' => $teacher->emergency_phone,
        'Started' => $teacher->start_date?->format('M j, Y'),
        'Date of birth' => $teacher->dob?->format('M j, Y'),
        'Transport' => $teacher->transport,
        'ASPIRE ID' => $teacher->aspire_id,
        'Direct deposit' => $teacher->direct_deposit ? 'Yes' : 'No',
        'Pay rate' => $teacher->pay_rate ? '$'.number_format((float) $teacher->pay_rate, 2).' / hr' : null,
        'Evaluation' => $teacher->evaluation_score,
    ];
@endphp

@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <x-page-header
        :title="$teacher->name"
        :subtitle="trim(($teacher->employment ?: 'No employment type').' · '.$rules->count().' rule'.($rules->count() === 1 ? '' : 's').', '.$rules->where('priority', 'HARD')->count().' hard')" />
    <div class="flex w-fit gap-2">
        <a href="{{ route('teachers.index') }}" class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Back to roster</a>
        <a href="{{ route('teachers.edit', $teacher) }}" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Edit details</a>
    </div>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
@endif

<section class="glass-card mt-7 rounded-2xl p-6">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Staff record</h2>
    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($profile as $label => $value)
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                <dd class="text-sm {{ filled($value) ? '' : 'text-slate-400' }}">{{ filled($value) ? $value : '—' }}</dd>
            </div>
        @endforeach
    </dl>
    @if(filled($teacher->staff_notes))
        <p class="mt-5 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">{{ $teacher->staff_notes }}</p>
    @endif
</section>

{{-- What this person presents at the clock on the wall.

     Two credentials, and neither is their password: the screen is in a lobby
     with a queue behind it, and a password typed there stops being one. The
     card is the everyday way in; the PIN is for the morning it is in a coat
     pocket at home. --}}
<section class="glass-card mt-7 rounded-2xl p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Time clock card</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">What {{ $teacher->firstName() }} presents at the clock. Neither is their password &mdash; the screen is in a lobby with a queue behind it.</p>
        </div>

        {{-- The three facts, on one line rather than as a column of labelled
             rows. They are short values a director glances at, not a record to
             be read down. --}}
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Staff ID</dt>
                <dd class="font-semibold tabular-nums">{{ $teacher->staffId() }}</dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Card</dt>
                <dd>{{ $teacher->hasCard() ? 'Issued '.$teacher->card_issued_at?->format('M j, Y') : '—' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Clock PIN</dt>
                <dd>
                    {{ $teacher->hasKioskPin() ? 'Set' : 'Not set' }}
                    @if($teacher->kioskIsLocked())
                        <span class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">Locked</span>
                    @endif
                </dd>
            </div>
        </dl>
    </div>

    <div class="mt-5 grid gap-5 border-t border-slate-100 pt-5 md:grid-cols-2 dark:border-white/10">

        {{-- The card. Only printable for a few minutes after issuing, because
             the code behind it is hashed on the way in and cannot be read
             back — see StaffCardController. --}}
        <div class="flex items-start gap-4">
            @if($cardReady)
                <div class="shrink-0 rounded-xl bg-white p-2 ring-1 ring-slate-200">
                    <img src="{{ route('staff.card.show', $teacher) }}" alt="Scan code for {{ $teacher->name }}" class="h-28 w-28">
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-bold">{{ $teacher->name }}</p>
                    <p class="text-xs text-slate-500">
                        {{-- The room once, not twice: a title that is the room
                             name already said it. --}}
                        {{ collect([$teacher->jobRole(), $teacher->classroom])->filter()->unique()->implode(' · ') ?: 'No room' }}
                    </p>
                    <p class="text-xs tabular-nums text-slate-400">{{ $teacher->staffId() }}</p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('staff.card.download', $teacher) }}" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">Download PNG</a>
                        <button type="button" onclick="window.print()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Print</button>
                    </div>
                    <p class="mt-2 text-[11px] font-semibold text-amber-700 dark:text-amber-300">Print or download now &mdash; this image cannot be shown again.</p>
                    <form method="POST" action="{{ route('staff.card.issue', $teacher) }}" class="mt-2">
                        @csrf
                        <button class="text-[11px] font-semibold text-slate-400 underline-offset-2 hover:underline">Issue a new card instead</button>
                    </form>
                </div>
            @else
                <div class="min-w-0">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        @if($teacher->hasCard())
                            A card was issued on {{ $teacher->card_issued_at?->format('M j, Y') }}. The image is not kept, so printing another means issuing a new one &mdash; which stops the old card working.
                        @else
                            No card yet. Issue one and it can be printed for the next few minutes.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('staff.card.issue', $teacher) }}" class="mt-3">
                        @csrf
                        <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                            {{ $teacher->hasCard() ? 'Issue a new card' : 'Issue card' }}
                        </button>
                    </form>
                </div>
            @endif
        </div>

        {{-- The fallback, for the morning the card is in a coat at home. --}}
        <form method="POST" action="{{ route('staff.card.pin', $teacher) }}" class="md:border-l md:border-slate-100 md:pl-5 md:dark:border-white/10">
            @csrf
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">4-digit clock PIN</p>
            <div class="mt-2 flex flex-wrap items-end gap-2">
                <label>
                    <span class="sr-only">Clock PIN</span>
                    <input type="password" name="kiosk_pin" inputmode="numeric" maxlength="4" required class="cs-input w-24 text-center tracking-[0.3em]" placeholder="••••">
                </label>
                <label>
                    <span class="sr-only">Confirm PIN</span>
                    {{-- Confirmed, because a mistyped PIN is not discovered
                         until somebody is at the clock unable to start. --}}
                    <input type="password" name="kiosk_pin_confirmation" inputmode="numeric" maxlength="4" required class="cs-input w-24 text-center tracking-[0.3em]" placeholder="••••">
                </label>
                <button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                    {{ $teacher->hasKioskPin() ? 'Replace' : 'Set PIN' }}
                </button>
            </div>
            <x-input-error :messages="$errors->get('kiosk_pin')" />
            <p class="mt-2 text-[11px] text-slate-400">Scan the card at the clock to punch in or out. The PIN is the fallback.</p>
        </form>
    </div>
</section>

<section class="mt-7">
    <h2 class="text-sm font-semibold">Scheduling rules</h2>
    {{-- "Hard" and "Soft" are the scheduler's words. What a director needs to
         know is what happens when the two disagree, which is what this says. --}}
    <p class="mt-1 text-sm text-slate-500">
        These shape the generated week. A <span class="font-semibold text-rose-700 dark:text-rose-400">must</span> is never broken &mdash; the scheduler leaves a room short first. A <span class="font-semibold text-sky-700 dark:text-sky-400">prefer</span> is honoured when it can be, and reported when it cannot.
    </p>

    <div class="mt-4 space-y-2">
        @forelse($rules as $rule)
            <div class="glass-card flex items-start gap-3 rounded-xl px-4 py-3">
                {{-- The word rather than the code: HARD/SOFT is what the
                     solver calls it, "Must"/"Prefer" is what it means. --}}
                <span class="mt-0.5 rounded-lg px-2 py-0.5 text-[10px] font-bold {{ $rule->isHard() ? 'bg-rose-100 text-rose-700' : 'bg-sky-100 text-sky-700' }}">{{ $rule->isHard() ? 'Must' : 'Prefer' }}</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ $rule->describe() }}</p>
                    @if(filled($rule->source_note))
                        <p class="mt-0.5 text-xs text-slate-500">{{ $rule->source_note }}</p>
                    @endif
                    {{-- A rule nobody acts on has to say so. Believing a
                         constraint is enforced is worse than not having it. --}}
                    @if(in_array($rule->rule_type, StaffRule::NOT_YET_ENFORCED, true))
                        <p class="mt-1 text-xs font-medium text-amber-700">Recorded, but the scheduler does not act on this rule yet.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('teachers.rules.destroy', [$teacher, $rule]) }}" onsubmit="return confirm('Remove this rule? The next generated schedule will stop honouring it.')">
                    @csrf @method('DELETE')
                    <button class="text-xs font-semibold text-rose-600 hover:text-rose-800">Remove</button>
                </form>
            </div>
        @empty
            <p class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500 dark:border-white/10">
                No rules yet. Without any, the scheduler will treat {{ $teacher->firstName() }} as available all week from opening time.
            </p>
        @endforelse
    </div>
</section>

{{-- The form shows only the inputs the chosen rule type uses — a half-filled
     rule is one the director believes is enforced and the solver ignores. --}}
<section
    class="glass-card mt-7 rounded-2xl p-6"
    x-data="{
        type: '{{ old('rule_type', 'AVAILABLE_WINDOW') }}',
        fields: {{ Js::from(StaffRule::FIELDS) }},
        hints: {{ Js::from(StaffRule::HINTS) }},
        pending: {{ Js::from(StaffRule::NOT_YET_ENFORCED) }},
        get unenforced() { return this.pending.includes(this.type) },
        uses(field) { return (this.fields[this.type] || []).includes(field) },
        get hint() { return this.hints[this.type] || '' },
        get roomValued() { return {{ Js::from(StaffRule::ROOM_VALUED) }}.includes(this.type) },
        get staffValued() { return {{ Js::from(StaffRule::STAFF_VALUED) }}.includes(this.type) },
    }">
    <h2 class="text-sm font-semibold">Add a rule</h2>

    <form method="POST" action="{{ route('teachers.rules.store', $teacher) }}" class="mt-4">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Rule type</span>
                {{-- Grouped and in plain English. Seventeen shouted constants
                     in one list is a picker people choose from by guessing;
                     the headings are the question each rule answers, which is
                     how somebody arrives at this form thinking. --}}
                <select name="rule_type" x-model="type" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach(StaffRule::GROUPS as $heading => $options)
                        <optgroup label="{{ $heading }}">
                            @foreach($options as $type => $label)
                                <option value="{{ $type }}" @selected(old('rule_type') === $type)>
                                    {{ $label }}@if(in_array($type, StaffRule::NOT_YET_ENFORCED, true)) &middot; not enforced yet @endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('rule_type')" />
            </label>

            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Priority</span>
                <select name="priority" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach(StaffRule::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(old('priority', 'HARD') === $priority)>{{ $priority }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block" x-show="uses('day')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Day</span>
                <select name="day" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach(StaffRule::DAYS as $day)
                        <option value="{{ $day }}" @selected(old('day', 'ALL') === $day)>{{ $day === 'ALL' ? 'Every day' : $day }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('day')" />
            </label>

            <label class="block" x-show="uses('time_1')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Time</span>
                {{-- A list of real times rather than a native time
                     spinner. The spinner asks for hours, minutes and AM/PM in
                     three separate hits and is genuinely awkward with a mouse;
                     a rule is nearly always on a quarter hour inside the
                     centre's own day, so those are the only times offered. --}}
                <select name="time_1" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <option value="">Pick a time</option>
                    @foreach($clockTimes as $value => $label)
                        <option value="{{ $value }}" @selected(old('time_1') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('time_1')" />
            </label>

            <label class="block" x-show="uses('time_2')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">End time</span>
                <select name="time_2" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <option value="">Pick a time</option>
                    @foreach($clockTimes as $value => $label)
                        <option value="{{ $value }}" @selected(old('time_2') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('time_2')" />
            </label>

            <label class="block" x-show="uses('number')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Number</span>
                <input type="number" name="number" step="0.5" min="0" value="{{ old('number') }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('number')" />
            </label>

            <label class="block" x-show="uses('value_text')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500"
                      x-text="staffValued ? 'Cannot work with' : (roomValued ? 'Room' : 'Value')"></span>

                {{-- Three inputs share the name because the value means three
                     different things. Each is disabled unless it is the one on
                     show — a hidden-but-enabled select still posts, and the
                     last one in the document would win. --}}
                <select name="value_text" x-show="staffValued" :disabled="! staffValued" x-cloak class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @forelse($colleagues as $name)
                        <option value="{{ $name }}" @selected(old('value_text') === $name)>{{ $name }}</option>
                    @empty
                        <option value="">No other teachers on staff</option>
                    @endforelse
                </select>

                <select name="value_text" x-show="roomValued" :disabled="! roomValued" x-cloak class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach($rooms as $room)
                        <option value="{{ $room }}" @selected(old('value_text') === $room)>{{ $room }}</option>
                    @endforeach
                </select>

                <select name="value_text" x-show="type === 'REQUIRED_HOURS'" :disabled="type !== 'REQUIRED_HOURS'" x-cloak class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <option value="WEEKLY" @selected(old('value_text', 'WEEKLY') === 'WEEKLY')>WEEKLY</option>
                    <option value="BIWEEKLY" @selected(old('value_text') === 'BIWEEKLY')>BIWEEKLY</option>
                </select>

                <x-input-error :messages="$errors->get('value_text')" />
            </label>

            <label class="block sm:col-span-2 lg:col-span-3">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Why this rule exists (optional)</span>
                <input name="source_note" value="{{ old('source_note') }}" placeholder="e.g. UPK contract hours" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <p class="mt-1 text-xs text-slate-500">Shown beside the rule, so whoever reads it next knows whether it still applies.</p>
            </label>
        </div>

        <p class="mt-4 text-xs text-slate-500" x-show="hint" x-text="hint" x-cloak></p>
        <p class="mt-2 text-xs font-medium text-amber-700" x-show="unenforced" x-cloak>
            Heads up — this rule is stored on the record but the schedule generator does not read it yet. It will not change the roster.
        </p>

        <div class="mt-6 flex justify-end">
            <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Add rule</button>
        </div>
    </form>
</section>
@endsection
