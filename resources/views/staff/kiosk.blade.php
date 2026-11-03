@extends('layouts.app')
@section('title', 'Kiosk')
@section('content')
{{-- The sidebar's Kiosk (Jordan, 4 Nov 2026): the room tablet as a page of
     the app, with the sidebar beside it like every other page, rather than a
     tab of its own. The tablet is the real /room page, framed — the same one
     a classroom iPad shows — with no device outline round it (2 Nov 2026).
     The gear and a room code still say which room it is. --}}
<div class="flex flex-col gap-3" data-kiosk-page>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Kiosk</h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Room tablet · tap ⚙ and key a room code to change which room it’s in.</p>
    </div>
    <iframe src="{{ route('room.kiosk') }}" title="Room tablet"
            class="block w-full rounded-2xl border border-slate-200 bg-[#f4f6fb] dark:border-white/10"
            style="height: calc(100dvh - 9rem); min-height: 640px;" data-kiosk-frame></iframe>
</div>
@endsection
