@extends('layouts.app')

@section('title', 'My Profile')

@php
    // Stored as codes so the scheduler can match on them; spelled out here.
    $employmentLabels = [
        'LEAD' => 'Lead teacher',
        'FT' => 'Full time',
        'FT_SALARY' => 'Full time (salaried)',
        'PT' => 'Part time',
        'SUB' => 'Substitute',
    ];

    $employment = [
        'Employment' => $employmentLabels[$user->employment] ?? $user->employment,
        'Role' => ucfirst($user->role ?? 'staff'),
        'Room' => $user->title,
        'Classrooms' => implode(', ', $user->assignedClassrooms()) ?: null,
        'Legal name (payroll)' => $user->legal_name,
        'Started' => $user->start_date?->format('M j, Y'),
        'ASPIRE ID' => $user->aspire_id,
    ];

    $facts = [
        ['label' => 'Email', 'value' => $user->email, 'icon' => 'M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
        ['label' => 'Phone #', 'value' => $user->phone, 'icon' => 'M3 5a2 2 0 012-2h2.6a1 1 0 01.97.757l.9 3.6a1 1 0 01-.28.96l-1.6 1.6a14 14 0 006.5 6.5l1.6-1.6a1 1 0 01.96-.28l3.6.9a1 1 0 01.75.97V19a2 2 0 01-2 2h-1C9.7 21 3 14.3 3 6V5z'],
        ['label' => 'Birthday', 'value' => $user->dob?->format('M j, Y'), 'icon' => 'M12 8a2 2 0 100-4 2 2 0 000 4zm0 0v3m-7 3a3 3 0 003-3h8a3 3 0 003 3m-14 0v5a1 1 0 001 1h12a1 1 0 001-1v-5m-14 0a3 3 0 013-3h8a3 3 0 013 3'],
        ['label' => 'Started', 'value' => $user->start_date?->format('M j, Y'), 'icon' => 'M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
    ];

    $rolePill = $user->isAdmin()
        ? 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'
        : 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30';
@endphp

@section('content')
<x-page-header title="My Profile" subtitle="Update your photo, contact details, and password." />

@if(session('success'))
    <div x-data="{ shown: true }" x-show="shown" x-transition.opacity class="mt-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-200">
        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span class="flex-1">{{ session('success') }}</span>
        <button type="button" @click="shown = false" class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
    </div>
@endif

<div x-data="avatarPicker">

    {{-- The photo lives up here with the name, where a profile page puts it, and
         reaches the details form below by id. --}}
    <section class="glass-card mt-6 overflow-hidden rounded-2xl">
        <div class="h-24 bg-gradient-to-r from-indigo-500 via-violet-500 to-indigo-400 sm:h-28"></div>

        <div class="px-6 pb-6 sm:px-8 sm:pb-8">
            <div class="-mt-12 flex flex-wrap items-end gap-5 sm:-mt-14">
                <div class="relative">
                    <template x-if="preview">
                        <img :src="preview" alt="" class="h-24 w-24 rounded-full object-cover ring-4 ring-white dark:ring-slate-900 sm:h-28 sm:w-28">
                    </template>
                    <div x-show="!preview">
                        <x-user-avatar :user="$user" size="h-24 w-24 sm:h-28 sm:w-28" text="text-2xl" class="bg-indigo-100 text-indigo-700 ring-4 ring-white dark:bg-slate-800 dark:text-indigo-300 dark:ring-slate-900" />
                    </div>

                    <label for="photo-input" class="absolute bottom-0 right-0 grid h-9 w-9 cursor-pointer place-items-center rounded-full bg-indigo-600 text-white shadow-lg ring-2 ring-white transition hover:bg-indigo-700 dark:ring-slate-900" title="Change photo">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h1.6l1-2h6.8l1 2H19a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3" stroke-width="2"/></svg>
                        <span class="sr-only">Change photo</span>
                    </label>
                    <input id="photo-input" type="file" name="photo" form="profile-form" accept="image/jpeg,image/png,image/webp" class="sr-only" x-ref="photo" @change="select($event)">
                </div>

                <div class="min-w-0 flex-1 pb-1">
                    <h2 class="truncate text-xl font-bold sm:text-2xl">{{ $user->name }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $rolePill }}">{{ ucfirst($user->role ?? 'staff') }}</span>
                        @foreach($user->assignedClassrooms() as $classroom)
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-white/10">{{ $classroom }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3 pb-1">
                    <template x-if="preview">
                        <span class="flex items-center gap-2 text-xs font-medium text-indigo-600 dark:text-indigo-300">
                            <span x-text="filename"></span>
                            <button type="button" @click="clear()" class="font-semibold underline underline-offset-2 hover:text-indigo-800">Undo</button>
                        </span>
                    </template>
                    @if($user->avatar_path)
                        <button type="submit" form="remove-photo" x-show="!preview" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Remove photo</button>
                    @endif
                </div>
            </div>

            <p x-show="preview" x-cloak class="mt-4 rounded-xl bg-indigo-50 px-4 py-2.5 text-xs font-medium text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">This photo is not saved yet — press Save Changes below to keep it.</p>
            <x-input-error :messages="$errors->get('photo')" class="mt-3 text-sm" />

            {{-- The facts as they stand, so the page answers before it asks. --}}
            <dl class="mt-6 grid gap-4 border-t border-slate-200/70 pt-5 dark:border-white/10 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($facts as $fact)
                    <div class="flex items-center gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $fact['icon'] }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $fact['label'] }}</dt>
                            <dd class="truncate text-sm font-semibold">{{ $fact['value'] ?: '—' }}</dd>
                        </div>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">

        {{-- What the user owns. --}}
        <form id="profile-form" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-6 xl:col-span-2">
            @csrf
            @method('PUT')

            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="card-title">
                    <span class="card-icon"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1"/></svg></span>
                    Personal details
                </h2>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="form-label">Name <span class="text-rose-500">*</span></span>
                        <input name="name" value="{{ old('name', $user->name) }}" class="form-input" required>
                        <x-input-error :messages="$errors->get('name')" />
                    </label>
                    <label class="block">
                        <span class="form-label">Email <span class="text-rose-500">*</span></span>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-input" required>
                        <x-input-error :messages="$errors->get('email')" />
                    </label>
                    <label class="block">
                        <span class="form-label">Phone #</span>
                        <input name="phone" value="{{ old('phone', $user->phone) }}" placeholder="555-0142" class="form-input">
                        <x-input-error :messages="$errors->get('phone')" />
                    </label>
                    <label class="block">
                        <span class="form-label">Birthday</span>
                        <input type="date" name="dob" value="{{ old('dob', $user->dob?->toDateString()) }}" class="form-input">
                        <x-input-error :messages="$errors->get('dob')" />
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="form-label">Transport</span>
                        <input name="transport" value="{{ old('transport', $user->transport) }}" placeholder="Own car, bus, walks…" class="form-input">
                        <p class="mt-1.5 text-xs text-slate-500">How you get to the centre, so cover can be arranged when the weather turns.</p>
                        <x-input-error :messages="$errors->get('transport')" />
                    </label>
                </div>
            </section>

            <section class="glass-card rounded-2xl p-6 sm:p-8">
                <h2 class="card-title">
                    <span class="card-icon"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21s-7-4.5-7-9.5A4.5 4.5 0 0112 8a4.5 4.5 0 017 3.5c0 5-7 9.5-7 9.5z"/></svg></span>
                    Emergency contact
                </h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Who the daycare should call if something happens while you are on shift.</p>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="form-label">Emerg. contact</span>
                        <input name="emergency_contact" value="{{ old('emergency_contact', $user->emergency_contact) }}" placeholder="Name and relationship" class="form-input">
                        <x-input-error :messages="$errors->get('emergency_contact')" />
                    </label>
                    <label class="block">
                        <span class="form-label">Contact #</span>
                        <input name="emergency_phone" value="{{ old('emergency_phone', $user->emergency_phone) }}" placeholder="555-0199" class="form-input">
                        <x-input-error :messages="$errors->get('emergency_phone')" />
                    </label>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-4">
                <p x-show="preview" x-cloak class="text-xs font-medium text-slate-500">Includes your new photo.</p>
                <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500/40 focus:outline-none">Save Changes</button>
            </div>
        </form>

        {{-- What the director owns, and the one thing that saves on its own. --}}
        <div class="space-y-6">
            <section class="glass-card h-fit rounded-2xl p-6 sm:p-8">
                <h2 class="card-title">
                    <span class="card-icon"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0v4m-9 0h10a1 1 0 011 1v7a1 1 0 01-1 1H7a1 1 0 01-1-1v-7a1 1 0 011-1z"/></svg></span>
                    Employment
                </h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Set by an administrator. Ask the director if something here is wrong.</p>

                <dl class="mt-5 space-y-3">
                    @foreach($employment as $label => $value)
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-slate-200/60 pb-3 last:border-0 last:pb-0 dark:border-white/5">
                            <dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-sm font-semibold {{ $value ? '' : 'text-slate-400 dark:text-slate-600' }}">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <form method="POST" action="{{ route('profile.password.update') }}" class="glass-card h-fit rounded-2xl p-6 sm:p-8" x-data="{ show: false }">
                @csrf
                @method('PUT')
                <h2 class="card-title">
                    <span class="card-icon"><svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a4 4 0 11-4 4h-1l-2 2-2-2v-2l4-4a4 4 0 015 2z"/></svg></span>
                    Change password
                </h2>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">At least 8 characters. You stay signed in on this device.</p>

                <div class="mt-6 space-y-5">
                    <label class="block">
                        <span class="form-label">Current password <span class="text-rose-500">*</span></span>
                        <input type="password" name="current_password" autocomplete="current-password" class="form-input" required>
                        <x-input-error :messages="$errors->get('current_password')" />
                    </label>
                    <label class="block">
                        <span class="form-label">New password <span class="text-rose-500">*</span></span>
                        <input :type="show ? 'text' : 'password'" type="password" name="password" autocomplete="new-password" class="form-input" required>
                        <x-input-error :messages="$errors->get('password')" />
                    </label>
                    <label class="block">
                        <span class="form-label">Confirm new password <span class="text-rose-500">*</span></span>
                        <input :type="show ? 'text' : 'password'" type="password" name="password_confirmation" autocomplete="new-password" class="form-input" required>
                        <x-input-error :messages="$errors->get('password_confirmation')" />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                        <input type="checkbox" x-model="show" class="h-4 w-4 rounded accent-indigo-600">
                        Show new password
                    </label>
                </div>

                <button class="mt-6 w-full rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus:ring-2 focus:ring-slate-500/40 focus:outline-none dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Update Password</button>
            </form>
        </div>
    </div>
</div>

{{-- Kept outside the details form; the "Remove photo" button reaches it by id. --}}
@if($user->avatar_path)
    <form id="remove-photo" method="POST" action="{{ route('profile.photo.destroy') }}" class="hidden">@csrf @method('DELETE')</form>
@endif
@endsection
