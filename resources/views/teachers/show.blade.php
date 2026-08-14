@extends('layouts.app')

@section('title', $teacher->name)

@php
    use App\Models\StaffRule;

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

<section class="mt-7">
    <h2 class="text-sm font-semibold">Scheduling rules</h2>
    <p class="mt-1 text-sm text-slate-500">
        Hard rules are never broken — the scheduler will leave a room short first. Soft rules are preferences it reports on when it cannot honour them.
    </p>

    <div class="mt-4 space-y-2">
        @forelse($rules as $rule)
            <div class="glass-card flex items-start gap-3 rounded-xl px-4 py-3">
                <span class="mt-0.5 rounded-lg px-2 py-0.5 text-[10px] font-bold {{ $rule->isHard() ? 'bg-rose-100 text-rose-700' : 'bg-sky-100 text-sky-700' }}">{{ $rule->priority }}</span>
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
                <select name="rule_type" x-model="type" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    @foreach(StaffRule::types() as $type)
                        <option value="{{ $type }}" @selected(old('rule_type') === $type)>{{ str_replace('_', ' ', $type) }}</option>
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
                <input type="time" name="time_1" value="{{ old('time_1') }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('time_1')" />
            </label>

            <label class="block" x-show="uses('time_2')" x-cloak>
                <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">End time</span>
                <input type="time" name="time_2" value="{{ old('time_2') }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
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
