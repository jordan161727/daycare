@extends('layouts.app')
@section('title', 'Class Attendance')
@section('content')
<div x-data="attendanceApp()">
    <div class="flex flex-col gap-4">
        <section class="min-w-0 flex-1">
            <div class="glass-card rounded-2xl px-3 py-2.5">
                {{-- Title, live counts and the date picker share one line. --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h1 class="text-base font-bold tracking-tight sm:text-lg">Class Attendance</h1>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span><span class="font-bold text-slate-900 dark:text-white">{{ $totalChildren }}</span> enrolled</span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span><span class="font-bold" x-text="presentCount"></span> present</span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-2.5 py-1 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span><span class="font-bold" x-text="{{ $totalChildren }} - presentCount"></span> not signed in</span>
                    </div>
                    <form method="GET" action="{{ route('attendance.index') }}" class="flex w-full items-center gap-1.5 sm:ml-auto sm:w-auto">
                        <label class="sr-only" for="attendance-date">Attendance date</label>
                        <input id="attendance-date" type="date" name="date" value="{{ $selectedDate }}" class="min-w-0 flex-1 rounded-lg border-0 bg-slate-100 px-2 py-1.5 text-xs text-slate-800 sm:flex-none dark:bg-slate-800 dark:text-slate-100">
                        <button class="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">View</button>
                    </form>
                </div>
                {{-- Search and room filters on the second line. --}}
                <div class="mt-2.5 flex flex-col gap-1.5 border-t border-slate-200/70 pt-2.5 sm:flex-row sm:items-center dark:border-white/10">
                    <label class="relative w-full shrink-0 sm:w-56"><svg class="pointer-events-none absolute left-3 top-2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg><input x-model="search" class="w-full rounded-lg border-0 bg-slate-100 py-1.5 pl-9 pr-3 text-xs ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:ring-white/10" placeholder="Search children..."></label>
                    <span class="hidden h-5 w-px shrink-0 bg-slate-200 sm:block dark:bg-white/10"></span>
                    {{-- Rooms scroll sideways on a phone instead of stacking three rows deep. --}}
                    <div class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 sm:pb-0">
                        <button @click="room=''" :class="room === '' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'" class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition">All</button>
                        @foreach($classrooms as $classroom)
                            <button @click="room=@js($classroom)" :class="room === @js($classroom) ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'" class="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition">{{ $classroom }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <div class="glass-card overflow-hidden rounded-2xl">
                    {{-- Week grid: needs the width, so it only appears from md up. --}}
                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full min-w-[860px] border-collapse text-left">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                    <th scope="col" class="w-12 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-400">#</th>
                                    <th scope="col" :aria-sort="sortDirection === 'asc' ? 'ascending' : 'descending'" class="px-3 py-2.5 text-left">
                                        <button type="button" @click="toggleSort" class="group inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 transition hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-300" :title="sortDirection === 'asc' ? 'Sorted A–Z, click for Z–A' : 'Sorted Z–A, click for A–Z'">
                                            <span>Student</span>
                                            <span class="text-[10px] leading-none text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-300" x-text="sortDirection === 'asc' ? '▲ A–Z' : '▼ Z–A'"></span>
                                        </button>
                                    </th>
                                    @foreach($weekDates as $date)
                                        <th scope="col" class="px-2 py-2 text-center">
                                            <span class="block text-[11px] uppercase tracking-wide text-slate-400">{{ $date->format('D') }}</span>
                                            <span class="block text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $date->format('M d') }}</span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                <template x-for="(child, index) in filteredChildren" :key="child.id">
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                        <td class="px-3 py-2 text-sm text-slate-400" x-text="index + 1"></td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-3">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 text-xs font-bold text-indigo-700" x-text="(child.first_name.charAt(0) + child.last_name.charAt(0)).toUpperCase()"></span>
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-semibold" x-text="child.first_name + ' ' + child.last_name"></p>
                                                    <p class="truncate text-xs text-slate-500" x-text="child.classroom"></p>
                                                </div>
                                            </div>
                                        </td>
                                        @foreach($weekDates as $date)
                                            <td class="px-2 py-2 text-center align-middle">
                                                @include('attendance.partials.day-buttons', ['date' => $date, 'variant' => 'table'])
                                            </td>
                                        @endforeach
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    {{-- Phone layout: one card per child, one row per day. --}}
                    <div class="divide-y divide-slate-100 md:hidden dark:divide-white/10">
                        <div class="flex items-center justify-between px-3 py-2">
                            <button type="button" @click="toggleSort" class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                <span>Student</span>
                                <span class="text-[10px] leading-none text-slate-400" x-text="sortDirection === 'asc' ? '▲ A–Z' : '▼ Z–A'"></span>
                            </button>
                            <span class="text-[11px] text-slate-400">{{ $weekDates->first()->format('M d') }} – {{ $weekDates->last()->format('M d') }}</span>
                        </div>
                        <template x-for="(child, index) in filteredChildren" :key="'card-' + child.id">
                            <article class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 text-xs font-bold text-indigo-700" x-text="(child.first_name.charAt(0) + child.last_name.charAt(0)).toUpperCase()"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold" x-text="child.first_name + ' ' + child.last_name"></p>
                                        <p class="truncate text-xs text-slate-500" x-text="child.classroom"></p>
                                    </div>
                                    <span class="text-xs text-slate-400" x-text="'#' + (index + 1)"></span>
                                </div>
                                <div class="mt-2 space-y-1 rounded-xl bg-slate-50 p-1.5 dark:bg-slate-800/50">
                                    @foreach($weekDates as $date)
                                        <div class="flex items-center justify-between gap-2 rounded-lg px-2 py-1">
                                            <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $date->format('D') }} <span class="text-slate-400">{{ $date->format('M d') }}</span></span>
                                            <div class="flex shrink-0 items-center gap-1.5">
                                                @include('attendance.partials.day-buttons', ['date' => $date, 'variant' => 'card'])
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        </template>
                    </div>

                    <p x-show="filteredCount === 0" class="p-8 text-center text-sm text-slate-500">No children match this search.</p>
                    <div x-show="filteredCount > 0" class="border-t border-slate-200 px-4 py-2.5 text-sm text-slate-500 dark:border-white/10">
                        Showing all <span class="font-medium text-slate-700 dark:text-slate-200" x-text="filteredCount"></span> children
                    </div>
                </div>
            </div>
        </section>
        <section class="glass-card mt-4 rounded-2xl p-4 sm:p-5">
            <div class="border-b border-slate-200 pb-3 dark:border-white/10">
                <p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">LIVE UPDATES</p>
                <h2 class="mt-0.5 text-base font-bold sm:text-lg">Recent sign-ins</h2>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-white/10">
                <template x-for="signIn in recent" :key="signIn.id">
                    <div class="flex items-center gap-3 py-3">
                        <span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-100 text-emerald-700">✓</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" x-text="signIn.name"></p>
                            <p class="text-xs text-slate-500" x-text="signIn.classroom"></p>
                        </div>
                        <span class="text-xs font-medium text-slate-500" x-text="signIn.time"></span>
                    </div>
                </template>
                <p x-show="recent.length === 0" class="p-8 text-center text-sm text-slate-500">No sign-ins yet.</p>
            </div>
        </section>
    </div>
</div>
<script>
function attendanceApp() { return {
    search: '',
    room: '',
    sortDirection: 'asc',
    presentCount: {{ $presentToday }},
    childrenData: @js($children->map(fn($child) => ['id' => $child->id, 'first_name' => $child->first_name, 'last_name' => $child->last_name, 'classroom' => $child->classroom])->values()),
    attendance: @js($attendanceMap),
    recent: @js($recentAttendance->map(fn($attendance) => ['id' => $attendance->id, 'name' => $attendance->child->first_name.' '.$attendance->child->last_name, 'classroom' => $attendance->child->classroom, 'time' => $attendance->signed_in_at->timezone(config('app.timezone'))->format('g:i A')])->values()),
    get filteredChildren() {
        return [...this.childrenData]
            .filter(child => this.matchesChild(child))
            .sort((a, b) => {
                const nameA = `${a.last_name} ${a.first_name}`.toLowerCase();
                const nameB = `${b.last_name} ${b.first_name}`.toLowerCase();
                return this.sortDirection === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
            });
    },
    get filteredCount() { return this.filteredChildren.length; },
    matches(name, classroom) { return name.includes(this.search.toLowerCase()) && (this.room === '' || classroom === this.room); },
    matchesChild(child) { return this.matches((child.first_name + ' ' + child.last_name).toLowerCase(), child.classroom); },
    toggleSort() { this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'; },
    isPresent(childId, date, session) { return !!this.attendance?.[childId]?.[date]?.[session]; },
    sessionTime(childId, date, session) { return this.attendance?.[childId]?.[date]?.[session] ?? ''; },
    hasAnyAttendanceForDate(childId, date) { return !!this.attendance?.[childId]?.[date] && Object.keys(this.attendance[childId][date]).length > 0; },
    async signIn(childId, date, session) {
        if (this.isPresent(childId, date, session)) {
            return;
        }

        const alreadyPresent = this.hasAnyAttendanceForDate(childId, date);

        try {
            const response = await fetch("{{ route('attendance.signin') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({child_id: childId, attendance_date: date, session})
            });

            if (!response.ok) throw new Error('Sign-in failed');

            const data = await response.json();

            if (data.success) {
                this.attendance[childId] = this.attendance[childId] || {};
                this.attendance[childId][date] = this.attendance[childId][date] || {};
                this.attendance[childId][date][data.session] = data.time;

                if (!alreadyPresent && date === '{{ $selectedDate }}') {
                    this.presentCount++;
                }

                this.recent.unshift({id: 'new-'+childId+'-'+date+'-'+session, name: data.child.name, classroom: data.child.classroom, time: data.time});
                this.recent = this.recent.slice(0, 10);
            }
        } catch (error) {
            alert('Unable to sign in right now. Please try again.');
        }
    }
}; }
</script>
@endsection
