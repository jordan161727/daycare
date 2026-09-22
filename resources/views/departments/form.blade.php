{{-- Shared by add and edit, because the two differ only in where they post and
     what the button says. A department is a name and a note; anything more
     belongs on the thing that holds it. --}}
<div class="mx-auto max-w-3xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('departments.index') }}" class="grid h-9 w-9 place-items-center rounded-xl text-slate-500 transition hover:bg-slate-100 dark:hover:bg-white/10" aria-label="Back to departments">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ $heading }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $subheading }}</p>
        </div>
    </div>

    <form method="POST" action="{{ $action }}" class="glass-card mt-6 rounded-2xl p-6">
        @csrf
        @isset($method)
            @method($method)
        @endisset

        <h2 class="text-sm font-semibold">Department Information</h2>

        <label class="mt-5 block">
            <span class="mb-2 block text-sm font-semibold">Department Name <span class="text-rose-500">*</span></span>
            <input name="name" required maxlength="120" value="{{ old('name', $department->name) }}" placeholder="Kitchen"
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
            <x-input-error :messages="$errors->get('name')" />
        </label>

        <label class="mt-5 block">
            <span class="mb-2 block text-sm font-semibold">Notes</span>
            <input name="notes" maxlength="500" value="{{ old('notes', $department->notes) }}" placeholder="Brief notes about the department"
                   class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
            <x-input-error :messages="$errors->get('notes')" />
        </label>

        <div class="mt-6 flex justify-end gap-2">
            <a href="{{ route('departments.index') }}" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Cancel</a>
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">{{ $submit }}</button>
        </div>
    </form>
</div>
