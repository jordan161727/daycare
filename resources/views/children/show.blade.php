@extends('layouts.app')
@section('title', $child->first_name.' '.$child->last_name)
@section('content')
{{-- The child record — Phase 2.2 and 2.3 of docs/work.md, as
     ChildProfile.dc.html draws it: the header with the status and how far
     the record is from done, the lifecycle (start, cancel, withdraw,
     re-enroll, archive, restore), and the cards — Overview, Family &
     pick-up, Health, Enrollment history, Notes & comments, To finish,
     Attendance this month, Documents, Record.

     Every card that can change saves in place (ChildRecordController) and
     comes back here; nothing on this page writes over Teacher Attendance. --}}
@include('children.partials.mock-style')
<x-kids-background />

@php($isAdmin = auth()->user()->isAdmin())
@php($digits = fn (?string $number) => preg_replace('/[^0-9+]/', '', (string) $number))
@php($roomColours = ['Infant' => '#8cc4b2', 'Transition' => '#e0b577', 'Toddler' => '#b3a3dc', 'PreK' => '#9dc585', 'UPK-4' => '#e8a0b8', 'School Age' => '#92b4df'])
@php($missing = $child->missingItems())
@php($pct = $child->completeness())
@php($alerts = $child->alertList())
@php($people = $child->personLinks->sortBy([fn ($a, $b) => ($a->priority ?? 99) <=> ($b->priority ?? 99), fn ($a, $b) => ($a->is_guardian ? 0 : 1) <=> ($b->is_guardian ? 0 : 1)])->values())
@php($guardianCount = $people->where('is_guardian', true)->count())
@php($emergencyCount = $people->where('is_emergency', true)->count())
@php($tone = ['Active' => 'ok', 'Pending' => 'info', 'Inactive' => 'mut', 'Archived' => 'mut'][$child->status] ?? 'mut')
@php($roomSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $child->classroom ?? '')))
@php($hours = $child->hoursPerWeek())
@php($medical = $child->document('medical_statement'))
@php($roomEvents = $statusChanges->filter(fn ($c) => in_array($c->event, ['room', 'started', 'reenrolled'], true) || ($c->event === 'status' && $c->from_status === null)))
@php($allNotes = collect()
    ->concat($teacherComments->map(fn ($c) => ['at' => $c->date ?? $c->created_at, 'text' => $c->body, 'by' => ($c->author?->name ?? 'Staff').' · teacher, at check-in', 'kind' => $c->type() === 'health' ? 'Health' : 'Teacher']))
    ->concat($officeNotes->map(fn ($n) => ['at' => $n->created_at, 'text' => $n->body, 'by' => ($n->author?->name ?? 'Office').' · office', 'kind' => $n->kind === 'health' ? 'Health' : 'Office']))
    ->sortByDesc('at')->values())
@php($noteCounts = ['All' => $allNotes->count(), 'Teacher' => $allNotes->where('kind', 'Teacher')->count(), 'Office' => $allNotes->where('kind', 'Office')->count(), 'Health' => $allNotes->where('kind', 'Health')->count()])

<div class="ch" style="display: flex; flex-direction: column; gap: 16px;"
     x-data="childRecord({ childId: @js($child->id), csrf: @js(csrf_token()), open: @js(session('open')) })"
     @keydown.escape.window="menu = false; statusMenu = false">

    <a href="{{ $back }}" style="font-size: 13px; font-weight: 600;">← {{ str_starts_with($back, route('children.index')) ? 'Children' : 'Back' }}</a>

    @if(session('success'))<div style="border-radius: 12px; border: 1px solid #b9e4cc; background: #e3f5ec; padding: 10px 14px; font-size: 13.5px; font-weight: 500; color: #166534;">{{ session('success') }}</div>@endif
    @if($errors->any())<div style="border-radius: 12px; border: 1px solid #fbc4cc; background: #ffe4e6; padding: 10px 14px; font-size: 13.5px; color: #be123c;">{{ $errors->first() }}</div>@endif

    {{-- ===== Header ===== --}}
    <section class="ch-card" style="padding: 20px 22px 0; display: flex; flex-direction: column; gap: 14px;" data-record-header>
        <div style="display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap;">
            <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; flex: none;">
                @php($photo = $child->photoUrl())
                @if($photo)
                    {{-- Larger on a tap: the photograph is how a face gets matched to a name. --}}
                    <div x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" @click="open = true" class="group relative block" title="Open the photo">
                            <img src="{{ $photo }}" alt="{{ $child->first_name }} {{ $child->last_name }}" class="h-[88px] w-[88px] rounded-[22px] object-cover">
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity @click="open = false" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/85 p-6 backdrop-blur-sm">
                            <img @click.stop src="{{ $photo }}" alt="{{ $child->first_name }} {{ $child->last_name }}" class="max-h-[85vh] max-w-[92vw] rounded-2xl object-contain shadow-2xl">
                            <button type="button" @click="open = false" class="absolute right-5 top-5 grid h-10 w-10 place-items-center rounded-full bg-white/10 text-lg text-white ring-1 ring-white/25" aria-label="Close">✕</button>
                        </div>
                    </div>
                @else
                    <x-child-avatar :child="$child" size="h-[88px] w-[88px]" shape="rounded-[22px]" />
                @endif
                <a href="{{ route('children.edit', $child) }}" style="font-size: 12px; font-weight: 600;">Change photo</a>
            </div>
            <div style="flex: 1 1 360px; display: flex; flex-direction: column; gap: 8px; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h1 style="margin: 0; font-size: 26px; font-weight: 700;">{{ $child->first_name }} {{ $child->last_name }}@if(filled($child->nickname)) <span style="font-weight: 500; color: #8a90a0; font-size: 20px;">({{ $child->nickname }})</span>@endif</h1>

                    {{-- The status, and — for the director — the way to change it. --}}
                    <span style="position: relative; display: inline-flex;">
                        @if($isAdmin)
                            <button type="button" class="pill {{ $tone }}" style="border: 0; cursor: pointer; font: inherit; font-size: 12px; font-weight: 600;" @click="statusMenu = ! statusMenu; menu = false" title="Change status" data-status-pill>{{ \App\Models\Child::statusLabel($child->status) }} ▾</button>
                            <div x-show="statusMenu" x-cloak @click.outside="statusMenu = false" class="menu" style="left: 0; right: auto; min-width: 260px;">
                                <span style="font-size: 11px; font-weight: 700; color: #8a90a0; text-transform: uppercase; letter-spacing: .06em; padding: 6px 12px 4px;">Status: {{ \App\Models\Child::statusLabel($child->status) }}</span>
                                @if($child->isPending())
                                    <button type="button" class="mi" style="color: #15803d;" @click="panel = 'start'; statusMenu = false"><b style="font-weight: 600;">Start enrollment</b><br><span style="font-size: 12px; color: #8a90a0;">Pick the first day → Active</span></button>
                                    <button type="button" class="mi" style="color: #be123c;" @click="panel = 'cancel'; statusMenu = false"><b style="font-weight: 600;">Cancel enrollment</b><br><span style="font-size: 12px; color: #8a90a0;">Never started → Withdrawn</span></button>
                                @elseif($child->status === 'Active')
                                    <button type="button" class="mi" style="color: #be123c;" @click="panel = 'withdraw'; statusMenu = false"><b style="font-weight: 600;">Withdraw…</b><br><span style="font-size: 12px; color: #8a90a0;">Last day, reason and note → Withdrawn</span></button>
                                @elseif($child->isWithdrawn())
                                    <form method="POST" action="{{ route('children.reenroll', $child) }}">@csrf<button type="submit" class="mi" style="color: #15803d;"><b style="font-weight: 600;">Re-enroll</b><br><span style="font-size: 12px; color: #8a90a0;">Same LAN and family → Active</span></button></form>
                                    <button type="button" class="mi" @click="panel = 'archive'; statusMenu = false"><b style="font-weight: 600;">Archive…</b><br><span style="font-size: 12px; color: #8a90a0;">File away → Archived</span></button>
                                @elseif($child->isArchived())
                                    <form method="POST" action="{{ route('children.unarchive', $child) }}">@csrf<button type="submit" class="mi"><b style="font-weight: 600;">Restore</b><br><span style="font-size: 12px; color: #8a90a0;">Back to Withdrawn</span></button></form>
                                @endif
                            </div>
                        @else
                            <span class="pill {{ $tone }}" data-status-pill>{{ \App\Models\Child::statusLabel($child->status) }}</span>
                        @endif
                    </span>

                    <span style="display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; margin-left: 6px;" class="muted" title="{{ $missing === [] ? 'Nothing missing' : 'Missing: '.strtolower(implode(', ', $missing)) }}" data-record-complete>
                        <span class="sw-bar"><span style="display: block; height: 100%; width: {{ $pct }}%; background: {{ $pct >= 90 ? '#15803d' : ($pct >= 70 ? '#f59e0b' : '#be123c') }};"></span></span>Record {{ $pct }}% complete
                    </span>
                </div>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; font-size: 13.5px; align-items: center;" class="muted">
                    <span>LAN {{ $child->lan }}</span><span>·</span>
                    <span style="display: inline-flex; align-items: center; gap: 6px;"><span style="width: 9px; height: 9px; border-radius: 3px; background: {{ $roomColours[$child->classroom] ?? '#c3cbe0' }};"></span>{{ $child->classroom ?: 'No room yet' }}</span><span>·</span>
                    <span>{{ $child->ageInWords() ?? '—' }}@if($child->ageLabel()) ({{ $child->ageLabel() }})@endif</span><span>·</span>
                    <span>{{ $child->enrolled_on ? ($child->isPending() ? 'Starts ' : 'Enrolled ').$child->enrolled_on->format('M j, Y') : ($child->isPending() ? 'No first day agreed yet' : 'Enrolled date not set') }}</span>
                </div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    @foreach($alerts as $alert)
                        <span class="pill {{ $alert['type'] }}">! {{ $alert['label'] }}: {{ $alert['text'] }}@if($alert['severity']) · {{ $alert['severity'] }}@endif</span>
                    @endforeach
                    @foreach($missing as $item)
                        <span class="pill miss">{{ $item }}</span>
                    @endforeach
                </div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a class="btn" href="{{ route('messages.index', ['child' => $child->id]) }}">Message parents</a>
                <a class="btn" href="{{ route('check-in.index') }}?{{ $roomSlug }}">Sign in / out</a>
                <div style="position: relative;">
                    <button type="button" class="btn" aria-label="More" @click="menu = ! menu; statusMenu = false">⋯</button>
                    <div x-show="menu" x-cloak @click.outside="menu = false" class="menu">
                        <a class="mi" href="{{ route('children.edit', $child) }}">Edit details</a>
                        @if($isAdmin)
                            <button type="button" class="mi" @click="menu = false; roomForm = true; sec = 'overview'">Move to another room</button>
                            @if($child->status === 'Active')<button type="button" class="mi" style="color: #be123c;" @click="menu = false; panel = 'withdraw'">Withdraw…</button>@endif
                            @if($child->isWithdrawn())
                                <form method="POST" action="{{ route('children.reenroll', $child) }}">@csrf<button type="submit" class="mi">Re-enroll</button></form>
                                <button type="button" class="mi" @click="menu = false; panel = 'archive'">Archive…</button>
                            @endif
                            @if($child->isPending())<button type="button" class="mi" @click="menu = false; panel = 'start'">Start enrollment</button>@endif
                            @if($child->isArchived())<form method="POST" action="{{ route('children.unarchive', $child) }}">@csrf<button type="submit" class="mi">Restore</button></form>@endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div style="display: flex; gap: 22px; border-top: 1px solid #f0f1f5; flex-wrap: wrap;" data-record-anchors>
            @foreach(['all' => 'All', 'overview' => 'Overview', 'family' => 'Family & pick-up', 'health' => 'Health', 'history' => 'Enrollment history', 'notes' => 'Notes & comments', 'attendance' => 'Attendance', 'docs' => 'Documents'] as $key => $label)
                <button type="button" class="anchor" :class="sec === '{{ $key }}' && 'on'" @click="sec = '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>
    </section>

    {{-- ===== Warnings ===== --}}
    @if($guardianCount === 0)
        <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; background: #fff1f2; border: 1px solid #fbc4cc; color: #9f1239; font-size: 14px; flex-wrap: wrap;" data-warning="guardian">
            <b>No legal guardian on file.</b><span>Nobody on {{ $child->first_name }}'s record is marked as a guardian.</span><button type="button" class="btn sm" style="margin-left: auto;" @click="sec = 'family'; addPerson = true">Add a person</button>
        </div>
    @endif
    @if($emergencyCount === 0)
        <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; background: #fff7e6; border: 1px solid #f6d79a; color: #7a4a0c; font-size: 14px; flex-wrap: wrap;" data-warning="emergency">
            <b>No emergency contact.</b><span>This is worth chasing today.</span><button type="button" class="btn sm" style="margin-left: auto;" @click="sec = 'family'; addPerson = true">Add a person</button>
        </div>
    @endif

    {{-- ===== Lifecycle panels (director) ===== --}}
    @if($isAdmin)
        @if($child->status === 'Active')
            <section class="sec" x-show="panel === 'withdraw'" x-cloak style="border-color: #f3a7b3; box-shadow: 0 0 0 3px #ffe4e6;" data-panel="withdraw">
                <div class="sech"><h2>Withdraw {{ $child->first_name }}</h2><span style="font-size: 12.5px;" class="faint">Nothing is deleted — the record, attendance and notes all stay</span></div>
                <form method="POST" action="{{ route('children.withdraw', $child) }}" style="display: flex; flex-direction: column; gap: 12px;">
                    @csrf
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <label class="fld"><span class="lbl">Last day attended</span><input class="inp" type="date" name="last_day" value="{{ old('last_day', today()->toDateString()) }}" required></label>
                        <label class="fld"><span class="lbl">Reason</span><select class="inp" name="reason">@foreach(\App\Models\Child::WITHDRAWAL_REASONS as $reason)<option @selected(old('reason') === $reason)>{{ $reason }}</option>@endforeach</select></label>
                        <div class="fld"><span class="lbl">Could {{ $child->first_name }} come back?</span><div style="display: flex; gap: 6px;" x-data="{ back: @js(old('could_return', 'Yes')) }">@foreach(\App\Models\Child::COULD_RETURN as $option)<button type="button" class="fchip" :class="back === @js($option) && 'on'" @click="back = @js($option)">{{ $option }}</button>@endforeach<input type="hidden" name="could_return" :value="back"></div></div>
                    </div>
                    <label class="fld"><span class="lbl">Note — shows on the DSS page and in the enrollment history</span><input class="inp" name="note" value="{{ old('note') }}" placeholder="e.g. Moving to Cheektowaga in November, mom gave 2 weeks' notice"></label>
                    <div class="panel" style="font-size: 13px; line-height: 1.5;">
                        <b>What happens</b>
                        <span>• After the last day: off the attendance sheets, check-in, schedule and ratio counts, and no group texts.</span>
                        <span>• Attendance up to the last day still counts for billing and the KinderConnect export.</span>
                        <span>• {{ $child->first_name }} moves to Children → <b>Withdrawn</b>. "Re-enroll" brings them back with the same LAN and family.</span>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="panel = null">Cancel</button><button type="submit" class="btn danger">Withdraw {{ $child->first_name }}</button></div>
                </form>
            </section>
        @endif

        @if($child->isPending())
            <section class="sec" x-show="panel === 'start'" x-cloak style="border-color: #b9e4cc; box-shadow: 0 0 0 3px #e3f5ec;" data-panel="start">
                <div class="sech"><h2>Start {{ $child->first_name }}'s enrollment</h2><span style="font-size: 12.5px;" class="faint">Pending → Active</span></div>
                <form method="POST" action="{{ route('children.start', $child) }}" style="display: flex; flex-direction: column; gap: 12px;">
                    @csrf
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                        <label class="fld" style="min-width: 220px;"><span class="lbl">First day</span><input class="inp" type="date" name="first_day" value="{{ old('first_day', $child->enrolled_on?->toDateString() ?? today()->toDateString()) }}" required></label>
                        <span style="font-size: 13px; flex: 1 1 300px;" class="muted">From the first day {{ $child->first_name }} shows on attendance, check-in, the schedule and ratio counts. It also happens on its own on the agreed start date if you don't do it here.</span>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="panel = null">Cancel</button><button type="submit" class="btn pri">Start enrollment</button></div>
                </form>
            </section>
            <section class="sec" x-show="panel === 'cancel'" x-cloak style="border-color: #f3a7b3; box-shadow: 0 0 0 3px #ffe4e6;" data-panel="cancel">
                <div class="sech"><h2>Cancel {{ $child->first_name }}'s enrollment</h2><span style="font-size: 12.5px;" class="faint">Never started · moves to Withdrawn</span></div>
                <form method="POST" action="{{ route('children.cancel', $child) }}" style="display: flex; flex-direction: column; gap: 12px;">
                    @csrf
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <label class="fld"><span class="lbl">Reason</span><select class="inp" name="reason">@foreach(\App\Models\Child::CANCEL_REASONS as $reason)<option>{{ $reason }}</option>@endforeach</select></label>
                        <label class="fld"><span class="lbl">Note</span><input class="inp" name="note" placeholder="e.g. Mom took a job closer to home"></label>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="panel = null">Keep pending</button><button type="submit" class="btn danger">Cancel enrollment</button></div>
                </form>
            </section>
        @endif

        @if($child->isWithdrawn())
            <section class="sec" x-show="panel === 'archive'" x-cloak style="border-color: #cfd3de; box-shadow: 0 0 0 3px #f2f4f9;" data-panel="archive">
                <div class="sech"><h2>Archive {{ $child->first_name }}'s record</h2><span style="font-size: 12.5px;" class="faint">Withdrawn → Archived</span></div>
                <span style="font-size: 13.5px; line-height: 1.5;" class="muted">{{ $child->first_name }} leaves the Withdrawn list and "All", but nothing is deleted — find them under Children → Archived, and Restore any time. Good for children who won't be coming back.</span>
                <form method="POST" action="{{ route('children.archive', $child) }}" style="display: flex; gap: 8px; justify-content: flex-end;">@csrf<button type="button" class="btn" @click="panel = null">Cancel</button><button type="submit" class="btn pri">Archive</button></form>
            </section>
        @endif
    @endif

    {{-- ===== Status banners ===== --}}
    @if($child->isPending())
        <section class="sec" style="flex-direction: row; align-items: center; gap: 14px; background: #f1f6fd; flex-wrap: wrap;" data-banner="pending">
            <span class="pill info">Pending</span>
            <span style="font-size: 14px; flex: 1 1 300px;">@if($child->enrolled_on)Starts <b>{{ $child->enrolled_on->format('D, M j') }}</b>@else No first day agreed yet @endif · not on attendance or ratio counts yet</span>
            @if($isAdmin)<button type="button" class="btn pri" @click="panel = 'start'">Start enrollment</button>@endif
        </section>
    @elseif($child->isWithdrawn())
        <section class="sec" style="flex-direction: row; align-items: center; gap: 14px; background: #f7f8fa; flex-wrap: wrap;" data-banner="withdrawn">
            <span class="pill mut">Withdrawn</span>
            <span style="font-size: 14px; flex: 1 1 300px;">Last day <b>{{ $child->withdrawn_on?->format('M j, Y') ?? '—' }}</b>{{ $child->withdrawal_reason ? ' · '.$child->withdrawal_reason : '' }}{{ $child->withdrawal_note ? ' — '.$child->withdrawal_note : '' }}{{ $child->could_return ? ' · Could return: '.$child->could_return : '' }}</span>
            @if($isAdmin)
                <form method="POST" action="{{ route('children.reenroll', $child) }}">@csrf<button type="submit" class="btn">Re-enroll</button></form>
                <button type="button" class="btn" @click="panel = 'archive'">Archive…</button>
            @endif
        </section>
    @elseif($child->isArchived())
        <section class="sec" style="flex-direction: row; align-items: center; gap: 14px; background: #f7f8fa; flex-wrap: wrap;" data-banner="archived">
            <span class="pill mut">Archived</span>
            <span style="font-size: 14px; flex: 1 1 300px;">Archived {{ $child->archived_at?->format('M j, Y') }} · last day <b>{{ $child->withdrawn_on?->format('M j, Y') ?? '—' }}</b> · read-only until restored</span>
            @if($isAdmin)<form method="POST" action="{{ route('children.unarchive', $child) }}">@csrf<button type="submit" class="btn">Restore</button></form>@endif
        </section>
    @elseif($child->status === 'Active' && $child->withdrawn_on && $child->withdrawn_on->gte(today()))
        <section class="sec" style="flex-direction: row; align-items: center; gap: 14px; background: #fff7e6; flex-wrap: wrap;" data-banner="leaving">
            <span class="pill warn">Leaving</span>
            <span style="font-size: 14px; flex: 1 1 300px;">Last day <b>{{ $child->withdrawn_on->format('D, M j') }}</b>{{ $child->withdrawal_reason ? ' · '.$child->withdrawal_reason : '' }} · still on check-in and the ratio counts until then</span>
        </section>
    @endif

    <div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start;">
        {{-- ===== Left column ===== --}}
        <div style="flex: 3 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 16px;" x-show="['all','overview','family','health','history','notes'].includes(sec)">

            {{-- Overview --}}
            <section class="sec" x-show="sec === 'all' || sec === 'overview'" data-section="overview">
                <div class="sech"><h2>Overview</h2><span style="font-size: 12.5px;" class="faint">Schedule and enrollment</span><button type="button" class="btn sm" style="margin-left: auto;" @click="overviewEdit = ! overviewEdit" x-text="overviewEdit ? 'Close' : 'Edit'">Edit</button></div>

                <div x-show="! overviewEdit">
                    <div class="kv">
                        <span>Days</span><span>@php($days = $child->scheduleDays() ?? [])@foreach(\App\Models\Child::WEEKDAYS as $n => $name)<span class="d lg {{ in_array($n, $days, true) ? 'y' : 'n' }}">{{ $name[0] }}</span>@endforeach</span>
                        <span>Drop-off · pick-up</span><span>{{ $child->scheduleLabel() ?? 'Not agreed' }}@if($hours !== null) <span class="faint">· {{ rtrim(rtrim(number_format($hours, 1), '0'), '.') }} h a week</span>@endif</span>
                        <span>Room</span>
                        <span style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <b style="font-weight: 600;">{{ $child->classroom ?: 'No room yet' }}</b>
                            @php($override = $child->hasClassroomOverrideOn())
                            <span class="pill {{ $override ? 'warn' : 'info' }}">{{ $override ? 'Set by hand' : 'Automatic by age' }}</span>
                            <span style="font-size: 13px;" class="faint">
                                @if($override)
                                    from {{ $child->classroom_override_from?->format('M j, Y') ?? 'the start' }} · by age {{ $child->first_name }} would be in {{ $child->automaticClassroom() ?? 'no room' }}
                                @else
                                    @php($next = \App\Services\ClassroomAssignment::rankOf($child->classroom) !== null ? (\App\Services\ClassroomAssignment::rooms()[\App\Services\ClassroomAssignment::rankOf($child->classroom) + 1] ?? null) : null)
                                    {{ $next ? 'moves to '.$next.' by age' : 'the oldest room' }}
                                @endif
                            </span>
                            @if($isAdmin)
                                @if(! $override)
                                    <button type="button" class="btn sm" @click="roomForm = ! roomForm">Move to another room</button>
                                @else
                                    <form method="POST" action="{{ route('children.room', $child) }}">@csrf<input type="hidden" name="automatic" value="1"><button type="submit" class="btn sm">Back to automatic</button></form>
                                @endif
                            @endif
                        </span>
                        <span>Enrolled</span><span>{{ $child->enrolled_on?->format('M j, Y') ?? 'Not set' }} <span class="faint">· {{ $child->status === 'Active' ? 'still enrolled' : \App\Models\Child::statusLabel($child->status) }}</span></span>
                        <span>Room history</span><span class="muted">@forelse($roomEvents->reverse() as $event){{ $loop->first ? '' : ' → ' }}{{ $event->event === 'room' ? \Illuminate\Support\Str::after($event->from_status, '→ ') : ($event->event === 'status' ? ($child->classroom ?: '—') : \Illuminate\Support\Str::after($event->from_status, '· ')) }} {{ $event->created_at->format('M j, Y') }}@empty{{ $child->classroom ?: '—' }}@endforelse</span>
                        <span>Subsidy</span><span>@if($child->isDss())DSS Case No <b style="font-weight: 600; font-family: ui-monospace, monospace;">{{ $child->dss_case_no ?: '—' }}</b> · DSS CIN <b style="font-weight: 600; font-family: ui-monospace, monospace; color: {{ $child->dss_cin ? 'inherit' : '#be123c' }};">{{ $child->dss_cin ?: 'missing' }}</b>@else<span class="muted">Private pay</span>@endif</span>
                    </div>

                    @if($isAdmin)
                        <form method="POST" action="{{ route('children.room', $child) }}" x-show="roomForm" x-cloak class="panel" style="margin-top: 12px;" data-room-form>
                            @csrf
                            <b style="font-size: 13.5px;">Move {{ $child->first_name }} to another room</b>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
                                <label class="fld"><span class="lbl">Room</span><select class="inp" name="room">@foreach(\App\Services\ClassroomAssignment::rooms() as $option)@continue($option === $child->classroom)<option>{{ $option }}</option>@endforeach</select></label>
                                <label class="fld"><span class="lbl">Starting</span><input class="inp" type="date" name="from" value="{{ today()->toDateString() }}"></label>
                                <label class="fld"><span class="lbl">Why (optional)</span><input class="inp" name="why" placeholder="e.g. Ready early, space in Toddler"></label>
                            </div>
                            <span style="font-size: 12.5px;" class="muted">By age {{ $child->first_name }} would stay in {{ $child->automaticClassroom() ?? 'no room' }}. A room you pick by hand stays until you switch back to automatic.</span>
                            <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="roomForm = false">Cancel</button><button type="submit" class="btn pri">Move</button></div>
                        </form>
                    @endif
                </div>

                <form method="POST" action="{{ route('children.overview', $child) }}" x-show="overviewEdit" x-cloak style="display: flex; flex-direction: column; gap: 12px;" data-overview-form>
                    @csrf @method('PUT')
                    <div class="fld"><span class="lbl">Days they attend</span><div><input type="hidden" name="schedule_days[]" value="">@foreach(\App\Models\Child::WEEKDAYS as $n => $name)<label class="d lg {{ in_array($n, $days, true) ? 'y' : 'n' }}" x-data="{ on: @js(in_array($n, $days, true)) }" :class="on ? 'y' : 'n'" style="cursor: pointer;"><input type="checkbox" name="schedule_days[]" value="{{ $n }}" x-model="on" style="display: none;">{{ $name[0] }}</label>@endforeach</div></div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                        <label class="fld"><span class="lbl">Drop-off</span><input class="inp" type="time" name="drop_off_time" value="{{ \App\Models\Child::timeInputValue($child->drop_off_time) }}"></label>
                        <label class="fld"><span class="lbl">Pick-up</span><input class="inp" type="time" name="pick_up_time" value="{{ \App\Models\Child::timeInputValue($child->pick_up_time) }}"></label>
                        <label class="fld"><span class="lbl">Hours a week (worked out)</span><input class="inp" value="{{ $hours !== null ? rtrim(rtrim(number_format($hours, 1), '0'), '.') : '—' }}" disabled></label>
                        <label class="fld"><span class="lbl">Subsidy case number</span><input class="inp" name="dss_case_no" value="{{ $child->dss_case_no }}" placeholder="e.g. S1177706D"></label>
                        <label class="fld"><span class="lbl">CIN (the child's own number)</span><input class="inp" name="dss_cin" value="{{ $child->dss_cin }}" placeholder="e.g. HB14137F"></label>
                        <label class="fld"><span class="lbl">Nickname</span><input class="inp" name="nickname" value="{{ $child->nickname }}"></label>
                    </div>
                    <span style="font-size: 12.5px;" class="faint">Hours are worked out from the days and times — no separate "expected hours" to keep in step.</span>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="overviewEdit = false">Cancel</button><button type="submit" class="btn pri">Save</button></div>
                </form>
            </section>

            {{-- Family & pick-up --}}
            <section class="sec" x-show="sec === 'all' || sec === 'family'" data-section="family">
                <div class="sech"><h2>Family &amp; pick-up</h2><span style="font-size: 12.5px;" class="faint">One list — tick what each person may do</span><button type="button" class="btn sm" style="margin-left: auto;" @click="addPerson = ! addPerson">+ Add person</button></div>

                <div x-show="addPerson" x-cloak class="panel" data-add-person>
                    <b style="font-size: 13.5px;">Add a person to {{ $child->first_name }}</b>
                    <span style="font-size: 12.5px;" class="muted">Search first — if they're already on file for a brother or sister, link the same person so their details are entered once.</span>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <input class="inp" style="flex: 1 1 260px;" placeholder="Name or cell number" x-model.debounce.300ms="query" @input="searchPeople()">
                        <select class="inp" style="width: 170px;" x-model="relationship">@foreach($relationships as $rel)<option>{{ $rel }}</option>@endforeach</select>
                    </div>
                    <template x-for="hit in hits" :key="hit.id">
                        <div style="display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #e7e9f0; border-radius: 10px; font-size: 13.5px;" class="dark:!bg-slate-800">
                            <span style="flex: 1;"><b x-text="hit.name"></b> · <span x-text="hit.cell || '—'"></span> <span class="muted" x-text="hit.already_linked ? '· already on this record' : (hit.families.length ? '· ' + hit.families.join(', ') : '')"></span></span>
                            <button type="button" class="btn sm pri" x-show="! hit.already_linked" @click="linkPerson(hit.id)">Link</button>
                        </div>
                    </template>
                    <span style="font-size: 13px;" class="faint" x-show="query.length > 1 && ! searching && hits.length === 0">No one on file matches.</span>
                    <div style="display: flex; align-items: center; gap: 8px; border-top: 1px solid #e7e9f0; padding-top: 10px; flex-wrap: wrap;">
                        <span style="font-size: 12.5px;" class="muted">Not one of these?</span>
                        <input class="inp" style="flex: 1 1 180px;" placeholder="Full name" x-model="newName">
                        <input class="inp" style="width: 160px;" placeholder="Mobile" x-model="newPhone">
                        <button type="button" class="btn sm" @click="createPerson()" :disabled="busy">+ Create a new person</button>
                    </div>
                    <span style="font-size: 12.5px; color: #be123c;" x-show="error" x-text="error"></span>
                </div>

                <div style="overflow-x: auto;">
                    <table class="tbl people" data-people-table>
                        <thead><tr><th title="Who to phone first in an emergency">Call #</th><th>Person</th><th>Relationship</th><th>Mobile</th><th>Guardian</th><th>Pick-up</th><th>Emergency</th><th>Pays</th><th>Texts</th><th></th></tr></thead>
                        <tbody>
                            @forelse($people as $link)
                                @php($person = $link->person)
                                @php($restricted = filled($link->restriction))
                                @php($tick = fn ($on) => $restricted ? '<span class="tick x">✕</span>' : ($on ? '<span class="tick y">✓</span>' : '<span class="tick n">—</span>'))
                                <tr data-person-row="{{ $person?->id }}">
                                    <td>
                                        <span style="display: inline-flex; align-items: center; gap: 4px;">
                                            <b style="display: inline-flex; width: 22px; height: 22px; border-radius: 50%; background: {{ $link->is_emergency ? ($link->priority === 1 ? '#1b5e91' : '#dcebfa') : '#f2f4f9' }}; color: {{ $link->is_emergency ? ($link->priority === 1 ? '#fff' : '#1b5e91') : '#b5bac6' }}; align-items: center; justify-content: center; font-size: 12px;">{{ $link->is_emergency ? $link->priority : '—' }}</b>
                                            @if($link->is_emergency && $person)
                                                <form method="POST" action="{{ route('children.people.order', [$child, $person]) }}" style="display: inline;">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="mv" title="Call earlier">↑</button></form>
                                                <form method="POST" action="{{ route('children.people.order', [$child, $person]) }}" style="display: inline;">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="mv" title="Call later">↓</button></form>
                                            @endif
                                        </span>
                                    </td>
                                    <td style="font-weight: 600; min-width: 170px;">{{ $person?->name ?? 'Name not on file' }}@if($restricted)<div style="font-size: 12px; font-weight: 500; color: #be123c;">{{ $link->restriction }}</div>@endif</td>
                                    <td class="muted">{{ $link->relationship ?: '—' }}</td>
                                    <td>@if(filled($person?->cell))<a href="tel:{{ $digits($person->cell) }}">{{ $person->cell }}</a>@else —@endif</td>
                                    <td>{!! $tick($link->is_guardian) !!}</td>
                                    <td>{!! $tick($link->can_pickup) !!}</td>
                                    <td>{!! $tick($link->is_emergency) !!}</td>
                                    <td>{!! $tick($link->pays_fees) !!}</td>
                                    <td>{!! $tick($link->gets_texts) !!}</td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <button type="button" class="btn sm" @click="editing = editing === {{ (int) $person?->id }} ? null : {{ (int) $person?->id }}">Edit</button>
                                        <button type="button" class="btn sm" style="color: #be123c;" @click="unlinkPerson({{ (int) $person?->id }}, @js($person?->name))" title="Remove from {{ $child->first_name }}'s record (the person stays on file)">Unlink</button>
                                    </td>
                                </tr>
                                <tr x-show="editing === {{ (int) $person?->id }}" x-cloak>
                                    <td colspan="10" style="white-space: normal; background: #f7f9fd;" class="dark:!bg-slate-800/60">
                                        <form method="POST" action="{{ route('children.people.flags', [$child, $person]) }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;" data-person-flags="{{ $person?->id }}">
                                            @csrf
                                            <label class="fld" style="min-width: 150px;"><span class="lbl">Relationship</span><select class="inp" name="relationship">@foreach($relationships as $rel)<option @selected($link->relationship === $rel)>{{ $rel }}</option>@endforeach</select></label>
                                            @foreach(['is_guardian' => 'Guardian', 'can_pickup' => 'Pick-up', 'is_emergency' => 'Emergency', 'pays_fees' => 'Pays the fees', 'gets_texts' => 'Gets texts'] as $field => $label)
                                                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; min-height: 38px;"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked($link->{$field}) style="width: 16px; height: 16px; accent-color: #1b5e91;"> {{ $label }}</label>
                                            @endforeach
                                            <label class="fld" style="flex: 1 1 220px;"><span class="lbl">Restriction (court order etc.)</span><input class="inp" name="restriction" value="{{ $link->restriction }}" placeholder="Leave blank if none"></label>
                                            <button type="submit" class="btn sm pri">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" style="padding: 24px; text-align: center;" class="muted">Nobody is on {{ $child->first_name }}'s record yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{-- Said in words, for the person at the door: who may collect,
                     and who must not. A restriction beats the tick. --}}
                @php($cleared = $people->filter(fn ($link) => $link->allowsPickup()))
                @php($barred = $people->filter(fn ($link) => filled($link->restriction)))
                <div style="font-size: 13px; display: flex; flex-direction: column; gap: 4px;" data-pickup-summary>
                    <span class="muted">Cleared to collect: {{ $cleared->isNotEmpty() ? $cleared->map(fn ($link) => $link->person?->name)->filter()->join(', ') : 'Nobody is cleared to collect this child.' }}</span>
                    @if($barred->isNotEmpty())
                        <span style="color: #be123c; font-weight: 600;">Must not collect: {{ $barred->map(fn ($link) => $link->person?->name.' — '.$link->restriction)->join(' · ') }}</span>
                    @endif
                </div>
                <div class="kv">
                    <span>Home address</span><span>{{ collect([$child->address, $child->city, $child->zip])->filter()->join(', ') ?: '—' }}</span>
                    <span>Custody</span><span>{{ $child->custody_note ?: ($child->parents_status ?: '—') }}</span>
                </div>
            </section>

            {{-- Health --}}
            <section class="sec" x-show="sec === 'all' || sec === 'health'" data-section="health">
                <div class="sech"><h2>Health</h2><span style="font-size: 12.5px;" class="faint">Every alert is its own entry — no free-text box to miss</span><button type="button" class="btn sm" style="margin-left: auto;" @click="alertForm = ! alertForm">+ Add alert</button><button type="button" class="btn sm" @click="healthEdit = ! healthEdit">Edit</button></div>

                <form method="POST" action="{{ route('children.alerts.store', $child) }}" x-show="alertForm" x-cloak class="panel" data-alert-form>
                    @csrf
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
                        <label class="fld"><span class="lbl">Type</span><select class="inp" name="type">@foreach(\App\Models\Child::ALERT_TYPES as $key => $type)<option value="{{ $key }}">{{ $type['label'] }}</option>@endforeach</select></label>
                        <label class="fld"><span class="lbl">What</span><input class="inp" name="text" required placeholder="e.g. Milk protein (dairy)"></label>
                        <label class="fld"><span class="lbl">Severity</span><select class="inp" name="severity"><option value="">—</option><option value="mild">Mild</option><option value="moderate">Moderate</option><option value="severe">Severe</option></select></label>
                        <label class="fld"><span class="lbl">Care plan signed</span><input class="inp" type="date" name="plan_on"></label>
                        <label class="fld" style="grid-column: 1 / -1;"><span class="lbl">Reaction</span><input class="inp" name="reaction" placeholder="e.g. hives, vomiting"></label>
                        <label class="fld" style="grid-column: 1 / -1;"><span class="lbl">What to do</span><input class="inp" name="action" placeholder="e.g. oat milk only; call parent; antihistamine per plan"></label>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="alertForm = false">Cancel</button><button type="submit" class="btn pri">Add alert</button></div>
                </form>

                <div style="display: flex; flex-direction: column; gap: 8px;">
                    @forelse($alerts as $i => $alert)
                        <div style="display: flex; gap: 12px; padding: 12px 14px; border-radius: 12px; background: {{ $alert['type'] === 'court' || $alert['severity'] === 'severe' ? '#fff7f8' : '#f7f9fd' }}; border: 1px solid {{ $alert['type'] === 'court' || $alert['severity'] === 'severe' ? '#fbc4cc' : '#e7e9f0' }}; align-items: flex-start;" class="dark:!bg-slate-800/60" data-alert="{{ $i }}">
                            <span class="pill {{ $alert['type'] }}">{{ $alert['label'] }}{{ $alert['severity'] ? ' · '.$alert['severity'] : '' }}</span>
                            <div style="flex: 1; display: flex; flex-direction: column; gap: 3px; font-size: 13.5px;">
                                <b>{{ $alert['text'] }}</b>
                                @if($alert['reaction'] || $alert['action'])<span class="muted">{{ $alert['reaction'] ? 'Reaction: '.$alert['reaction'].'. ' : '' }}{{ $alert['action'] ? 'Do: '.$alert['action'] : '' }}</span>@endif
                                <span style="font-size: 12px;" class="faint">@if($alert['plan_on'])Care plan signed {{ \Illuminate\Support\Carbon::parse($alert['plan_on'])->format('M j, Y') }} · @endif shown on the roll, check-in and the kitchen list</span>
                            </div>
                            <form method="POST" action="{{ route('children.alerts.destroy', [$child, $i]) }}" onsubmit="return confirm('Remove this alert?')">@csrf @method('DELETE')<button type="submit" class="btn sm">Remove</button></form>
                        </div>
                    @empty
                        <span style="font-size: 13px;" class="faint">No alerts on file.</span>
                    @endforelse
                </div>

                @if(filled($child->important_notes))
                    {{-- The paragraph the alerts summarise, from the enrolment form. --}}
                    <div style="display: flex; gap: 12px; padding: 12px 14px; border-radius: 12px; background: #fffbeb; border: 1px solid #f6d79a;" class="dark:!bg-slate-800/60">
                        <span class="pill warn">Important</span>
                        <p style="margin: 0; white-space: pre-line; font-size: 13.5px;">{{ $child->important_notes }}</p>
                    </div>
                @endif

                <div class="kv" x-show="! healthEdit">
                    <span>Doctor</span><span>{{ $child->health('doctor') ?: '—' }}</span>
                    <span>Immunizations</span><span>@if($child->immunizationsDue())<span class="pill warn">{{ $child->health('immunizations_due') ? 'Due' : 'Not on file' }}</span>@else<span class="pill ok">Up to date</span>@endif @if($child->health('immunizations_due'))<span style="font-size: 13px;" class="faint">next due {{ \Illuminate\Support\Carbon::parse($child->health('immunizations_due'))->format('M j, Y') }}</span>@endif</span>
                    <span>Medical statement</span><span>@if($medical)<span class="pill {{ $medical->status()[1] }}">On file</span> <span style="font-size: 13px;" class="faint">signed {{ $medical->signed_on?->format('M j, Y') }}{{ $medical->expires_on ? ' · renews '.$medical->expires_on->format('M j, Y') : '' }}</span>@else<span class="pill miss">Missing</span>@endif</span>
                    <span>Medication</span><span>{{ $child->health('medication') ?: 'None given at the centre' }}</span>
                </div>

                <form method="POST" action="{{ route('children.health', $child) }}" x-show="healthEdit" x-cloak style="display: flex; flex-direction: column; gap: 12px;" data-health-form>
                    @csrf @method('PUT')
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                        <label class="fld"><span class="lbl">Doctor</span><input class="inp" name="doctor" value="{{ $child->health('doctor') }}" placeholder="Dr. Name · Practice · phone"></label>
                        <label class="fld"><span class="lbl">Immunizations next due</span><input class="inp" type="date" name="immunizations_due" value="{{ $child->health('immunizations_due') }}"></label>
                        <label class="fld"><span class="lbl">Medication</span><input class="inp" name="medication" value="{{ $child->health('medication') }}" placeholder="None given at the centre"></label>
                        <label class="fld"><span class="lbl">Custody</span><input class="inp" name="custody_note" value="{{ $child->custody_note }}" placeholder="e.g. Shared — both parents may collect"></label>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="healthEdit = false">Cancel</button><button type="submit" class="btn pri">Save</button></div>
                </form>
            </section>

            {{-- Enrollment history --}}
            <section class="sec" x-show="sec === 'all' || sec === 'history'" data-section="history">
                <div class="sech"><h2>Enrollment history</h2><span style="font-size: 12.5px;" class="faint">Every change to {{ $child->first_name }}'s place here</span></div>
                <div style="display: flex; flex-direction: column; gap: 6px; padding: 12px 14px; border-radius: 12px; background: #faf7ff; border: 1px solid #e4dcfb;" class="dark:!bg-slate-800/60" data-enrollment-note>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;"><span class="lbl" style="color: #5b21b6;">Enrollment note</span><span class="pill dss">On DSS page</span><span style="font-size: 12px;" class="faint">One note — keep it current, or leave it blank</span></div>
                    <div x-show="! noteEdit" style="display: flex; align-items: center; gap: 10px;">
                        <span style="flex: 1; font-size: 14px;">{{ $child->enrollment_note ?: 'No note.' }}</span>
                        @if($child->enrollment_note_at)<span style="font-size: 12px;" class="faint">Updated {{ $child->enrollment_note_at->format('M j') }}</span>@endif
                        <button type="button" class="btn sm" @click="noteEdit = true">Edit</button>
                    </div>
                    <form method="POST" action="{{ route('children.enrollment-note', $child) }}" x-show="noteEdit" x-cloak style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        @csrf @method('PUT')
                        <input class="inp" style="flex: 1 1 260px;" name="enrollment_note" value="{{ $child->enrollment_note }}" placeholder="e.g. Subsidy authorization ends Nov 30 — renewal sent">
                        <button type="submit" class="btn pri">Save</button>
                        <button type="button" class="btn" @click="noteEdit = false">Cancel</button>
                        @if($child->enrollment_note)<button type="submit" class="btn" style="color: #be123c;" name="enrollment_note" value="">Clear</button>@endif
                    </form>
                </div>
                <div class="tl" data-history>
                    @forelse($statusChanges as $change)
                        <div style="display: flex; gap: 12px; padding: 7px 0; align-items: flex-start; position: relative;">
                            <span class="dot {{ $change->tone() }}"></span>
                            <span style="width: 96px; flex: none; font-size: 12.5px;" class="faint">{{ $change->created_at->format('M j, Y') }}</span>
                            <div style="flex: 1; display: flex; flex-direction: column; gap: 2px; font-size: 13.5px;"><b style="font-weight: 600;">{{ $change->summary() }}</b>@if($change->note)<span class="muted">{{ $change->note }}</span>@endif @if($change->changedBy)<span style="font-size: 11px;" class="faint">by {{ $change->changedBy->name }}</span>@endif</div>
                            @if(in_array($change->event, ['withdrawn', 'cancelled', 'note'], true))<span class="pill dss">On DSS page</span>@endif
                        </div>
                    @empty
                        <span style="font-size: 13px;" class="faint">Nothing yet.</span>
                    @endforelse
                </div>
            </section>

            {{-- Notes & comments --}}
            <section class="sec" x-show="sec === 'all' || sec === 'notes'" data-section="notes" x-data="{ filter: 'All', older: false }">
                <div class="sech"><h2>Notes &amp; comments</h2><span style="font-size: 12.5px;" class="faint">Teacher comments from check-in, plus office notes · newest first</span></div>
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    @foreach($noteCounts as $kind => $count)
                        <button type="button" class="fchip" :class="filter === '{{ $kind }}' && 'on'" @click="filter = '{{ $kind }}'">{{ $kind }} <span style="opacity: .7;">{{ $count }}</span></button>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('children.notes.store', $child) }}" style="display: flex; gap: 8px; flex-wrap: wrap;" data-note-form>
                    @csrf
                    <input class="inp" style="flex: 1 1 260px;" name="body" required placeholder="Add a note for {{ $child->first_name }}…">
                    <select class="inp" style="width: 120px;" name="kind"><option value="office">Office</option><option value="health">Health</option></select>
                    <button type="submit" class="btn pri">Add</button>
                </form>
                <div style="display: flex; flex-direction: column;">
                    @forelse($allNotes as $i => $note)
                        <div style="display: flex; gap: 12px; padding: 10px 2px; border-bottom: 1px solid #f0f1f5; align-items: flex-start;" x-show="(filter === 'All' || filter === '{{ $note['kind'] }}') && ({{ $i }} < 8 || older)">
                            <span style="width: 92px; flex: none; font-size: 12.5px;" class="faint">{{ $note['at']->format('M j') }}</span>
                            <div style="flex: 1; display: flex; flex-direction: column; gap: 3px; font-size: 13.5px;"><span>{{ $note['text'] }}</span><span style="font-size: 12px;" class="faint">{{ $note['by'] }}</span></div>
                            <span class="pill {{ ['Teacher' => 'info', 'Office' => 'mut', 'Health' => 'bad'][$note['kind']] }}">{{ $note['kind'] }}</span>
                        </div>
                    @empty
                        <span style="font-size: 13px; padding: 8px 0;" class="faint">No notes yet.</span>
                    @endforelse
                </div>
                @if($allNotes->count() > 8)
                    <button type="button" class="btn sm" style="align-self: flex-start;" @click="older = ! older" x-text="older ? 'Show fewer' : 'Show older ({{ $allNotes->count() - 8 }})'">Show older ({{ $allNotes->count() - 8 }})</button>
                @endif
                @if(filled($child->other_notes))
                    <div style="border-top: 1px solid #f0f1f5; padding-top: 10px;"><span class="lbl">From the enrolment form</span><p style="margin: 4px 0 0; white-space: pre-line; font-size: 13.5px;">{{ $child->other_notes }}</p></div>
                @endif
            </section>
        </div>

        {{-- ===== Right column ===== --}}
        <div style="min-width: 0; display: flex; flex-direction: column; gap: 16px;" :style="sec === 'all' ? 'flex: 2 1 340px' : 'flex: 1 1 100%'" x-show="['all','attendance','docs'].includes(sec)">

            {{-- To finish --}}
            @if($missing !== [])
                <section class="sec" x-show="sec === 'all'" style="border-color: #f6d79a; background: #fffcf5;" class="dark:!bg-slate-900" data-section="to-finish">
                    <div class="sech"><h2>To finish</h2><span class="pill miss">{{ count($missing) }} {{ count($missing) === 1 ? 'item' : 'items' }}</span></div>
                    @foreach($missing as $key => $label)
                        <div style="display: flex; gap: 10px; align-items: center; font-size: 13.5px; flex-wrap: wrap;">
                            <span style="flex: 1 1 200px;">
                                @switch($key)
                                    @case('photo') Photo consent isn't signed — {{ $child->first_name }} is left out of photos and the newsletter until it is. @break
                                    @case('guard') Nobody is marked as a legal guardian. @break
                                    @case('emerg') No emergency contact to ring. @break
                                    @case('imm') {{ $child->health('immunizations_due') ? 'Immunizations are due.' : 'No immunization date on file.' }} @break
                                    @case('hours') Drop-off and pick-up times aren't agreed. @break
                                @endswitch
                            </span>
                            @if($key === 'photo')
                                <form method="POST" action="{{ route('children.documents.store', $child) }}" enctype="multipart/form-data" style="display: inline-flex; gap: 6px; align-items: center;">@csrf<input type="hidden" name="kind" value="photo_consent"><label class="btn sm" style="cursor: pointer;">Upload signed form<input type="file" name="file" accept=".pdf,image/*" style="display: none;" onchange="this.form.submit()"></label></form>
                            @elseif(in_array($key, ['guard', 'emerg'], true))
                                <button type="button" class="btn sm" @click="sec = 'family'; addPerson = true">Add a person</button>
                            @elseif($key === 'imm')
                                <button type="button" class="btn sm" @click="sec = 'health'; healthEdit = true">Enter the date</button>
                            @else
                                <button type="button" class="btn sm" @click="sec = 'overview'; overviewEdit = true">Set the hours</button>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- Attendance · month --}}
            <section class="sec" x-show="sec === 'all' || sec === 'attendance'" data-section="attendance">
                <div class="sech"><h2>Attendance · {{ $month['label'] }}</h2><a href="{{ route('attendance.index') }}" style="margin-left: auto; font-size: 13px; font-weight: 600;">Open register →</a></div>
                <div class="cal">
                    @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $head)<span style="font-size: 11px; font-weight: 700; text-align: center;" class="faint">{{ $head }}</span>@endforeach
                    @for($i = 0; $i < $month['lead']; $i++)<span></span>@endfor
                    @foreach($month['days'] as $day)
                        <span class="cd c-{{ $day['state'] }}" title="{{ $day['date']->format('D M j') }} · {{ ['in' => 'Signed in', 'absent' => 'Absent', 'today' => 'Today', 'planned' => 'Planned', 'closed' => 'Centre closed', 'none' => 'Not scheduled'][$day['state']] }}">{{ $day['date']->day }}<span style="font-size: 10px; font-weight: 500;">{{ ['in' => $day['times'] ?? 'in', 'absent' => 'absent', 'today' => $day['times'] ?? 'today', 'planned' => '', 'closed' => 'closed', 'none' => ''][$day['state']] }}</span></span>
                    @endforeach
                </div>
                <div style="display: flex; gap: 14px; font-size: 13px; flex-wrap: wrap;" class="muted"><span><b style="color: #15803d;">{{ $month['in'] }}</b> days in</span><span><b style="color: #be123c;">{{ $month['absent'] }}</b> absent</span>@if($month['avgIn'])<span>avg {{ $month['avgIn'] }}{{ $month['avgOut'] ? ' – '.$month['avgOut'] : '' }}</span>@endif</div>
            </section>

            {{-- Documents --}}
            <section class="sec" x-show="sec === 'all' || sec === 'docs'" data-section="documents">
                <div class="sech"><h2>Documents</h2><button type="button" class="btn sm" style="margin-left: auto;" @click="docForm = ! docForm">Upload</button></div>
                <form method="POST" action="{{ route('children.documents.store', $child) }}" enctype="multipart/form-data" x-show="docForm" x-cloak class="panel" data-document-form>
                    @csrf
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
                        <label class="fld"><span class="lbl">Document</span><select class="inp" name="kind">@foreach(\App\Models\Child::DOCUMENT_KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="fld"><span class="lbl">Signed on</span><input class="inp" type="date" name="signed_on" value="{{ today()->toDateString() }}"></label>
                        <label class="fld"><span class="lbl">Expires (optional)</span><input class="inp" type="date" name="expires_on"></label>
                        <label class="fld"><span class="lbl">File (optional)</span><input class="inp" type="file" name="file" accept=".pdf,image/*"></label>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;"><button type="button" class="btn" @click="docForm = false">Cancel</button><button type="submit" class="btn pri">Save</button></div>
                </form>
                @foreach(\App\Models\Child::DOCUMENT_KINDS as $key => $label)
                    @php($doc = $child->document($key))
                    <div style="display: flex; align-items: center; gap: 10px; font-size: 13.5px; padding: 4px 0;" data-document="{{ $key }}">
                        <span style="flex: 1;">@if($doc && $doc->path)<a href="{{ route('children.documents.show', [$child, $doc]) }}" target="_blank">{{ $label }}</a>@else{{ $label }}@endif</span>
                        @if($doc)<span class="pill {{ $doc->status()[1] }}">{{ $doc->status()[0] }}</span>@else<span class="pill miss">Missing</span>@endif
                    </div>
                @endforeach
            </section>

            {{-- Record --}}
            <section class="sec" x-show="sec === 'all'" style="gap: 8px;" data-section="record">
                <div class="sech"><h2 style="font-size: 14px;">Record</h2></div>
                <span style="font-size: 12.5px;" class="faint">Created {{ $child->created_at?->format('M j, Y') }} · last change {{ $child->updated_at?->format('M j') }}{{ $child->updatedBy ? ' by '.$child->updatedBy->name : '' }}</span>
                @if($isAdmin)
                    @if($child->status === 'Active')<button type="button" class="btn sm" style="align-self: flex-start; color: #be123c;" @click="panel = 'withdraw'">Withdraw…</button>@endif
                    @if($child->isWithdrawn())<form method="POST" action="{{ route('children.reenroll', $child) }}">@csrf<button type="submit" class="btn sm">Re-enroll</button></form>@endif
                @endif
            </section>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('childRecord', ({ childId, csrf, open }) => ({
        sec: 'all',
        menu: false, statusMenu: false,
        panel: open || null,
        overviewEdit: false, roomForm: false, healthEdit: false, alertForm: false, noteEdit: false, docForm: false,
        addPerson: false, editing: null,
        query: '', hits: [], searching: false, relationship: 'Mother', newName: '', newPhone: '', busy: false, error: '',

        headers() {
            return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
        },

        async searchPeople() {
            const q = this.query.trim();
            if (q.length < 2) { this.hits = []; return; }
            this.searching = true;
            try {
                const response = await fetch(`/people/search?q=${encodeURIComponent(q)}&child_id=${childId}`, { headers: this.headers() });
                this.hits = (await response.json()).people || [];
            } catch (e) { this.hits = []; } finally { this.searching = false; }
        },

        // Link somebody already on file: pick-up and emergency by default, as
        // a new person is. The Edit row sets the rest.
        async linkPerson(id) {
            await this.send(`/people/${id}/link`, { child_id: childId, relationship: this.relationship, can_pickup: true, is_emergency: true });
        },

        async createPerson() {
            if (! this.newName.trim()) { this.error = 'A name is needed.'; return; }
            if (! this.newPhone.trim()) { this.error = 'A mobile number is needed.'; return; }
            await this.send('/people', { child_id: childId, name: this.newName.trim(), cell: this.newPhone.trim(), relationship: this.relationship, can_pickup: true, is_emergency: true });
        },

        async unlinkPerson(id, name) {
            if (! confirm('Remove ' + name + ' from this record? They stay on file for any other child.')) return;
            await this.send(`/people/${id}/unlink`, { child_id: childId });
        },

        async send(url, body) {
            this.busy = true; this.error = '';
            try {
                const response = await fetch(url, { method: 'POST', headers: this.headers(), body: JSON.stringify(body) });
                if (! response.ok) { const data = await response.json().catch(() => ({})); throw new Error(data.message || ('HTTP ' + response.status)); }
                window.location.reload();
            } catch (e) { this.error = e.message; this.busy = false; }
        },
    }));
});
</script>
@endsection
