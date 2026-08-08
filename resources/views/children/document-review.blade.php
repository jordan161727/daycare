@extends('layouts.app')
@section('title', 'Review imported details')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
    <div class="min-w-0">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Daycare management</p>
        <h1 class="text-xl font-bold tracking-tight sm:text-2xl">Review imported details</h1>
    </div>
    <a href="{{ route('children.document-import.create') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">← Import another form</a>
</div>
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

    <aside x-data="{ fit: true }" class="order-1 min-w-0 lg:order-2 lg:sticky lg:top-24">
        <div class="glass-card flex h-[60vh] flex-col overflow-hidden rounded-2xl lg:h-[calc(100vh-8rem)]">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-2.5 dark:border-white/10">
                <p class="min-w-0 truncate text-sm font-semibold" title="{{ $documentName }}">{{ $documentName }}</p>
                <div class="flex shrink-0 items-center gap-1">
                    @unless($isPdf)
                        <button type="button" @click="fit = ! fit" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" x-text="fit ? 'Actual size' : 'Fit width'"></button>
                    @endunless
                    <a href="{{ route('children.document-import.file', $token) }}" target="_blank" rel="noopener" class="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-indigo-500/10">Open in new tab ↗</a>
                </div>
            </div>
            @if($isPdf)
                <iframe src="{{ route('children.document-import.file', $token) }}#view=FitH" title="Uploaded enrollment form" class="min-h-0 flex-1 bg-slate-100 dark:bg-slate-900"></iframe>
            @else
                <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-3 dark:bg-slate-900">
                    <img src="{{ route('children.document-import.file', $token) }}" alt="Uploaded enrollment form" :class="fit ? 'w-full' : 'max-w-none'" class="mx-auto rounded-lg shadow-sm">
                </div>
            @endif
        </div>
    </aside>
</div>
@endsection
