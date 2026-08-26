{{--
    The columns follow the width of the form, not the width of the window.

    This form is the whole page on Add and Edit, and one half of a split screen
    on the PDF review, where the other half is the scanned document. A viewport
    breakpoint cannot tell those apart — it would put four columns in a column
    500px wide. @container asks the box it is actually in, so the same markup
    fills a 1400px page and stays two columns beside the scan.
--}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="@container glass-card rounded-2xl p-6 sm:p-8">
    @csrf
    @if($method !== 'POST') @method($method) @endif
    @php($extracted = $extracted ?? [])
    @php($fromDocument = fn ($field) => filled($extracted[$field] ?? null))
    @php($fieldClass = fn ($field) => 'w-full rounded-xl border px-4 py-3 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-slate-800 '.($fromDocument($field) ? 'border-indigo-300 bg-indigo-50/70 dark:border-indigo-400/40 dark:bg-indigo-500/10' : 'border-slate-200 bg-white dark:border-white/10'))
    @php($documentBadge = '<span class="ml-2 rounded-md bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-200">From form</span>')
    {{-- Date casts hand back a Carbon, and echoing one gives "2022-01-10 00:00:00",
         which <input type="date"> rejects outright — the field renders blank and
         saving then wipes the value. Dates go in as Y-m-d. --}}
    @php($fieldValue = function (string $field) use ($child, $extracted) {
        $value = data_get($child, $field) ?? ($extracted[$field] ?? '');

        return $value instanceof \Carbon\CarbonInterface ? $value->toDateString() : $value;
    })
    @isset($importToken)<input type="hidden" name="import_token" value="{{ $importToken }}">@endisset
    {{-- The photograph, first, because it is what a relief teacher matches to a
         face. Alpine swaps the preview as soon as a file is chosen, so the
         wrong photo is caught here rather than on the record. --}}
    <div x-data="{ preview: null, cleared: false }" class="mb-7 flex flex-wrap items-center gap-5 border-b border-slate-100 pb-7 dark:border-white/10">
        {{-- Three states in one 96px square: the file just chosen, the photo on
             file, and the initial that stands in for either. --}}
        @php($currentPhoto = $child?->photoUrl())
        <div class="h-24 w-24 shrink-0">
            <img x-show="preview" x-cloak :src="preview" alt="" class="h-24 w-24 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-white/10">
            @if($currentPhoto)
                <img x-show="! preview && ! cleared" src="{{ $currentPhoto }}" alt="{{ $child->first_name }} {{ $child->last_name }}" class="h-24 w-24 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-white/10">
            @endif
            {{-- The drawn face the child would be shown with, so what happens
                 on Remove is visible before it is saved. --}}
            <div x-show="! preview @if($currentPhoto) && cleared @endif" @if($currentPhoto) x-cloak @endif>
                @if($child)
                    <x-child-avatar :child="$child" size="h-24 w-24" shape="rounded-2xl" class="ring-1 ring-slate-200 dark:ring-white/10" />
                @else
                    <div class="grid h-24 w-24 place-items-center rounded-2xl bg-slate-100 text-3xl text-slate-300 dark:bg-slate-800">＋</div>
                @endif
            </div>
        </div>
        <div class="min-w-0 flex-1">
            <span class="mb-2 block text-sm font-semibold">Photo</span>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; cleared = false"
                class="block w-full max-w-sm cursor-pointer rounded-xl border border-slate-200 bg-white text-sm file:mr-4 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-slate-100 file:px-4 file:py-3 file:text-sm file:font-semibold file:text-slate-700 dark:border-white/10 dark:bg-slate-800 dark:file:bg-slate-700 dark:file:text-slate-200">
            <x-input-error :messages="$errors->get('photo')" />
            <span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">JPG, PNG or WebP, up to 4 MB. Kept off the public web — only staff who may see this child can open it.</span>
            @if($currentPhoto)
                <label class="mt-2 inline-flex items-center gap-2 text-xs font-medium text-rose-600 dark:text-rose-400">
                    <input type="checkbox" name="remove_photo" value="1" x-model="cleared" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    Remove the photo on file
                </label>
            @endif
        </div>
    </div>

    {{-- Identity and enrolment. Three columns at most: every field here carries a
         line of help under it, and a fourth column would squeeze those to shreds. --}}
    <div class="grid gap-5 @md:grid-cols-2 @4xl:grid-cols-3">
        <label class="block"><span class="mb-2 block text-sm font-semibold">LAN <span class="text-rose-500">*</span>@if(! $child && isset($nextLan))<span class="ml-2 rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">Auto</span>@endif</span><input name="lan" value="{{ old('lan', $child?->lan ?? ($nextLan ?? '')) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800" required><x-input-error :messages="$errors->get('lan')" />@if(! $child && isset($nextLan))<span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">Next number in sequence — change it if the paper record uses another.</span>@endif</label>
        <label class="block @md:col-span-2"><span class="mb-2 block text-sm font-semibold">Child Name{!! $fromDocument('child_name') ? $documentBadge : '' !!}</span><input name="child_name" value="{{ old('child_name', $child?->child_name ?? ($extracted['child_name'] ?? '')) }}" class="{{ $fieldClass('child_name') }}"><x-input-error :messages="$errors->get('child_name')" /></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">First name <span class="text-rose-500">*</span>{!! $fromDocument('first_name') ? $documentBadge : '' !!}</span><input name="first_name" value="{{ old('first_name', $child?->first_name ?? ($extracted['first_name'] ?? '')) }}" class="{{ $fieldClass('first_name') }}" required><x-input-error :messages="$errors->get('first_name')" /></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Last name <span class="text-rose-500">*</span>{!! $fromDocument('last_name') ? $documentBadge : '' !!}</span><input name="last_name" value="{{ old('last_name', $child?->last_name ?? ($extracted['last_name'] ?? '')) }}" class="{{ $fieldClass('last_name') }}" required><x-input-error :messages="$errors->get('last_name')" /></label>
        {{-- Read the date of birth the form is actually showing, not the one on
             the record: on a document import there is no record yet, and the
             date came out of the PDF.

             Where there is a record, read it the way the model does — the date
             sits in `dob` on everything that predates the enrolment form and in
             `birth_date` after it. Reading only the newer column opened those
             records with the field blank, which then saved the date away. --}}
        @php($formBirthDate = old('birth_date', $child?->birthDate()?->toDateString() ?? ($extracted['birth_date'] ?? null)) ?: null)
        {{-- The date of birth is the fact that gets typed in; the roster's Age
             column is this same date written 3/15/2026, so there is no second
             field to keep in step and nothing left behind when it is corrected. --}}
        <label class="block"><span class="mb-2 block text-sm font-semibold">Date of birth{!! $fromDocument('birth_date') ? $documentBadge : '' !!}</span><input id="birth_date" type="date" name="birth_date" value="{{ $formBirthDate }}" class="{{ $fieldClass('birth_date') }}"><x-input-error :messages="$errors->get('birth_date')" /><span id="age-preview" class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">@php($previewAge = $formBirthDate ? \App\Models\Child::ageLabelFor(\Illuminate\Support\Carbon::parse($formBirthDate)) : null){{ $previewAge ? 'The roster shows this as '.$previewAge.'.' : 'The age on the roster follows this date.' }}</span></label>
        {{-- Only the drawn stand-in face reads this, which is exactly why it is
             asked rather than guessed: a name does not say, in any language. --}}
        <label class="block"><span class="mb-2 block text-sm font-semibold">Girl or boy</span><select name="gender" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><option value="">Not recorded</option>@foreach(\App\Models\Child::GENDERS as $gender)<option value="{{ $gender }}" @selected(old('gender', $child?->gender) === $gender)>{{ $gender }}</option>@endforeach</select><x-input-error :messages="$errors->get('gender')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">Used for the drawn face shown until a photo is uploaded. Leave it blank and the face stays neutral.</span></label>
        @php($automaticRoom = $formBirthDate ? \App\Services\ClassroomAssignment::automaticFor(\Illuminate\Support\Carbon::parse($formBirthDate)) : null)
        @php($chosenRoom = old('classroom_override', $child?->classroom_override))
        <div class="block">
            <span class="mb-2 block text-sm font-semibold">Classroom</span>
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">
                <p id="classroom-effective" class="text-sm font-semibold">{{ $chosenRoom ?: ($automaticRoom ?? 'Add a date of birth') }}</p>
                <p id="classroom-note" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    @if($automaticRoom)
                        Automatic: {{ $automaticRoom }}
                    @elseif($formBirthDate)
                        No band covers this date of birth — set the room by hand below.
                    @else
                        Add a date of birth and the room follows it.
                    @endif
                </p>
                {{-- What the room this child lands in actually runs as. Read
                     only: it is set once for the whole room on the Room
                     Schedules page, not per child. --}}
                @php($roomSchedule = \App\Models\RoomSchedule::byRoom()[$chosenRoom ?: $automaticRoom] ?? null)
                @if($roomSchedule?->hoursLabel())
                    <p class="mt-2 border-t border-slate-100 pt-2 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">🕘 {{ $roomSchedule->hoursLabel() }}</p>
                @endif
            </div>
        </div>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Override the classroom</span><select name="classroom_override" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><option value="">Use the automatic room</option>@foreach(\App\Services\ClassroomAssignment::rooms() as $room)<option value="{{ $room }}" @selected(old('classroom_override', $child?->classroom_override) === $room)>{{ $room }}</option>@endforeach</select><x-input-error :messages="$errors->get('classroom_override')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">For moving a child up early. It holds until you clear it.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Override starts on</span><input type="date" name="classroom_override_from" value="{{ old('classroom_override_from', $child?->classroom_override_from?->toDateString()) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('classroom_override_from')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">Leave blank for today. Only used when a room is picked — an earlier date changes the room counts on days already reported.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Status <span class="text-rose-500">*</span></span><select name="status" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800">@foreach(['Active', 'Inactive'] as $status)<option value="{{ $status }}" @selected(old('status', $child?->status ?? 'Active') === $status)>{{ $status }}</option>@endforeach</select><x-input-error :messages="$errors->get('status')" /></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Enrolled on</span><input type="date" name="enrolled_on" value="{{ old('enrolled_on', $child?->enrolled_on?->toDateString()) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('enrolled_on')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">Attendance boxes start on this day. Leave blank if they have always been here.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Withdrawn on</span><input type="date" name="withdrawn_on" value="{{ old('withdrawn_on', $child?->withdrawn_on?->toDateString()) }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('withdrawn_on')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">Last day they attend. Leave blank while they are still enrolled.</span></label>
        {{-- Hours are what the parent contracted for, against which the week's
             projection is measured. They do not decide which days a child comes
             — the attendance does that — so a wrong number here never puts a
             child in a room nobody staffed for. --}}
        <label class="block @md:col-span-2"><span class="mb-2 block text-sm font-semibold">Expected hours a week</span><input type="number" name="expected_hours_per_week" step="0.25" min="0" max="168" value="{{ old('expected_hours_per_week', $child?->expected_hours_per_week) }}" placeholder="e.g. {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS * 5, 1) }} for a full week" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('expected_hours_per_week')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">A full day counts as {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS, 1) }} h, a morning or afternoon as {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS / 2, 1) }} h. Leave blank if it has not been agreed — the week's projection then reports the days without a target to hit. Zero means they are not expected at all.</span></label>
        {{-- The hours of the day, as against the expected hours of the week and
             the schedule boxes on the attendance page — those say which days a
             child comes, these say when in the day they arrive and go home.

             A new record opens on the full day the centre is open; narrowing it
             is the edit that gets made, and starting from the widest pair means
             nobody has to type both ends to say "the usual". --}}
        @php($scheduleDefault = fn ($field, $default) => \App\Models\Child::timeInputValue(old($field, $child ? $child->{$field} : $default)))
        <label class="block"><span class="mb-2 block text-sm font-semibold">Drop-off time</span><input type="time" name="drop_off_time" value="{{ $scheduleDefault('drop_off_time', \App\Models\Child::DAY_OPENS_AT) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('drop_off_time')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">When they normally arrive. The centre opens at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_OPENS_AT) }}.</span></label>
        <label class="block"><span class="mb-2 block text-sm font-semibold">Pick-up time</span><input type="time" name="pick_up_time" value="{{ $scheduleDefault('pick_up_time', \App\Models\Child::DAY_CLOSES_AT) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-white/10 dark:bg-slate-800"><x-input-error :messages="$errors->get('pick_up_time')" /><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">When they normally go home. The centre closes at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_CLOSES_AT) }}.</span></label>
    </div>
    @php($sections = [
        'Child details' => ['Nickname' => 'nickname', 'Address' => 'address', 'City' => 'city', 'Zip' => 'zip', 'Telephone' => 'telephone', 'Parents Status' => 'parents_status', 'Responsible for Payment' => 'responsible_for_payment'],
        'Mother / Legal Guardian' => ['Mother Name' => 'mother_name', 'Mother Address' => 'mother_address', 'Mother Home Phone' => 'mother_home_phone', 'Mother Employer' => 'mother_employer', 'Mother Work Phone' => 'mother_work_phone', 'Mother Fax' => 'mother_fax', 'Mother Cell' => 'mother_cell', 'Mother Email' => 'mother_email', 'Mother Title' => 'mother_title', 'Mother SSN' => 'mother_ssn'],
        'Father / Legal Guardian' => ['Father Name' => 'father_name', 'Father Address' => 'father_address', 'Father Home Phone' => 'father_home_phone', 'Father Employer' => 'father_employer', 'Father Work Phone' => 'father_work_phone', 'Father Fax' => 'father_fax', 'Father Cell' => 'father_cell', 'Father Email' => 'father_email', 'Father Title' => 'father_title', 'Father SSN' => 'father_ssn'],
        'Emergency contacts' => ['Emergency Contact' => 'emergency_contact', 'Secondary Emergency Contact' => 'secondary_emergency_contact', 'Emergency Telephone' => 'emergency_telephone', 'Emergency Relationship' => 'emergency_relationship', 'Emergency License #' => 'emergency_license_number'],
        'Authorized Pickup 1' => ['Pickup 1 Name' => 'pickup_1_name', 'Pickup 1 Address' => 'pickup_1_address', 'Pickup 1 Telephone' => 'pickup_1_telephone', 'Pickup 1 Alternate' => 'pickup_1_alternate', 'Pickup 1 Relationship' => 'pickup_1_relationship', 'Pickup 1 License #' => 'pickup_1_license_number'],
        'Authorized Pickup 2' => ['Pickup 2 Name' => 'pickup_2_name', 'Pickup 2 Address' => 'pickup_2_address', 'Pickup 2 Telephone' => 'pickup_2_telephone', 'Pickup 2 Alternate' => 'pickup_2_alternate', 'Pickup 2 Relationship' => 'pickup_2_relationship', 'Pickup 2 License #' => 'pickup_2_license_number'],
        'Authorized Pickup 3' => ['Pickup 3 Name' => 'pickup_3_name', 'Pickup 3 Address' => 'pickup_3_address', 'Pickup 3 Telephone' => 'pickup_3_telephone', 'Pickup 3 Alternate' => 'pickup_3_alternate', 'Pickup 3 Relationship' => 'pickup_3_relationship', 'Pickup 3 License #' => 'pickup_3_license_number'],
    ])
    @foreach($sections as $heading => $fields)
        {{-- These are short fields — a phone number, a licence number — with no
             help text under them, so they take a fourth column where there is
             room for one rather than running to 400px of empty input. --}}
        <section class="mt-8 border-t border-slate-200 pt-7 dark:border-white/10"><h2 class="mb-5 text-lg font-bold">{{ $heading }}</h2><div class="grid gap-5 @md:grid-cols-2 @4xl:grid-cols-3 @6xl:grid-cols-4">@foreach($fields as $label => $field)<label class="block {{ str_contains($field, 'address') ? '@md:col-span-2' : '' }}"><span class="mb-2 block text-sm font-semibold">{{ $label }}{!! $fromDocument($field) ? $documentBadge : '' !!}</span><input id="{{ $field }}" type="{{ $field === 'birth_date' ? 'date' : 'text' }}" name="{{ $field }}" value="{{ old($field, $fieldValue($field)) }}" class="{{ $fieldClass($field) }}"><x-input-error :messages="$errors->get($field)" /></label>@endforeach</div></section>
    @endforeach
    {{-- Two boxes of prose: side by side once there is width for both. --}}
    <section class="mt-8 border-t border-slate-200 pt-7 dark:border-white/10"><h2 class="mb-5 text-lg font-bold">Notes</h2><div class="grid gap-5 @4xl:grid-cols-2"><label><span class="mb-2 block text-sm font-semibold">Other Notes{!! $fromDocument('other_notes') ? $documentBadge : '' !!}</span><textarea name="other_notes" rows="3" class="{{ $fieldClass('other_notes') }}">{{ old('other_notes', $child?->other_notes ?? ($extracted['other_notes'] ?? '')) }}</textarea></label><label><span class="mb-2 block text-sm font-semibold">Important Notes{!! $fromDocument('important_notes') ? $documentBadge : '' !!}</span><textarea name="important_notes" rows="3" class="{{ $fieldClass('important_notes') }}">{{ old('important_notes', $child?->important_notes ?? ($extracted['important_notes'] ?? '')) }}</textarea></label></div></section>
    <script>
        /* The line under the field says what the roster's Age column will say,
           so a mistyped year shows up here rather than three screens later. */
        (() => {
            const birth = document.getElementById('birth_date'), preview = document.getElementById('age-preview');
            if (!birth || !preview) return;
            const label = () => {
                if (!birth.value) return 'The age on the roster follows this date.';
                const dob = new Date(`${birth.value}T00:00:00`);
                if (isNaN(dob)) return 'The age on the roster follows this date.';
                const now = new Date(), today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                if (dob > today) return 'That date is in the future — check the year.';
                return `The roster shows this as ${dob.getMonth() + 1}/${dob.getDate()}/${dob.getFullYear()}.`;
            };
            const refresh = () => { preview.textContent = label(); };
            birth.addEventListener('change', refresh);
            birth.addEventListener('input', refresh);
            refresh();
        })();
    </script>
    <script>
        /* The room follows the date of birth, so it has to follow the field too —
           otherwise an imported form shows a room that is one edit out of date. */
        (() => {
            const bands = @js(\App\Services\ClassroomAssignment::BANDS);
            const agesOutAt = @js(\App\Services\ClassroomAssignment::AGES_OUT_AT_YEARS);
            const birth = document.getElementById('birth_date');
            const picked = document.querySelector('[name="classroom_override"]');
            const effective = document.getElementById('classroom-effective');
            const note = document.getElementById('classroom-note');
            if (!birth || !effective) return;

            // Month-end births land on the month end, as on the server: a child
            // born the 31st reaches 18 months at the end of February.
            const addMonths = (date, count) => {
                const result = new Date(date.getTime());
                const day = result.getDate();
                result.setDate(1);
                result.setMonth(result.getMonth() + count);
                result.setDate(Math.min(day, new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate()));
                return result;
            };
            const startOf = (dob, band) => band.weeks
                ? new Date(dob.getTime() + band.weeks * 7 * 86400000)
                : addMonths(dob, band.months);

            const automaticRoom = () => {
                if (!birth.value) return undefined;
                const dob = new Date(`${birth.value}T00:00:00`);
                if (isNaN(dob)) return undefined;
                const now = new Date();
                const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                if (today < startOf(dob, bands[0]) || today >= addMonths(dob, agesOutAt * 12)) return null;

                let room = null;
                for (const band of bands) {
                    if (today < startOf(dob, band)) break;
                    room = band.room;
                }
                return room;
            };

            const refresh = () => {
                const automatic = automaticRoom();
                const chosen = picked?.value || '';
                effective.textContent = chosen || automatic || 'Add a date of birth';
                note.textContent = automatic
                    ? `Automatic: ${automatic}`
                    : automatic === null
                        ? 'No band covers this date of birth — set the room by hand below.'
                        : 'Add a date of birth and the room follows it.';
            };

            birth.addEventListener('change', refresh);
            birth.addEventListener('input', refresh);
            picked?.addEventListener('change', refresh);
            refresh();
        })();
    </script>
    <div class="mt-8 flex flex-wrap justify-end gap-3"><a href="{{ route('children.index') }}" class="rounded-xl px-5 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</a><button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/25 hover:bg-indigo-700">{{ $submit }}</button></div>
</form>
