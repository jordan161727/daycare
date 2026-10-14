@extends('layouts.app')
@section('title', 'Import Enrollment Form')
@section('content')
<div class="mx-auto max-w-5xl">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><x-page-header title="Import enrollment forms" subtitle="Turn scanned forms into ready-to-review child records — one at a time, or a whole pile." /><a href="{{ route('children.create') }}" class="inline-flex w-fit items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">← Back to child form</a></div>

    @if($errors->any())<div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">{{ $errors->first() }}</div>@endif
    @if(session('success'))<div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('success') }}</div>@endif
    @if(! empty($waiting))
        {{-- Forms from an earlier upload, still waiting for review. Named, so
             nothing surfaces later as a form nobody remembers; with a way to
             carry on, and a way to throw the pile away. --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-import-waiting>
            <p><b>{{ count($waiting) }} form{{ count($waiting) === 1 ? '' : 's' }} still waiting for review:</b> {{ collect($waiting)->pluck('name')->implode(', ') }}</p>
            <div class="flex items-center gap-2">
                <a href="{{ route('children.document-import.review', $waiting[0]['token']) }}" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Continue reviewing</a>
                <form method="POST" action="{{ route('children.document-import.clear') }}" onsubmit="return confirm('Discard every waiting form? Nothing is saved from them.')">@csrf<button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 dark:text-rose-300 dark:hover:bg-rose-500/10">Discard them</button></form>
            </div>
        </div>
    @endif

    <div class="mt-7 grid gap-6 lg:grid-cols-[1fr_20rem]">
        <form x-data="documentImport" @submit="submitForm" method="POST" action="{{ route('children.document-import.store') }}" enctype="multipart/form-data" class="glass-card rounded-3xl p-6 sm:p-8">@csrf
            <div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-100 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l4 4v14H7a2 2 0 01-2-2V5a2 2 0 012-2zm7 0v5h5M9 13h6m-6 4h4"/></svg></span><div><h2 class="text-lg font-bold">Upload the enrollment forms</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Gemini reads each form and fills in the details. Drop several and review them one after another.</p></div></div>
            <div @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop.prevent="handleDrop" :class="drag ? 'border-indigo-500 bg-indigo-50/70 dark:bg-indigo-500/10' : 'border-slate-300 bg-slate-50/70 dark:border-white/15 dark:bg-night-800/40'" class="relative mt-8 rounded-2xl border-2 border-dashed p-8 text-center transition sm:p-12">
                {{-- As many as are dropped on it. Choosing again adds to the pile. --}}
                <input x-ref="file" @change="selectFile" type="file" name="documents[]" multiple accept=".pdf,image/png,image/jpeg" class="absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0">
                <template x-if="preview"><img :src="preview" class="mx-auto mb-5 max-h-44 rounded-xl object-contain shadow-sm"></template>
                <template x-if="!preview"><span class="mx-auto mb-5 grid h-16 w-16 place-items-center rounded-2xl bg-white text-indigo-600 shadow-sm dark:bg-slate-900 dark:text-indigo-300"><svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 16V4m0 0L8 8m4-4l4 4M5 16v3a1 1 0 001 1h12a1 1 0 001-1v-3"/></svg></span></template>
                <p class="font-semibold" x-text="files.length ? (files.length === 1 ? files[0].name : files.length + ' files ready') : 'Drop your form here, or browse'"></p><p class="mt-2 text-sm text-slate-500 dark:text-slate-400" x-text="files.length ? totalSize() + ' · drop more to add them' : 'PDF, PNG, or JPG · maximum 10 MB each · up to 20 forms'"></p>
            </div>
            <p x-show="error" x-text="error" class="mt-3 text-sm font-medium text-rose-600" x-cloak></p>
            {{-- Two files can be two children or two pages of one. A phone
                 photographs a form a page at a time, so pages is the default;
                 a scanner that makes a PDF a child makes the other choice. --}}
            <fieldset x-show="files.length > 1" x-cloak class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50/60 px-4 py-3 text-sm dark:border-indigo-500/30 dark:bg-indigo-500/10" data-import-pages>
                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">These files are</legend>
                <label class="flex items-start gap-2 py-1"><input type="radio" name="pages" value="one" checked class="relative z-20 mt-0.5"> <span><b>Pages of one form</b> — one child; the pages are read together, in the order listed.</span></label>
                <label class="flex items-start gap-2 py-1"><input type="radio" name="pages" value="separate" class="relative z-20 mt-0.5"> <span><b>A separate form each</b> — one child per file, reviewed one after another.</span></label>
            </fieldset>
            <ul x-show="files.length" x-cloak class="mt-4 divide-y divide-slate-200 rounded-xl bg-slate-100 text-sm dark:divide-white/10 dark:bg-slate-800" data-import-files>
                <template x-for="(file, index) in files" :key="file.name + file.size">
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5"><span class="min-w-0"><span class="block truncate font-medium" x-text="file.name"></span><span class="text-xs text-slate-500 dark:text-slate-400" x-text="size(file)"></span></span><button type="button" @click="removeFile(index)" class="relative z-20 shrink-0 font-semibold text-rose-600" :aria-label="'Remove ' + file.name">Remove</button></li>
                </template>
            </ul>
            <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('children.create') }}" class="rounded-xl px-5 py-3 text-center text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</a><button :disabled="loading" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-70"><svg x-show="loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3"/></svg><span x-text="loading ? (files.length > 1 ? 'Reading ' + files.length + ' files…' : 'Reading form…') : (files.length > 1 ? 'Extract details from ' + files.length + ' files' : 'Extract details')"></span></button></div>
        </form>
        <aside class="space-y-4"><section class="glass-card rounded-2xl p-5"><h3 class="font-bold">What happens next?</h3><ol class="mt-4 space-y-4 text-sm text-slate-600 dark:text-slate-300"><li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">1</span><span>Upload clear scans or photos — one form or a whole pile.</span></li><li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">2</span><span>Gemini extracts the enrollment details.</span></li><li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700">3</span><span>Check each form side by side with its document and save; the next one opens by itself.</span></li></ol></section><section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"><p class="font-bold">Review carefully</p><p class="mt-1 leading-6">Handwriting and sensitive details such as phone numbers or SSNs may need correction.</p></section></aside>
    </div>
</div>
@endsection
