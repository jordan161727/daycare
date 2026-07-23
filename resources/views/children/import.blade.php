@extends('layouts.app')

@section('title', 'Import Children')

@section('content')
    <style>[x-cloak] { display: none !important; }</style>
    <main x-data="childrenImport" class="mx-auto max-w-3xl">
        <section class="overflow-hidden rounded-2xl bg-white shadow-xl shadow-slate-200/70">
            <header class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-8 sm:px-10">
                <h1 class="text-3xl font-bold text-white">Import children</h1>
                <p class="mt-2 text-blue-100">Upload a daycare roster to create or update child records.</p>
            </header>

            <form action="{{ route('children.import') }}" method="POST" enctype="multipart/form-data" @submit="submitForm($event)" class="p-6 sm:p-10">
                @csrf

                @if(session('success'))
                    <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                        <span class="text-lg" aria-hidden="true">✓</span>
                        <p class="font-medium">{{ session('success') }}</p>
                    </div>
                @endif

                @if(session('import_errors'))
                    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                        <p class="font-semibold">Some rows were not imported:</p>
                        <ul class="mt-2 list-inside list-disc space-y-1 text-sm">
                            @foreach(session('import_errors') as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($errors->any())
                    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
                        <p class="font-semibold">Please fix the following:</p>
                        <ul class="mt-2 list-inside list-disc space-y-1 text-sm">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <label
                    @dragover.prevent="drag = true"
                    @dragleave.prevent="drag = false"
                    @drop.prevent="handleDrop($event)"
                    :class="drag ? 'border-blue-600 bg-blue-50' : 'border-slate-300 bg-white hover:border-blue-400'"
                    class="block cursor-pointer rounded-xl border-2 border-dashed p-8 transition-colors"
                >
                    <input x-ref="file" @change="selectFile($event)" type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only">
                    <div class="flex flex-col items-center text-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-2xl text-blue-700" aria-hidden="true">↑</div>
                        <h2 class="mt-4 text-lg font-semibold">Select an Excel file</h2>
                        <p class="mt-1 text-sm text-slate-500">Drag and drop, or click to browse.</p>
                        <p class="mt-3 text-xs font-medium uppercase tracking-wide text-slate-400">XLSX, XLS, or CSV · maximum 5 MB</p>
                    </div>
                </label>

                <div x-show="filename" x-cloak x-transition class="mt-5 flex items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="min-w-0">
                        <p class="font-semibold text-emerald-800">Ready to import</p>
                        <p x-text="filename" class="mt-1 truncate text-sm text-slate-700"></p>
                        <p x-text="filesize" class="text-xs text-slate-500"></p>
                    </div>
                    <button @click.prevent="clearFile()" type="button" class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Remove</button>
                </div>

                <p x-show="error" x-cloak x-text="error" class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"></p>

                <button :disabled="!filename || error || loading" :class="(!filename || error || loading) ? 'cursor-not-allowed bg-slate-300' : 'bg-blue-600 hover:bg-blue-700'" type="submit" class="mt-7 w-full rounded-xl px-5 py-4 text-lg font-semibold text-white transition-colors">
                    Import children
                </button>
            </form>
        </section>

        <div x-show="loading" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-5">
            <div class="w-full max-w-sm rounded-2xl bg-white p-8 text-center shadow-2xl">
                <div class="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-blue-100 border-t-blue-600"></div>
                <p class="mt-4 text-lg font-semibold">Importing children…</p>
                <p class="mt-1 text-sm text-slate-500">Please keep this page open.</p>
            </div>
        </div>
    </main>
@endsection
