@extends('layouts.app')
@section('title', 'Children settings')
@section('content')
{{-- The archived children, and whether the Children page shows them.

     The one place an archived child is seen without asking: the Children page
     leaves them out of everything — the list, the search, the export and every
     count — so this is where they are found and put back. --}}

<div class="mx-auto max-w-5xl lg:flex lg:gap-6">
    @include('settings.tabs')

    <div class="min-w-0 flex-1"
         x-data="archivedChildren({ csrf: @js(csrf_token()), count: {{ $archived->count() }} })">
        <h1 class="text-2xl font-bold tracking-tight">Children</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">How names read, archived children, and whether the Children page shows them.</p>

        @if(session('status'))
            <p class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">{{ session('status') }}</p>
        @endif

        {{-- Names: one choice for the centre, moved here from the register's
             ⋯ menu (Jordan, 27 Oct 2026). Two cards, each the thing it does:
             a name the way it will read, and a line on who reads it that way. --}}
        @php($nameFormat = auth()->user()->nameFormat())
        <form method="POST" action="{{ route('settings.names.update') }}" class="mt-6" x-data="{ pick: @js($nameFormat), saved: @js($nameFormat) }" data-names-setting>
            @csrf
            @method('PUT')
            <section class="glass-card rounded-2xl p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">Names</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">How a child's name reads on every screen and sheet: the register, the roster, the reports. The order of the lists does not change — a roll is still found by surname.</p>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Show names as">
                    @foreach([
                        'first_last' => ['First name first', 'What a child answers to — the room reads it this way.', 'John', 'Smith'],
                        'last_first' => ['Surname first', 'How the paper file is ordered — the office reads it this way.', 'Smith,', 'John'],
                    ] as $value => [$title, $why, $a, $b])
                        <label class="flex min-h-[44px] cursor-pointer items-start gap-3 rounded-2xl border-2 p-4 transition"
                               :class="pick === @js($value) ? 'border-indigo-500 bg-indigo-50/60 dark:border-indigo-400 dark:bg-indigo-500/10' : 'border-slate-200 hover:border-slate-300 dark:border-white/10 dark:hover:border-white/20'"
                               data-name-option="{{ $value }}">
                            <input type="radio" name="name_format" value="{{ $value }}" x-model="pick" class="sr-only" @checked($nameFormat === $value)>
                            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border-2 transition"
                                  :class="pick === @js($value) ? 'border-indigo-500 bg-indigo-500 text-white dark:border-indigo-400 dark:bg-indigo-400' : 'border-slate-300 dark:border-white/20'" aria-hidden="true">
                                <svg x-show="pick === @js($value)" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xl font-bold tracking-tight tabular-nums"><span class="text-slate-900 dark:text-white">{{ $a }}</span> <span class="text-slate-400 dark:text-slate-500">{{ $b }}</span></span>
                                <span class="mt-1 block text-sm font-semibold">{{ $title }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $why }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <span class="text-xs text-slate-500 dark:text-slate-400" x-show="pick !== saved" x-cloak>Not saved yet.</span>
                    <span class="text-xs text-slate-500 dark:text-slate-400" x-show="pick === saved">Reads this way for everyone now.</span>
                    <button class="inline-flex min-h-[44px] items-center rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-default disabled:opacity-50 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200" :disabled="pick === saved">Save</button>
                </div>
            </section>
        </form>

        {{-- The switch, and the word before it is saved. Flicking it opens the
             dialog, which says what the Children page will do and carries Save;
             Cancel puts the switch back. Nothing is saved on the flick alone. --}}
        <form method="POST" action="{{ route('settings.children.update') }}" class="mt-6"
              x-data="{ on: @js($showArchived), saved: @js($showArchived), confirming: false }"
              @keydown.escape.window="if (confirming) { confirming = false; on = saved }">
            @csrf
            @method('PUT')

            <section class="glass-card rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Archived children on the Children page</h2>

                <label class="mt-4 flex min-h-[44px] cursor-pointer items-center justify-between gap-4 rounded-xl bg-slate-50 px-4 py-3 dark:bg-white/5">
                    <span>
                        <span class="block text-sm font-semibold">Show archived children</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">Adds an Archived tab to the Children page. Archived children are still left out of the All, Active and Inactive counts.</span>
                    </span>
                    {{-- A real checkbox in the switch role: posts as one and reads
                         as a switch to a screen reader. --}}
                    <span class="relative inline-flex h-11 w-14 shrink-0 items-center">
                        <input type="checkbox" role="switch" name="show_archived" value="1" x-model="on"
                               :aria-checked="on ? 'true' : 'false'"
                               @change="confirming = true; $nextTick(() => $refs.confirm?.focus())"
                               class="peer absolute inset-0 h-full w-full cursor-pointer appearance-none rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        <span aria-hidden="true" class="pointer-events-none absolute left-0 top-1/2 h-6 w-11 -translate-y-1/2 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 dark:bg-slate-600"></span>
                        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-1/2 h-5 w-5 -translate-y-1/2 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                    </span>
                </label>

                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Currently <span class="font-semibold" x-text="saved ? 'on' : 'off'">{{ $showArchived ? 'on' : 'off' }}</span>: the Children page
                    <span x-text="saved ? 'has an Archived tab.' : 'has no Archived tab.'">{{ $showArchived ? 'has an Archived tab.' : 'has no Archived tab.' }}</span>
                </p>

                {{-- The way without JavaScript: the switch posts like any checkbox. --}}
                <noscript>
                    <div class="mt-4 flex justify-end">
                        <button class="inline-flex min-h-[44px] items-center rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Save</button>
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
                                class="inline-flex min-h-[44px] items-center justify-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Save setting</button>
                    </div>
                </div>
            </div>
        </form>

        <section class="glass-card mt-5 rounded-2xl p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">Archived children (<span x-text="count">{{ $archived->count() }}</span>)</h2>
                <label class="relative block w-full sm:w-64">
                    <span class="sr-only">Search archived children</span>
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm" aria-hidden="true">🔍</span>
                    <input x-model="search" @keydown.escape="search = ''" placeholder="Search archived children"
                           class="min-h-[44px] w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm transition focus:ring-2 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800">
                </label>
            </div>

            <ul class="mt-4 divide-y divide-slate-200 dark:divide-white/10" x-show="count > 0" x-cloak>
                @foreach($archived as $child)
                    @php($childName = $child->first_name.' '.$child->last_name)
                    <li x-show="!gone.includes(@js($child->lan)) && @js(strtolower($childName.' '.$child->lan.' '.$child->classroom)).includes(search.toLowerCase())"
                        class="flex flex-wrap items-center gap-3 py-3">
                        <span class="att-avatar shrink-0"><x-child-avatar :child="$child" /></span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('children.show', $child) }}" class="block truncate text-sm font-semibold text-slate-900 underline-offset-2 hover:text-indigo-600 hover:underline dark:text-white dark:hover:text-indigo-300">{{ $child->displayName() }}</a>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                LAN {{ $child->lan }} · {{ $child->classroom ?: 'Unassigned' }} · Archived {{ $child->archived_at?->format('n/j/Y') ?? '—' }}
                            </p>
                        </div>
                        <button type="button"
                                @click="unarchive({ lan: @js($child->lan), name: @js($childName), url: @js(route('children.unarchive', $child)) })"
                                :disabled="busy"
                                class="inline-flex min-h-[44px] items-center gap-1.5 rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60"
                                aria-label="Unarchive {{ $childName }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            Unarchive
                        </button>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400" x-show="count === 0" @if($archived->isNotEmpty()) x-cloak @endif>No archived children.</p>

            <p class="mt-4 border-t border-slate-200 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">Unarchived children go back to the Children page as Inactive.</p>
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
