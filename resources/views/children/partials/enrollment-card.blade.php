{{-- The Enrollment card: status, classroom, dates and schedule. Drawn inside
     Basics on Edit and on the PDF review, and as its own tab on Add; see
     children/form. Reads $child, $formBirthDate and the form's Alpine status. --}}
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
                                        <span id="classroom-note">@if($automaticRoom)Automatic: {{ $automaticRoom }}@elseif($formBirthDate)No band covers this date of birth — set the room by hand.@else {{ $noBirthDateNote }}@endif</span>
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

                                 A new record opens on the usual day, 7:30 to 6
                                 (Child::DEFAULT_DROP_OFF / DEFAULT_PICK_UP), so
                                 nobody has to type both ends to say "the usual";
                                 the centre's own hours stay the limits. --}}
                            @php($scheduleDefault = fn ($field, $default) => \App\Models\Child::timeInputValue(old($field, $child ? $child->{$field} : $default)))
                            <label>
                                <span class="cs-label">Drop-off time</span>
                                <input type="time" name="drop_off_time" value="{{ $scheduleDefault('drop_off_time', \App\Models\Child::DEFAULT_DROP_OFF) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="cs-input">
                                <x-input-error :messages="$errors->get('drop_off_time')" />
                                <span class="cs-help">When they normally arrive. The centre opens at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_OPENS_AT) }}.</span>
                            </label>
                            <label>
                                <span class="cs-label">Pick-up time</span>
                                <input type="time" name="pick_up_time" value="{{ $scheduleDefault('pick_up_time', \App\Models\Child::DEFAULT_PICK_UP) }}" min="{{ \App\Models\Child::DAY_OPENS_AT }}" max="{{ \App\Models\Child::DAY_CLOSES_AT }}" class="cs-input">
                                <x-input-error :messages="$errors->get('pick_up_time')" />
                                <span class="cs-help">When they normally go home. The centre closes at {{ \App\Models\Child::timeLabel(\App\Models\Child::DAY_CLOSES_AT) }}.</span>
                            </label>
                        </div>
                        </div>
                    </section>
