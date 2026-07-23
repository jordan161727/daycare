@props(['title', 'subtitle' => null])
<div><p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">DAYCARE MANAGEMENT</p><h1 class="mt-1 text-3xl font-bold tracking-tight sm:text-4xl">{{ $title }}</h1>@if($subtitle)<p class="mt-2 text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>@endif</div>
