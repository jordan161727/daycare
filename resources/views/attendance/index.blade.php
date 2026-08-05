@extends('layouts.app')
@section('title', 'Attendance')
@section('content')
<div x-data="attendanceApp()">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><x-page-header title="Attendance" subtitle="Mark arrivals and review attendance by date." /><form method="GET" action="{{ route('attendance.index') }}" class="glass-card flex items-center gap-2 rounded-xl p-2"><label class="sr-only" for="attendance-date">Attendance date</label><input id="attendance-date" type="date" name="date" value="{{ $selectedDate }}" class="rounded-lg border-0 bg-slate-100 px-3 py-2 text-sm text-slate-800 dark:bg-slate-800 dark:text-slate-100"><button class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">View date</button></form></div>
    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-stat-card icon="children" title="Children enrolled" :value="$totalChildren" color="indigo"/>
        <div class="glass-card rounded-2xl p-5"><p class="text-sm font-medium text-slate-500 dark:text-slate-400">Present today</p><p class="mt-3 text-3xl font-bold text-emerald-600" x-text="presentCount"></p></div>
        <div class="glass-card rounded-2xl p-5"><p class="text-sm font-medium text-slate-500 dark:text-slate-400">Not signed in</p><p class="mt-3 text-3xl font-bold text-rose-600" x-text="{{ $totalChildren }} - presentCount"></p></div>
    </div>
    <div class="mt-7 flex flex-col gap-4">
        <section class="min-w-0 flex-1">
            <div class="glass-card rounded-2xl p-4">
                <label class="relative block"><svg class="pointer-events-none absolute left-4 top-3.5 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg><input x-model="search" @input="page = 1" class="w-full rounded-xl border-0 bg-slate-100 py-3 pl-11 pr-4 text-sm ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:ring-white/10" placeholder="Search children..."></label>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button @click="room=''; page = 1" :class="room === '' ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'" class="rounded-lg px-3.5 py-2 text-sm font-semibold">All</button>
                    @foreach($classrooms as $classroom)
                        <button @click="room=@js($classroom); page = 1" :class="room === @js($classroom) ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'" class="rounded-lg px-3.5 py-2 text-sm font-semibold">{{ $classroom }}</button>
                    @endforeach
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">
                        <span>Sort</span>
                        <select x-model="sortDirection" @change="page = 1" class="rounded-md border border-slate-200 bg-transparent px-2 py-1 text-sm outline-none dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                            <option value="asc">Ascending</option>
                            <option value="desc">Descending</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">
                        <span>Show</span>
                        <select x-model.number="pageSize" @change="page = 1" class="rounded-md border border-slate-200 bg-transparent px-2 py-1 text-sm outline-none dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                            <option value="10">10</option>
                            <option value="15">15</option>
                            <option value="20">20</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                </div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <div class="glass-card rounded-2xl p-4">
                    <div class="hidden gap-3 border-b border-slate-200 pb-4 text-sm text-slate-500 dark:border-white/10 md:grid md:grid-cols-[1.5fr_repeat(5,minmax(0,1fr))]">
                        <div class="font-semibold">Student</div>
                        @foreach($weekDates as $date)
                            <div class="text-center">
                                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $date->format('D') }}</div>
                                <div class="mt-1 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $date->format('M d') }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="space-y-3 mt-4">
                        <template x-for="(child, index) in pagedChildren()" :key="child.id">
                            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-900 md:p-5">
                                <div class="grid gap-3 md:grid-cols-[1.5fr_repeat(5,minmax(0,1fr))] md:items-center">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="((page - 1) * pageSize) + index + 1"></span>
                                        <span class="grid h-11 w-11 place-items-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 font-bold text-indigo-700" x-text="(child.first_name.charAt(0) + child.last_name.charAt(0)).toUpperCase()"></span>
                                        <div class="min-w-0">
                                            <h2 class="truncate font-bold" x-text="child.first_name + ' ' + child.last_name"></h2>
                                            <p class="text-sm text-slate-500" x-text="child.classroom"></p>
                                        </div>
                                    </div>

                                    @foreach($weekDates as $date)
                                        <div class="grid min-h-[72px] place-items-center md:justify-center md:items-center">
                                            <template x-if="child.classroom === 'School Age'">
                                                <div class="flex flex-wrap items-center justify-center gap-2">
                                                    <button @click="signIn(child.id, '{{ $date->toDateString() }}', 'AM')" :disabled="isPresent(child.id, '{{ $date->toDateString() }}', 'AM')" :class="isPresent(child.id, '{{ $date->toDateString() }}', 'AM') ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border-slate-200'" class="rounded-xl border px-3 py-2 text-xs font-semibold transition">
                                                        <span x-text="isPresent(child.id, '{{ $date->toDateString() }}', 'AM') ? 'AM ✓ ' + sessionTime(child.id, '{{ $date->toDateString() }}', 'AM') : 'AM'"></span>
                                                    </button>
                                                    <button @click="signIn(child.id, '{{ $date->toDateString() }}', 'PM')" :disabled="isPresent(child.id, '{{ $date->toDateString() }}', 'PM')" :class="isPresent(child.id, '{{ $date->toDateString() }}', 'PM') ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border-slate-200'" class="rounded-xl border px-3 py-2 text-xs font-semibold transition">
                                                        <span x-text="isPresent(child.id, '{{ $date->toDateString() }}', 'PM') ? 'PM ✓ ' + sessionTime(child.id, '{{ $date->toDateString() }}', 'PM') : 'PM'"></span>
                                                    </button>
                                                </div>
                                            </template>
                                            <template x-if="child.classroom !== 'School Age'">
                                                <button @click="signIn(child.id, '{{ $date->toDateString() }}', 'FULL')" :disabled="isPresent(child.id, '{{ $date->toDateString() }}', 'FULL')" :class="isPresent(child.id, '{{ $date->toDateString() }}', 'FULL') ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border-slate-200'" class="rounded-xl border px-4 py-2 text-sm font-semibold transition">
                                                    <span x-text="isPresent(child.id, '{{ $date->toDateString() }}', 'FULL') ? 'Present ✓ ' + sessionTime(child.id, '{{ $date->toDateString() }}', 'FULL') : 'Present'"></span>
                                                </button>
                                            </template>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        </template>
                    </div>
                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 text-sm text-slate-500 dark:border-white/10">
                        <p x-show="filteredCount > 0" class="font-medium text-slate-700 dark:text-slate-200">Showing <span x-text="Math.min(filteredCount, page * pageSize)"></span> of <span x-text="filteredCount"></span> children</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button @click="prevPage" :disabled="page === 1" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">Previous</button>
                            <template x-for="pageNumber in pageNumbers()" :key="pageNumber">
                                <button @click="page = pageNumber" :class="page === pageNumber ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200'" class="rounded-lg px-3 py-2 text-sm font-semibold transition" x-text="pageNumber"></button>
                            </template>
                            <button @click="nextPage" :disabled="page === pageCount" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">Next</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section class="glass-card rounded-2xl p-5 mt-4">
            <div class="border-b border-slate-200 pb-4 dark:border-white/10">
                <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">LIVE UPDATES</p>
                <h2 class="mt-1 text-lg font-bold">Recent sign-ins</h2>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-white/10">
                <template x-for="signIn in recent" :key="signIn.id">
                    <div class="flex items-center gap-3 p-4">
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
    page: 1,
    pageSize: 10,
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
    get pageCount() { return Math.max(1, Math.ceil(this.filteredCount / this.pageSize)); },
    pagedChildren() {
        if (this.page > this.pageCount) {
            this.page = this.pageCount;
        }
        return this.filteredChildren.slice((this.page - 1) * this.pageSize, this.page * this.pageSize);
    },
    pageNumbers() { return Array.from({ length: this.pageCount }, (_, index) => index + 1); },
    prevPage() { if (this.page > 1) this.page--; },
    nextPage() { if (this.page < this.pageCount) this.page++; },
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
