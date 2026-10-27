@extends('layouts.app')
@section('title', 'Staff')
@section('content')
{{-- The Staff page — Phase 5 of docs/work.md, as Staff.dc.html draws it:
     everyone on the team down the left with where they stand this minute,
     and one person on the right under Details, Rules, Attendance and Time
     off. The kiosks are not here: the clock and the door screen live in
     their own tab on the wall, and what this page decides (a rule, a day
     off, a PIN) reaches them through the data, not through a link. --}}
@include('children.partials.mock-style')
@include('staff.partials.style')
<x-kids-background />

@php
    use App\Models\StaffRule;
    use App\Models\StaffTimeOff;

    $isAdmin = auth()->user()->isAdmin();
    $tabUrl = fn (string $t) => $selected ? route('staff.show', [$selected, 'tab' => $t]) : route('staff.index', ['tab' => $t]);
    $ini = fn ($u) => $u->initials;
@endphp

<div class="ch" x-data="staffPage(@js([
        'shifts' => $timeOff['shifts'] ?? [],
        'first' => $selected?->firstName(),
        'rules' => isset($rules) ? $rules->map(fn (StaffRule $r) => [
            'id' => $r->id, 'url' => route('teachers.rules.update', [$selected, $r]),
            'rule_type' => $r->rule_type, 'priority' => $r->priority, 'day' => $r->day ?? 'ALL',
            'time_1' => StaffRule::toClock($r->time_1), 'time_2' => StaffRule::toClock($r->time_2),
            'number' => $r->number, 'value_text' => $r->value_text, 'source_note' => $r->source_note,
        ])->values() : [],
        'fields' => StaffRule::FIELDS, 'pending' => StaffRule::NOT_YET_ENFORCED,
        'roomValued' => StaffRule::ROOM_VALUED, 'staffValued' => StaffRule::STAFF_VALUED,
        'old' => ['kind' => old('kind', 'all'), 'date' => old('date', today()->toDateString()), 'until' => old('until', ''), 'from' => old('from', ''), 'to' => old('to', ''), 'reason' => old('reason', 'sick'), 'paid' => old('paid', '0'), 'note' => old('note', '')],
        'editingOff' => (int) old('editing_off', 0),
    ]))" style="display: flex; flex-direction: column; gap: 20px;">

    <header style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px;">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <span style="font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;" class="muted">Staff</span>
            <h1 style="margin: 0; font-size: 28px; font-weight: 700;">Staff</h1>
            <span style="font-size: 14px;" class="muted">Everyone on the team — their details, scheduling rules and attendance in one place.</span>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a class="btn" href="{{ route('staff.export') }}">Export</a>
            <a class="btn pri" href="{{ route('teachers.create') }}">+ Add staff</a>
        </div>
    </header>

    @if(session('success'))
        <div class="ch-card" style="padding: 12px 16px; background: #e3f5ec; border-color: #bfe6cf; color: #15803d; font-size: 14px; font-weight: 600;" data-flash>{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="ch-card" style="padding: 12px 16px; background: #ffe4e6; border-color: #fecdd3; color: #be123c; font-size: 14px; font-weight: 600;" data-flash>{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="ch-card" style="padding: 12px 16px; background: #fff7f8; border-color: #fecdd3; color: #be123c; font-size: 13.5px;" data-errors>
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">

        {{-- ---- the team ---- --}}
        <section class="ch-card" style="flex: 1 1 270px; min-width: 0; padding: 12px; display: flex; flex-direction: column; gap: 2px;" data-staff-list>
            <input class="inp" placeholder="Search staff" aria-label="Search staff" x-model="search" style="margin-bottom: 8px;">
            @foreach($people as $person)
                @php($st = $statuses[$person->id])
                <a href="{{ route('staff.show', [$person, 'tab' => $tab]) }}" class="srow {{ $selected?->id === $person->id ? 'on' : '' }}"
                   x-show="matches(@js(strtolower($person->name.' '.$person->jobRole().' '.($person->classroom ?? ''))))" data-staff-row="{{ $person->id }}">
                    <span class="av" style="background: #dcebfa; color: #1b5e91; width: 30px; height: 30px;">{{ $ini($person) }}</span>
                    <span style="display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0;">
                        <span style="font-weight: 600; font-size: 14px;">{{ $person->name }}</span>
                        <span style="font-size: 12px;" class="muted">{{ $person->employmentLabel() }} · {{ $person->classroom ?: '—' }}</span>
                    </span>
                    <span style="width: 9px; height: 9px; border-radius: 50%; background: {{ $st['dot'] }}; flex: none;" title="{{ $st['text'] }}" data-status-dot="{{ $st['key'] }}"></span>
                </a>
            @endforeach
            @if($people->isEmpty())
                <p style="padding: 14px; font-size: 13px;" class="muted">Nobody on the team yet. Add staff to start.</p>
            @endif
            @if($offSchedule->isNotEmpty())
                <div style="border-top: 1px solid #f0f1f5; margin-top: 8px; padding: 10px 8px 2px; display: flex; flex-direction: column; gap: 6px;" data-off-schedule>
                    <span class="lbl">Not on the schedule</span>
                    @foreach($offSchedule as $person)
                        <a href="{{ route('staff.show', [$person, 'tab' => $tab]) }}" style="font-size: 13px; color: #5f6676; {{ $selected?->id === $person->id ? 'font-weight: 700;' : '' }}" x-show="matches(@js(strtolower($person->name.' '.$person->jobRole())))">{{ $person->name }} · {{ $person->employment === 'INACTIVE' ? 'Inactive' : $person->jobRole() }}</a>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ---- one person ---- --}}
        @if($selected)
        <section class="ch-card" style="flex: 999 1 600px; min-width: 0; display: flex; flex-direction: column;" data-staff-record="{{ $selected->id }}">
            <div style="padding: 20px 22px 0; display: flex; flex-direction: column; gap: 12px;">
                <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <span class="av" style="width: 52px; height: 52px; font-size: 17px; background: #dcebfa; color: #1b5e91;">{{ $ini($selected) }}</span>
                        <div style="display: flex; flex-direction: column; gap: 3px;">
                            <span style="font-size: 22px; font-weight: 700;">{{ $selected->name }}</span>
                            <span style="font-size: 13px;" class="muted">{{ $selected->jobRole() }} · {{ $selected->employmentLabel() }} · {{ $selected->classroom ?: '—' }}</span>
                        </div>
                    </div>
                    <span class="pill {{ $status['tone'] }}" data-status-pill>{{ $status['text'] }}</span>
                </div>
                <nav style="display: flex; gap: 22px; border-bottom: 1px solid #e7e9f0; flex-wrap: wrap;" data-tabs>
                    <a href="{{ $tabUrl('details') }}" class="ptab {{ $tab === 'details' ? 'on' : '' }}">Details</a>
                    <a href="{{ $tabUrl('rules') }}" class="ptab {{ $tab === 'rules' ? 'on' : '' }}">Rules · {{ $rules->count() }}</a>
                    <a href="{{ $tabUrl('attendance') }}" class="ptab {{ $tab === 'attendance' ? 'on' : '' }}">Attendance</a>
                    <a href="{{ $tabUrl('off') }}" class="ptab {{ $tab === 'off' ? 'on' : '' }}">Time off · {{ $selected->timeOff()->get()->filter(fn ($o) => $o->isUpcoming())->count() }}</a>
                </nav>
            </div>

            {{-- ======== Details ======== --}}
            @if($tab === 'details')
            <form method="POST" action="{{ route('staff.update', $selected) }}" style="padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 16px;" data-details-form>
                @csrf @method('PUT')
                <div class="sec" style="padding: 18px;">
                    <div class="sech"><h2 style="font-size: 14px;">Contact</h2></div>
                    <div class="grid-fit">
                        <label class="fld"><span class="lbl">Name shown in the app</span><input class="inp" name="name" value="{{ old('name', $selected->name) }}" required></label>
                        <label class="fld"><span class="lbl">Legal name (payslips)</span><input class="inp" name="legal_name" value="{{ old('legal_name', $selected->legal_name) }}"></label>
                        <label class="fld"><span class="lbl">Mobile</span><input class="inp" name="phone" value="{{ old('phone', $selected->phone) }}"></label>
                        <label class="fld"><span class="lbl">Email</span><input class="inp" type="email" name="email" value="{{ old('email', $selected->email) }}" required></label>
                        <label class="fld"><span class="lbl">Emergency contact</span><input class="inp" name="emergency_contact" value="{{ old('emergency_contact', $selected->emergency_contact) }}" placeholder="Name · relationship"></label>
                        <label class="fld"><span class="lbl">Emergency phone</span><input class="inp" name="emergency_phone" value="{{ old('emergency_phone', $selected->emergency_phone) }}"></label>
                        <label class="fld"><span class="lbl">Getting to work</span><input class="inp" name="transport" value="{{ old('transport', $selected->transport) }}" placeholder="Own car, bus, walks…"></label>
                    </div>
                </div>
                <div class="sec" style="padding: 18px;">
                    <div class="sech"><h2 style="font-size: 14px;">Employment</h2></div>
                    <div class="grid-fit">
                        <label class="fld"><span class="lbl">Employment type</span>
                            <select class="inp" name="employment">
                                <option value="">Not set</option>
                                @foreach(StaffRule::EMPLOYMENT as $type)<option value="{{ $type }}" @selected(old('employment', $selected->employment) === $type)>{{ StaffRule::EMPLOYMENT_LABELS[$type] }}</option>@endforeach
                            </select></label>
                        <label class="fld"><span class="lbl">Role</span>
                            <select class="inp" name="job_role">
                                <option value="">Not set</option>
                                @foreach(\App\Models\User::JOB_ROLES as $option)<option value="{{ $option }}" @selected(old('job_role', $selected->job_role) === $option)>{{ $option }}</option>@endforeach
                            </select></label>
                        <label class="fld"><span class="lbl">Home room</span>
                            <select class="inp" name="classroom">
                                <option value="">—</option>
                                @foreach($rooms as $room)<option value="{{ $room }}" @selected(old('classroom', $selected->classroom) === $room)>{{ $room }}</option>@endforeach
                            </select></label>
                        <label class="fld"><span class="lbl">Start date</span><input class="inp" type="date" name="start_date" value="{{ old('start_date', $selected->start_date?->toDateString()) }}"></label>
                        <label class="fld"><span class="lbl">ASPIRE ID</span><input class="inp" name="aspire_id" value="{{ old('aspire_id', $selected->aspire_id) }}"></label>
                        <label class="fld"><span class="lbl">Last evaluation (0–5)</span><input class="inp" type="number" step="0.1" min="0" max="5" name="evaluation_score" value="{{ old('evaluation_score', $selected->evaluation_score) }}"></label>
                    </div>
                    <span style="font-size: 12.5px;" class="muted">Home room is where the schedule tries to place them first. Moving rooms during the day is done on the Schedule.</span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    {{-- The clock on the wall: what they present at it. Neither
                         is their password — see teachers.show for why. --}}
                    <div class="sec" style="padding: 18px;" data-time-clock>
                        <div class="sech"><h2 style="font-size: 14px;">Time clock</h2></div>
                        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
                            <div style="display: flex; justify-content: space-between;"><span class="muted">Staff ID</span><b>{{ $selected->staffId() }}</b></div>
                            <div style="display: flex; justify-content: space-between;"><span class="muted">Card</span><b>{{ $selected->hasCard() ? 'Issued '.$selected->card_issued_at?->format('M j, Y') : 'Not issued' }}</b></div>
                            <div style="display: flex; justify-content: space-between;"><span class="muted">4-digit PIN</span><b>{{ $selected->hasKioskPin() ? 'Set' : 'Not set' }}@if($selected->kioskIsLocked()) <span class="pill bad">Locked</span>@endif</b></div>
                        </div>
                        @if($cardReady)
                            <div style="display: flex; gap: 12px; align-items: flex-start;">
                                <img src="{{ route('staff.card.show', $selected) }}" alt="Scan code for {{ $selected->name }}" style="width: 96px; height: 96px; border: 1px solid #e7e9f0; border-radius: 10px; background: #fff; padding: 4px;">
                                <div style="display: flex; flex-direction: column; gap: 6px; font-size: 12.5px;">
                                    <span style="font-weight: 600; color: #b45309;">Print or download now — this image cannot be shown again.</span>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;"><a class="btn sm" href="{{ route('staff.card.download', $selected) }}">Download PNG</a><button type="button" class="btn sm" onclick="window.print()">Print</button></div>
                                </div>
                            </div>
                        @endif
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <button type="submit" class="btn sm" form="card-form">{{ $selected->hasCard() ? 'Reprint card' : 'Issue card' }}</button>
                            <button type="button" class="btn sm" @click="pinForm = ! pinForm">{{ $selected->hasKioskPin() ? 'Reset PIN' : 'Set PIN' }}</button>
                        </div>
                        <div x-show="pinForm" x-cloak style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;" data-pin-form>
                            <label class="fld"><span class="lbl">New PIN</span><input class="inp" form="pin-form" type="password" name="kiosk_pin" inputmode="numeric" maxlength="4" required style="width: 90px; text-align: center; letter-spacing: .3em;" placeholder="••••"></label>
                            <label class="fld"><span class="lbl">Again</span><input class="inp" form="pin-form" type="password" name="kiosk_pin_confirmation" inputmode="numeric" maxlength="4" required style="width: 90px; text-align: center; letter-spacing: .3em;" placeholder="••••"></label>
                            <button type="submit" class="btn sm pri" form="pin-form">Save PIN</button>
                        </div>
                        <span style="font-size: 12px;" class="faint">A reprint issues a new card and the old one stops working. The PIN is the fallback for the morning the card is at home.</span>
                    </div>

                    {{-- Pay: director only, and hidden until asked for, so a
                         screen shared over a shoulder shows dots. --}}
                    @if($isAdmin)
                    <div class="sec pay" style="padding: 18px;" data-pay>
                        <div class="sech"><h2 style="font-size: 14px;">Pay</h2><span class="pill must">Director only</span></div>
                        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;"><span class="muted">Pay rate</span>
                                <b x-show="! showPay">••••</b>
                                <span x-show="showPay" x-cloak style="display: inline-flex; align-items: center; gap: 4px;">$ <input class="inp" type="number" step="0.01" min="0" name="pay_rate" value="{{ old('pay_rate', $selected->pay_rate) }}" style="width: 110px;"> / hr</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;"><span class="muted">Direct deposit</span>
                                <b x-show="! showPay">••••</b>
                                <label x-show="showPay" x-cloak style="display: inline-flex; align-items: center; gap: 6px;"><input type="hidden" name="direct_deposit" value="0"><input type="checkbox" name="direct_deposit" value="1" @checked(old('direct_deposit', $selected->direct_deposit))> Yes</label>
                            </div>
                        </div>
                        <button type="button" class="btn sm" style="align-self: flex-start;" @click="showPay = ! showPay" x-text="showPay ? 'Hide' : 'Show'">Show</button>
                    </div>
                    @endif

                    <div class="sec" style="padding: 18px;" data-login>
                        <div class="sech"><h2 style="font-size: 14px;">AngelNest login</h2></div>
                        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
                            <div style="display: flex; justify-content: space-between;"><span class="muted">Can sign in</span><b>Yes · {{ $selected->role }}{{ $selected->mustChangePassword() ? ' · must pick a password' : '' }}</b></div>
                            <div style="display: flex; justify-content: space-between;"><span class="muted">Last sign-in</span><b>{{ $lastSignIn ? ($lastSignIn->isToday() ? 'Today '.$lastSignIn->format('g:i A') : ($lastSignIn->isYesterday() ? 'Yesterday' : $lastSignIn->format('M j, Y'))) : 'Never' }}</b></div>
                        </div>
                        <button type="submit" class="btn sm" style="align-self: flex-start;" form="password-form" onclick="return confirm('Email {{ $selected->firstName() }} a new temporary password? The current one stops working.')">Send a new temporary password</button>
                    </div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;"><a class="btn" href="{{ $tabUrl('details') }}">Cancel</a><button type="submit" class="btn pri">Save changes</button></div>
            </form>
            {{-- The side forms, outside the details form because a form
                 cannot hold a form; the buttons above point at them by id. --}}
            <form id="card-form" method="POST" action="{{ route('staff.card.issue', $selected) }}" onsubmit="return confirm('Issue a new card? {{ $selected->hasCard() ? 'The old one stops working.' : '' }}')">@csrf</form>
            <form id="pin-form" method="POST" action="{{ route('staff.card.pin', $selected) }}">@csrf</form>
            <form id="password-form" method="POST" action="{{ route('staff.password', $selected) }}">@csrf</form>
            @endif

            {{-- ======== Rules ======== --}}
            @if($tab === 'rules')
            <div style="padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 14px;" data-rules>
                <span style="font-size: 13px; line-height: 1.5;" class="muted"><b style="color: #1c2130;">Must</b> is never broken by the schedule. <b style="color: #1c2130;">Prefer</b> is kept when it can be and reported when it can't. Notes are for directors only.</span>
                @forelse($rules as $rule)
                    <div style="display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 1px solid #e7e9f0; border-radius: 12px;" data-rule="{{ $rule->id }}">
                        <span class="pill {{ $rule->isHard() ? 'must' : 'pref' }}" style="margin-top: 1px;">{{ $rule->isHard() ? 'Must' : 'Prefer' }}</span>
                        <div style="flex: 1; display: flex; flex-direction: column; gap: 3px; min-width: 0;">
                            <span style="font-weight: 600; font-size: 14px;">{{ $rule->describe() }}</span>
                            @if(filled($rule->source_note))<span style="font-size: 12.5px;" class="muted">{{ $rule->source_note }}</span>@endif
                            @if(in_array($rule->rule_type, StaffRule::NOT_YET_ENFORCED, true))<span style="font-size: 12px; color: #b45309; font-weight: 600;">Recorded, but the schedule does not act on this rule yet.</span>@endif
                        </div>
                        <button type="button" class="btn sm" @click="editRule({{ $rule->id }})">Edit</button>
                        <form method="POST" action="{{ route('teachers.rules.destroy', [$selected, $rule]) }}" onsubmit="return confirm('Remove this rule? The next generated schedule will stop honouring it.')">@csrf @method('DELETE')<button type="submit" class="btn sm" style="color: #be123c;">Delete</button></form>
                    </div>
                @empty
                    <div style="padding: 14px; border: 1px dashed #cfd3de; border-radius: 12px; font-size: 13px;" class="muted" data-no-rules>No rules yet — the schedule treats {{ $selected->firstName() }} as available {{ StaffRule::formatTime((int) config('daycare.default_earliest')) }} – {{ StaffRule::formatTime((int) config('daycare.close')) }} every weekday.</div>
                @endforelse

                <form method="POST" :action="rule.id ? rule.url : @js(route('teachers.rules.store', $selected))" class="dashed" data-rule-form>
                    @csrf
                    <template x-if="rule.id"><input type="hidden" name="_method" value="PUT"></template>
                    <span style="font-weight: 700; font-size: 14px;" x-text="rule.id ? 'Edit the rule' : 'Add a rule'">Add a rule</span>
                    <div class="grid-fit tight" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));">
                        <label class="fld"><span class="lbl">Rule</span>
                            <select class="inp" name="rule_type" x-model="rule.rule_type">
                                @foreach(StaffRule::GROUPS as $heading => $options)
                                    <optgroup label="{{ $heading }}">
                                        @foreach($options as $type => $label)<option value="{{ $type }}">{{ $label }}@if(in_array($type, StaffRule::NOT_YET_ENFORCED, true)) · not enforced yet @endif</option>@endforeach
                                    </optgroup>
                                @endforeach
                            </select></label>
                        <label class="fld" x-show="uses('day')"><span class="lbl">Day</span>
                            <select class="inp" name="day" x-model="rule.day">
                                @foreach(StaffRule::DAYS as $day)<option value="{{ $day }}">{{ $day === 'ALL' ? 'Every day' : $day }}</option>@endforeach
                            </select></label>
                        <label class="fld" x-show="uses('time_1')"><span class="lbl" x-text="rule.rule_type === 'FIXED_END' ? 'Ends at' : 'From'">From</span>
                            <select class="inp" name="time_1" x-model="rule.time_1"><option value="">Pick a time</option>@foreach($clockTimes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="fld" x-show="uses('time_2')"><span class="lbl">To</span>
                            <select class="inp" name="time_2" x-model="rule.time_2"><option value="">Pick a time</option>@foreach($clockTimes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="fld" x-show="uses('number')"><span class="lbl">Number</span><input class="inp" type="number" name="number" step="0.5" min="0" x-model="rule.number"></label>
                        <label class="fld" x-show="uses('value_text')"><span class="lbl" x-text="isStaffValued ? 'Can’t work with' : (isRoomValued ? 'Room' : 'Every')">Details</span>
                            <select class="inp" name="value_text" x-show="isStaffValued" :disabled="! isStaffValued" x-model="rule.value_text">
                                @forelse($colleagues as $name)<option value="{{ $name }}">{{ $name }}</option>@empty<option value="">No other teachers on staff</option>@endforelse
                            </select>
                            <select class="inp" name="value_text" x-show="isRoomValued" :disabled="! isRoomValued" x-model="rule.value_text">
                                @foreach($rooms as $room)<option value="{{ $room }}">{{ $room }}</option>@endforeach
                            </select>
                            <select class="inp" name="value_text" x-show="rule.rule_type === 'REQUIRED_HOURS'" :disabled="rule.rule_type !== 'REQUIRED_HOURS'" x-model="rule.value_text">
                                <option value="WEEKLY">Week</option><option value="BIWEEKLY">2 weeks</option>
                            </select></label>
                        <label class="fld"><span class="lbl">Strength</span>
                            <select class="inp" name="priority" x-model="rule.priority"><option value="HARD">Must</option><option value="SOFT">Prefer</option></select></label>
                        <label class="fld"><span class="lbl">Note (director only)</span><input class="inp" name="source_note" placeholder="Why" x-model="rule.source_note"></label>
                    </div>
                    <span style="font-size: 12px; color: #b45309; font-weight: 600;" x-show="unenforced" x-cloak>Heads up — this rule is stored on the record but the schedule does not read it yet.</span>
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" class="btn" x-show="rule.id" x-cloak @click="resetRule()">Cancel</button>
                        <button type="submit" class="btn pri" x-text="rule.id ? 'Save rule' : 'Add rule'">Add rule</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- ======== Attendance ======== --}}
            @if($tab === 'attendance')
            @php($att = $attendance)
            <div style="padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 16px;" data-attendance>
                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;">
                    <div style="display: flex; flex-wrap: wrap;" data-ranges>
                        @foreach(['last' => 'Last week', 'this' => 'This week', 'two' => 'Last 2 weeks', 'month' => 'This month'] as $key => $label)
                            <a href="{{ route('staff.show', [$selected, 'tab' => 'attendance', 'range' => $key]) }}" class="seg sq {{ $att['range'] === $key ? 'on' : '' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                    <form method="GET" action="{{ route('staff.show', $selected) }}" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="tab" value="attendance"><input type="hidden" name="range" value="custom">
                        {{-- Wrapped, because dates.js swaps a date input for its
                             own box, which copies the class and not the width. --}}
                        <span style="display: inline-block; width: 150px; flex: none;"><input class="inp" type="date" name="from" value="{{ $att['from'] }}" aria-label="From"></span>
                        <span class="muted">to</span>
                        <span style="display: inline-block; width: 150px; flex: none;"><input class="inp" type="date" name="to" value="{{ $att['to'] }}" aria-label="To"></span>
                        <button type="submit" class="btn">Show</button>
                        <a class="btn" href="{{ route('staff.attendance.export', [$selected, 'range' => $att['range'], 'from' => $att['from'], 'to' => $att['to']]) }}">Export</a>
                    </form>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px;" data-attendance-cards>
                    <div class="stat"><div class="l">Days worked</div><div class="n">{{ $att['days'] }}</div></div>
                    <div class="stat"><div class="l">Hours worked</div><div class="n">{{ $att['hours'] }}</div></div>
                    <div class="stat"><div class="l">Scheduled hours</div><div class="n">{{ $att['sched'] }}</div></div>
                    <div class="stat" style="background: #fffbeb;"><div class="l">Late arrivals</div><div class="n" style="color: #b45309;">{{ $att['late'] }}</div></div>
                    <div class="stat" style="background: #fff7f8;"><div class="l">Punches to fix</div><div class="n" style="color: #be123c;">{{ $att['fix'] }}</div></div>
                </div>
                <div style="overflow-x: auto; border: 1px solid #e7e9f0; border-radius: 12px;">
                    <table class="tbl">
                        <thead><tr><th>Day</th><th>Room</th><th>Scheduled</th><th>In</th><th>Out</th><th style="text-align: right;">Hours</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach($att['rows'] as $row)
                                <tr data-attendance-row="{{ $row['date'] }}">
                                    <td style="font-weight: 600;">{{ $row['day'] }}</td>
                                    <td>{{ $row['room'] }}</td>
                                    <td class="muted">{{ $row['sched'] }}</td>
                                    <td>{{ $row['in'] }}</td>
                                    <td>{{ $row['out'] }}</td>
                                    <td style="text-align: right; font-variant-numeric: tabular-nums; font-weight: 600;">{{ $row['hrs'] }}</td>
                                    <td><span class="pill {{ $row['tone'] }}">{{ $row['status'] }}</span></td>
                                    <td style="text-align: right;">
                                        @if($row['action'])<a href="{{ \App\Http\Controllers\StaffController::fixUrl($selected, $row['date']) }}" style="font-size: 13px; font-weight: 600;">{{ $row['action'] === 'fix' ? 'Fix →' : 'Edit' }}</a>@endif
                                    </td>
                                </tr>
                            @endforeach
                            @if($att['rows'] === [])
                                <tr><td colspan="8" style="text-align: center; padding: 24px;" class="muted">No working days in that range.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
                <span style="font-size: 12.5px;" class="faint">Late is more than {{ \App\Http\Controllers\StaffController::LATE_GRACE }} minutes after the scheduled start. Fix → opens the day on the timesheet, where the original punch is kept and the change is logged.</span>
            </div>
            @endif

            {{-- ======== Time off ======== --}}
            @if($tab === 'off')
            @php($off = $timeOff)
            <div style="padding: 20px 22px 24px; display: flex; flex-direction: column; gap: 16px;" data-time-off>
                <span style="font-size: 13px; line-height: 1.5;" class="muted">One-off changes for a date — sick days, vacation, appointments, leaving early, or an extra day. <b style="color: #1c2130;">Rules</b> are for things that are always true; these are only true on the dates you pick. The Schedule treats each one as a <b style="color: #1c2130;">Must</b> for that date only.</span>

                <div style="display: flex; flex-direction: column; gap: 8px;" data-coming-up>
                    <span style="font-size: 14px; font-weight: 700;">Coming up</span>
                    @forelse($off['upcoming'] as $entry)
                        @php($bar = $entry->kind === 'extra' ? [0, 0] : ($entry->kind === 'all' ? [0, 100] : ($entry->kind === 'until' ? [0, max(0, min(100, (($entry->from_minute - 420) / 660) * 100))] : ($entry->kind === 'leave' ? [max(0, min(100, (($entry->to_minute - 420) / 660) * 100)), 100] : [0, max(0, min(100, (($entry->from_minute - 420) / 660) * 100))]))))
                        <div class="offrow" data-time-off-entry="{{ $entry->id }}">
                            <div class="leaf"><b>{{ strtoupper($entry->date->format('M')) }}</b><span>{{ $entry->date->format('j') }}</span></div>
                            <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px;">
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <span style="font-weight: 700; font-size: 14.5px;">{{ $entry->describe() }}@if($entry->until) <span class="muted" style="font-weight: 500;">· to {{ $entry->until->format('D, M j') }}</span>@endif</span>
                                    <span class="pill {{ $entry->reasonTone() }}">{{ $entry->reasonLabel() }}</span>
                                    <span class="pill mut">{{ $entry->payLabel() }}</span>
                                </div>
                                <div style="position: relative; height: 10px; border-radius: 6px; background: #e3f5ec; overflow: hidden; max-width: 360px;" title="7 AM – 6 PM · striped = not available"><span class="hatch" style="position: absolute; top: 0; bottom: 0; left: {{ $bar[0] }}%; width: {{ $bar[1] - $bar[0] }}%;"></span></div>
                                @if($entry->impact)<span style="font-size: 12.5px; color: {{ $entry->impact['tone'] === 'bad' ? '#be123c' : '#5f6676' }};" data-impact>{{ $entry->impact['text'] }}</span>@endif
                                @if($entry->note)<span style="font-size: 12.5px;" class="muted">Note: {{ $entry->note }}</span>@endif
                                <span style="font-size: 12px;" class="faint">Added by {{ $entry->creator?->name ?? 'the office' }} · {{ $entry->created_at->format('M j') }}</span>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn sm" @click="editOff(@js(['id' => $entry->id, 'url' => route('staff.time-off.update', [$selected, $entry]), 'kind' => $entry->kind, 'date' => $entry->date->toDateString(), 'until' => $entry->until?->toDateString() ?? '', 'from' => StaffRule::toClock($entry->from_minute), 'to' => StaffRule::toClock($entry->to_minute), 'reason' => $entry->reason, 'paid' => $entry->paid ? '1' : '0', 'note' => $entry->note ?? '']))">Edit</button>
                                <form method="POST" action="{{ route('staff.time-off.destroy', [$selected, $entry]) }}" onsubmit="return confirm('Remove this?')">@csrf @method('DELETE')<button type="submit" class="btn sm" style="color: #be123c;">Remove</button></form>
                            </div>
                        </div>
                    @empty
                        <div style="padding: 14px; border: 1px dashed #cfd3de; border-radius: 12px; font-size: 13px;" class="muted" data-nothing-coming>Nothing coming up for {{ $selected->firstName() }}.</div>
                    @endforelse
                </div>

                <form method="POST" :action="off.id ? off.url : @js(route('staff.time-off.store', $selected))" class="dashed" style="gap: 14px;" data-time-off-form>
                    @csrf
                    <template x-if="off.id"><input type="hidden" name="_method" value="PUT"></template>
                    <input type="hidden" name="editing_off" :value="off.id || 0">
                    <span style="font-weight: 700; font-size: 14px;" x-text="off.id ? 'Edit the change' : 'Add time off or a change'">Add time off or a change</span>
                    <input type="hidden" name="kind" :value="off.kind">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px;" data-kinds>
                        @foreach(StaffTimeOff::KINDS as $key => [$label, $hint])
                            <button type="button" class="tchip" :class="off.kind === '{{ $key }}' ? 'on' : ''" @click="pickKind('{{ $key }}')" data-kind="{{ $key }}">{{ $label }}<small>{{ $hint }}</small></button>
                        @endforeach
                    </div>
                    <div class="grid-fit tight">
                        <label class="fld"><span class="lbl">Date</span><input class="inp" type="date" name="date" x-model="off.date" required></label>
                        <label class="fld"><span class="lbl">Until (date, optional)</span><input class="inp" type="date" name="until" x-model="off.until" placeholder="Same day"></label>
                        <label class="fld" x-show="off.kind === 'until' || off.kind === 'window' || off.kind === 'extra'"><span class="lbl" x-text="off.kind === 'until' ? 'Available from' : 'From'">From</span><input class="inp" type="time" name="from" x-model="off.from" step="300"></label>
                        <label class="fld" x-show="off.kind === 'leave' || off.kind === 'window' || off.kind === 'extra'"><span class="lbl" x-text="off.kind === 'leave' ? 'Leaves at' : 'To'">To</span><input class="inp" type="time" name="to" x-model="off.to" step="300"></label>
                        <label class="fld"><span class="lbl">Reason</span>
                            <select class="inp" name="reason" x-model="off.reason" :disabled="off.kind === 'extra'">
                                @foreach(StaffTimeOff::REASONS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select></label>
                        <label class="fld"><span class="lbl">Pay</span>
                            <select class="inp" name="paid" x-model="off.paid" :disabled="off.kind === 'extra'"><option value="0">Unpaid</option><option value="1">Paid (PTO / sick)</option></select></label>
                    </div>
                    <label class="fld"><span class="lbl">Note (director only)</span><input class="inp" name="note" x-model="off.note" placeholder="e.g. Doctor, bringing a note"></label>
                    <div style="display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13px; line-height: 1.5;" :style="'background:' + ({bad: '#fff7f8', warn: '#fffbeb', ok: '#f0faf5'}[preview.tone])" data-preview>
                        <span class="pill" :class="preview.tone" x-text="preview.tag"></span><span x-text="preview.text"></span>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" class="btn" @click="resetOff()" x-text="off.id ? 'Cancel' : 'Clear'">Clear</button>
                        <button type="submit" class="btn pri">Save</button>
                    </div>
                </form>

                <div style="display: flex; flex-direction: column; gap: 8px;" data-past>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 14px; font-weight: 700;">Past · {{ $off['year'] }}</span>
                        @foreach($off['pastTotals'] as $reason => $n)
                            <span class="pill {{ StaffTimeOff::REASON_TONES[$reason] ?? 'mut' }}">{{ $n }} {{ $reason === 'appointment' ? ($n === 1 ? 'appointment' : 'appointments') : strtolower(StaffTimeOff::REASONS[$reason] ?? $reason).($n === 1 ? ' day' : ' days') }}</span>
                        @endforeach
                        @if($off['past']->isNotEmpty())
                            <button type="button" class="btn sm" style="margin-left: auto;" @click="pastOpen = ! pastOpen" x-text="pastOpen ? 'Hide' : 'Show {{ $off['past']->count() }}'">Show {{ $off['past']->count() }}</button>
                        @endif
                    </div>
                    <span style="font-size: 12.5px;" class="faint">Entries move here by themselves the day after. They’re kept for payroll and records but stay folded away; earlier years are under Export.</span>
                    <div x-show="pastOpen" x-cloak style="display: flex; flex-direction: column;">
                        @foreach($off['past'] as $entry)
                            <div style="display: flex; gap: 12px; align-items: center; font-size: 13.5px; padding: 8px 4px; border-bottom: 1px solid #f0f1f5;" data-past-entry="{{ $entry->id }}">
                                <span style="width: 110px;" class="muted">{{ $entry->date->format('D, M j') }}</span><span style="font-weight: 600;">{{ $entry->describe() }}</span><span class="pill {{ $entry->reasonTone() }}">{{ $entry->reasonLabel() }}</span><span class="pill mut">{{ $entry->payLabel() }}</span>
                            </div>
                        @endforeach
                    </div>
                    @if($off['past']->isEmpty())<span style="font-size: 13px;" class="faint">None this year.</span>@endif
                </div>
            </div>
            @endif
        </section>
        @endif
    </div>
</div>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('staffPage', (opts) => ({
        search: '',
        pinForm: false,
        showPay: false,
        pastOpen: false,
        shifts: opts.shifts || {},
        first: opts.first || 'They',
        rules: opts.rules || [],
        fields: opts.fields || {},
        pending: opts.pending || [],
        rule: { id: null, url: '', rule_type: 'AVAILABLE_WINDOW', priority: 'HARD', day: 'ALL', time_1: '', time_2: '', number: '', value_text: '', source_note: '' },
        off: { id: null, url: '', kind: 'all', date: '', until: '', from: '', to: '', reason: 'sick', paid: '0', note: '' },

        init() {
            const o = opts.old || {};
            this.off = { ...this.off, ...o };
            if (opts.editingOff) { this.off.id = opts.editingOff; }
            if (this.off.kind === 'extra') { this.off.reason = 'extra'; }
        },

        matches(text) { return text.includes(this.search.toLowerCase().trim()); },

        /* ---- rules ---- */
        uses(field) { return (this.fields[this.rule.rule_type] || []).includes(field); },
        get isRoomValued() { return (opts.roomValued || []).includes(this.rule.rule_type); },
        get isStaffValued() { return (opts.staffValued || []).includes(this.rule.rule_type); },
        get unenforced() { return this.pending.includes(this.rule.rule_type); },
        editRule(id) {
            const r = this.rules.find(x => x.id === id);
            if (! r) return;
            this.rule = { ...r, number: r.number ?? '', value_text: r.value_text ?? '', source_note: r.source_note ?? '' };
            this.$nextTick(() => document.querySelector('[data-rule-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        },
        resetRule() { this.rule = { id: null, url: '', rule_type: 'AVAILABLE_WINDOW', priority: 'HARD', day: 'ALL', time_1: '', time_2: '', number: '', value_text: '', source_note: '' }; },

        /* ---- time off ---- */
        pickKind(kind) {
            this.off.kind = kind;
            if (kind === 'until' && ! this.off.from) this.off.from = '15:00';
            if (kind === 'leave' && ! this.off.to) this.off.to = '12:00';
            if ((kind === 'window' || kind === 'extra')) { if (! this.off.from) this.off.from = '09:00'; if (! this.off.to) this.off.to = '15:00'; }
            if (kind === 'extra') this.off.reason = 'extra';
            else if (this.off.reason === 'extra') this.off.reason = 'sick';
        },
        editOff(entry) {
            this.off = { ...entry };
            this.$nextTick(() => document.querySelector('[data-time-off-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        },
        resetOff() { this.off = { id: null, url: '', kind: 'all', date: this.off.date, until: '', from: '', to: '', reason: 'sick', paid: '0', note: '' }; },
        tmin(t) { const m = /^(\d{1,2}):(\d{2})/.exec(t || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; },
        clock(m) { const h = Math.floor(m / 60), mm = m % 60, hh = h % 12 === 0 ? 12 : h % 12; return hh + ':' + String(mm).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM'); },
        get preview() {
            const date = this.off.date;
            const label = date ? new Date(date + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : 'that day';
            const s = this.shifts[date];
            if (this.off.kind === 'extra') return { tag: 'Schedule', tone: 'ok', text: `${this.first} will show as available on ${label} so you can add a shift.` };
            if (! s) return { tag: 'Schedule', tone: 'ok', text: 'Not on the schedule that day — nothing to fix.' };
            const was = this.clock(s.start) + ' – ' + this.clock(s.end), room = s.room || 'their room';
            if (this.off.kind === 'all') return { tag: 'Conflict', tone: 'bad', text: `Scheduled ${was} in ${room} on ${label}. Saving removes the shift and the Schedule shows who could cover.` };
            const f = this.tmin(this.off.from), t = this.tmin(this.off.to);
            const whole = { tag: 'Conflict', tone: 'bad', text: `Scheduled ${was}. None of it is left — ${room} needs cover for the whole shift.` };
            if (this.off.kind === 'until') return f !== null && f >= s.end ? whole : (f !== null && f > s.start
                ? { tag: 'Conflict', tone: 'bad', text: `Scheduled ${was}. Only ${this.clock(f)} – ${this.clock(s.end)} can stay — ${room} needs cover ${this.clock(s.start)} – ${this.clock(f)}.` }
                : { tag: 'OK', tone: 'ok', text: 'Already starts after that — no change.' });
            if (this.off.kind === 'leave') return t !== null && t <= s.start ? whole : (t !== null && t < s.end
                ? { tag: 'Conflict', tone: 'bad', text: `Scheduled ${was}. Shift will end at ${this.clock(t)} — ${room} needs cover ${this.clock(t)} – ${this.clock(s.end)}.` }
                : { tag: 'OK', tone: 'ok', text: 'Already leaves before that — no change.' });
            if (f !== null && t !== null && (t <= s.start || f >= s.end)) return { tag: 'Conflict', tone: 'bad', text: `Scheduled ${was}. None of it fits the window — ${room} needs cover for the whole shift.` };
            return { tag: 'Check', tone: 'warn', text: `Scheduled ${was}. Any part outside ${f !== null ? this.clock(f) : '…'} – ${t !== null ? this.clock(t) : '…'} is flagged on the Schedule.` };
        },
    }));
});
</script>
@endonce
@endsection
