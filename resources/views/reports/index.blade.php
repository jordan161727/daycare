@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<x-page-header title="Reports" subtitle="Understand attendance patterns at a glance." />
<section class="glass-card mt-7 rounded-2xl p-6"><div class="flex items-center justify-between"><div><p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">WEEKLY ATTENDANCE</p><h2 class="mt-1 text-xl font-bold">Attendance overview</h2></div><span class="rounded-xl bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">This week</span></div><div class="mt-10 flex h-64 items-end justify-between gap-3 border-b border-slate-200 px-2 dark:border-white/10">@foreach(['Mon'=>72,'Tue'=>85,'Wed'=>68,'Thu'=>91,'Fri'=>77] as $day => $value)<div class="flex flex-1 flex-col items-center gap-3"><div class="w-full max-w-16 rounded-t-xl bg-gradient-to-t from-indigo-600 to-violet-400" style="height: {{ $value }}%"></div><span class="text-xs font-medium text-slate-500">{{ $day }}</span></div>@endforeach</div><p class="mt-6 text-sm text-slate-500">Weekly reports will reflect your recorded attendance as data is collected.</p></section>
@endsection
