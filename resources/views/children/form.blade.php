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
    $tabbed  bool     true for the tabbed shape (Add): six tabs in one card,
                      a summary strip over them and Back / Next / Add along
                      the bottom (Jordan's brief, 6 Nov 2026)
    $extracted array  values read off an imported PDF, on the review screen
--}}
@php($stepper = $stepper ?? false)
@php($tabbed = ($tabbed ?? false) && ! $stepper)
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

{{-- The two contact fields drawn as a pick-list rather than a box: the
     state, and Parents status. A Parents status somebody typed or imported
     before the list existed stays on as a choice of its own, so opening and
     saving the record never quietly blanks it. Null for every other field. --}}
@php($choices = function (string $field, $current) {
    if ($field === 'state') {
        return ['' => 'Select a state'] + \App\Models\Child::STATES;
    }
    if ($field === 'parents_status') {
        $options = ['' => 'Not recorded'] + array_combine(\App\Models\Child::PARENTS_STATUSES, \App\Models\Child::PARENTS_STATUSES);

        return filled($current) && ! isset($options[$current]) ? $options + [$current => $current] : $options;
    }

    return null;
})

{{-- The repeated sections, as data: the same seven blocks of short fields make
     up steps 2 to 4, and writing them out would be four hundred lines of markup
     differing only in a prefix. --}}
@php($sections = [
    'Contact' => ['Nickname' => 'nickname', 'Address' => 'address', 'City' => 'city', 'State' => 'state', 'Zip' => 'zip', 'Telephone' => 'telephone', 'Parents Status' => 'parents_status', 'Responsible for Payment' => 'responsible_for_payment'],
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

{{-- Add, as tabs: the Basics step split in two, the nickname moved up to sit
     with the names, and the parents and pick-ups one person at a time. --}}
@php($contactFields = array_values(array_diff($sections['Contact'], ['nickname'])))
@php($steps = $tabbed ? [
    ['label' => 'Basics', 'fields' => array_merge(['photo', 'nickname'], $identityFields)],
    ['label' => 'Enrollment', 'fields' => $enrollmentFields],
    ['label' => 'Child details', 'fields' => $contactFields],
    ['label' => 'Parents', 'fields' => array_merge(array_values($sections['Mother / Legal Guardian']), array_values($sections['Father / Legal Guardian']))],
    ['label' => 'Emergency & pickup', 'fields' => array_merge(array_values($sections['Emergency contacts']), array_values($sections['Authorized Pickup 1']), array_values($sections['Authorized Pickup 2']), array_values($sections['Authorized Pickup 3']))],
    ['label' => 'Notes', 'fields' => $noteFields],
] : array_values(array_filter([
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
@php($formFirst = old('first_name', $child?->first_name ?? ($extracted['first_name'] ?? '')))
@php($formLast = old('last_name', $child?->last_name ?? ($extracted['last_name'] ?? '')))
{{-- What the Classroom group says before there is a date of birth; the tabbed
     shape keeps the date on another tab, so it says where. --}}
@php($noBirthDateNote = $tabbed ? 'Add a date of birth on Basics and the room follows automatically.' : 'Add a date of birth and the room follows it.')

<form
    method="POST"
    action="{{ $action }}"
    enctype="multipart/form-data"
    class="cs-form @if($tabbed) ca @endif"
    @if($tabbed)
        {{-- The browser's own check stops at a required box on a hidden tab
             and says nothing anyone can see; the submit guard below checks the
             names itself and opens Basics, and the server checks the rest
             and opens the tab that holds what it refused. --}}
        novalidate
        x-ref="card"
    @endif
    {{-- index is the stepper's place; status is read by the save buttons,
         which say "Save and archive" when that is what saving would do. --}}
    x-data="{
        index: {{ $initialStep }},
        firstName: @js($formFirst),
        lastName: @js($formLast),
        nameMissing: false,
        go(i) {
            this.index = Math.max(0, Math.min({{ $last }}, i));
            if (this.$refs.card && this.$refs.card.getBoundingClientRect().top < 0) this.$refs.card.scrollIntoView({ block: 'start', behavior: 'smooth' });
        },
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
    @submit="if (@js($tabbed) && (! firstName.trim() || ! lastName.trim())) { $event.preventDefault(); nameMissing = true; go(0); $nextTick(() => (firstName.trim() ? $refs.lastName : $refs.firstName)?.focus()) } else if (originalStatus !== null && status !== originalStatus && ! statusConfirmed) { $event.preventDefault(); confirmingStatus = true; $nextTick(() => $refs.statusConfirm?.focus()) }"
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

    @if($tabbed)
        {{-- Who is being added, as it is typed: the initials, the name, the
             number the save will issue, and the status picked on Enrollment. --}}
        <div class="ca-summary">
            <span class="ca-avatar" aria-hidden="true" x-text="((firstName.trim()[0] || '') + (lastName.trim()[0] || '')).toUpperCase() || '+'">{{ strtoupper(mb_substr(trim($formFirst), 0, 1).mb_substr(trim($formLast), 0, 1)) ?: '+' }}</span>
            <div class="min-w-0">
                <p class="ca-name truncate" x-text="(firstName.trim() + ' ' + lastName.trim()).trim() || 'New child'">{{ trim($formFirst.' '.$formLast) ?: 'New child' }}</p>
                <p class="ca-meta">LAN {{ $nextLan ?? '' }} · assigned on save · Classroom follows date of birth</p>
            </div>
            <span class="ca-status" :data-status="status" data-status="{{ old('status', 'Active') }}">
                <span class="ca-status-dot" aria-hidden="true"></span>
                <span x-text="status">{{ old('status', 'Active') }}</span>
            </span>
        </div>

        {{-- Arrow keys move along the tabs, Home and End jump to the ends; only
             the open tab is in the Tab order, as the ARIA tabs pattern has it. --}}
        <div class="ca-tabs" role="tablist" aria-label="Child record sections"
             @keydown.arrow-right.prevent="go(index + 1); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())"
             @keydown.arrow-left.prevent="go(index - 1); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())"
             @keydown.home.prevent="go(0); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())"
             @keydown.end.prevent="go({{ $last }}); $nextTick(() => $el.querySelector('[aria-selected=true]')?.focus())">
            @foreach($steps as $i => $step)
                <button type="button" role="tab" id="ca-tab-{{ $i }}" aria-controls="ca-panel-{{ $i }}" class="ca-tab"
                        @click="go({{ $i }})"
                        aria-selected="{{ $i === $initialStep ? 'true' : 'false' }}" :aria-selected="index === {{ $i }} ? 'true' : 'false'"
                        tabindex="{{ $i === $initialStep ? 0 : -1 }}" :tabindex="index === {{ $i }} ? 0 : -1"
                        @if($stepHasError($step['fields'])) data-invalid="true" @endif>
                    <span class="ca-num" aria-hidden="true">{{ $i + 1 }}</span>
                    <span>{{ $step['label'] }}</span>
                    @if($i === 0)
                        <span class="ca-required" x-show="! firstName.trim() || ! lastName.trim()" @if(filled(trim($formFirst)) && filled(trim($formLast))) x-cloak @endif>Required</span>
                    @endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- A panel's switches: shown when its step or tab is the open one. --}}
    @php($panel = function (int $i) use ($stepper, $tabbed, $initialStep) {
        if ($tabbed) {
            return 'id="ca-panel-'.$i.'" role="tabpanel" aria-labelledby="ca-tab-'.$i.'" tabindex="0" x-show="index === '.$i.'"'.($i === $initialStep ? '' : ' x-cloak');
        }

        return $stepper ? 'x-show="index === '.$i.'"'.($i === 0 ? '' : ' x-cloak') : '';
    })

    <div class="cs-body">
        {{-- gap rather than margins: a hidden step is display:none and takes no
             gap with it, so the stepper has no stray space above the card. --}}
        <div class="flex flex-col gap-[22px]">

            @if($tabbed)
            {{-- ---- Add, tab 1: Basics ---- --}}
            <div {!! $panel(0) !!}>
                <div class="cs-cards">
                    <section class="cs-card">
                        <h3 class="cs-card-title">Identity</h3>
                        <div class="ca-identity">
                            {{-- The tile is the file box: a click anywhere on it
                                 opens the picker, and the chosen photo shows in it
                                 at once, so the wrong one is caught here. --}}
                            <label class="ca-photo" x-data="{ preview: null }">
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only"
                                       @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                                <img x-show="preview" x-cloak :src="preview" alt="Chosen photo">
                                <span x-show="! preview" class="ca-photo-add" aria-hidden="true">＋</span>
                                <span class="sr-only">Photo</span>
                            </label>
                            <label>
                                <span class="cs-label">First name <span class="cs-req">*</span></span>
                                <input name="first_name" x-ref="firstName" x-model="firstName" value="{{ $formFirst }}" class="cs-input" required autocomplete="off"
                                       :aria-invalid="nameMissing && ! firstName.trim() ? 'true' : null">
                                <x-input-error :messages="$errors->get('first_name')" />
                                <span class="ca-error" x-show="nameMissing && ! firstName.trim()" x-cloak>Add the child's first name.</span>
                            </label>
                            <label>
                                <span class="cs-label">Last name <span class="cs-req">*</span></span>
                                <input name="last_name" x-ref="lastName" x-model="lastName" value="{{ $formLast }}" class="cs-input" required autocomplete="off"
                                       :aria-invalid="nameMissing && ! lastName.trim() ? 'true' : null">
                                <x-input-error :messages="$errors->get('last_name')" />
                                <span class="ca-error" x-show="nameMissing && ! lastName.trim()" x-cloak>Add the child's last name.</span>
                            </label>
                            <span class="cs-help ca-identity-help">
                                Photo: JPG, PNG or WebP, up to 4 MB. Kept off the public web — only staff who may see this child can open it.
                                @error('photo')<span class="ca-error">{{ $message }}</span>@enderror
                            </span>
                        </div>
                        <div class="cs-grid mt-4">
                            <label>
                                <span class="cs-label">Nickname</span>
                                <input name="nickname" value="{{ old('nickname') }}" class="cs-input">
                                <x-input-error :messages="$errors->get('nickname')" />
                            </label>
                            <label>
                                <span class="cs-label">Date of birth</span>
                                <input id="birth_date" type="date" name="birth_date" value="{{ $formBirthDate }}" class="cs-input">
                                <x-input-error :messages="$errors->get('birth_date')" />
                                <span id="age-preview" class="cs-help">The age on the roster follows this date.</span>
                            </label>
                            <label>
                                <span class="cs-label">Girl or boy</span>
                                <select name="gender" class="cs-input"><option value="">Not recorded</option>@foreach(\App\Models\Child::GENDERS as $gender)<option value="{{ $gender }}" @selected(old('gender') === $gender)>{{ $gender }}</option>@endforeach</select>
                                <x-input-error :messages="$errors->get('gender')" />
                            </label>
                            <label class="cs-wide">
                                <span class="cs-label">Description</span>
                                <input type="text" name="description" value="{{ old('description') }}" class="cs-input" maxlength="255" placeholder="e.g. blonde long hair">
                                <x-input-error :messages="$errors->get('description')" />
                                <span class="cs-help">A few words on how the child looks, to tell one face from the next.</span>
                            </label>
                            <label class="cs-wide">
                                <span class="cs-label">Child name <span class="cs-optional">· as written on the enrollment form, if different</span></span>
                                <input name="child_name" value="{{ old('child_name') }}" class="cs-input">
                                <x-input-error :messages="$errors->get('child_name')" />
                            </label>
                        </div>
                    </section>

                    <section class="cs-card">
                        <h3 class="cs-card-title">Record numbers</h3>
                        <div class="cs-grid">
                            <label>
                                <span class="cs-label">LAN <span class="cs-badge">Auto</span></span>
                                <input value="{{ $nextLan ?? '' }}" class="cs-input" readonly tabindex="-1" aria-readonly="true">
                                <span class="cs-help">The next number in sequence, issued when this record is saved.</span>
                            </label>
                            <label>
                                <span class="cs-label">DSS case no.</span>
                                <input name="dss_case_no" value="{{ old('dss_case_no') }}" class="cs-input" autocapitalize="characters" spellcheck="false" placeholder="e.g. S1177706D">
                                <x-input-error :messages="$errors->get('dss_case_no')" />
                            </label>
                            <label>
                                <span class="cs-label">DSS CIN</span>
                                <input name="dss_cin" value="{{ old('dss_cin') }}" class="cs-input" autocapitalize="characters" spellcheck="false" placeholder="e.g. HB14137F">
                                <x-input-error :messages="$errors->get('dss_cin')" />
                                <span class="cs-help">This child's own client number, not the family's.</span>
                            </label>
                        </div>
                    </section>
                </div>
            </div>

            {{-- ---- Add, tab 2: Enrollment ---- --}}
            <div {!! $panel(1) !!}>
                <div class="cs-cards">
                    @include('children.partials.enrollment-card')
                </div>
            </div>
            @else
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

                    @include('children.partials.enrollment-card')
                </div>
            </div>
            @endif

            {{-- ---- 2: the repeated blocks of short fields ----

                 The parent, emergency and pick-up blocks used to be drawn here
                 too. They are people now, not columns, and the People step
                 below is where they are set. The columns themselves are still
                 on the table and still hold what they held — nothing on this
                 form posts them any more, so a save leaves them exactly as the
                 migration found them until they are dropped. --}}
            @if($tabbed)
            {{-- One field of the tabbed shape: [label, column, input type, help, wide]. --}}
            @php($tabField = function (array $f) use ($errors) {
                [$label, $name, $type, $help, $wide] = $f + [2 => 'text', 3 => null, 4 => false];
                $value = e(old($name));
                $masked = $type === 'ssn';
                $input = '<input id="'.$name.'" name="'.$name.'" value="'.$value.'" class="cs-input'.($masked ? ' ca-masked' : '').'"'
                    .' type="'.($masked ? 'text' : $type).'"'
                    .($type === 'tel' ? ' inputmode="tel" autocomplete="off"' : '')
                    .($masked ? ' inputmode="numeric" autocomplete="off" spellcheck="false" :class="{ \'ca-shown\': shown }"' : '').'>';
                $error = $errors->has($name) ? '<p class="mt-1.5 text-xs font-medium text-rose-600">'.e($errors->first($name)).'</p>' : '';

                return '<label class="'.($wide ? 'cs-wide' : '').'"'.($masked ? ' x-data="{ shown: false }"' : '').'>'
                    .'<span class="cs-label">'.e($label).'</span>'
                    .($masked ? '<span class="ca-masked-row">'.$input.'<button type="button" class="ca-show" @click="shown = ! shown" :aria-pressed="shown" x-text="shown ? \'Hide\' : \'Show\'">Show</button></span>' : $input)
                    .$error
                    .($help ? '<span class="cs-help">'.e($help).'</span>' : '')
                    .'</label>';
            })

            {{-- ---- Add, tab 3: Child details ---- --}}
            <div {!! $panel(2) !!}>
                <div class="cs-cards">
                    <section class="cs-card">
                        <h3 class="cs-card-title">Home and contact</h3>
                        <div class="cs-grid">
                            {!! $tabField(['Address', 'address', 'text', null, true]) !!}
                            {!! $tabField(['City', 'city']) !!}
                            <label>
                                <span class="cs-label">State</span>
                                <select id="state" name="state" class="cs-input">@foreach($choices('state', old('state')) as $value => $text)<option value="{{ $value }}" @selected((string) old('state') === (string) $value)>{{ $text }}</option>@endforeach</select>
                                <x-input-error :messages="$errors->get('state')" />
                            </label>
                            {!! $tabField(['Zip', 'zip']) !!}
                            {!! $tabField(['Telephone', 'telephone', 'tel']) !!}
                            <label>
                                <span class="cs-label">Parents status</span>
                                <select id="parents_status" name="parents_status" class="cs-input">@foreach($choices('parents_status', old('parents_status')) as $value => $text)<option value="{{ $value }}" @selected((string) old('parents_status') === (string) $value)>{{ $text }}</option>@endforeach</select>
                                <x-input-error :messages="$errors->get('parents_status')" />
                            </label>
                            {!! $tabField(['Responsible for payment', 'responsible_for_payment']) !!}
                        </div>
                    </section>
                </div>
            </div>

            {{-- ---- Add, tab 4: Parents, one guardian at a time ----
                 Both guardians' boxes are in the form the whole time, the other
                 one only hidden, so switching never loses a word and the save
                 posts both. A refused field opens its guardian. --}}
            @php($guardianError = collect($sections['Father / Legal Guardian'])->contains(fn ($f) => $errors->has($f)) && ! collect($sections['Mother / Legal Guardian'])->contains(fn ($f) => $errors->has($f)))
            <div {!! $panel(3) !!} x-data="{ guardian: @js($guardianError ? 'father' : 'mother') }">
                <div class="cs-cards">
                    <section class="cs-card">
                        <div class="ca-card-head">
                            <h3 class="cs-card-title">Parents</h3>
                            <div class="ca-switch" role="group" aria-label="Which guardian">
                                <button type="button" @click="guardian = 'mother'" :aria-pressed="guardian === 'mother'" aria-pressed="{{ $guardianError ? 'false' : 'true' }}">Mother / legal guardian</button>
                                <button type="button" @click="guardian = 'father'" :aria-pressed="guardian === 'father'" aria-pressed="{{ $guardianError ? 'true' : 'false' }}">Father / legal guardian</button>
                            </div>
                        </div>
                        @foreach(['mother' => 'Mother', 'father' => 'Father'] as $who => $Who)
                            <div x-show="guardian === @js($who)" @if(($who === 'father') !== $guardianError) x-cloak @endif data-guardian="{{ $who }}">
                                <div class="cs-grid">
                                    {!! $tabField([$Who.' name', $who.'_name']) !!}
                                    {!! $tabField([$Who.' email', $who.'_email', 'email']) !!}
                                    {!! $tabField([$Who.' address', $who.'_address', 'text', null, true]) !!}
                                </div>
                                <p class="ca-eyebrow">Phones</p>
                                <div class="cs-grid">
                                    {!! $tabField([$Who.' cell', $who.'_cell', 'tel']) !!}
                                    {!! $tabField([$Who.' home phone', $who.'_home_phone', 'tel']) !!}
                                    {!! $tabField([$Who.' work phone', $who.'_work_phone', 'tel']) !!}
                                    {!! $tabField([$Who.' fax', $who.'_fax', 'tel']) !!}
                                </div>
                                <p class="ca-eyebrow">Work and ID</p>
                                <div class="cs-grid">
                                    {!! $tabField([$Who.' employer', $who.'_employer']) !!}
                                    {!! $tabField([$Who.' title', $who.'_title']) !!}
                                    {!! $tabField([$Who.' SSN', $who.'_ssn', 'ssn']) !!}
                                </div>
                            </div>
                        @endforeach
                    </section>
                </div>
            </div>

            {{-- ---- Add, tab 5: Emergency & pickup, one pick-up adult at a time ---- --}}
            @php($pickupError = collect([1, 2, 3])->first(fn ($n) => collect($sections['Authorized Pickup '.$n])->contains(fn ($f) => $errors->has($f))) ?? 1)
            <div {!! $panel(4) !!} x-data="{ pickup: {{ $pickupError }} }">
                <div class="cs-cards">
                    <section class="cs-card">
                        <h3 class="cs-card-title">Emergency contact</h3>
                        <div class="cs-grid">
                            {!! $tabField(['Emergency contact', 'emergency_contact']) !!}
                            {!! $tabField(['Secondary emergency contact', 'secondary_emergency_contact']) !!}
                            {!! $tabField(['Telephone', 'emergency_telephone', 'tel']) !!}
                            {!! $tabField(['Relationship', 'emergency_relationship']) !!}
                            {!! $tabField(['License #', 'emergency_license_number']) !!}
                        </div>
                    </section>
                    <section class="cs-card">
                        <div class="ca-card-head">
                            <h3 class="cs-card-title">Authorized pickup</h3>
                            <div class="ca-switch" role="group" aria-label="Which pick-up adult">
                                @foreach([1, 2, 3] as $n)
                                    <button type="button" @click="pickup = {{ $n }}" :aria-pressed="pickup === {{ $n }}" aria-pressed="{{ $n === $pickupError ? 'true' : 'false' }}">Pickup {{ $n }}</button>
                                @endforeach
                            </div>
                        </div>
                        @foreach([1, 2, 3] as $n)
                            <div class="cs-grid" x-show="pickup === {{ $n }}" @if($n !== $pickupError) x-cloak @endif data-pickup="{{ $n }}">
                                {!! $tabField(['Name', 'pickup_'.$n.'_name']) !!}
                                {!! $tabField(['Relationship', 'pickup_'.$n.'_relationship']) !!}
                                {!! $tabField(['Address', 'pickup_'.$n.'_address', 'text', null, true]) !!}
                                {!! $tabField(['Telephone', 'pickup_'.$n.'_telephone', 'tel']) !!}
                                {!! $tabField(['Alternate phone', 'pickup_'.$n.'_alternate', 'tel']) !!}
                                {!! $tabField(['License #', 'pickup_'.$n.'_license_number', 'text', 'Checked at the door before release.']) !!}
                            </div>
                        @endforeach
                    </section>
                </div>
            </div>
            @else
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
                                            @php($current = old($field, $fieldValue($field)))
                                            @if($options = $choices($field, $current))
                                                <select id="{{ $field }}" name="{{ $field }}" class="{{ $fieldClass($field) }}">@foreach($options as $value => $text)<option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $text }}</option>@endforeach</select>
                                            @else
                                                <input id="{{ $field }}" type="text" name="{{ $field }}" value="{{ $current }}" class="{{ $fieldClass($field) }}">
                                            @endif
                                            <x-input-error :messages="$errors->get($field)" />
                                        </label>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endforeach
            @endif

            {{-- ---- 3. People: one row per adult, saved as it goes ---- --}}
            @if($usesPeopleStep)
                <div @if($stepper) x-show="index === 2" x-cloak @endif>
                    @include('children.partials.people-step')
                </div>
            @endif

            {{-- ---- 4. Notes: two boxes of prose, a card each ---- --}}
            <div @if($tabbed) {!! $panel($last) !!} @elseif($stepper) x-show="index === {{ $last }}" x-cloak @endif>
                @unless($tabbed)
                    <h2 class="cs-title">{{ $steps[$last]['label'] }}</h2>
                    <p class="cs-blurb">{{ $steps[$last]['blurb'] }}</p>
                @endunless
                <div class="cs-cards">
                    {{-- On Add the important card leads, in amber; the order
                         is the layout's, so the markup stays one. --}}
                    <section class="cs-card" @if($tabbed) style="order: 2" @endif>
                        <div class="cs-card-head"><h3 class="cs-card-title">Other notes</h3></div>
                        <label>
                            <span class="cs-label">Anything worth knowing{!! $fromDocument('other_notes') ? $documentBadge : '' !!}</span>
                            <textarea name="other_notes" rows="4" class="{{ $fieldClass('other_notes') }}">{{ old('other_notes', $child?->other_notes ?? ($extracted['other_notes'] ?? '')) }}</textarea>
                            <x-input-error :messages="$errors->get('other_notes')" />
                        </label>
                    </section>
                    <section class="cs-card @if($tabbed) ca-important @endif">
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
        @elseif($tabbed)
            <a href="{{ route('children.index') }}" class="ca-cancel">Cancel</a>
            <span class="ca-step">Step <span x-text="index + 1">{{ $initialStep + 1 }}</span> of {{ count($steps) }}</span>
            <button type="button" class="ca-btn" @click="go(index - 1)" x-show="index > 0" @if($initialStep === 0) x-cloak @endif>Back</button>
            {{-- Next is a plain button and Add is the submit, so Enter in a text
                 box adds the child rather than turning the page. --}}
            <button type="button" class="ca-btn" @click="go(index + 1)" x-show="index < {{ $last }}" @if($initialStep === $last) x-cloak @endif
                    x-text="'Next: ' + @js(array_column($steps, 'label'))[index + 1]">Next: {{ $steps[min($initialStep + 1, $last)]['label'] }}</button>
            <button class="ca-btn ca-btn-primary" x-text="status === 'Archived' ? 'Save and archive' : @js($submit)">{{ $submit }}</button>
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
                        : @js($noBirthDateNote);
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
