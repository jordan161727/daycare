@extends('layouts.app')
@section('title', $title)
@section('content')
{{-- A page that is on the menu before it is built (Phase 1.1 puts the whole
     menu in place first; the modules come in their own phases). Says so,
     and where the thing lives meanwhile, rather than a 404 off the sidebar. --}}
<div class="mx-auto max-w-3xl">
    <x-page-header :title="$title" :subtitle="$subtitle" />
    <section class="glass-card mt-6 rounded-2xl p-6">
        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $body }}</p>
        @isset($link)
            <a href="{{ $link['href'] }}" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">{{ $link['label'] }} <span>→</span></a>
        @endisset
    </section>
</div>
@endsection
