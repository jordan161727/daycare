@extends('layouts.app')

@section('title', 'Payroll')

@section('content')
<x-page-header title="Payslip Mailer" subtitle="Split a combined payroll PDF into one payslip per employee, then send each one." />

@if(session('success'))
    <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
@endif

<section class="mt-7 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
    <h2 class="font-bold">How this works</h2>
    <p class="mt-1.5">
        Upload the combined payroll PDF. Each page is matched to a staff member by the legal name printed on it —
        so every teacher needs a <b>Legal name (payroll)</b> on their record. You then step through the payslips one at a
        time, see the actual pages on screen, and send each one. A page the matcher was not sure about arrives
        unassigned rather than guessed, and nothing is ever sent in bulk.
    </p>
</section>

<section class="glass-card mt-7 rounded-2xl p-6">
    <h2 class="text-sm font-semibold">Upload a payroll run</h2>

    <form method="POST" action="{{ route('payroll.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-5 sm:grid-cols-2">
        @csrf
        <label class="block">
            <span class="mb-2 block text-sm font-semibold">Combined payroll PDF <span class="text-rose-500">*</span></span>
            <input type="file" name="file" accept="application/pdf" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm dark:border-white/10 dark:bg-slate-800">
            <p class="mt-1 text-xs text-slate-500">Up to 25 MB. Stored privately and deleted with the batch.</p>
            <x-input-error :messages="$errors->get('file')" />
        </label>

        <label class="block">
            <span class="mb-2 block text-sm font-semibold">Period label</span>
            <input name="period_label" value="{{ old('period_label') }}" placeholder="Jul 9 – Jul 22, {{ now()->year }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
            <p class="mt-1 text-xs text-slate-500">Optional. Leave blank to use the dates read off each payslip.</p>
            <x-input-error :messages="$errors->get('period_label')" />
        </label>

        <div class="sm:col-span-2">
            <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">Upload and split</button>
        </div>
    </form>
</section>

<section class="glass-card mt-7 overflow-hidden rounded-2xl">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-night-800/60">
                <tr>
                    <th class="px-6 py-4 font-semibold">Period</th>
                    <th class="px-6 py-4 font-semibold">File</th>
                    <th class="px-6 py-4 font-semibold">Pages</th>
                    <th class="px-6 py-4 font-semibold">Payslips</th>
                    <th class="px-6 py-4 font-semibold">Uploaded</th>
                    <th class="px-6 py-4 font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @forelse($batches as $batch)
                    <tr class="hover:bg-slate-50/80 dark:hover:bg-white/5">
                        <td class="px-6 py-4 font-semibold">{{ $batch->period_label ?: '—' }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $batch->original_filename }}</td>
                        <td class="px-6 py-4 tabular-nums">{{ $batch->page_count }}</td>
                        <td class="px-6 py-4 tabular-nums">{{ $batch->slips_count }}</td>
                        <td class="px-6 py-4 text-slate-500">{{ $batch->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-4">
                                <a href="{{ route('payroll.show', $batch) }}" class="font-semibold text-indigo-600 hover:text-indigo-800">Review &amp; send</a>
                                <form method="POST" action="{{ route('payroll.destroy', $batch) }}" onsubmit="return confirm('Delete this batch and its PDF? Payslips already sent are not recalled.')">
                                    @csrf @method('DELETE')
                                    <button class="font-semibold text-rose-600 hover:text-rose-800">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-500">No payroll runs uploaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($batches->hasPages())<div class="border-t border-slate-100 px-6 py-4 dark:border-white/10">{{ $batches->links() }}</div>@endif
</section>
@endsection
