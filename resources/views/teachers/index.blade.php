@extends('layouts.app')
@section('title', 'Staff')
@section('content')
{{--
    The staff list.

    Read for two different reasons, and it has to serve both: "who works here"
    — the roll, the rooms, the addresses — and "where is everybody this
    morning", which is the status column and the last thing each of them did.
--}}
<div x-data="{ search: '' }">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Staff</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Everyone on the payroll, and where they stand today.</p>
        </div>
        <a href="{{ route('teachers.create') }}" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 transition hover:bg-indigo-700">+ Add staff</a>
    </div>

    <section class="glass-card mt-5 rounded-2xl p-5">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Filters</p>
        <form method="GET" class="mt-3 flex flex-wrap items-end gap-4" @change="$event.target.form.submit()">
            {{-- Changing a filter should not quietly put them back on
                 twenty-five rows when they had asked for a hundred. --}}
            <input type="hidden" name="per_page" value="{{ $perPage }}">
            <label>
                <span class="cs-label">Role</span>
                <select name="role" class="cs-input w-44">
                    <option value="">All roles</option>
                    @foreach($roles as $option)
                        <option value="{{ $option }}" @selected($role === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="cs-label">Classroom</span>
                <select name="classroom" class="cs-input w-44">
                    <option value="">All classrooms</option>
                    @foreach($classrooms as $option)
                        <option value="{{ $option }}" @selected($classroom === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            {{-- Three states, because "not in" is the one a director acts on at
                 nine in the morning and folding it into "out" would hide it. --}}
            <fieldset>
                <span class="cs-label">Status</span>
                <div class="mt-1 flex items-center gap-3 text-sm">
                    @foreach(['' => 'All', 'in' => 'In', 'out' => 'Out'] as $value => $label)
                        <label class="inline-flex items-center gap-1.5">
                            <input type="radio" name="status" value="{{ $value }}" @checked($status === $value) class="h-4 w-4">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </form>
    </section>

    <section class="glass-card mt-5 overflow-hidden rounded-2xl">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-white/10">
            <label class="ml-auto">
                <input x-model="search" class="w-56 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm dark:border-white/10 dark:bg-slate-800" placeholder="Search…">
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 dark:border-white/10">
                        <th class="px-4 py-3 font-semibold">Photo</th>
                        <th class="px-4 py-3 font-semibold">ID</th>
                        <th class="px-4 py-3 font-semibold">Name</th>
                        <th class="px-4 py-3 font-semibold">Role</th>
                        <th class="px-4 py-3 font-semibold">Classroom</th>
                        <th class="px-4 py-3 font-semibold">Email</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">Last activity</th>
                        <th class="px-4 py-3 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse($teachers as $teacher)
                        <tr class="transition hover:bg-slate-50/60 dark:hover:bg-white/5"
                            x-show="@js(strtolower($teacher->name.' '.$teacher->staffId().' '.$teacher->email.' '.$teacher->classroom)).includes(search.toLowerCase().trim())">
                            <td class="px-4 py-3">
                                @if($teacher->avatar_url)
                                    <img src="{{ $teacher->avatar_url }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                @else
                                    <span class="grid h-8 w-8 place-items-center rounded-full bg-indigo-100 text-[11px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">{{ $teacher->initials }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('teachers.show', $teacher) }}" class="font-semibold tabular-nums text-indigo-600 underline-offset-2 hover:underline dark:text-indigo-400">{{ $teacher->staffId() }}</a>
                            </td>
                            <td class="px-4 py-3 font-semibold">{{ $teacher->name }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $teacher->jobRole() }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $teacher->classroom ?: '—' }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $teacher->email }}</td>
                            <td class="px-4 py-3">
                                @php($badge = [
                                    'in' => ['In', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300'],
                                    'out' => ['Out', 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200'],
                                    'not_in' => ['Not in', 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400'],
                                ][$teacher->clock_state])
                                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ $badge[1] }}">{{ $badge[0] }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
                                @if($teacher->last_activity)
                                    {{ $teacher->last_activity['type'] === 'IN' ? 'In at' : 'Out at' }}
                                    {{ $teacher->last_activity['at']->format('g:i A') }}
                                    <span class="text-slate-400">&middot; {{ $teacher->last_activity['at']->format('M j') }}</span>
                                @else
                                    No activity today
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('teachers.edit', $teacher) }}" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-12 text-center text-slate-500">No staff match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-500 dark:border-white/10 dark:text-slate-400">
            <form method="GET" class="flex items-center gap-2">
                @foreach(request()->only('role', 'classroom', 'status') as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <span class="text-xs">Rows per page</span>
                <select name="per_page" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-slate-800">
                    @foreach([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </form>

            <span class="text-xs">Showing {{ $teachers->firstItem() ?? 0 }} to {{ $teachers->lastItem() ?? 0 }} of {{ $teachers->total() }} entries</span>

            <div>{{ $teachers->links() }}</div>
        </div>
    </section>
</div>
@endsection
