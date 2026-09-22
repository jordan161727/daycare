@extends('layouts.app')
@section('title', 'Administrators')
@section('content')
{{-- Who may open the director's screens.

     A list rather than a manager: an account is made, renamed and retired on
     the staff record, and a second place to do it is a second place for the two
     to disagree. What this answers is "who has the keys", which is a question
     with no screen behind it until now. --}}

<div class="mx-auto max-w-5xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-bold tracking-tight">Administrators</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Everyone who can open the director's screens — pay rates, reports, settings and this page.</p>

        <section class="glass-card mt-6 rounded-2xl p-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-white/10 dark:text-slate-400">
                        <th scope="col" class="px-3 py-2 font-semibold">Name</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Email</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Department</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Card</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($admins as $admin)
                        <tr class="border-b border-slate-100 last:border-0 dark:border-white/5">
                            <td class="px-3 py-3 font-semibold">{{ $admin->name }}</td>
                            <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $admin->email }}</td>
                            <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $admin->department?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-slate-500 dark:text-slate-400">
                                {{ $admin->card_issued_at ? 'Issued '.$admin->card_issued_at->format('M j, Y') : 'None' }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                <a href="{{ route('teachers.show', $admin) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Open record</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Said plainly, because the alternative is somebody hunting this page
             for an "Add administrator" button that was never here. --}}
        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
            Somebody becomes an administrator on their own staff record. This page is the list, not the way in.
        </p>
    </div>
</div>
@endsection
