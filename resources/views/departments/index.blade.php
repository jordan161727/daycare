@extends('layouts.app')
@section('title', 'Departments')
@section('content')
{{-- The parts of the centre.

     A short page on purpose: a centre has a handful of these, they are set up
     once, and the only thing that happens afterwards is a rename. What it has
     to do well is the headcount beside each one, because that is what makes
     removing a department a decision rather than a click. --}}

<div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Departments</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The parts of the centre staff are grouped into. Reports total hours by these, so a department is worth naming the way payroll says it.</p>
        </div>
        <a href="{{ route('departments.create') }}" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Add Department</a>
    </div>

    @if(session('status'))
        <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
    @endif

    <section class="glass-card mt-6 rounded-2xl p-6">
        @if($departments->isEmpty())
            <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                No departments yet. Staff work perfectly well without one — add these only if you want hours totalled by part of the centre.
            </p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-white/10 dark:text-slate-400">
                        <th scope="col" class="px-3 py-2 font-semibold">Department</th>
                        <th scope="col" class="px-3 py-2 font-semibold">Notes</th>
                        <th scope="col" class="px-3 py-2 text-center font-semibold">Staff</th>
                        <th scope="col" class="px-3 py-2 text-right font-semibold">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($departments as $department)
                        <tr class="border-b border-slate-100 last:border-0 dark:border-white/5">
                            <td class="px-3 py-3 font-semibold">{{ $department->name }}</td>
                            <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $department->notes ?: '—' }}</td>
                            <td class="px-3 py-3 text-center tabular-nums">{{ $department->staff_count }}</td>
                            <td class="px-3 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('departments.edit', $department) }}" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">Edit</a>
                                    {{-- Said out loud, with the headcount in it:
                                         nobody is deleted, but everybody in it
                                         comes out of it, and that is the part
                                         somebody would not have guessed. --}}
                                    <form method="POST" action="{{ route('departments.destroy', $department) }}"
                                          onsubmit="return confirm('Remove {{ $department->name }}? The {{ $department->staff_count }} {{ \Illuminate\Support\Str::plural('person', $department->staff_count) }} in it stay, but become unassigned.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-300 dark:hover:bg-rose-500/10">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
        Somebody's department is set on their staff record, under Employment details.
    </p>
</div>
@endsection
