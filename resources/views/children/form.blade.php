{{--
    The child record, in five sections.

    One partial, two shapes. On Edit it is a stepper — a header band naming the
    child, five numbered steps, Back and Next along the bottom — because a
    hundred fields presented at once is a form nobody finishes and a record
    nobody trusts. On Add and on the PDF review the same five sections render
    stacked, since there the whole sheet is the point: one is being typed from
    scratch and the other is being checked against a scan beside it.

    Every field is in the DOM in both shapes — the steps are hidden with
    display:none, not removed — so a save from step 1 posts the answers given on
    step 5. Nothing is lost by never walking to the end.

    $action  string   where the form posts
    $method  string   POST on create, PUT on edit
    $child   ?Child   the record being edited, or null on create
    $submit  string   the label on the save button
    $stepper bool     true for the stepper shape (Edit)
    $extracted array  values read off an imported PDF, on the review screen
--}}
@php($stepper = $stepper ?? false)
@php($extracted = $extracted ?? [])
@php($fromDocument = fn ($field) => filled($extracted[$field] ?? null))
@php($fieldClass = fn ($field) => 'cs-input'.($fromDocument($field) ? ' cs-from-doc' : ''))
@php($documentBadge = '<span class="cs-badge">From form</span>')
{{-- Date casts hand back a Carbon, and echoing one gives "2022-01-10 00:00:00",
     which <input type="date"> rejects outright — the field renders blank and
     saving then wipes the value. Dates go in as Y-m-d. --}}
@php($fieldValue = function (string $field) use ($child, $extracted) {
    $value = data_get($child, $field) ?? ($extracted[$field] ?? '');

    return $value instanceof \Carbon\CarbonInterface ? $value->toDateString() : $value;
})

{{-- The repeated sections, as data: the same seven blocks of short fields make
     up steps 2 to 4, and writing them out would be four hundred lines of markup
     differing only in a prefix. --}}
@php($sections = [
    'Contact' => ['Nickname' => 'nickname', 'Address' => 'address', 'City' => 'city', 'Zip' => 'zip', 'Telephone' => 'telephone', 'Parents Status' => 'parents_status', 'Responsible for Payment' => 'responsible_for_payment'],
    'Mother / Legal Guardian' => ['Mother Name' => 'mother_name', 'Mother Address' => 'mother_address', 'Mother Home Phone' => 'mother_home_phone', 'Mother Employer' => 'mother_employer', 'Mother Work Phone' => 'mother_work_phone', 'Mother Fax' => 'mother_fax', 'Mother Cell' => 'mother_cell', 'Mother Email' => 'mother_email', 'Mother Title' => 'mother_title', 'Mother SSN' => 'mother_ssn'],
    'Father / Legal Guardian' => ['Father Name' => 'father_name', 'Father Address' => 'father_address', 'Father Home Phone' => 'father_home_phone', 'Father Employer' => 'father_employer', 'Father Work Phone' => 'father_work_phone', 'Father Fax' => 'father_fax', 'Father Cell' => 'father_cell', 'Father Email' => 'father_email', 'Father Title' => 'father_title', 'Father SSN' => 'father_ssn'],
    'Emergency contacts' => ['Emergency Contact' => 'emergency_contact', 'Secondary Emergency Contact' => 'secondary_emergency_contact', 'Emergency Telephone' => 'emergency_telephone', 'Emergency Relationship' => 'emergency_relationship', 'Emergency License #' => 'emergency_license_number'],
    'Authorized Pickup 1' => ['Pickup 1 Name' => 'pickup_1_name', 'Pickup 1 Address' => 'pickup_1_address', 'Pickup 1 Telephone' => 'pickup_1_telephone', 'Pickup 1 Alternate' => 'pickup_1_alternate', 'Pickup 1 Relationship' => 'pickup_1_relationship', 'Pickup 1 License #' => 'pickup_1_license_number'],
    'Authorized Pickup 2' => ['Pickup 2 Name' => 'pickup_2_name', 'Pickup 2 Address' => 'pickup_2_address', 'Pickup 2 Telephone' => 'pickup_2_telephone', 'Pickup 2 Alternate' => 'pickup_2_alternate', 'Pickup 2 Relationship' => 'pickup_2_relationship', 'Pickup 2 License #' => 'pickup_2_license_number'],
    'Authorized Pickup 3' => ['Pickup 3 Name' => 'pickup_3_name', 'Pickup 3 Address' => 'pickup_3_address', 'Pickup 3 Telephone' => 'pickup_3_telephone', 'Pickup 3 Alternate' => 'pickup_3_alternate', 'Pickup 3 Relationship' => 'pickup_3_relationship', 'Pickup 3 License #' => 'pickup_3_license_number'],
])
{{-- The two hand-written cards are listed too — not to draw them, but so the
     fill counter and the "which step holds the error" lookup below cover the
     whole form rather than the easy two thirds of it. --}}
@php($identityFields = ['lan', 'dss_case_no', 'dss_cin', 'child_name', 'first_name', 'last_name', 'birth_date', 'gender', 'description'])
@php($enrollmentFields = ['classroom_override', 'classroom_override_from', 'status', 'enrolled_on', 'withdrawn_on', 'schedule_days', 'expected_hours_per_week', 'drop_off_time', 'pick_up_time'])
@php($noteFields = ['other_notes', 'important_notes', 'alerts'])

{{--
    Which shape the middle of the form takes.

    On an existing child it is the People step: one row per adult, four ticks
    each, and a person shared with a sibling linked rather than typed again.

    On a new child it is still the old Parents and Emergency & pickup blocks.
    A link needs a child to point at and there is not one yet — and, more to
    the point, the PDF import pre-fills those very inputs on this form, so
    taking them away here would drop the parents off every scanned enrollment
    between now and the import being rewritten to produce people itself.

    Both halves go when that happens; until then the create form is the one
    place the old columns are still written.
--}}
@php($usesPeopleStep = $child !== null)

@php($steps = array_values(array_filter([
    ['label' => 'Basics', 'blurb' => 'Who the child is, and how their place at the centre is set up.', 'fields' => array_merge(['photo'], $identityFields, $enrollmentFields)],
    ['label' => 'Child details', 'blurb' => 'Where they live, and the number to ring first.', 'fields' => array_values($sections['Contact'])],

    $usesPeopleStep
        // Carries no fields of its own: a link is a row in another table and
        // saves as it goes, so there is nothing here for "Save changes" to post.
        ? ['label' => 'People', 'blurb' => 'Parents, guardians, pick-up adults and emergency contacts, in one list.', 'fields' => []]
        : ['label' => 'Parents', 'blurb' => 'Both guardians, in the same field order.', 'fields' => array_merge(array_values($sections['Mother / Legal Guardian']), array_values($sections['Father / Legal Guardian']))],

    $usesPeopleStep
        ? null
        : ['label' => 'Emergency & pickup', 'blurb' => 'Who may be called, and who may take this child home.', 'fields' => array_merge(array_values($sections['Emergency contacts']), array_values($sections['Authorized Pickup 1']), array_values($sections['Authorized Pickup 2']), array_values($sections['Authorized Pickup 3']))],

    ['label' => 'Notes', 'blurb' => 'Anything a relief teacher would need to be told.', 'fields' => $noteFields],
])))
@php($last = count($steps) - 1)

{{-- A rejected field four steps back is invisible on a stepper, so the form
     opens on the step that holds the first error rather than on step 1. --}}
@php($stepHasError = fn (array $fields) => collect($fields)->contains(fn ($field) => $errors->has($field)))
@php($initialStep = collect($steps)->search(fn ($step) => $stepHasError($step['fields'])) ?: 0)
{{-- How much of a section is answered. A record half filled in looks exactly
     like a finished one until something counts it. --}}
@php($filledCount = fn (array $fields) => collect($fields)->filter(fn ($field) => filled(old($field, $fieldValue($field))))->count())
@php($showCounts = (bool) $child)

<form
    method="POST"
    action="{{ $action }}"
    enctype="multipart/form-data"
    class="cs-form"
    {{-- index is the stepper's place; status is read by the save buttons,
         which say "Save and archive" when that is what saving would do. --}}
    x-data="{
        index: {{ $initialStep }},
        status: @js(old('status', $child?->status ?? 'Active')),
        {{-- What the record said when the form opened. A save that changes it
             is held for a word first, whichever way it went: moving a child
             on or off the roll is not an edit to be made by mistake. --}}
        originalStatus: @js($child?->status),
        confirmingStatus: false,
        statusConfirmed: false,
    }"
    {{-- The dialog opens the moment a different status is picked, with Save on
         it, so the change is made and saved from where the hand already is
         rather than from the top of the page. The submit guard is the second
         lock: nothing with a changed status posts without the word. --}}
    x-init="$watch('status', value => { if (originalStatus !== null && value !== originalStatus && ! statusConfirmed) { confirmingStatus = true; $nextTick(() => $refs.statusConfirm?.focus()) } })"
    @submit="if (originalStatus !== null && status !== originalStatus && ! statusConfirmed) { $event.preventDefault(); confirmingStatus = true; $nextTick(() => $refs.statusConfirm?.focus()) }"
    @keydown.escape.window="if (confirmingStatus) { confirmingStatus = false; status = originalStatus }"
>
    @csrf
    @if($method !== 'POST') @method($method) @endif
    @isset($importToken)<input type="hidden" name="import_token" value="{{ $importToken }}">@endisset

    @if($errors->has('duplicate'))
        {{-- The same child twice, refused by name: the record it matches,
             and the one way past — saying this is a different child. --}}
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100" role="alert" data-duplicate>
            <p class="font-semibold">Possible duplicate</p>
            <p class="mt-1">{{ $errors->first('duplicate') }}</p>
            <label class="mt-2 flex items-center gap-2 font-medium"><input type="checkbox" name="allow_duplicate" value="1" class="rounded border-amber-400"> Save anyway — this is a different child</label>
        </div>
    @endif

    @if($stepper)
        {{-- The band: who is being edited, and where in the form you are. It
             carries Save as well as the footer does, so a one-field correction
             on step 1 does not have to be walked to the end to be saved. --}}
        <header class="cs-band">
            <div class="cs-band-top">
                <span class="cs-avatar shrink-0"><x-child-avatar :child="$child" size="h-11 w-11" /></span>
                <div class="min-w-0">
                    <p class="cs-band-name truncate">{{ $child->first_name }} {{ $child->last_name }}</p>
                    <p class="cs-meta truncate">LAN {{ $child->lan }} · {{ $child->classroom ?: 'Unassigned' }} · {{ $child->status }}</p>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <a href="{{ route('children.index') }}" class="cs-btn cs-btn-plain">Cancel</a>
                    <button class="cs-btn cs-btn-primary" x-text="status === 'Archived' ? 'Save and archive' : @js($submit)">{{ $submit }}</button>
                </div>
            </div>
            <div class="cs-tabs" role="tablist">
                @foreach($steps as $i => $step)
                    <button
                        type="button"
                        role="tab"
                        class="cs-tab"
                        @click="index = {{ $i }}"
                        :aria-selected="index === {{ $i }} ? 'true' : 'false'"
                        :data-done="index > {{ $i }} ? 'true' : 'false'"
                        @if($stepHasError($step['fields'])) data-invalid="true" @endif
                    >
                        <span class="cs-dot" aria-hidden="true">{{ $i + 1 }}</span>
                        <span>{{ $step['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </header>
    @endif

    <div class="cs-body">
        {{-- gap rather than margins: a hidden step is display:none and takes no
             gap with it, so the stepper has no stray space above the card. --}}
        <div class="flex flex-col gap-[22px]">

            {{-- ---- 1. Basics ---- --}}
            <div @if($stepper) x-show="index === 0" @endif>
                <h2 class="cs-title">{{ $steps[0]['label'] }}</h2>
                <p class="cs-blurb">{{ $steps[0]['blurb'] }}</p>
                <div class="cs-cards">
                    <section class="cs-card">
                        <div class="cs-card-head">
                            <h3 class="cs-card-title">Identity</h3>
                            @if($showCounts)<span class="cs-pill">{{ $filledCount($identityFields) }}/{{ count($identityFields) }}</span>@endif
                        </div>
                        {{-- The photograph, first, because it is what a relief
                             teacher matches to a face. Alpine swaps the preview
                             as soon as a file is chosen, so the wrong photo is
                             caught here rather than on the record. --}}
                        <div x-data="{ preview: null, cleared: false }" class="mb-3.5 flex flex-wrap items-center gap-4">
                            @php($currentPhoto = $child?->photoUrl())
                            {{-- Three states in one square: the file just chosen,
                                 the photo on file, and the drawn face that stands
                                 in for either. --}}
                            <div class="h-16 w-16 shrink-0">
                                <img x-show="preview" x-cloak :src="preview" alt="" class="h-16 w-16 rounded-xl object-cover">
                                @if($currentPhoto)
                                    <img x-show="! preview && ! cleared" src="{{ $currentPhoto }}" alt="{{ $child->first_name }} {{ $child->last_name }}" class="h-16 w-16 rounded-xl object-cover">
                                @endif
                                <div x-show="! preview @if($currentPhoto) && cleared @endif" @if($currentPhoto) x-cloak @endif>
                                    @if($child)
                                        <x-child-avatar :child="$child" size="h-16 w-16" shape="rounded-xl" />
                                    @else
                                        <div class="grid h-16 w-16 place-items-center rounded-xl border border-dashed border-current/20 text-2xl opacity-40">＋</div>
                                    @endif
                                </div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="cs-label">Photo</span>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
                                    @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; cleared = false"
                                    class="cs-input cursor-pointer py-1 file:mr-3 file:h-full file:cursor-pointer file:rounded-md file:border-0 file:bg-black/5 file:px-2 file:text-[11px] file:font-semibold">
                                <x-input-error :messages="$errors->get('photo')" />
                                <span class="cs-help">JPG, PNG or WebP, up to 4 MB. Kept off the public web — only staff who may see this child can open it.</span>
                                @if($currentPhoto)
                                    <label class="mt-1.5 inline-flex items-center gap-2 text-[10.5px] font-medium text-rose-600 dark:text-rose-400">
                                        <input type="checkbox" name="remove_photo" value="1" x-model="cleared" class="h-3.5 w-3.5 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                        Remove the photo on file
                                    </label>
                                @endif
                            </div>
                        </div>
                        <div class="cs-grid">
                            <label>
                                <span class="cs-label">LAN @if(! $child)<span class="cs-badge">Auto</span>@endif</span>
                                {{-- Read-only, and issued on the server whatever
                                     arrives here: it is a unique key, and a
                                     typeable one meant two people adding
                                     children at once both took the number they
                                     were shown, the second finding out only
                                     when the finished form was refused. --}}
                                <input value="{{ $child?->lan ?? ($nextLan ?? '') }}" class="cs-input" readonly tabindex="-1" aria-readonly="true">
                                <span class="cs-help">{{ $child ? 'Issued when this record was created. It does not change.' : 'The next number in sequence, issued when this record is saved.' }}</span>
                            </label>
                            {{-- The two numbers the state knows a subsidised child
                                 by, beside the one the centre knows them by. The
                                 case belongs to the family and covers every child
                                 on it; the CIN belongs to this child alone. Both
                                 are read off the record for billing far more often
                                 than they are typed into it, so they sit with the
                                 LAN rather than three sections down. --}}
                            <label>
                                <span class="cs-label">DSS Case No{!! $fromDocument('dss_case_no') ? $documentBadge : '' !!}</span>
                                <input name="dss_case_no" value="{{ old('dss_case_no', $fieldValue('dss_case_no')) }}" class="{{ $fieldClass('dss_case_no') }}" autocapitalize="characters" spellcheck="false" placeholder="e.g. S1177706D">
                                <x-input-error :messages="$errors->get('dss_case_no')" />
                            </label>
                            <label>
                                <span class="cs-label">DSS CIN{!! $fromDocument('dss_cin') ? $documentBadge : '' !!}</span>
                                <input name="dss_cin" value="{{ old('dss_cin', $fieldValue('dss_cin')) }}" class="{{ $fieldClass('dss_cin') }}" autocapitalize="characters" spellcheck="false" placeholder="e.g. HB14137F">
                                <x-input-error :messages="$errors->get('dss_cin')" />
                                <span class="cs-help">This child's own client number, not the family's.</span>
                            </label>
                            <label class="cs-wide">
                                <span class="cs-label">Child Name{!! $fromDocument('child_name') ? $documentBadge : '' !!}</span>
                                <input name="child_name" value="{{ old('child_name', $child?->child_name ?? ($extracted['child_name'] ?? '')) }}" class="{{ $fieldClass('child_name') }}">
                                <x-input-error :messages="$errors->get('child_name')" />
                            </label>
                            <label>
                                <span class="cs-label">First name <span class="cs-req">*</span>{!! $fromDocument('first_name') ? $documentBadge : '' !!}</span>
                                <input name="first_name" value="{{ old('first_name', $child?->first_name ?? ($extracted['first_name'] ?? '')) }}" class="{{ $fieldClass('first_name') }}" required>
                                <x-input-error :messages="$errors->get('first_name')" />
                            </label>
                            <label>
                                <span class="cs-label">Last name <span class="cs-req">*</span>{!! $fromDocument('last_name') ? $documentBadge : '' !!}</span>
                                <input name="last_name" value="{{ old('last_name', $child?->last_name ?? ($extracted['last_name'] ?? '')) }}" class="{{ $fieldClass('last_name') }}" required>
                                <x-input-error :messages="$errors->get('last_name')" />
                            </label>
                            {{-- Read the date of birth the form is actually showing,
                                 not the one on the record: on a document import
                                 there is no record yet, and the date came out of
                                 the PDF.

                                 Where there is a record, read it the way the model
                                 does — the date sits in `dob` on everything that
                                 predates the enrolment form and in `birth_date`
                                 after it. Reading only the newer column opened
                                 those records with the field blank, which then
                                 saved the date away. --}}
                            @php($formBirthDate = old('birth_date', $child?->birthDate()?->toDateString() ?? ($extracted['birth_date'] ?? null)) ?: null)
                            {{-- The date of birth is the fact that gets typed in;
                                 the roster's Age column is this same date written
                                 2026/03/15, so there is no second field to keep in
                                 step and nothing left behind when it is corrected. --}}
                            <label>
                                <span class="cs-label">Date of birth{!! $fromDocument('birth_date') ? $documentBadge : '' !!}</span>
                                <input id="birth_date" type="date" name="birth_date" value="{{ $formBirthDate }}" class="{{ $fieldClass('birth_date') }}">
                                <x-input-error :messages="$errors->get('birth_date')" />
                                <span id="age-preview" class="cs-help">@php($previewAge = $formBirthDate ? \App\Models\Child::ageLabelFor(\Illuminate\Support\Carbon::parse($formBirthDate)) : null){{ $previewAge ? 'The roster shows this as '.$previewAge.'.' : 'The age on the roster follows this date.' }}</span>
                            </label>
                            {{-- Only the drawn stand-in face reads this, which is
                                 exactly why it is asked rather than guessed: a name
                                 does not say, in any language. --}}
                            <label>
                                <span class="cs-label">Girl or boy</span>
                                <select name="gender" class="cs-input"><option value="">Not recorded</option>@foreach(\App\Models\Child::GENDERS as $gender)<option value="{{ $gender }}" @selected(old('gender', $child?->gender) === $gender)>{{ $gender }}</option>@endforeach</select>
                                <x-input-error :messages="$errors->get('gender')" />
                                <span class="cs-help">Used for the drawn face shown until a photo is uploaded. Leave it blank and the face stays neutral.</span>
                            </label>
                            <label>
                                <span class="cs-label">Description</span>
                                <input type="text" name="description" value="{{ old('description', $child?->description) }}" class="cs-input" maxlength="255" placeholder="e.g. blonde long hair">
                                <x-input-error :messages="$errors->get('description')" />
                                <span class="cs-help">A few words on how the child looks, to tell one face from the next.</span>
                            </label>
                        </div>
                    </section>

                    @php($statusHelp = [
                        'Active' => 'Enrolled and attending. Shows on the Children page and in counts.',
                        'Pending' => 'A place agreed and not yet started. On the Children page, not counted as here.',
                        'Inactive' => 'Not attending right now. Still shows on the Children page under Inactive.',
                        'Archived' => 'Hidden from the Children page, search, Export and all counts. Restore anytime from Settings.',
                    ])
                    {{-- The dot each status wears, here and on the pill in the card's
                         head: the roll's colours, so the two screens agree. --}}
                    @php($statusDot = ['Active' => '#2fb37a', 'Pending' => '#e6a23c', 'Inactive' => '#9aa5a0', 'Archived' => '#b9c2bd'])
                    @php($statusOptions = auth()->user()?->isAdmin() ? \App\Models\Child::STATUSES : \App\Models\Child::ROLL_STATUSES)
                    <section class="cs-card">
                        <div class="cs-card-head">
                            <h3 class="cs-card-title">Enrollment</h3>
                            {{-- The status, as the roll draws it, following the control below. --}}
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                  :class="{
                                      'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300': status === 'Active',
                                      'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200': status === 'Pending',
                                      'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300': status === 'Inactive',
                                      'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200': status === 'Archived',
                                  }">
                                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                <span x-text="status">{{ old('status', $child?->status ?? 'Active') }}</span>
                            </span>
                            @if($showCounts)<span class="cs-pill">{{ $filledCount($enrollmentFields) }}/{{ count($enrollmentFields) }}</span>@endif
                        </div>

                        {{-- ---- Status ---- --}}
                        <fieldset class="cs-group">
                            <legend class="cs-group-label">Status <span class="cs-req">*</span></legend>
                            {{-- The four words in a row, a dot apiece in the status's
                                 colour, and what the chosen one means on the line
                                 under. Archived is offered to the director only; the
                                 rules refuse it from anybody else either way. --}}
                            <div class="cs-seg">
                                @foreach($statusOptions as $option)
                                    <label>
                                        <input type="radio" name="status" value="{{ $option }}" x-model="status"
                                               @checked(old('status', $child?->status ?? 'Active') === $option)
                                               aria-describedby="status-help">
                                        <span style="--seg-dot: {{ $statusDot[$option] }}">{{ $option }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <x-input-error :messages="$errors->get('status')" />
                            <span id="status-help" class="cs-help" x-text="@js($statusHelp)[status] ?? ''">{{ $statusHelp[old('status', $child?->status ?? 'Active')] ?? '' }}</span>
                        </fieldset>

                        {{-- The word before a status changes. Save is held until
                             "Save as …" is pressed here, because the next press
                             moves the child on or off the Children page — and,
                             for Archived, off every count the centre keeps. --}}
                        @php($statusChangeNotice = [
                            'Active' => ':name will be back on the Children page as Active, counted as attending, and will appear on the attendance sheet from the next week.',
                            'Pending' => ':name will show on the Children page under Pending — a place held, not counted as here.',
                            'Inactive' => ':name will show on the Children page under Inactive and will no longer be counted as attending.',
                            'Archived' => ':name will disappear from the Children page, search, Export and all counts. Their records are kept. To see or restore them, go to Settings › Children.',
                        ])
                        @php($statusChangeName = $child?->first_name ?: 'This child')
                        <div x-show="confirmingStatus" x-cloak
                             class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 sm:items-center"
                             @click.self="confirmingStatus = false; status = originalStatus">
                            <div role="dialog" aria-modal="true" aria-labelledby="status-dialog-title" aria-describedby="status-dialog-body"
                                 class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-white/10 dark:bg-slate-900">
                                <h2 id="status-dialog-title" class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
                                    Change status to <span x-text="status"></span>?
                                </h2>
                                <p id="status-dialog-body" class="mt-2 text-sm text-slate-600 dark:text-slate-300"
                                   x-text="(@js($statusChangeNotice)[status] ?? '').replace(':name', @js($statusChangeName))"></p>
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                    Was <span class="font-semibold" x-text="originalStatus"></span>. The change is recorded in the child's history.
                                </p>
                                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                    {{-- Cancel puts the status back as well as closing: a
                                         change that was not agreed to is not left sitting
                                         in the form for the next Save to carry. --}}
                                    <button type="button" @click="confirmingStatus = false; status = originalStatus"
                                            class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/10">Cancel</button>
                                    <button type="button" x-ref="statusConfirm"
                                            @click="statusConfirmed = true; confirmingStatus = false; $root.requestSubmit()"
                                            :class="status === 'Archived' ? 'bg-amber-600 hover:bg-amber-700' : 'bg-slate-900 hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200'"
                                            class="inline-flex min-h-[44px] items-center justify-center rounded-xl px-4 text-sm font-semibold text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                            x-text="status === 'Archived' ? 'Save and archive' : 'Save as ' + status">Save</button>
                                </div>
                            </div>
                        </div>

                        {{-- ---- Classroom ---- --}}
                        @php($automaticRoom = $formBirthDate ? \App\Services\ClassroomAssignment::automaticFor(\Illuminate\Support\Carbon::parse($formBirthDate)) : null)
                        @php($chosenRoom = old('classroom_override', $child?->classroom_override))
                        {{-- What the room this child lands in actually runs as. Read
                             only: it is set once for the whole room on the Room
                             Schedules page, not per child. --}}
                        @php($roomSchedule = \App\Models\RoomSchedule::byRoom()[$chosenRoom ?: $automaticRoom] ?? null)
                        {{-- The override fields open when there is one already, or when
                             one was refused; otherwise they wait behind the button. --}}
                        <div class="cs-group" x-data="{ moving: @js(filled($chosenRoom) || $errors->has('classroom_override') || $errors->has('classroom_override_from')), override: @js($chosenRoom ?? '') }">
                            <span class="cs-group-label">Classroom</span>
                            <div class="cs-room">
                                <span class="cs-room-icon"><x-room-icon :room="$chosenRoom ?: ($automaticRoom ?? '')" size="text-lg" /></span>
                                <div class="cs-room-body">
                                    <p class="cs-room-name">
                                        <span id="classroom-effective" class="truncate">{{ $chosenRoom ?: ($automaticRoom ?? 'Add a date of birth') }}</span>
                                        <span class="cs-tag" x-text="override ? 'Set by hand' : 'Automatic'">{{ $chosenRoom ? 'Set by hand' : 'Automatic' }}</span>
                                    </p>
                                    <p class="cs-room-sub">
                                        <span id="classroom-note">@if($automaticRoom)Automatic: {{ $automaticRoom }}@elseif($formBirthDate)No band covers this date of birth — set the room by hand.@else Add a date of birth and the room follows it.@endif</span>
                                        @if($roomSchedule?->hoursLabel()) · {{ $roomSchedule->hoursLabel() }}@endif
                                    </p>
                                </div>
                                <button type="button" class="cs-btn cs-btn-ghost" @click="moving = !moving" :aria-expanded="moving" aria-controls="classroom-move"
                                        x-text="moving ? 'Keep this room' : 'Move to another room'">Move to another room</button>
                            </div>
                            <div id="classroom-move" class="cs-room-move cs-two" x-show="moving" @if(! filled($chosenRoom) && ! $errors->has('classroom_override') && ! $errors->has('classroom_override_from')) x-cloak @endif>
                                <label>
                                    <span class="cs-label">Room</span>
                                    <select name="classroom_override" class="cs-input" x-model="override"><option value="">Use the automatic room</option>@foreach(\App\Services\ClassroomAssignment::rooms() as $room)<option value="{{ $room }}" @selected($chosenRoom === $room)>{{ $room }}</option>@endforeach</select>
                                    <x-input-error :messages="$errors->get('classroom_override')" />
                                    <span class="cs-help">For moving a child up early. It holds until you set it back to the automatic room.</span>
                                </label>
                                <label>
                                    <span class="cs-label">Starts on</span>
                                    <input type="date" name="classroom_override_from" value="{{ old('classroom_override_from', $child?->classroom_override_from?->toDateString()) }}" class="cs-input">
                                    <x-input-error :messages="$errors->get('classroom_override_from')" />
                                    <span class="cs-help">Blank means today. An earlier date changes the room counts on days already reported.</span>
                                </label>
                            </div>
                        </div>

                        {{-- ---- Dates ---- --}}
                        <div class="cs-group">
                            <span class="cs-group-label">Dates</span>
                            <div class="cs-two">
                                <label>
                                    <span class="cs-label">Enrolled on{!! $fromDocument('enrolled_on') ? $documentBadge : '' !!}</span>
                                    <input type="date" name="enrolled_on" value="{{ old('enrolled_on', $child?->enrolled_on?->toDateString() ?? ($extracted['enrolled_on'] ?? '')) }}" class="{{ $fieldClass('enrolled_on') }}">
                                    <x-input-error :messages="$errors->get('enrolled_on')" />
                                    <span class="cs-help">Attendance starts on this day. Blank if they've always been here.</span>
                                </label>
                                <label>
                                    <span class="cs-label">Withdrawn on <span class="cs-optional">· optional</span></span>
                                    <input type="date" name="withdrawn_on" value="{{ old('withdrawn_on', $child?->withdrawn_on?->toDateString()) }}" class="cs-input">
                                    <x-input-error :messages="$errors->get('withdrawn_on')" />
                                    <span class="cs-help">Last day attending. Leave blank while they're enrolled.</span>
                                </label>
                            </div>
                        </div>

                        {{-- ---- Schedule ---- --}}
                        <div class="cs-group">
                        <span class="cs-group-label">Schedule</span>
                        <div class="cs-grid">
                            {{-- Which days the parent signed up for.
 
                                 The hours below say when in the day a child is
                                 here; this says which days they come at all, and
                                 until now nothing on the record did. A
                                 Monday-Wednesday-Friday child and a full-week
                                 child were the same record, and every newly
                                 enrolled child's first week came up blank because
                                 there was nothing to copy forward and nothing to
                                 fall back on.

                                 It is the standing arrangement, not the week: a
                                 week is still ticked and corrected on its own, and
                                 a holiday never reaches back to this.

                                 Rendered as checkboxes with a hidden empty value
                                 in front, so clearing every day posts "no days"
                                 rather than posting nothing and being read as
                                 "leave it alone". --}}
                            @php($chosenDays = collect(old('schedule_days', $child?->scheduleDays() ?? [])) ->map(fn ($day) => (int) $day))
                            @php($everSet = old('schedule_days') !== null || $child?->scheduleDays() !== null)
                            <div class="cs-wide" x-data="{ days: @js($chosenDays->values()) }">
                                <span class="cs-label">Days they attend</span>
                                <input type="hidden" name="schedule_days" value="">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach(\App\Models\Child::WEEKDAYS as $number => $label)
                                        <label class="cs-day">
                                            <input type="checkbox" name="schedule_days[]" value="{{ $number }}" x-model.number="days" class="sr-only">
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                    {{-- The full week in one click, because it is
                                         the commonest arrangement by far. --}}
                                    <button type="button" class="cs-btn cs-btn-ghost ml-1 h-[26px] px-2.5 text-[11px]"
                                            @click="days = days.length === {{ count(\App\Models\Child::WEEKDAYS) }} ? [] : @js(array_keys(\App\Models\Child::WEEKDAYS))"
                                            x-text="days.length === {{ count(\App\Models\Child::WEEKDAYS) }} ? 'Clear' : 'Every day'"></button>
                                </div>
                                <x-input-error :messages="$errors->get('schedule_days')" />
                                <span class="cs-help">
                                    @if($everSet)
                                        A new week starts with these days ticked. Change a single week on the attendance sheet instead — that never comes back here.
                                    @else
                                        Nobody has said yet, so this child's weeks start blank. Tick the days they are signed up for and every new week starts from them.
                                    @endif
                                </span>
                            </div>
                            {{-- Hours are what the parent contracted for, against
                                 which the week's projection is measured. They do not
                                 decide which days a child comes — the attendance
                                 does that — so a wrong number here never puts a
                                 child in a room nobody staffed for. --}}
                            <label class="cs-wide">
                                <span class="cs-label">Expected hours a week</span>
                                <input type="number" name="expected_hours_per_week" step="0.25" min="0" max="168" value="{{ old('expected_hours_per_week', $child?->expected_hours_per_week) }}" placeholder="e.g. {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS * 5, 1) }} for a full week" class="cs-input">
                                <x-input-error :messages="$errors->get('expected_hours_per_week')" />
                                <span class="cs-help">A full day counts as {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS, 1) }} h, a morning or afternoon as {{ number_format(\App\Services\AttendanceProjection::FULL_DAY_HOURS / 2, 1) }} h. Leave blank if it has not been agreed — the week's projection then reports the days without a target to hit. Zero means they are not expected at all.</span>
                            </label>
                            {{-- The hours of the day, as against the expected hours
                                 of the week and the schedule boxes on the attendance
                                 page — those say which days a child comes, these say
                                 when in the day they arrive and go home.

                                 A new record opens on the full day the centre is
                                 open; narrowing it is the edit that gets made, and
                                 starting from the widest pair means nobody has to
                                 type both ends to say "the usual". --}}
                            @php($scheduleDefault = fn ($field, $default) => \App\Models\Child::timeInputValue(old($field, $child ? $child->{$field} : $default)))
                            <label>
                                <span class="cs-label">Drop-off time</span>
                                <input type="time" name="drop_off_time" value="{{ $scheduleDefault('drop_off_time', \App\Models\Child::DAY_OPENS_AT) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="cs-input">
                                <x-input-error :messages="$errors->get('drop_off_time')" />
                                <span class="cs-help">When they normally arrive. The centre opens at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_OPENS_AT) }}.</span>
                            </label>
                            <label>
                                <span class="cs-label">Pick-up time</span>
                                <input type="time" name="pick_up_time" value="{{ $scheduleDefault('pick_up_time', \App\Models\Child::DAY_CLOSES_AT) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="cs-input">
                                <x-input-error :messages="$errors->get('pick_up_time')" />
                                <span class="cs-help">When they normally go home. The centre closes at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_CLOSES_AT) }}.</span>
                            </label>
                        </div>
                        </div>
                    </section>
                </div>
            </div>

            {{-- ---- 2: the repeated blocks of short fields ----

                 The parent, emergency and pick-up blocks used to be drawn here
                 too. They are people now, not columns, and the People step
                 below is where they are set. The columns themselves are still
                 on the table and still hold what they held — nothing on this
                 form posts them any more, so a save leaves them exactly as the
                 migration found them until they are dropped. --}}
            @foreach($usesPeopleStep
                ? [1 => ['Contact']]
                : [1 => ['Contact'], 2 => ['Mother / Legal Guardian', 'Father / Legal Guardian'], 3 => ['Emergency contacts', 'Authorized Pickup 1', 'Authorized Pickup 2', 'Authorized Pickup 3']]
            as $stepIndex => $cards)
                <div @if($stepper) x-show="index === {{ $stepIndex }}" x-cloak @endif>
                    <h2 class="cs-title">{{ $steps[$stepIndex]['label'] }}</h2>
                    <p class="cs-blurb">{{ $steps[$stepIndex]['blurb'] }}</p>
                    <div class="cs-cards">
                        @foreach($cards as $heading)
                            @php($fields = $sections[$heading])
                            <section class="cs-card">
                                <div class="cs-card-head">
                                    <h3 class="cs-card-title">{{ $heading }}</h3>
                                    @if($showCounts)<span class="cs-pill">{{ $filledCount(array_values($fields)) }}/{{ count($fields) }}</span>@endif
                                </div>
                                <div class="cs-grid">
                                    @foreach($fields as $label => $field)
                                        <label class="{{ str_contains($field, 'address') ? 'cs-wide' : '' }}">
                                            <span class="cs-label">{{ $label }}{!! $fromDocument($field) ? $documentBadge : '' !!}</span>
                                            <input id="{{ $field }}" type="text" name="{{ $field }}" value="{{ old($field, $fieldValue($field)) }}" class="{{ $fieldClass($field) }}">
                                            <x-input-error :messages="$errors->get($field)" />
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- ---- 3. People: one row per adult, saved as it goes ---- --}}
            @if($usesPeopleStep)
                <div @if($stepper) x-show="index === 2" x-cloak @endif>
                    @include('children.partials.people-step')
                </div>
            @endif

            {{-- ---- 4. Notes: two boxes of prose, a card each ---- --}}
            <div @if($stepper) x-show="index === {{ $last }}" x-cloak @endif>
                <h2 class="cs-title">{{ $steps[$last]['label'] }}</h2>
                <p class="cs-blurb">{{ $steps[$last]['blurb'] }}</p>
                <div class="cs-cards">
                    <section class="cs-card">
                        <div class="cs-card-head"><h3 class="cs-card-title">Other notes</h3></div>
                        <label>
                            <span class="cs-label">Anything worth knowing{!! $fromDocument('other_notes') ? $documentBadge : '' !!}</span>
                            <textarea name="other_notes" rows="4" class="{{ $fieldClass('other_notes') }}">{{ old('other_notes', $child?->other_notes ?? ($extracted['other_notes'] ?? '')) }}</textarea>
                            <x-input-error :messages="$errors->get('other_notes')" />
                        </label>
                    </section>
                    <section class="cs-card">
                        <div class="cs-card-head"><h3 class="cs-card-title">Important notes</h3></div>

                        {{-- The short form of what is below, so it can be read
                             at a glance down a roll of sixty. A kind and a line
                             apiece: the kind colours the chip, which is how a
                             court order gets noticed without being read.

                             Above the paragraph rather than instead of it — an
                             allergy needs more said about it than fits on a
                             chip, and the paragraph is where that goes. --}}
                        <div
                            x-data="{
                                rows: @js(old('alerts', $child?->alerts ?? ($extracted['alerts'] ?? []))),
                                examples: @js(collect(\App\Models\Child::ALERT_TYPES)->map->example),
                                add() { this.rows.push({type: 'allergy', text: ''}); },
                                remove(index) { this.rows.splice(index, 1); },
                            }"
                            class="cs-alerts"
                        >
                            <span class="cs-label">Alerts</span>

                            {{-- The hidden empty value keeps "cleared" and
                                 "untouched" apart, the same way the day boxes
                                 do: without it, removing the last alert posts
                                 nothing at all and reads as never asked. --}}
                            <input type="hidden" name="alerts" value="">

                            <template x-for="(row, index) in rows" :key="index">
                                <div class="cs-alert-row">
                                    <select :name="`alerts[${index}][type]`" x-model="row.type" class="cs-input cs-alert-type">
                                        @foreach(\App\Models\Child::ALERT_TYPES as $value => $type)
                                            <option value="{{ $value }}">{{ $type['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <input :name="`alerts[${index}][text]`" x-model="row.text" :placeholder="examples[row.type]" maxlength="120" class="cs-input">
                                    <button type="button" @click="remove(index)" class="cs-alert-drop" aria-label="Remove this alert">&times;</button>
                                </div>
                            </template>

                            <button type="button" @click="add()" class="cs-alert-add">+ Add alert</button>
                            <span class="cs-help">Shown as a chip on the roll and at the top of the record. Keep it to the line a relief teacher needs before the day starts.</span>
                        </div>

                        <label>
                            <span class="cs-label">Allergies, medication, court orders{!! $fromDocument('important_notes') ? $documentBadge : '' !!}</span>
                            <textarea name="important_notes" rows="4" class="{{ $fieldClass('important_notes') }}">{{ old('important_notes', $child?->important_notes ?? ($extracted['important_notes'] ?? '')) }}</textarea>
                            <x-input-error :messages="$errors->get('important_notes')" />
                        </label>
                        <span class="cs-help">Shown on the child's record where a relief teacher will see it.</span>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <div class="cs-foot">
        @if($stepper)
            <button type="button" class="cs-btn cs-btn-ghost" @click="index--" :disabled="index === 0">‹ Back</button>
            {{-- Next is a plain button and Save is the submit, so Enter in a text
                 field saves the record rather than skipping a section. --}}
            <button type="button" class="cs-btn cs-btn-primary" x-show="index < {{ $last }}" @click="index++">Next section ›</button>
            <button class="cs-btn cs-btn-primary" x-show="index === {{ $last }}" x-cloak x-text="status === 'Archived' ? 'Save and archive' : @js($submit)">{{ $submit }}</button>
            <span class="cs-counter">Section <span x-text="index + 1">{{ $initialStep + 1 }}</span> of {{ count($steps) }}</span>
        @else
            <a href="{{ route('children.index') }}" class="cs-btn cs-btn-plain">Cancel</a>
            <button class="cs-btn cs-btn-primary ml-auto" x-text="status === 'Archived' ? 'Save and archive' : @js($submit)">{{ $submit }}</button>
        @endif
    </div>

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
                // m/d/y, padded, exactly as Child::ageLabelFor writes it on the
                // server, so the preview under the field agrees with every
                // column that shows the date.
                const pad = (number) => String(number).padStart(2, '0');
                return `The roster shows this as ${pad(dob.getMonth() + 1)}/${pad(dob.getDate())}/${String(dob.getFullYear()).slice(-2)}.`;
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
                        ? 'No band covers this date of birth — set the room by hand.'
                        : 'Add a date of birth and the room follows it.';
            };

            birth.addEventListener('change', refresh);
            birth.addEventListener('input', refresh);
            picked?.addEventListener('change', refresh);
            refresh();
        })();
    </script>

    {{-- The People step and its drawer. Outside the step markup because the
         drawer teleports over the whole form, and the component behind both is
         one object — saving a person has to change the row that named them. --}}
    @if($usesPeopleStep)
        @include('children.partials.people-script')
    @endif
</form>
