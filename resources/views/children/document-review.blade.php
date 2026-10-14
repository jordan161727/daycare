@extends('layouts.app')
@section('title', 'Review imported details')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
    <div class="min-w-0">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Daycare management</p>
        <h1 class="text-xl font-bold tracking-tight sm:text-2xl">Review imported details</h1>
    </div>
    <div class="flex items-center gap-2">
        @if($pending > 1)
            {{-- Where this form sits in the pile. Saving or skipping opens the next. --}}
            <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200" data-import-position>Form {{ $position }} of {{ $pending }}@if($waiting > 0) · {{ $waiting }} more waiting @endif</span>
        @endif
        {{-- Set this form aside without a child: a duplicate, a blank page, the wrong scan. --}}
        <form method="POST" action="{{ route('children.document-import.skip', $token) }}" onsubmit="return confirm('Skip this form? Nothing is saved from it.')">@csrf<button type="submit" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Skip this form</button></form>
        <a href="{{ route('children.document-import.create') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">← Import more forms</a>
    </div>
</div>
@if(session('success'))<p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</p>@endif
@if(filled(session('unread_documents')))<p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">Could not be read and set aside: {{ implode(', ', session('unread_documents')) }}. Rescan and upload again.</p>@endif
<p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">Compare each field against the document on the right. Fields tagged <span class="rounded-md bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200">From form</span> were filled in from the upload — correct anything the extractor misread before saving.</p>

<div class="mt-4 grid items-start gap-4 lg:grid-cols-2">
    <div class="order-2 min-w-0 lg:order-1">
        @include('children.form', [
            'action' => route('children.store'),
            'method' => 'POST',
            'child' => null,
            'submit' => 'Save child',
            'extracted' => $extracted,
            'nextLan' => $nextLan,
            'importToken' => $token,
        ])
    </div>

    <aside x-data="{ fit: true, page: 1 }" class="order-1 min-w-0 lg:order-2 lg:sticky lg:top-24">
        <div class="glass-card flex h-[60vh] flex-col overflow-hidden rounded-2xl lg:h-[calc(100vh-8rem)]">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-2.5 dark:border-white/10">
                @if(count($pages) > 1)
                    {{-- One page at a time, chosen by its tab, opening on page 1:
                         stacked, the panel could be scrolled to page two and
                         read as if that were the whole form. --}}
                    <div class="flex min-w-0 items-center gap-1" role="tablist" aria-label="Pages of the form">
                        @foreach($pages as $index => $page)
                            <button type="button" role="tab" @click="page = {{ $index + 1 }}" :aria-selected="page === {{ $index + 1 }} ? 'true' : 'false'"
                                    :class="page === {{ $index + 1 }} ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'"
                                    class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ $page['name'] }}" data-import-page>Page {{ $index + 1 }} · {{ $page['name'] }}</button>
                        @endforeach
                    </div>
                @else
                    <p class="min-w-0 truncate text-sm font-semibold" title="{{ $documentName }}">{{ $documentName }}</p>
                @endif
                <div class="flex shrink-0 items-center gap-1">
                    @unless($isPdf)
                        <button type="button" @click="fit = ! fit" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" x-text="fit ? 'Actual size' : 'Fit width'"></button>
                    @endunless
                    @foreach($pages as $index => $page)
                        <a x-show="page === {{ $index + 1 }}" href="{{ $page['url'] }}" target="_blank" rel="noopener" class="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-indigo-500/10">Open in new tab ↗</a>
                    @endforeach
                </div>
            </div>
            {{-- The chosen page. The second page — allergies, pediatrician,
                 insurance — is read beside the notes it was folded into. --}}
            @foreach($pages as $index => $page)
                @if($page['isPdf'])
                    <iframe x-show="page === {{ $index + 1 }}" src="{{ $page['url'] }}#view=FitH" title="Page {{ $index + 1 }} of the enrollment form" class="min-h-0 flex-1 bg-slate-100 dark:bg-slate-900"></iframe>
                @else
                    <div x-show="page === {{ $index + 1 }}" class="min-h-0 flex-1 overflow-auto bg-slate-100 p-3 dark:bg-slate-900">
                        <img src="{{ $page['url'] }}" alt="Page {{ $index + 1 }} of the enrollment form" :class="fit ? 'w-full' : 'max-w-none'" class="mx-auto rounded-lg shadow-sm">
                    </div>
                @endif
            @endforeach
        </div>
    </aside>
</div>
@endsection
