@extends('layouts.app')
@section('title', 'Login history')
@section('content')
{{-- Who signed in, and who tried and failed.

     The failures are why this screen exists, so they are not behind a filter:
     a run of them reads as a run only when it sits next to the successes it is
     interleaved with. --}}

<div class="mx-auto max-w-5xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-bold tracking-tight">Login History</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The last {{ $rows }} times somebody identified themselves, successful or not — at the sign-in form and at the kiosk. No password, PIN or card code is ever recorded.</p>

        @if($failures > 0)
            {{-- Stated as a number rather than left to be counted down the
                 page: six failures overnight is the thing somebody opens this
                 screen to find, and it is easy to miss in a list. --}}
            <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                {{ $failures }} failed {{ \Illuminate\Support\Str::plural('attempt', $failures) }} in the last 24 hours, across the sign-in form and the kiosk.
            </p>
        @endif

        <section class="glass-card mt-6 rounded-2xl p-6">
            @if($events->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nothing recorded yet. This is logged from the moment it was switched on, so an empty list is expected until somebody next signs in or presents a card.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-max text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-white/10 dark:text-slate-400">
                                <th scope="col" class="px-3 py-2 font-semibold">When</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Who</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Source</th>
                                <th scope="col" class="px-3 py-2 font-semibold">Outcome</th>
                                <th scope="col" class="px-3 py-2 font-semibold">IP address</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($events as $event)
                                <tr class="border-b border-slate-100 last:border-0 dark:border-white/5">
                                    <td class="px-3 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ $event->created_at?->format('M j, Y g:i A') }}</td>
                                    {{-- The account where there is one, the
                                         address as typed where there is not —
                                         which is what a failure against an
                                         account that does not exist looks
                                         like, and the pattern worth seeing. --}}
                                    <td class="px-3 py-2.5 font-medium">{{ $event->user?->name ?? ($event->email ?: 'Unknown') }}</td>
                                    {{-- Card and PIN name the kiosk; a
                                         password names the sign-in form. The
                                         distinction is the point of showing
                                         both on one page. --}}
                                    <td class="px-3 py-2.5 text-slate-500 dark:text-slate-400">{{ $event->source() }}</td>
                                    {{-- A kiosk press is a success, but a quieter
                                         one — sky rather than green. It happens
                                         dozens of times a day, and a page of
                                         green would drown the sign-ins it sits
                                         among. --}}
                                    <td class="px-3 py-2.5">
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-xs font-semibold',
                                            'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' => $event->failed(),
                                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' => $event->outcome === \App\Models\LoginEvent::SUCCESS,
                                            'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' => $event->outcome === \App\Models\LoginEvent::KIOSK,
                                            'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300' => $event->outcome === \App\Models\LoginEvent::LOGOUT,
                                        ])>{{ $event->label() }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-xs text-slate-500 dark:text-slate-400">{{ $event->ip_address ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
@endsection
