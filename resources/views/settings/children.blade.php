@extends('layouts.app')
@section('title', 'Children settings')
@section('content')
{{-- The archived children, and whether the Children page shows them.

     The one place an archived child is seen without asking: the Children page
     leaves them out of everything — the list, the search, the export and every
     count — so this is where they are found and put back.

     Laid out as three cards, each one setting (Jordan's mock, 28 Oct 2026):
     how names read, the Archived tab, and the archived themselves. --}}

<div class="mx-auto max-w-5xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="mt-5 min-w-0 flex-1 lg:mt-0"
         x-data="archivedChildren({ csrf: @js(csrf_token()), count: {{ $archived->count() }} })">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Children</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">How names read, archived children, and whether the Children page shows them.</p>

        @if(session('status'))
            <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
        @endif

        {{-- Names: one choice for the centre, moved here from the register's
             ⋯ menu (Jordan, 27 Oct 2026). A two-way switch showing each name
             the way it will read; picking one saves it, there is nothing else
             on the form to fill in first. --}}
        @php($nameFormat = auth()->user()->nameFormat())
        <form method="POST" action="{{ route('settings.names.update') }}" class="mt-6" x-ref="names"
              x-data="{ pick: @js($nameFormat) }" data-names-setting>
            @csrf
            @method('PUT')
            <section class="glass-card flex flex-wrap items-center justify-between gap-4 rounded-2xl p-5">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Name order</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">How names read on every screen and sheet. Lists still sort by surname.</p>
                </div>

                <div class="inline-flex shrink-0 rounded-full bg-slate-100 p-1 dark:bg-white/10" role="radiogroup" aria-label="Name order">
                    @foreach([
                        'first_last' => ['John Smith', 'First name first — what a child answers to.'],
                        'last_first' => ['Smith, John', 'Surname first — how the paper file is ordered.'],
                    ] as $value => [$example, $why])
                        <label class="relative inline-flex min-h-[36px] cursor-pointer items-center rounded-full px-4 text-sm font-semibold transition"
                               :class="pick === @js($value) ? 'bg-white text-slate-900 shadow-sm dark:bg-night-800 dark:text-white' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                               title="{{ $why }}" data-name-option="{{ $value }}">
                            <input type="radio" name="name_format" value="{{ $value }}" x-model="pick"
                                   @change="$refs.names.submit()"
                                   class="peer absolute inset-0 cursor-pointer appearance-none rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                                   @checked($nameFormat === $value)>
                            {{ $example }}
                        </label>
                    @endforeach
                </div>

                <noscript>
                    <button class="inline-flex min-h-[40px] items-center rounded-xl bg-indigo-700 px-4 text-sm font-semibold text-white">Save</button>
                </noscript>
            </section>
        </form>

        {{-- The switch, and the word before it is saved. Flicking it opens the
             dialog, which says what the Children page will do and carries Save;
             Cancel puts the switch back. Nothing is saved on the flick alone. --}}
        <form method="POST" action="{{ route('settings.children.update') }}" class="mt-4"
              x-data="{ on: @js($showArchived), saved: @js($showArchived), confirming: false }"
              @keydown.escape.window="if (confirming) { confirming = false; on = saved }">
            @csrf
            @method('PUT')

            <section class="glass-card rounded-2xl p-5">
                <label class="flex min-h-[44px] cursor-pointer items-center justify-between gap-4">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">Show archived children</span>
                        <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">Adds an Archived tab to the Children page. They stay out of the All, Active and Inactive counts.</span>
                    </span>
                    {{-- A real checkbox in the switch role: posts as one and reads
                         as a switch to a screen reader. --}}
                    <span class="relative inline-flex h-11 w-14 shrink-0 items-center">
                        <input type="checkbox" role="switch" name="show_archived" value="1" x-model="on"
                               :aria-checked="on ? 'true' : 'false'"
                               @change="confirming = true; $nextTick(() => $refs.confirm?.focus())"
                               class="peer absolute inset-0 h-full w-full cursor-pointer appearance-none rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        <span aria-hidden="true" class="pointer-events-none absolute left-0 top-1/2 h-6 w-11 -translate-y-1/2 rounded-full bg-slate-300 transition peer-checked:bg-indigo-700 dark:bg-slate-600 dark:peer-checked:bg-indigo-500"></span>
                        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-1/2 h-5 w-5 -translate-y-1/2 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>

                {{-- The way without JavaScript: the switch posts like any checkbox. --}}
                <noscript>
                    <div class="mt-4 flex justify-end">
                        <button class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-700 px-5 text-sm font-semibold text-white">Save</button>
                    </div>
                </noscript>
            </section>

            <div x-show="confirming" x-cloak
                 class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center"
                 @click.self="confirming = false; on = saved">
                <div role="dialog" aria-modal="true" aria-labelledby="setting-dialog-title" aria-describedby="setting-dialog-body"
                     class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-white/10 dark:bg-slate-900">
                    <h2 id="setting-dialog-title" class="text-lg font-bold tracking-tight text-slate-900 dark:text-white"
                        x-text="on ? 'Show archived children?' : 'Hide archived children?'"></h2>
                    <p id="setting-dialog-body" class="mt-2 text-sm text-slate-600 dark:text-slate-300"
                       x-text="on
                           ? 'The Children page gets an Archived tab listing archived children, with an Unarchive button on each. They stay out of the All, Active and Inactive counts, search and Export.'
                           : 'The Archived tab is removed from the Children page. Archived children stay archived and can still be found and restored here in Settings › Children.'"></p>
                    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button type="button" @click="confirming = false; on = saved"
                                class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">Cancel</button>
                        <button type="submit" x-ref="confirm"
                                class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-indigo-700 px-4 text-sm font-semibold text-white transition hover:bg-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Save setting</button>
                    </div>
                </div>
            </div>
        </form>

        <section class="glass-card mt-4 rounded-2xl p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Archived children (<span x-text="count">{{ $archived->count() }}</span>)</h2>
                <label class="relative block w-full sm:w-60">
                    <span class="sr-only">Search archived children</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                    <input x-model="search" @keydown.escape="search = ''" placeholder="Search archived children"
                           class="min-h-[40px] w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm transition placeholder:text-slate-400 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-300 dark:border-white/10 dark:bg-slate-800">
                </label>
            </div>

            <ul class="mt-3 divide-y divide-slate-200 dark:divide-white/10" x-show="count > 0" x-cloak>
                @foreach($archived as $child)
                    @php($childName = $child->first_name.' '.$child->last_name)
                    <li x-show="!gone.includes(@js($child->lan)) && @js(strtolower($childName.' '.$child->lan.' '.$child->classroom)).includes(search.toLowerCase())"
                        class="flex flex-wrap items-center gap-3 py-3">
                        <span class="att-avatar shrink-0"><x-child-avatar :child="$child" /></span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('children.show', $child) }}" class="block truncate text-sm font-semibold text-slate-900 underline-offset-2 hover:text-indigo-700 hover:underline dark:text-white dark:hover:text-indigo-300">{{ $child->displayName() }}</a>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                LAN {{ $child->lan }} · {{ $child->classroom ?: 'Unassigned' }} · Archived {{ $child->archived_at?->format('M j, Y') ?? '—' }}
                            </p>
                        </div>
                        <button type="button"
                                @click="unarchive({ lan: @js($child->lan), name: @js($childName), url: @js(route('children.unarchive', $child)) })"
                                :disabled="busy"
                                class="inline-flex min-h-[40px] items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-semibold text-indigo-900 transition hover:border-indigo-300 hover:bg-indigo-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
                                aria-label="Unarchive {{ $childName }}">
                            <svg class="h-4 w-4 text-indigo-700 dark:text-indigo-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a1 1 0 011-1h14a1 1 0 011 1v2M5 8h14l-1 11a1 1 0 01-1 1H7a1 1 0 01-1-1L5 8zm7 9v-5m0 0l-2 2m2-2l2 2"/></svg>
                            Unarchive
                        </button>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400" x-show="count === 0" @if($archived->isNotEmpty()) x-cloak @endif>No archived children.</p>

            <p class="mt-3 border-t border-slate-200 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">Unarchived children go back to the Children page as Inactive.</p>
        </section>

        <div x-show="toast" x-cloak role="status" aria-live="polite"
             class="fixed bottom-6 left-1/2 z-50 flex w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-xl dark:bg-white dark:text-slate-900">
            <span class="min-w-0 flex-1" x-text="toast"></span>
            <button type="button" @click="toast = ''" aria-label="Dismiss"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-xl leading-none text-white/70 transition hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 dark:text-slate-500 dark:hover:text-slate-900">×</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('archivedChildren', ({ csrf, count }) => ({
        search: '',
        gone: [],
        count,
        toast: '',
        toastTimer: null,
        busy: false,

        async unarchive(child) {
            if (this.busy) return;

            this.busy = true;
            try {
                const response = await fetch(child.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: '{}',
                });

                if (! response.ok) throw new Error('HTTP ' + response.status);

                const data = await response.json();
                this.gone.push(child.lan);
                this.count = Math.max(0, this.count - 1);
                this.show(data.message);
            } catch (error) {
                this.show('Could not unarchive ' + child.name + '. Please try again.');
            } finally {
                this.busy = false;
            }
        },

        show(message) {
            this.toast = message;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast = ''; }, 8000);
        },
    }));
});
</script>
@endsection
