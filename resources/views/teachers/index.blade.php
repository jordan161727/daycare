@extends('layouts.app')

@section('title', 'Teachers')

@section('content')
<div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <x-page-header title="Teachers" subtitle="Create teacher accounts and assign the classrooms each teacher can access." />
    <a href="{{ route('teachers.create') }}" class="inline-flex w-fit items-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">+ Add Teacher</a>
</div>

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
@endif

<section class="glass-card mt-7 overflow-hidden rounded-2xl">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-800/60"><tr><th class="px-6 py-4 font-semibold">Teacher</th><th class="px-6 py-4 font-semibold">Email</th><th class="px-6 py-4 font-semibold">Assigned classroom</th><th class="px-6 py-4 font-semibold">Assigned students</th><th class="px-6 py-4 font-semibold">Actions</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @forelse($teachers as $teacher)
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-white/5">
                        <td class="px-6 py-4 font-semibold">{{ $teacher->name }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $teacher->email }}</td>
                        <td class="px-6 py-4">{{ implode(', ', $teacher->assignedClassrooms()) ?: 'Not assigned' }}</td>
                        <td class="px-6 py-4"><span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ $teacher->students_count }}</span></td>
                        <td class="px-6 py-4"><div class="flex items-center gap-4"><a href="{{ route('teachers.edit', $teacher) }}" class="font-semibold text-indigo-600 hover:text-indigo-800">Edit</a><form method="POST" action="{{ route('teachers.destroy', $teacher) }}" onsubmit="return confirm('Delete this teacher account?')">@csrf @method('DELETE')<button class="font-semibold text-rose-600 hover:text-rose-800">Delete</button></form></div></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">No teacher accounts have been added yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($teachers->hasPages())<div class="border-t border-slate-100 px-6 py-4 dark:border-white/10">{{ $teachers->links() }}</div>@endif
</section>
@endsection
