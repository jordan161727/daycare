<form method="POST" action="{{ $action }}" class="glass-card rounded-2xl p-6 sm:p-8">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    <div class="grid gap-5 sm:grid-cols-2">
        <label class="block"><span class="mb-2 block text-sm font-semibold">Name <span class="text-rose-500">*</span></span><input name="name" value="{{ old('name', $teacher->name) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800" required><x-input-error :messages="$errors->get('name')" /></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Email <span class="text-rose-500">*</span></span><input type="email" name="email" value="{{ old('email', $teacher->email) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800" required><x-input-error :messages="$errors->get('email')" /></label>
        <label class="block sm:col-span-2"><span class="mb-2 block text-sm font-semibold">Assigned classrooms</span><select name="classrooms[]" multiple class="h-40 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><option value="">No classroom assigned</option>@foreach($classrooms as $classroom)<option value="{{ $classroom }}" @selected(in_array($classroom, old('classrooms', $teacher->assignedClassrooms()), true))>{{ $classroom }}</option>@endforeach</select><p class="mt-2 text-xs text-slate-500">Select one or more classrooms for this teacher.</p><x-input-error :messages="$errors->get('classrooms')" /></label>
        @if($teacher->exists)
        <label class="block"><span class="mb-2 block text-sm font-semibold">New password</span><input type="password" name="password" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('password')" /></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Confirm password</span><input type="password" name="password_confirmation" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('password_confirmation')" /></label>
        @else
        {{-- How the first password gets set. Emailing a generated one is the
             default because it is the only version where nobody but the teacher
             ever knows their password; the hidden field keeps the choice in old
             input so a failed validation does not silently flip it back. --}}
        <div class="sm:col-span-2" x-data="{ invite: {{ old('send_invite', '1') == '1' ? 'true' : 'false' }} }">
            <input type="hidden" name="send_invite" value="0">
            <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white/60 p-4 dark:border-white/10 dark:bg-night-800/60">
                <input type="checkbox" name="send_invite" value="1" x-model="invite" class="mt-0.5 h-4 w-4 rounded border-slate-300">
                <span>
                    <span class="block text-sm font-semibold">Email a temporary password</span>
                    <span class="mt-1 block text-xs text-slate-500">We send the teacher a one-time password and make them choose their own the first time they sign in. Leave this off to set the password yourself and hand it over in person.</span>
                </span>
            </label>

            <div class="mt-5 grid gap-5 sm:grid-cols-2" x-show="! invite" x-cloak>
                <label class="block"><span class="mb-2 block text-sm font-semibold">Password <span class="text-rose-500">*</span></span><input type="password" name="password" :required="! invite" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('password')" /></label>
                <label class="block"><span class="mb-2 block text-sm font-semibold">Confirm password <span class="text-rose-500">*</span></span><input type="password" name="password_confirmation" :required="! invite" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('password_confirmation')" /></label>
            </div>
        </div>
        @endif
    </div>
    {{-- Employment details. Optional to a one — an account is useful the moment
         it can log in, and the scheduler falls back to config defaults for
         anything left blank. Only the payroll name has to be exact, because a
         payslip page is matched on it. --}}
    <fieldset class="mt-8 border-t border-slate-100 pt-6 dark:border-white/10">
        <legend class="sr-only">Employment details</legend>
        <h2 class="text-sm font-semibold">Employment details</h2>
        <p class="mt-1 text-sm text-slate-500">Used by the staff scheduler and the payslip mailer. Everything here is optional.</p>

        <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-2 block text-sm font-semibold">Employment type</span>
                <select name="employment" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <option value="">Not set</option>
                    @foreach(\App\Models\StaffRule::EMPLOYMENT as $type)
                        <option value="{{ $type }}" @selected(old('employment', $teacher->employment) === $type)>{{ str_replace('_', ' ', $type) }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Sets default weekly hours. Substitutes are only scheduled to cover a ratio gap.</p>
                <x-input-error :messages="$errors->get('employment')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Room they lead</span>
                <select name="title" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                    <option value="">Not set</option>
                    @foreach(\App\Services\ClassroomAssignment::rooms() as $room)
                        <option value="{{ $room }}" @selected(old('title', $teacher->title) === $room)>{{ $room }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('title')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Legal name (payroll)</span>
                <input name="legal_name" value="{{ old('legal_name', $teacher->legal_name) }}" placeholder="{{ $teacher->name ?: 'Maria G. Santos' }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <p class="mt-1 text-xs text-slate-500">The name printed on their payslip. Payslips are matched to people by this.</p>
                <x-input-error :messages="$errors->get('legal_name')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Phone</span>
                <input name="phone" value="{{ old('phone', $teacher->phone) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('phone')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Emergency contact</span>
                <input name="emergency_contact" value="{{ old('emergency_contact', $teacher->emergency_contact) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('emergency_contact')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Emergency phone</span>
                <input name="emergency_phone" value="{{ old('emergency_phone', $teacher->emergency_phone) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('emergency_phone')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Start date</span>
                <input type="date" name="start_date" value="{{ old('start_date', $teacher->start_date?->toDateString()) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('start_date')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Date of birth</span>
                <input type="date" name="dob" value="{{ old('dob', $teacher->dob?->toDateString()) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('dob')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Transport</span>
                <input name="transport" value="{{ old('transport', $teacher->transport) }}" placeholder="Own car, bus, walks…" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('transport')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">ASPIRE ID</span>
                <input name="aspire_id" value="{{ old('aspire_id', $teacher->aspire_id) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('aspire_id')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Pay rate ($ / hour)</span>
                <input type="number" step="0.01" min="0" name="pay_rate" value="{{ old('pay_rate', $teacher->pay_rate) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('pay_rate')" /></label>

            <label class="block"><span class="mb-2 block text-sm font-semibold">Latest evaluation (0–5)</span>
                <input type="number" step="0.1" min="0" max="5" name="evaluation_score" value="{{ old('evaluation_score', $teacher->evaluation_score) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <x-input-error :messages="$errors->get('evaluation_score')" /></label>

            <label class="flex items-center gap-3 sm:col-span-2 lg:col-span-3">
                <input type="hidden" name="direct_deposit" value="0">
                <input type="checkbox" name="direct_deposit" value="1" @checked(old('direct_deposit', $teacher->direct_deposit)) class="h-4 w-4 rounded border-slate-300">
                <span class="text-sm font-semibold">On direct deposit</span></label>

            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-2 block text-sm font-semibold">Notes</span>
                <textarea name="staff_notes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">{{ old('staff_notes', $teacher->staff_notes) }}</textarea>
                <x-input-error :messages="$errors->get('staff_notes')" /></label>
        </div>
    </fieldset>

    <div class="mt-8 flex flex-wrap justify-end gap-3"><a href="{{ route('teachers.index') }}" class="rounded-xl px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</a><button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">{{ $submit }}</button></div>
</form>
