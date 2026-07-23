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
    <div class="mt-7 flex flex-col gap-4 lg:flex-row">
        <section class="min-w-0 flex-1">
            <div class="glass-card rounded-2xl p-4">
                <label class="relative block"><svg class="pointer-events-none absolute left-4 top-3.5 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.15a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg><input x-model="search" class="w-full rounded-xl border-0 bg-slate-100 py-3 pl-11 pr-4 text-sm ring-1 ring-slate-200 focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:ring-white/10" placeholder="Search children..."></label>
                <div class="mt-4 flex flex-wrap gap-2"><button @click="room=''" :class="room === '' ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'" class="rounded-lg px-3.5 py-2 text-sm font-semibold">All</button>@foreach($classrooms as $classroom)<button @click="room=@js($classroom)" :class="room === @js($classroom) ? 'bg-indigo-600 text-white' : 'bg-slate-100 dark:bg-slate-800'" class="rounded-lg px-3.5 py-2 text-sm font-semibold">{{ $classroom }}</button>@endforeach</div>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach($children as $child)
                    <article x-show="matches(@js(strtolower($child->first_name.' '.$child->last_name)), @js($child->classroom))" class="glass-card rounded-2xl p-5">
                        <div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-full bg-gradient-to-br from-blue-100 to-violet-100 font-bold text-indigo-700">{{ strtoupper(substr($child->first_name, 0, 1).substr($child->last_name, 0, 1)) }}</span><div class="min-w-0"><h2 class="truncate font-bold">{{ $child->first_name }} {{ $child->last_name }}</h2><p class="text-sm text-slate-500">{{ $child->classroom }}</p></div></div>
                        <button @click="signIn({{ $child->id }})" :disabled="present[{{ $child->id }}]" :class="present[{{ $child->id }}] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-indigo-600 text-white hover:bg-indigo-700'" class="mt-5 w-full rounded-xl px-4 py-3 text-sm font-bold transition"><span x-text="present[{{ $child->id }}] ? 'Present ✓ ' + present[{{ $child->id }}] : 'Sign In'"></span></button>
                    </article>
                @endforeach
            </div>
        </section>
        <aside class="glass-card h-fit rounded-2xl lg:w-80"><div class="border-b border-slate-100 p-5 dark:border-white/10"><p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">LIVE UPDATES</p><h2 class="mt-1 text-lg font-bold">Recent sign-ins</h2></div><div class="divide-y divide-slate-100 dark:divide-white/10"><template x-for="signIn in recent" :key="signIn.id"><div class="flex items-center gap-3 p-4"><span class="grid h-9 w-9 place-items-center rounded-full bg-emerald-100 text-emerald-700">✓</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold" x-text="signIn.name"></p><p class="text-xs text-slate-500" x-text="signIn.classroom"></p></div><span class="text-xs font-medium text-slate-500" x-text="signIn.time"></span></div></template><p x-show="recent.length === 0" class="p-8 text-center text-sm text-slate-500">No sign-ins yet.</p></div></aside>
    </div>
</div>
<script>
function attendanceApp() { return {
    search: '', room: '', presentCount: {{ $presentToday }},
    present: @js($todayAttendance->mapWithKeys(fn($attendance) => [$attendance->child_id => $attendance->signed_in_at->format('g:i A')])),
    recent: @js($recentAttendance->map(fn($attendance) => ['id' => $attendance->id, 'name' => $attendance->child->first_name.' '.$attendance->child->last_name, 'classroom' => $attendance->child->classroom, 'time' => $attendance->signed_in_at->format('g:i A')])->values()),
    matches(name, classroom) { return name.includes(this.search.toLowerCase()) && (this.room === '' || classroom === this.room); },
    async signIn(childId) {
        try {
            const response = await fetch("{{ route('attendance.signin') }}", { method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'}, body: JSON.stringify({child_id: childId, attendance_date: @js($selectedDate)}) });
            if (!response.ok) throw new Error('Sign-in failed');
            const data = await response.json();
            if (data.success) { const isNew = !this.present[childId]; this.present[childId] = data.time; if (isNew && data.created) { this.presentCount++; this.recent.unshift({id: 'new-'+childId, name: data.child.name, classroom: data.child.classroom, time: data.time}); this.recent = this.recent.slice(0, 10); } }
        } catch (error) { alert('Unable to sign in right now. Please try again.'); }
    }
}; }
</script>
@endsection
