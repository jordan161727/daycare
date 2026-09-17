@extends('layouts.app')
@section('title', $child->first_name.' '.$child->last_name)
@section('content')
{{-- The child's record, read rather than edited.

     What is on file about a child is looked up far more often than it is
     changed — a number at pick-up time, who may collect them, the line about
     the allergy — and the edit form puts all of that behind inputs the teacher
     holding the child is not allowed to open.

     So the page is ordered by how urgently something is needed rather than by
     how the form collects it: the allergy note first, then the day, then the
     people, with every number a link because this is read on a phone at the
     door as often as at a desk. Fields nobody has filled in are left out
     instead of printed as a column of dashes. Social security numbers are
     deliberately absent — they are enrolment paperwork, not door information. --}}

{{-- The same background as the sheet and the roster: this is the page a parent
     is shown at the door, so it belongs with the family-facing screens rather
     than with payroll. The quiet strength — the record is read, not admired. --}}
<x-kids-background />

@php($digits = fn (?string $number) => preg_replace('/[^0-9+]/', '', (string) $number))
@php($guardians = collect([
    ['title' => 'Mother', 'name' => $child->mother_name, 'rows' => [
        ['Cell', $child->mother_cell, 'tel'],
        ['Home', $child->mother_home_phone, 'tel'],
        ['Work', $child->mother_work_phone, 'tel'],
        ['Email', $child->mother_email, 'mail'],
        ['Employer', $child->mother_employer, null],
        ['Address', $child->mother_address, null],
    ]],
    ['title' => 'Father', 'name' => $child->father_name, 'rows' => [
        ['Cell', $child->father_cell, 'tel'],
        ['Home', $child->father_home_phone, 'tel'],
        ['Work', $child->father_work_phone, 'tel'],
        ['Email', $child->father_email, 'mail'],
        ['Employer', $child->father_employer, null],
        ['Address', $child->father_address, null],
    ]],
])->map(fn ($guardian) => [...$guardian, 'rows' => collect($guardian['rows'])->filter(fn ($row) => filled($row[1]))])
  ->filter(fn ($guardian) => filled($guardian['name']) || $guardian['rows']->isNotEmpty()))
@php($pickups = collect([1, 2, 3])->map(fn ($number) => [
    'name' => $child->{"pickup_{$number}_name"},
    'relationship' => $child->{"pickup_{$number}_relationship"},
    'telephone' => $child->{"pickup_{$number}_telephone"} ?: $child->{"pickup_{$number}_alternate"},
    'licence' => $child->{"pickup_{$number}_license_number"},
])->filter(fn ($pickup) => filled($pickup['name'])))
@php($household = collect([
    ['Address', collect([$child->address, $child->city, $child->zip])->filter()->join(', '), null],
    ['Telephone', $child->telephone, 'tel'],
    ['Email', $child->email_address, 'mail'],
    ['Parents', $child->parents_status, null],
    ['Pays the fees', $child->responsible_for_payment, null],
])->filter(fn ($row) => filled($row[1])))

{{-- The hero carries the four facts somebody opens this page for, so the
     common lookup is answered before any scrolling happens. --}}
<section class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-500 to-violet-500 text-white shadow-xl shadow-indigo-500/20">
    {{-- Drawn on the banner because this is a page about a four-year-old and a
         flat panel of corporate gradient says otherwise. Kept faint and behind
         everything: it is wallpaper, and the moment it competes with the name
         or the hours it has stopped doing its job. Line art rather than solid
         shapes for the same reason — it reads as texture at a glance and as
         balloons and blocks only if you look. --}}
    <svg class="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
        <defs>
            <pattern id="nursery-wallpaper" width="150" height="150" patternUnits="userSpaceOnUse" patternTransform="rotate(-8)">
                <g fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".2">
                    {{-- Star --}}
                    <path d="M26 14l3 6.1 6.7 1-4.9 4.7 1.2 6.7L26 29.4 20 32.5l1.2-6.7-4.9-4.7 6.7-1z"/>
                    {{-- Cloud --}}
                    <path d="M92 34h20a7 7 0 0 0 0-14 10 10 0 0 0-18.4-4.4A8 8 0 0 0 92 34z"/>
                    {{-- Balloon --}}
                    <path d="M40 74a9 9 0 1 0-18 0c0 5.6 4.5 10.2 9 13.4 4.5-3.2 9-7.8 9-13.4z"/>
                    <path d="M31 87.4v9.4"/>
                    {{-- Building block --}}
                    <rect x="80" y="70" width="19" height="19" rx="5"/>
                    {{-- Kite --}}
                    <path d="M124 108l9 11-9 11-9-11z"/>
                    <path d="M124 130v9"/>
                    {{-- Rainbow --}}
                    <path d="M18 132a19 19 0 0 1 38 0"/>
                    <path d="M26 132a11 11 0 0 1 22 0"/>
                </g>
                {{-- Confetti, to break up the grid the motifs would otherwise
                     fall into. --}}
                <g fill="#fff" opacity=".16">
                    <circle cx="120" cy="52" r="2.6"/>
                    <circle cx="62" cy="44" r="2.1"/>
                    <circle cx="14" cy="104" r="2.4"/>
                    <circle cx="104" cy="128" r="2"/>
                    <circle cx="72" cy="14" r="1.8"/>
                </g>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#nursery-wallpaper)"/>
        {{-- A sun off the top corner, for somewhere for the eye to rest. --}}
        <circle cx="94%" cy="-10" r="90" fill="#fff" opacity=".06"/>
    </svg>

    <div class="relative flex flex-col gap-6 p-6 sm:p-8 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex items-center gap-5">
            {{-- Large, and larger still on a tap: the photograph is how a face
                 gets matched to a name, and a 64px thumbnail is not enough to
                 do that with a child you have met twice. --}}
            @php($photo = $child->photoUrl())
            @if($photo)
                <div x-data="{ open: false }" @keydown.escape.window="open = false" class="shrink-0">
                    <button type="button" @click="open = true" class="group relative block" title="Open the photo">
                        <img src="{{ $photo }}" alt="{{ $child->first_name }} {{ $child->last_name }}" class="h-24 w-24 rounded-2xl object-cover ring-2 ring-white/40 transition group-hover:ring-white/80 sm:h-32 sm:w-32">
                        <span class="absolute inset-0 grid place-items-center rounded-2xl bg-slate-950/40 text-2xl opacity-0 transition group-hover:opacity-100" aria-hidden="true">⤢</span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity @click="open = false"
                         class="fixed inset-0 z-50 grid place-items-center bg-slate-950/85 p-6 backdrop-blur-sm">
                        <img @click.stop src="{{ $photo }}" alt="{{ $child->first_name }} {{ $child->last_name }}"
                             x-show="open" x-transition.scale.origin.center
                             class="max-h-[85vh] max-w-[92vw] rounded-2xl object-contain shadow-2xl">
                        <p class="absolute bottom-6 text-sm font-semibold text-white/70">{{ $child->first_name }} {{ $child->last_name }}</p>
                        <button type="button" @click="open = false" class="absolute right-5 top-5 grid h-10 w-10 place-items-center rounded-full bg-white/10 text-lg text-white ring-1 ring-white/25 transition hover:bg-white/20" aria-label="Close">✕</button>
                    </div>
                </div>
            @else
                {{-- No photograph to open. For the director the tile is the way
                     to go and add one; for a teacher it is simply the face. --}}
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('children.edit', $child) }}" class="group relative block shrink-0" title="Add a photo">
                        <x-child-avatar :child="$child" size="h-24 w-24 sm:h-32 sm:w-32" shape="rounded-2xl" class="ring-2 ring-white/40 transition group-hover:ring-white/80" />
                        <span class="absolute inset-0 grid place-items-center rounded-2xl bg-slate-950/40 text-xs font-semibold opacity-0 transition group-hover:opacity-100">Add a photo</span>
                    </a>
                @else
                    <x-child-avatar :child="$child" size="h-24 w-24 sm:h-32 sm:w-32" shape="rounded-2xl" class="shrink-0 ring-2 ring-white/40" />
                @endif
            @endif
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $child->first_name }} {{ $child->last_name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-white/15 px-2.5 py-1 ring-1 ring-white/25">LAN {{ $child->lan }}</span>
                    <span class="rounded-full bg-white/15 px-2.5 py-1 ring-1 ring-white/25"><x-room-icon :room="$child->classroom" size="text-sm" /> {{ $child->classroom }}</span>
                    <span class="rounded-full px-2.5 py-1 ring-1 {{ $child->status === 'Active' ? 'bg-emerald-400/25 text-emerald-50 ring-emerald-200/40' : 'bg-white/10 text-white/80 ring-white/25' }}">{{ $child->status }}</span>
                    @if(filled($child->nickname))<span class="rounded-full bg-white/15 px-2.5 py-1 ring-1 ring-white/25">“{{ $child->nickname }}”</span>@endif
                </div>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            {{-- Back to wherever this record was opened from, not to one named
                 page: it is reached from the roster, from the attendance sheet
                 and from a search, and only one of those was ever the roster. --}}
            <a href="{{ $back }}" class="rounded-xl bg-white/10 px-4 py-2.5 text-sm font-semibold ring-1 ring-white/25 transition hover:bg-white/20">← Back</a>
            {{-- Whoever may read this record may keep it up to date. A teacher
                 gets the contact details, the pick-up list and the notes; what
                 decides rooms, enrolment and billing stays the director's, and
                 the form shows those as set rather than offering them. --}}
            <a href="{{ route('children.edit', $child) }}" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-indigo-700 shadow-lg shadow-indigo-900/20 transition hover:-translate-y-0.5">Edit record</a>
        </div>
    </div>

    {{-- Five figures now the days are among them, so the row breaks 3+2 on a
         tablet and runs clean across a laptop rather than leaving one figure
         stranded on a line of its own. --}}
    <dl class="relative grid grid-cols-2 gap-px border-t border-white/15 bg-white/15 sm:grid-cols-3 lg:grid-cols-5">
        @foreach([
            ['Their hours', $child->scheduleLabel() ?? 'Not agreed'],
            ['Days they attend', $child->scheduleDaysLabel() ?? 'Not set'],
            [$child->classroom.' runs', $roomSchedule?->hoursLabel() ?? 'Not set'],
            ['Date of birth', $child->ageLabel() ?? '—'],
            ['Expected a week', filled($child->expected_hours_per_week) ? rtrim(rtrim(number_format($child->expected_hours_per_week, 2), '0'), '.').' hours' : 'Not agreed'],
        ] as [$label, $value])
            {{-- Tinted rather than painted over, so the wallpaper carries on
                 behind the figures instead of stopping at the strip. --}}
            <div class="bg-indigo-800/35 px-6 py-4 backdrop-blur-[2px]">
                {{-- A shade darker than the banner and a shade brighter in the
                     label: the wallpaper behind it costs contrast, and these
                     are the four figures the page exists to be read for. --}}
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-white/75">{{ $label }}</dt>
                <dd class="mt-1 text-sm font-bold sm:text-base">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</section>

{{-- The line that changes what somebody does in the next five minutes, above
     everything it would otherwise be scrolled past for. --}}
{{-- The alerts above the paragraph they summarise, because a relief teacher
     reads down this page and the chips are what they came for. --}}
@if($child->alertList())
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach($child->alertList() as $alert)
            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold {{ $alert['classes'] }}">
                <span class="h-1.5 w-1.5 rounded-full bg-current opacity-60" aria-hidden="true"></span>
                {{ $alert['label'] }}: {{ $alert['text'] }}
            </span>
        @endforeach
    </div>
@endif

@if(filled($child->important_notes))
    <div class="mt-6 flex gap-4 rounded-2xl border border-amber-300/70 bg-amber-50 p-5 dark:border-amber-400/30 dark:bg-amber-500/10">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-amber-400/25 text-lg">⚠</span>
        <div>
            <h2 class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Important</h2>
            <p class="mt-1.5 whitespace-pre-line text-sm font-medium text-amber-900 dark:text-amber-100">{{ $child->important_notes }}</p>
        </div>
    </div>
@endif

<div class="mt-6 grid gap-5 xl:grid-cols-3">
    <div class="space-y-5 xl:col-span-2">
        {{-- Parents first and in full: this is the panel that gets used. --}}
        <section class="glass-card rounded-2xl p-6">
            <h2 class="card-title"><span class="card-icon">👪</span> Parents &amp; guardians</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                @forelse($guardians as $guardian)
                    <div class="rounded-2xl bg-slate-50/80 p-4 dark:bg-white/5">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ $guardian['title'] }}</p>
                        <p class="mt-1 text-base font-bold">{{ $guardian['name'] ?: 'Name not on file' }}</p>
                        <dl class="mt-3 divide-y divide-slate-200/70 text-sm dark:divide-white/10">
                            @foreach($guardian['rows'] as [$label, $value, $type])
                                <div class="flex items-baseline justify-between gap-3 py-2">
                                    <dt class="shrink-0 text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</dt>
                                    <dd class="text-right font-medium">
                                        @if($type === 'tel')
                                            <a href="tel:{{ $digits($value) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $value }}</a>
                                        @elseif($type === 'mail')
                                            <a href="mailto:{{ $value }}" class="break-all text-indigo-600 hover:underline dark:text-indigo-400">{{ $value }}</a>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @empty
                    <p class="rounded-2xl bg-slate-50/80 p-6 text-center text-sm text-slate-500 sm:col-span-2 dark:bg-white/5 dark:text-slate-400">No parent details are on file yet.</p>
                @endforelse
            </div>
        </section>

        @if($household->isNotEmpty())
            <section class="glass-card rounded-2xl p-6">
                <h2 class="card-title"><span class="card-icon">🏠</span> Household</h2>
                <dl class="mt-4 divide-y divide-slate-100 text-sm dark:divide-white/10">
                    @foreach($household as [$label, $value, $type])
                        <div class="flex items-baseline justify-between gap-4 py-2.5">
                            <dt class="shrink-0 text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</dt>
                            <dd class="text-right font-medium">
                                @if($type === 'tel')
                                    <a href="tel:{{ $digits($value) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $value }}</a>
                                @elseif($type === 'mail')
                                    <a href="mailto:{{ $value }}" class="break-all text-indigo-600 hover:underline dark:text-indigo-400">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if(filled($child->other_notes))
            <section class="glass-card rounded-2xl p-6">
                <h2 class="card-title"><span class="card-icon">📝</span> Notes</h2>
                <p class="mt-4 whitespace-pre-line text-sm leading-relaxed">{{ $child->other_notes }}</p>
            </section>
        @endif
    </div>

    <div class="space-y-5">
        {{-- Read under pressure with a stranger at the door, so it says who they
             are, what they were asked to bring, and gives the number to ring. --}}
        <section class="glass-card rounded-2xl p-6">
            <h2 class="card-title"><span class="card-icon">🪪</span> Authorised for pick-up</h2>
            <ul class="mt-4 space-y-3">
                @forelse($pickups as $index => $pickup)
                    <li class="flex gap-3 rounded-2xl bg-slate-50/80 p-3.5 dark:bg-white/5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">{{ $loop->iteration }}</span>
                        <div class="min-w-0 text-sm">
                            <p class="font-bold">{{ $pickup['name'] }}</p>
                            @if(filled($pickup['relationship']))<p class="text-xs text-slate-500 dark:text-slate-400">{{ $pickup['relationship'] }}</p>@endif
                            @if(filled($pickup['telephone']))<a href="tel:{{ $digits($pickup['telephone']) }}" class="mt-1 inline-block font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $pickup['telephone'] }}</a>@endif
                            @if(filled($pickup['licence']))<p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">Licence {{ $pickup['licence'] }}</p>@endif
                        </div>
                    </li>
                @empty
                    <li class="rounded-2xl bg-slate-50/80 p-5 text-center text-sm text-slate-500 dark:bg-white/5 dark:text-slate-400">Nobody is on file. The parents on the record collect.</li>
                @endforelse
            </ul>
        </section>

        {{-- Break glass. Its own colour so it is never mistaken for one more
             contact block on a page full of them. --}}
        <section class="rounded-2xl border border-rose-200 bg-rose-50/70 p-6 dark:border-rose-400/20 dark:bg-rose-500/10">
            <h2 class="card-title"><span class="card-icon bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300">🚑</span> Emergency</h2>
            @if(filled($child->emergency_contact) || filled($child->emergency_telephone))
                <p class="mt-4 text-base font-bold">{{ $child->emergency_contact ?: 'Contact not named' }}</p>
                @if(filled($child->emergency_relationship))<p class="text-xs text-slate-500 dark:text-slate-400">{{ $child->emergency_relationship }}</p>@endif
                @if(filled($child->emergency_telephone))
                    <a href="tel:{{ $digits($child->emergency_telephone) }}" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-500/25 transition hover:bg-rose-700">📞 {{ $child->emergency_telephone }}</a>
                @endif
                @if(filled($child->secondary_emergency_contact))
                    <p class="mt-4 border-t border-rose-200/70 pt-3 text-sm dark:border-rose-400/20"><span class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Second</span><br>{{ $child->secondary_emergency_contact }}</p>
                @endif
            @else
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">No emergency contact is on file. This is worth chasing.</p>
            @endif
        </section>

        <section class="glass-card rounded-2xl p-6">
            <h2 class="card-title"><span class="card-icon">🗓</span> Enrolment</h2>
            <dl class="mt-4 space-y-3 text-sm">
                {{-- The subsidy numbers, when there are any. Shown here rather
                     than only on the edit form: billing reads them, and reading
                     a record should not mean opening it for editing. Monospaced,
                     because these get copied onto a claim by eye and an O beside
                     a 0 in a proportional face is a rejected claim. --}}
                @foreach(['DSS Case No' => $child->dss_case_no, 'DSS CIN' => $child->dss_cin] as $label => $number)
                    @if(filled($number))
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="font-mono text-[13px] font-semibold tracking-wide">{{ $number }}</dd>
                        </div>
                    @endif
                @endforeach
                {{-- What they are now and since when, above the two dates that
                     say what was planned. The badge at the top of the page says
                     "Inactive"; the next thing a reader wants is when, and
                     withdrawn_on cannot answer it because it is a box somebody
                     has to remember to fill in. This is recorded whether they
                     remembered or not. --}}
                @php($became = $statusChanges->firstWhere('to_status', $child->status))
                @if($child->status !== 'Active' && $became?->from_status !== null)
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">{{ $child->status }} since</dt>
                        <dd class="font-semibold">{{ $became->created_at->format('n/j/Y') }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Enrolled</dt>
                    <dd class="font-semibold">{{ $child->enrolled_on?->format('n/j/Y') ?? 'Always been here' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Withdrawn</dt>
                    <dd class="font-semibold">{{ $child->withdrawn_on?->format('n/j/Y') ?? 'Still enrolled' }}</dd>
                </div>

                {{-- What their place on the roll has actually done, as against
                     the two dates above, which say what was planned for it.

                     Only once there is something to tell: a record that has
                     been Active since the day it was made says so in the badge
                     at the top of the page, and repeating it here as "Added as
                     Active" would be a panel that teaches the reader to skip
                     this part of the record. --}}
                @if($statusChanges->contains(fn ($change) => $change->from_status !== null))
                    <div class="border-t border-slate-200/70 pt-3 dark:border-white/10">
                        <dt class="mb-1.5 text-slate-500 dark:text-slate-400">On the roll</dt>
                        <dd>
                            <ul class="space-y-1">
                                @foreach($statusChanges as $change)
                                    <li class="flex justify-between gap-4">
                                        <span class="font-semibold">{{ $change->summary() }}</span>
                                        <span class="whitespace-nowrap text-right text-slate-500 dark:text-slate-400">
                                            {{ $change->created_at->format('n/j/Y') }}
                                            @if($change->changedBy)
                                                <span class="block text-[11px]">by {{ $change->changedBy->name }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </dd>
                    </div>
                @endif
                @if(filled($child->gender))
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Girl or boy</dt>
                        <dd class="font-semibold">{{ $child->gender }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Room</dt>
                    <dd class="text-right font-semibold">
                        {{ $child->classroom }}
                        {{-- A room the director chose is a decision worth seeing on
                             the record, since it is the one thing here the date of
                             birth does not explain. --}}
                        @if(filled($child->classroom_override))<span class="mt-0.5 block text-xs font-medium text-amber-600 dark:text-amber-400">Set by hand{{ $child->classroom_override_from ? ' from '.$child->classroom_override_from->format('n/j/Y') : '' }}</span>@endif
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</div>
@endsection
