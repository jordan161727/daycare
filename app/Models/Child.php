<?php

namespace App\Models;

use App\Services\ClassroomAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class Child extends Model
{
    /** Rooms signed in by half day rather than one full-day stamp. */
    public const SESSION_ROOMS = ['School Age'];

    /**
     * What the record may say about a child, and nothing else.
     *
     * Blank is the third state and the default one: plenty of records predate
     * the question being asked. Only the drawn avatar reads this.
     */
    public const GENDERS = ['Girl', 'Boy'];

    /** The earliest drop-off and the latest pick-up the centre will take. */
    public const DAY_OPENS_AT = '07:00';

    public const DAY_CLOSES_AT = '20:00';

    /** The usual day a new record starts on (Jordan, 6 Nov 2026): 7:30 to 6. */
    public const DEFAULT_DROP_OFF = '07:30';

    public const DEFAULT_PICK_UP = '18:00';

    protected $casts = [
        'dob' => 'date',
        'birth_date' => 'date',
        'archived_at' => 'datetime',
        'enrolled_on' => 'date:Y-m-d',
        'withdrawn_on' => 'date:Y-m-d',
        'classroom_override_from' => 'date:Y-m-d',
        // Float rather than decimal: the projection does arithmetic with this on
        // every read, and a decimal cast hands back a string.
        'expected_hours_per_week' => 'float',
        'schedule_days' => 'array',
        'alerts' => 'array',
        'health' => 'array',
        'enrollment_gaps' => 'array',
        'enrollment_note_at' => 'datetime',
        'mother_ssn' => 'encrypted',
        'father_ssn' => 'encrypted',
    ];

    /** Never in a page's JSON: a child turned into an array would carry them decrypted. */
    protected $hidden = ['mother_ssn', 'father_ssn'];

     protected $fillable = [
        'lan',
        // The two numbers the state knows a subsidised child by: the family's
        // case and this child's own CIN. See the migration that adds them.
        'dss_case_no', 'dss_cin',
        'child_name', 'nickname', 'address', 'city', 'state', 'zip', 'telephone', 'birth_date',
        'status',
        'enrolled_on',
        'withdrawn_on',
        'withdrawal_reason', 'withdrawal_note', 'could_return',
        'enrollment_note', 'enrollment_note_at',
        'health', 'custody_note',
        'expected_hours_per_week',
        'drop_off_time',
        'pick_up_time',
        'schedule_days',
        'first_name',
        'last_name',
        'photo_path',
        'gender',
        'description',
        'dob',
        'age',
        'classroom',
        'classroom_override',
        'classroom_override_from',
        'mother_name', 'mother_address', 'mother_home_phone', 'mother_employer', 'mother_work_phone', 'mother_fax', 'mother_cell', 'mother_email', 'mother_title', 'mother_ssn',
        'father_name', 'father_address', 'father_home_phone', 'father_employer', 'father_work_phone', 'father_fax', 'father_cell', 'father_email', 'father_title', 'father_ssn',
        'email_address', 'parents_status', 'responsible_for_payment', 'emergency_contact', 'secondary_emergency_contact', 'emergency_telephone', 'emergency_relationship', 'emergency_license_number',
        'pickup_1_name', 'pickup_1_address', 'pickup_1_telephone', 'pickup_1_alternate', 'pickup_1_relationship', 'pickup_1_license_number',
        'pickup_2_name', 'pickup_2_address', 'pickup_2_telephone', 'pickup_2_alternate', 'pickup_2_relationship', 'pickup_2_license_number',
        'pickup_3_name', 'pickup_3_address', 'pickup_3_telephone', 'pickup_3_alternate', 'pickup_3_relationship', 'pickup_3_license_number', 'other_notes', 'important_notes', 'alerts',
        'where_advertised',
        'who_referred',
    ];

    /**
     * `classroom` is the worked-out answer, never something typed in — it is what
     * reports group by, what teacher visibility filters on and what the room
     * counts add up, all of them straight from SQL. Recomputing it on every save
     * is what keeps those reads honest.
     */
    protected static function booted(): void
    {
        static::saving(function (self $child) {
            // A room written straight onto a record the age rule cannot place —
            // no date of birth, or one outside every band — is somebody making
            // the call by hand. That is an override, so record it as one rather
            // than discarding it and leaving the child in no room at all.
            if ($child->isDirty('classroom')
                && filled($child->classroom)
                && blank($child->classroom_override)
                && $child->automaticClassroom() === null) {
                $child->classroom_override = $child->classroom;
            }

            // A child who has left keeps the room they left from: it is the
            // "Last room" on the Withdrawn tab and what the old weeks were
            // reported under. Recomputing it would let the age rule move a
            // withdrawn child on their birthday the next time anybody saved
            // the record. The room is worked out again when they come back
            // (the status changes), or when someone sets one by hand.
            $left = in_array($child->status, ['Inactive', self::ARCHIVED], true);
            $frozen = $child->exists && $left
                && in_array($child->getOriginal('status'), ['Inactive', self::ARCHIVED], true)
                && filled($child->getOriginal('classroom'))
                && ! $child->isDirty(['classroom', 'classroom_override', 'classroom_override_from']);

            if (! $frozen) {
                $child->classroom = $child->classroomOn();
            }

            // The footer's "last change by": whoever is signed in, when
            // anybody is. A seeder or the nightly room sync leaves it as it was.
            if ($child->exists && $child->isDirty() && auth()->id()) {
                $child->updated_by = auth()->id();
            }
        });

        // A parent's SSN written onto the record is a line in the activity log (QA #34).
        static::saved(function (self $child) {
            foreach (['mother_ssn' => 'mother', 'father_ssn' => 'father'] as $column => $who) {
                if ($child->wasChanged($column) || ($child->wasRecentlyCreated && filled($child->{$column}))) {
                    \App\Services\SensitiveAccess::record(
                        \App\Services\SensitiveAccess::UPDATED,
                        $who."'s ".(blank($child->{$column}) ? 'cleared' : 'set').' on the record of '.trim($child->first_name.' '.$child->last_name),
                        $child,
                        trim($child->first_name.' '.$child->last_name),
                    );
                }
            }
        });

        // The first line of the history: added to the roll, as something.
        static::created(fn (self $child) => $child->recordStatus(null, $child->status));

        // And every line after it. wasChanged rather than isDirty, because this
        // runs after the write — isDirty is already false by then, which is the
        // quiet way an audit trail ends up empty.
        static::updated(function (self $child) {
            if ($child->wasChanged('status')) {
                $child->recordStatus($child->getOriginal('status'), $child->status);
            }

            /*
             * The enrollment history (Phase 2.3) is every change to the
             * child's place here, not only the status: the room they moved
             * to, the days and hours changing, the subsidy starting. Written
             * off the save itself so the nightly room sync and the edit form
             * leave the same line.
             */
            if ($child->wasChanged('classroom') && filled($child->getOriginal('classroom')) && filled($child->classroom)) {
                $line = $child->recordEvent('room', $child->getOriginal('classroom').' → '.$child->classroom, $child->roomChangeNote);

                // A move dated back is dated back in the history too (QA C7),
                // so the line and the room-on-a-date reads agree with it.
                if ($child->roomChangeAt && $child->roomChangeAt->lt(Carbon::today())) {
                    $line->forceFill(['created_at' => $child->roomChangeAt->copy()->startOfDay()])->saveQuietly();
                }
            }

            if ($child->wasChanged(['schedule_days', 'drop_off_time', 'pick_up_time'])) {
                $child->recordEvent('schedule', 'Schedule changed', $child->scheduleSummary());
            }

            if ($child->wasChanged('dss_case_no') && blank($child->getOriginal('dss_case_no')) && filled($child->dss_case_no)) {
                $child->recordEvent('subsidy', 'Subsidy started', 'DSS case '.$child->dss_case_no.(filled($child->dss_cin) ? ' · CIN '.$child->dss_cin : ''));
            } elseif ($child->wasChanged('dss_case_no') && filled($child->getOriginal('dss_case_no')) && filled($child->dss_case_no)) {
                // A new case number, and the subsidy ending, leave their lines too (QA F2).
                $child->recordEvent('subsidy', 'Subsidy case changed', 'DSS case '.$child->getOriginal('dss_case_no').' → '.$child->dss_case_no);
            } elseif ($child->wasChanged('dss_case_no') && filled($child->getOriginal('dss_case_no')) && blank($child->dss_case_no)) {
                $child->recordEvent('subsidy', 'Subsidy ended', 'DSS case '.$child->getOriginal('dss_case_no').' removed');
            }
        });
    }

    /** The DSS case number: letters, digits and dashes (QA C9). */
    public const DSS_CASE_NO_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{2,19}$/';

    /** The child's CIN as New York issues it: two letters, five digits, a letter — AB12345C. */
    public const DSS_CIN_PATTERN = '/^[A-Za-z]{2}\d{5}[A-Za-z]$/';

    /** @return array<string, string> */
    public static function dssMessages(): array
    {
        return [
            'dss_case_no.regex' => 'A DSS case number is letters and digits only, like S1177706D.',
            'dss_cin.regex' => 'A DSS CIN is two letters, five digits and a letter, like AB12345C.',
        ];
    }

    /** Why a room was set by hand, carried from the form to the history line. Not a column. */
    public ?string $roomChangeNote = null;

    /** The day a room set by hand starts from, when that is in the past. Not a column. */
    public ?Carbon $roomChangeAt = null;

    /**
     * One line of history that is not a status change: a room move, a
     * schedule change, the subsidy starting, a withdrawal's reason.
     */
    public function recordEvent(string $event, string $summary, ?string $note = null): ChildStatusChange
    {
        return $this->statusChanges()->create([
            'event' => $event,
            'from_status' => $summary,
            'to_status' => $this->status,
            'note' => $note,
            'changed_by' => auth()->id(),
        ]);
    }

    /* ---- Phase 2: how the record reads on the list and the page ---- */

    /** Inactive is what the column says; Withdrawn is what the screen says. */
    public const STATUS_LABELS = ['Active' => 'Active', 'Pending' => 'Pending', 'Inactive' => 'Withdrawn', 'Archived' => 'Archived'];

    public const WITHDRAWAL_REASONS = ['Moved out of the area', 'Changed provider', 'Started school / aged out', 'Subsidy ended', 'Family choice', 'Non-payment', 'Other'];

    public const CANCEL_REASONS = ['Family changed plans', 'Chose another provider', 'Subsidy not approved', 'No space in the room', 'Other'];

    public const COULD_RETURN = ['Yes', 'No', 'Not sure'];

    /** The papers a record keeps, in the order the Documents card lists them. */
    public const DOCUMENT_KINDS = [
        'enrollment_packet' => 'Enrollment packet',
        'medical_statement' => 'Medical statement (yearly)',
        'immunization_record' => 'Immunization record',
        'allergy_care_plan' => 'Allergy care plan',
        'photo_consent' => 'Photo consent',
        'sunscreen_consent' => 'Sunscreen & insect repellent consent',
    ];

    /** What "Records to finish" counts, keyed as the list filters them. */
    public const GAPS = [
        'guard' => 'No guardian',
        'emerg' => 'No emergency contact',
        'imm' => 'Immunizations due',
        'photo' => 'Photo consent unsigned',
        'hours' => 'Hours not agreed',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === 'Inactive';
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }

    /** On the subsidy: either DSS number on file. */
    public function isDss(): bool
    {
        return filled($this->dss_case_no) || filled($this->dss_cin);
    }

    /** Hours a week, worked out from the days and the times — never typed in. */
    public function hoursPerWeek(): ?float
    {
        $days = $this->scheduleDays();

        if (! $days || blank($this->drop_off_time) || blank($this->pick_up_time)) {
            return null;
        }

        $minutes = Carbon::parse($this->drop_off_time)->diffInMinutes(Carbon::parse($this->pick_up_time), false);

        return $minutes > 0 ? round($minutes / 60 * count($days), 1) : null;
    }

    /** "Mon–Fri 8:00 AM – 5:00 PM", for the history and the overview. */
    public function scheduleSummary(): string
    {
        $days = $this->scheduleDaysLabel();
        $days = $days === 'Every day' ? 'Mon–Fri' : $days;

        return trim(($days ?? '').' '.($this->scheduleLabel() ?? ''));
    }

    /** The health facts beside the alerts: doctor, immunizations, medication. */
    public function health(string $key): ?string
    {
        $value = $this->health[$key] ?? null;

        return blank($value) ? null : (string) $value;
    }

    /** Due when nothing is on file, or the next date is past or within a month. */
    public function immunizationsDue(): bool
    {
        $due = $this->health('immunizations_due');

        if ($due === null) {
            return true;
        }

        return Carbon::parse($due)->lte(Carbon::today()->addDays(30));
    }

    public function documents()
    {
        return $this->hasMany(ChildDocument::class)->latest();
    }

    public function notes()
    {
        return $this->hasMany(ChildNote::class)->latest();
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The document of a kind, latest first, or null. Reads the loaded relation when there is one. */
    public function document(string $kind): ?ChildDocument
    {
        return $this->documents->firstWhere('kind', $kind);
    }

    /**
     * A signed photo consent that is still in date. An expired one is not a
     * signed one: the Records-to-finish chip, the record's badge and the
     * "no photos" mark on messaging all read this.
     */
    public function photoConsentOnFile(): bool
    {
        $consent = $this->document('photo_consent');

        return $consent !== null
            && ($consent->expires_on === null || $consent->expires_on->gte(Carbon::today()));
    }

    /**
     * The guardian link, first by call order. Reads the loaded personLinks.
     * A restricted person (a court order, most often) is never the guardian
     * the roll offers to ring, whatever the tick says.
     */
    public function guardianLink(): ?ChildPerson
    {
        return $this->personLinks->filter(fn (ChildPerson $link) => $link->is_guardian && blank($link->restriction))
            ->sortBy(fn (ChildPerson $link) => $link->priority ?? 99)->first();
    }

    /** Somebody unrestricted to ring. A restricted person does not count. */
    public function hasEmergencyContact(): bool
    {
        return $this->personLinks->contains(fn (ChildPerson $link) => $link->is_emergency && blank($link->restriction));
    }

    /**
     * What is still to be done on this record, keyed as GAPS.
     *
     * Reads personLinks and documents, so load both before asking down a roll.
     *
     * @return array<string, string>
     */
    public function missingItems(): array
    {
        $gaps = [];

        if ($this->guardianLink() === null) {
            $gaps['guard'] = self::GAPS['guard'];
        }
        if (! $this->hasEmergencyContact()) {
            $gaps['emerg'] = self::GAPS['emerg'];
        }
        if ($this->immunizationsDue()) {
            $gaps['imm'] = self::GAPS['imm'];
        }
        if (! $this->photoConsentOnFile()) {
            $gaps['photo'] = self::GAPS['photo'];
        }
        if (! $this->hoursAgreed()) {
            $gaps['hours'] = self::GAPS['hours'];
        }

        return $gaps;
    }

    /** What a child must have on file before they can be made Active (tester report, Nov 2026). */
    public const ACTIVE_REQUIRES = [
        'birth_date' => 'a date of birth',
        'guardian' => 'a guardian',
        'emergency' => 'an emergency contact',
    ];

    /**
     * What this record still lacks before it may be marked Active, keyed as
     * ACTIVE_REQUIRES. Reads personLinks, so a record on file is asked about
     * its people; a new one (no links yet) passes its own answers instead.
     *
     * @param  array{birth_date?: bool, guardian?: bool, emergency?: bool}  $has  facts already known, e.g. from the Add form
     * @return array<string, string>
     */
    public function missingForActive(array $has = []): array
    {
        $has += [
            'birth_date' => $this->birthDate() !== null,
            'guardian' => $this->exists && $this->guardianLink() !== null,
            'emergency' => $this->exists && $this->hasEmergencyContact(),
        ];

        return array_filter(self::ACTIVE_REQUIRES, fn ($label, $key) => ! $has[$key], ARRAY_FILTER_USE_BOTH);
    }

    /** "Add a date of birth and an emergency contact before marking Ava Active." */
    public static function activeRequirementMessage(array $missing, ?string $firstName = null): string
    {
        $items = array_values($missing);
        $list = count($items) > 1
            ? implode(', ', array_slice($items, 0, -1)).' and '.end($items)
            : ($items[0] ?? '');

        return 'Add '.$list.' before marking '.(filled($firstName) ? trim($firstName) : 'this child').' Active.';
    }

    /**
     * What the Children page's search box looks through for this child,
     * lower-cased: the name both ways round, the nickname, the room, the LAN
     * and every unrestricted guardian's name. Reads personLinks.person.
     */
    public function rosterSearchText(): string
    {
        $parents = $this->personLinks
            ->filter(fn (ChildPerson $link) => $link->is_guardian && blank($link->restriction))
            ->sortBy(fn (ChildPerson $link) => $link->priority ?? 99)
            ->map(fn (ChildPerson $link) => $link->person?->name)
            ->filter()
            ->implode(' ');

        return mb_strtolower($this->first_name.' '.$this->last_name.' '.$this->displayName('last_first').' '.$this->nickname.' '.$this->classroom.' '.$this->lan.' '.$parents);
    }

    /**
     * A child already on file who looks like this one (tester report, Nov
     * 2026): the same first and last name, ignoring case and spaces, and the
     * same birthday — read from either date column. A record on either side
     * with no birthday at all still matches by name, since nothing then says
     * they differ; a different birthday on both never does.
     *
     * Shared by the Add form, the scanned-form import (which saves through
     * it) and the spreadsheet import.
     */
    public static function findDuplicate(?string $firstName, ?string $lastName, ?string $birthDate = null, ?int $except = null): ?self
    {
        return self::duplicatesQuery($firstName, $lastName, $birthDate, $except)?->first();
    }

    /**
     * Every child on file that findDuplicate would match, oldest first, in
     * every status — the Add form's live check lists them (QA, Nov 2026).
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function findDuplicates(?string $firstName, ?string $lastName, ?string $birthDate = null, ?int $except = null, ?int $limit = null): \Illuminate\Support\Collection
    {
        $query = self::duplicatesQuery($firstName, $lastName, $birthDate, $except);

        return $query ? $query->when($limit !== null, fn ($query) => $query->limit($limit))->get() : collect();
    }

    /** The match itself, or null when there is no name to match on. */
    public static function duplicatesQuery(?string $firstName, ?string $lastName, ?string $birthDate = null, ?int $except = null): ?\Illuminate\Database\Eloquent\Builder
    {
        $key = fn (?string $value) => str_replace(' ', '', mb_strtolower(trim((string) $value)));

        if ($key($firstName) === '' || $key($lastName) === '') {
            return null;
        }

        $squash = fn (string $column) => "REPLACE(LOWER(TRIM({$column})), ' ', '') = ?";

        return self::query()
            ->whereRaw($squash('first_name'), [$key($firstName)])
            ->whereRaw($squash('last_name'), [$key($lastName)])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->when(filled($birthDate), fn ($query) => $query->where(fn ($query) => $query
                ->whereDate('birth_date', $birthDate)
                ->orWhere(fn ($query) => $query->whereNull('birth_date')->whereDate('dob', $birthDate))
                ->orWhere(fn ($query) => $query->whereNull('birth_date')->whereNull('dob'))))
            ->orderBy('id');
    }

    /** "A child with this name and birthday already exists: LAN 1050 · Ada Lovelace · Infant · Active · born Mar 2, 2024." */
    public function duplicateSummary(?string $birthDate = null): string
    {
        $born = $this->birthDate();

        return sprintf(
            'A child with %s already exists: LAN %s · %s %s · %s · %s%s.',
            filled($birthDate) && $born !== null ? 'this name and birthday' : 'this name',
            $this->lan,
            $this->first_name,
            $this->last_name,
            $this->classroom ?: 'no room yet',
            self::statusLabel($this->status),
            $born ? ' · born '.$born->format('M j, Y') : ' · no date of birth on file',
        );
    }

    /**
     * The drop-off and pick-up rules, one copy for the edit form, the Add
     * form and the record's Overview card (QA C2; second QA brief, Nov 2026):
     * nothing before DAY_OPENS_AT or after DAY_CLOSES_AT, and pick-up after
     * drop-off. Hours inside those but outside the centre's own opening
     * hours are allowed and flagged — see hoursOutsideOpening.
     */
    public static function scheduleTimeRules(): array
    {
        return [
            'drop_off_time' => ['nullable', 'date_format:H:i', 'after_or_equal:'.self::DAY_OPENS_AT, 'before_or_equal:'.self::DAY_CLOSES_AT],
            'pick_up_time' => ['nullable', 'date_format:H:i', 'after_or_equal:'.self::DAY_OPENS_AT, 'before_or_equal:'.self::DAY_CLOSES_AT, 'after:drop_off_time'],
        ];
    }

    /** The same words wherever those rules refuse a time. */
    public static function scheduleTimeMessages(): array
    {
        $opens = self::timeLabel(self::DAY_OPENS_AT);
        $closes = self::timeLabel(self::DAY_CLOSES_AT);

        return [
            'drop_off_time.after_or_equal' => "Drop-off can't be before {$opens} — the earliest the center takes a child.",
            'drop_off_time.before_or_equal' => "Drop-off can't be after {$closes}.",
            'pick_up_time.after_or_equal' => "Pick-up can't be before {$opens}.",
            'pick_up_time.before_or_equal' => "Pick-up can't be after {$closes} — the latest the center keeps a child.",
            'pick_up_time.after' => 'Pick-up has to be after drop-off.',
        ];
    }

    /**
     * Hours that fall outside when the centre — or the child's room, where
     * Settings › Rooms & hours gives it its own — is open, as a sentence, or
     * null when they fit. Five duplicate records carried 7:00 AM – 8:00 PM
     * against a centre that closes at 6 (second QA brief, Nov 2026). Said on
     * save by the edit form, the Add form and the Overview card alike.
     */
    public static function hoursOutsideOpening(?string $dropOff, ?string $pickUp, ?string $room = null): ?string
    {
        if (blank($dropOff) && blank($pickUp)) {
            return null;
        }

        $settings = app(\App\Services\CentreSettings::class);
        $schedule = filled($room) ? (\App\Models\RoomSchedule::byRoom()[$room] ?? null) : null;
        $centreOpens = substr((string) $settings->get('centre_opens'), 0, 5);
        $centreCloses = substr((string) $settings->get('centre_closes'), 0, 5);
        $roomOpens = substr((string) ($schedule?->opens_at ?: $centreOpens), 0, 5);
        $roomCloses = substr((string) ($schedule?->closes_at ?: $centreCloses), 0, 5);
        // The narrower of the two: a room set to close after the centre does
        // (which Rooms & hours warns about) does not keep a child past it.
        $opens = max($centreOpens, $roomOpens);
        $closes = min($centreCloses, $roomCloses);
        $schedule = $schedule && ($roomOpens > $centreOpens || $roomCloses < $centreCloses) ? $schedule : null;
        $hm = fn (?string $time) => blank($time) ? null : substr(Carbon::parse($time)->format('H:i'), 0, 5);

        $early = $hm($dropOff) !== null && $hm($dropOff) < $opens;
        $late = $hm($pickUp) !== null && $hm($pickUp) > $closes;

        if (! $early && ! $late) {
            return null;
        }

        $where = $schedule ? $room.' is open' : 'the center is open';

        return 'Hours '.trim((self::timeLabel($dropOff) ?? '…').' – '.(self::timeLabel($pickUp) ?? '…'))
            .' fall outside when '.$where.' ('.self::timeLabel($opens).' – '.self::timeLabel($closes).'). Check the times.';
    }

    /**
     * Whether the child's week has been agreed: the days they come and the
     * hours of the day, both.
     *
     * QA #31, 12 Nov 2026: a new record opens on the usual 7:30 to 6
     * (DEFAULT_DROP_OFF / DEFAULT_PICK_UP), so a child added with no days
     * ticked had times on file and was never counted under "Hours not
     * agreed" — while the roll showed a week of grey days. Times alone are
     * not an agreement, so the badge, the Record column and the roll's
     * Schedule column all ask this one question.
     */
    public function hoursAgreed(): bool
    {
        return $this->scheduleLabel() !== null && (bool) $this->scheduleDays();
    }

    /** How much of the record is filled in, as the header's bar shows it. */
    public function completeness(): int
    {
        $checks = [
            $this->birthDate() !== null,
            $this->enrolled_on !== null,
            filled($this->classroom),
            (bool) $this->scheduleDays(),
            $this->scheduleLabel() !== null,
            $this->guardianLink() !== null,
            $this->hasEmergencyContact(),
            ! $this->immunizationsDue(),
            $this->photoConsentOnFile(),
            // An expired statement is one to renew, not one on file (QA E7).
            ($medical = $this->document('medical_statement')) !== null && ! ($medical->expires_on && $medical->expires_on->lt(Carbon::today())),
        ];

        return (int) round(count(array_filter($checks)) / count($checks) * 100);
    }

    /**
     * Append one line to this child's history on the roll.
     *
     * auth()->id() is null for a seeder, an import or a command run from the
     * shell, and the row is written anyway: "we do not know who" is a fact
     * worth keeping and is not the same as nobody having done it.
     */
    protected function recordStatus(?string $from, ?string $to): void
    {
        if ($to === null || $from === $to) {
            return;
        }

        $this->statusChanges()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * What people type into Nickname to mean "there isn't one".
     *
     * QA #20, 12 Nov 2026: "N/A" was saved and shown on the roll as
     * "Mia (N/A)", as if it were what she is called. Compared trimmed and
     * without regard to case.
     */
    public const NO_NICKNAME = ['', 'n/a', 'na', 'none', '-', '—', '–'];

    /** A nickname, or null when what was typed means "none" (see NO_NICKNAME). */
    public static function cleanNickname(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return in_array(mb_strtolower($value), self::NO_NICKNAME, true) ? null : $value;
    }

    /**
     * Read and written through cleanNickname, so every save — the Add form,
     * the edit form, the record's Overview card, an import — stores null for
     * "N/A", and a record saved before this reads as having no nickname
     * rather than showing the placeholder on every screen.
     */
    protected function nickname(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => self::cleanNickname($value),
            set: fn ($value) => self::cleanNickname($value),
        );
    }

    public function getFullNameAttribute()
    {
        return "{$this->last_name}, {$this->first_name}";
    }

    /**
     * This child's name written the way the reader asked for it.
     *
     * The office works from surnames because that is how the paper file is
     * ordered; the room works from first names because that is what a child
     * answers to. Both read the same roster, so the shape is the reader's
     * preference and every screen asks this rather than concatenating its own
     * two columns — which is how one page ends up disagreeing with the next.
     *
     * The sort is deliberately not affected. Ordering the roll by surname is
     * how a roll is found; that is a different question from how a name reads,
     * and tying the two would reorder the whole sheet on a display setting.
     *
     * @param  string|null  $format  a User::NAME_FORMATS key; the signed-in
     *                               reader's own preference when omitted
     */
    public function displayName(?string $format = null): string
    {
        // Only a staff account has a preference; a parent signed in to the
        // app (a Person) reads names the default way.
        $reader = auth()->user();
        $format ??= $reader instanceof User ? $reader->nameFormat() : User::NAME_FORMAT_DEFAULT;

        return $format === 'last_first'
            ? trim($this->last_name.', '.$this->first_name, ' ,')
            : trim($this->first_name.' '.$this->last_name);
    }

    /**
     * The key a list of children is ordered by: surname, then first name,
     * whichever way displayName() writes the name. Lowercased so "de Souza"
     * files among the Ds rather than after every capital Z. Settings →
     * Children promises "Lists still sort by surname"; a list sorted in the
     * browser takes this key rather than sorting on the text it shows.
     */
    public function sortName(): string
    {
        return mb_strtolower(trim($this->last_name.' '.$this->first_name));
    }

    /**
     * The date of birth, from whichever column holds it. `dob` came first and
     * `birth_date` arrived with the enrolment form; records exist with either.
     */
    public function birthDate(): ?Carbon
    {
        return $this->birth_date ?? $this->dob;
    }

    /**
     * The date of birth written the way every screen reads it: 12/15/22.
     *
     * Month, day, year — the way the centre writes a date on paper and asked
     * to read it here, in place of the year-first form that sorted by eye but
     * read as nobody's date. Every part padded to two digits, so 06/15/23
     * and 12/05/23 are the same width and the slashes line up down a column
     * of sixty. Formatted on every read rather than stored, so it cannot
     * drift out of step with the date it comes from.
     */
    public static function ageLabelFor(?CarbonInterface $birthDate): ?string
    {
        return $birthDate?->format('m/d/y');
    }

    /** This child's date of birth as the roster shows it, or null when none is on file. */
    public function ageLabel(): ?string
    {
        return static::ageLabelFor($this->birthDate());
    }

    /**
     * How old the child is: "3y 2m".
     *
     * One shape everywhere — the roster's Age column, the attendance sheet's,
     * and anywhere else a child's age is shown. Both halves are always said,
     * so a ten-month-old reads "0y 10m" rather than "10 months": down a column
     * of sixty children the years sit under the years and the months under the
     * months, and two ages are compared by looking rather than by reading. The
     * long form was also three times the width, in the narrowest column on the
     * densest screen in the app.
     *
     * The exception is a child not yet a month old, who would otherwise be
     * "0y 0m" — an infant room takes babies at six weeks, and "0y 0m" for a
     * fortnight-old is not an age at all. Those read "12d", in the same compact
     * shape, until there is a month to report.
     *
     * Worked out on every read and never stored, because it is different next
     * month. Days are dropped once there are months: they matter to a parent
     * and not to a roster, and "3y 2m 14d" is three facts where one was wanted.
     */
    public function ageInWords(?Carbon $asOf = null): ?string
    {
        $birthDate = $this->birthDate();

        if ($birthDate === null) {
            return null;
        }

        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();
        $birthDate = $birthDate->copy()->startOfDay();

        // A date of birth in the future is somebody's typo, not a negative age.
        if ($birthDate->greaterThan($asOf)) {
            return null;
        }

        $age = $birthDate->diff($asOf);

        if ($age->y === 0 && $age->m === 0) {
            return $age->d.'d';
        }

        return $age->y.'y '.$age->m.'m';
    }

    /**
     * Where the browser can fetch this child's photograph, or null when there
     * is none to fetch.
     *
     * A route rather than a URL on a public disk. The file is a photograph of a
     * minor, so every request for it goes through the same check the child's
     * record does — see ChildController::photo. The existence check keeps a row
     * pointing at a file somebody has since removed from showing as a broken
     * image on every page the child appears on.
     */
    public function photoUrl(): ?string
    {
        if (blank($this->photo_path) || ! Storage::disk('local')->exists($this->photo_path)) {
            return null;
        }

        // The path is a fresh random name on every upload, so a stamp made
        // from it changes the moment the photograph does. Without it the
        // browser, told it may keep the picture for an hour, went on showing
        // the one just replaced on every board and roster until the hour was up.
        return route('children.photo', ['child' => $this, 'v' => substr(md5($this->photo_path), 0, 8)]);
    }

    /** The initial the avatar falls back to while there is no photograph. */
    public function initial(): string
    {
        return strtoupper(substr($this->first_name ?? '?', 0, 1));
    }

    /**
     * The five days a week can be registered for.
     *
     * Monday to Friday only: the centre does not open at the weekend, so a
     * sixth key would be a box nobody could ever tick. Keyed by ISO weekday so
     * the number stored is the number Carbon::dayOfWeekIso hands back and
     * nothing has to translate between the two.
     */
    /**
     * What a child can be on the roll.
     *
     * Pending is a child whose paperwork is in and whose place is agreed but
     * who has not started. They are on the roll, so they can be found and
     * their record filled in, and they are not Active, so they are not counted
     * as somebody the rooms are staffed for or looked for at sign-in.
     */
    public const STATUSES = ['Active', 'Pending', 'Inactive', 'Archived'];

    /**
     * Archived is the one status that takes a child off every list.
     *
     * Active, Pending and Inactive are all "on the roll, in some state";
     * Archived is "off it, record kept". Named here so the roster, the counts
     * and the export all ask the same question.
     */
    public const ARCHIVED = 'Archived';

    /** The statuses the Children page shows and counts. Archived is never one of them. */
    public const ROLL_STATUSES = ['Active', 'Pending', 'Inactive'];

    /**
     * The kinds of alert a record can carry, and how each one reads.
     *
     * The kind is what gives the chip its colour, so a court order is noticed
     * without being read — which is the whole point of putting these on the
     * roll rather than leaving them in the paragraph below them.
     *
     * The example is what the empty field offers as the shape of an answer,
     * so it has to belong to the kind: a court order prompted with a banana
     * teaches the wrong thing about both.
     *
     * Colours are the app's own and mean what they mean everywhere else here:
     * rose is a rule that must not be broken, amber is a thing to watch, sky
     * is a matter of fact, slate is a remark.
     */
    public const ALERT_TYPES = [
        // The example is a placeholder only; a real-looking name here read as
        // a real order when it turned up on records (QA, 16 Nov 2026).
        'court' => ['label' => 'Court Order', 'example' => 'No pickup by (name on the order)', 'classes' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-200'],
        'allergy' => ['label' => 'Allergy', 'example' => 'Banana — swells, no epi-pen', 'classes' => 'bg-orange-100 text-orange-900 dark:bg-orange-500/20 dark:text-orange-100'],
        'medical' => ['label' => 'Medical', 'example' => 'Asthma — inhaler in office', 'classes' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-100'],
        'medication' => ['label' => 'Medication', 'example' => 'Amoxicillin 5 ml at noon until Friday', 'classes' => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-100'],
        'note' => ['label' => 'Note', 'example' => 'Leaves early on Tuesdays', 'classes' => 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'],
    ];

    /**
     * The health alerts: drawn in their own colour with their own icon, apart
     * from a court order and well apart from an everyday note (QA, 16 Nov
     * 2026: "Peanuts" looked the same as "Leaves early on Tuesdays").
     */
    public const HEALTH_ALERT_TYPES = ['allergy', 'medical', 'medication'];

    /** One small icon per kind (24-unit stroke paths), drawn by <x-child-alert>. */
    public const ALERT_ICONS = [
        'allergy' => '<path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>',
        'medical' => '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>',
        'medication' => '<path d="M10.5 20.5a4.95 4.95 0 0 1-7-7l10-10a4.95 4.95 0 0 1 7 7z"/><path d="m8.5 8.5 7 7"/>',
        'court' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
        'note' => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4m0-4h.01"/>',
    ];

    /** The whole icon as markup, for a page drawing chips in Alpine (x-html). */
    public static function alertIcon(string $type): string
    {
        $paths = self::ALERT_ICONS[$type] ?? self::ALERT_ICONS['note'];

        return '<svg class="na-alert-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    }

    /**
     * One alert as a screen drawn in Alpine needs it: the words, the kind
     * (which picks the colour), whether it is severe, and the icon.
     *
     * @param  array<string, mixed>  $alert  one row of alertList()
     */
    public static function alertForScreen(array $alert): array
    {
        return [
            'type' => $alert['type'],
            'label' => $alert['label'],
            'text' => $alert['text'],
            'severity' => $alert['severity'] ?? null,
            'severe' => strtolower((string) ($alert['severity'] ?? '')) === 'severe',
            'health' => in_array($alert['type'], self::HEALTH_ALERT_TYPES, true),
            'icon' => self::alertIcon($alert['type']),
        ];
    }

    /** The record's alerts for an Alpine screen, health ones first, severe first among them. */
    public function alertsForScreen(): array
    {
        return array_values(collect($this->alertList())
            ->sortBy(fn (array $a) => [in_array($a['type'], self::HEALTH_ALERT_TYPES, true) || $a['type'] === 'court' ? 0 : 1, strtolower((string) ($a['severity'] ?? '')) === 'severe' ? 0 : 1, $a['index']])
            ->map(fn (array $a) => self::alertForScreen($a))
            ->all());
    }

    /**
     * The alerts on this record, as the page wants to draw them.
     *
     * Rows with no words in them are dropped rather than shown empty: the form
     * posts a blank row whenever somebody opens one and changes their mind,
     * and a chip reading "Allergy:" with nothing after it is worse than no
     * chip, because it says a question was answered when it was not.
     *
     * A kind that is not one of ours — a record hand-edited, or a type retired
     * later — falls back to Note rather than disappearing. The words are the
     * part that matters; the colour is how they are found.
     *
     * @return array<int, array{type: string, label: string, classes: string, text: string}>
     */
    public function alertList(): array
    {
        $out = [];

        foreach (array_values($this->alerts ?? []) as $index => $alert) {
            $text = trim((string) ($alert['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $type = (string) ($alert['type'] ?? 'note');
            $known = self::ALERT_TYPES[$type] ?? self::ALERT_TYPES['note'];

            $out[] = [
                'type' => isset(self::ALERT_TYPES[$type]) ? $type : 'note',
                'label' => $known['label'],
                'classes' => $known['classes'],
                'text' => $text,
                // The record page's own entry (Phase 2.3): how bad, what
                // happens, what to do, when the care plan was signed.
                'severity' => $alert['severity'] ?? null,
                'reaction' => $alert['reaction'] ?? null,
                'action' => $alert['action'] ?? null,
                'plan_on' => $alert['plan_on'] ?? null,
                // Where it sits in the stored list, and what it says, so a
                // Remove or Edit from a page drawn before somebody else's
                // change finds this alert and not whichever one has moved
                // into its place (QA E4).
                'index' => $index,
                'key' => self::alertKey($alert),
            ];
        }

        return $out;
    }

    /** A short fingerprint of one stored alert, as the record page posts it back. */
    public static function alertKey(mixed $alert): string
    {
        return substr(sha1(json_encode($alert)), 0, 12);
    }

    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];

    /** The state in the home address: postal code => name, DC included. */
    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
        'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
        'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
        'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ];

    /**
     * The choices for Parents status (Jordan, 6 Nov 2026). Still a free-text
     * column underneath: imports and older records carry their own words,
     * and the form keeps any of those as an extra choice rather than losing it.
     */
    public const PARENTS_STATUSES = ['Married', 'Single', 'Divorced', 'Separated', 'Widowed', 'Living together'];

    /**
     * The days this child is registered to attend, or null when nobody has said.
     *
     * Null and [] are different answers and stay that way: null is every record
     * that predates the question, and a week opened for such a child starts
     * blank exactly as it always did. [] is somebody saying "no days", which is
     * a real statement about a child on the roll who is not currently coming.
     *
     * @return array<int, int>|null ISO weekdays, ascending
     */
    public function scheduleDays(): ?array
    {
        if (! is_array($this->schedule_days)) {
            return null;
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', $this->schedule_days),
            fn (int $day) => array_key_exists($day, self::WEEKDAYS)
        )));

        sort($days);

        return $days;
    }

    /**
     * Whether the standing arrangement puts this child here on a given date.
     *
     * Only the registered pattern is consulted — not the week's ticks, not the
     * closures. A week is the authority on itself; this answers what a week
     * should start from when there is nothing to copy forward.
     */
    public function attendsOn(string|CarbonInterface $date): bool
    {
        $days = $this->scheduleDays();

        if ($days === null) {
            return false;
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return in_array($date->dayOfWeekIso, $days, true);
    }

    /**
     * The pattern as a phrase — "Mon, Wed, Thu, Fri" — for the screens that
     * read a child's arrangement rather than set it.
     *
     * The full week gets its own name. "Mon, Tue, Wed, Thu, Fri" is five words
     * for the commonest arrangement there is, and down a column of sixty
     * children it is the one that should take the least reading.
     */
    public function scheduleDaysLabel(): ?string
    {
        $days = $this->scheduleDays();

        if ($days === null) {
            return null;
        }

        if ($days === []) {
            return 'No days';
        }

        if (count($days) === count(self::WEEKDAYS)) {
            return 'Every day';
        }

        return implode(', ', array_map(fn (int $day) => self::WEEKDAYS[$day], $days));
    }

    /**
     * The contracted day as one line — "7:00 AM – 5:30 PM" — or null until both
     * ends have been agreed. This is a different fact from the schedule boxes on
     * the attendance page: those say which days a child comes, these say the
     * hours of the day they are here.
     */
    public function scheduleLabel(): ?string
    {
        if (blank($this->drop_off_time) || blank($this->pick_up_time)) {
            return null;
        }

        return static::timeLabel($this->drop_off_time).' – '.static::timeLabel($this->pick_up_time);
    }

    /**
     * The contracted day as the paper register writes it: "730-430".
     *
     * No colon and no meridiem. A daycare register has no morning pick-ups and
     * no midnight drop-offs, so the meridiem says nothing a reader did not
     * already know, and the name column on the printed sheet is the tightest
     * thing on the page — two characters a row is a column's worth over a
     * roll of sixty.
     *
     * Null until both ends are agreed, like scheduleLabel(): half a range is
     * worse than none, because "730-" reads as a time that was cut off.
     */
    public function hoursCompact(): ?string
    {
        if (blank($this->drop_off_time) || blank($this->pick_up_time)) {
            return null;
        }

        $strip = fn (string $time) => Carbon::parse($time)->format('gi');

        return $strip($this->drop_off_time).'-'.$strip($this->pick_up_time);
    }

    /**
     * A clock time at sheet size: "7:10a", "12:28p".
     *
     * The sign-in grid is sixty rows by five columns and every filled cell
     * carries one of these, so the four characters "7:00 AM" costs over the
     * compact form are four characters times three hundred cells. The meridiem
     * is one lowercase letter because at this size a full "AM" reads as part of
     * the number rather than as a suffix to it.
     *
     * timeLabel() stays the form for everywhere a time is read in prose — a
     * child's record, a room's hours — where the width is free and "7:00 AM" is
     * simply how it is said.
     */
    public static function timeShort(\DateTimeInterface|string|null $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        $time = $time instanceof \DateTimeInterface ? Carbon::instance($time) : Carbon::parse($time);

        return $time->format('g:i').strtolower($time->format('A'))[0];
    }

    /**
     * One stored time as it is read aloud: "7:00 AM".
     *
     * MySQL hands these back as H:i:s and SQLite as whatever was written, so
     * they are parsed rather than printed straight out of the column.
     */
    public static function timeLabel(?string $time): ?string
    {
        return blank($time) ? null : Carbon::parse($time)->format('g:i A');
    }

    /** A stored time as <input type="time"> wants it, which is H:i and nothing else. */
    public static function timeInputValue(?string $time): ?string
    {
        return blank($time) ? null : Carbon::parse($time)->format('H:i');
    }

    /** The room the age bands put this child in, ignoring any override. */
    public function automaticClassroom(?Carbon $asOf = null): ?string
    {
        return ClassroomAssignment::automaticFor($this->birthDate(), $asOf);
    }

    /**
     * Whether the director's choice is in force on a date.
     *
     * A null start date means it always was — that is how rooms predating the
     * age rule were carried over.
     */
    public function hasClassroomOverrideOn(?Carbon $asOf = null): bool
    {
        if (blank($this->classroom_override)) {
            return false;
        }

        if (! $this->classroom_override_from) {
            return true;
        }

        return ($asOf ? $asOf->copy() : Carbon::today())->startOfDay()
            ->gte($this->classroom_override_from->copy()->startOfDay());
    }

    /**
     * The room this child is actually in on a date: the override if one is in
     * force, otherwise the age bands, otherwise nothing.
     *
     * Everything that counts heads or checks access goes through here, so an
     * overridden child is counted against the room they are really in — which is
     * the room whose ratio has to be staffed.
     */
    public function classroomOn(?Carbon $asOf = null): ?string
    {
        return $this->hasClassroomOverrideOn($asOf)
            ? $this->classroom_override
            : $this->automaticClassroom($asOf);
    }

    /**
     * An override that has stopped doing anything.
     *
     * Overrides exist to move a child up early, so they are ahead of the child's
     * age by design. Once the age catches up the override is holding the child
     * in a room they may have grown out of — it is not undone automatically,
     * because a decision the director made is not something to quietly reverse,
     * but the sheet flags it so it can be cleared.
     */
    public function classroomOverrideIsStale(?Carbon $asOf = null): bool
    {
        if (! $this->hasClassroomOverrideOn($asOf)) {
            return false;
        }

        $automatic = ClassroomAssignment::rankOf($this->automaticClassroom($asOf));
        $override = ClassroomAssignment::rankOf($this->classroom_override);

        return $automatic !== null && $override !== null && $automatic >= $override;
    }

    /**
     * Whether this child's age fits the room they are in: ok, mismatch (⚠,
     * set by hand or not) or unknown. The one check every age-against-room
     * mark reads; see ClassroomAssignment::fit.
     *
     * @return array{state: string, text: ?string, automatic: ?string, by_hand: bool}
     */
    public function roomFit(?Carbon $asOf = null): array
    {
        return ClassroomAssignment::fit($this, $asOf);
    }

    public function scheduleSlots()
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    /** The sessions this child's day splits into. */

    /**
     * The adults on this child's record. Not users — see Guardian.
     *
     * can_collect on the pivot is the one that matters at the door: being told
     * about a child and being allowed to take them home are different
     * permissions, and only the second gets past the kiosk.
     */
    public function guardians()
    {
        return $this->belongsToMany(Guardian::class)->withPivot('can_collect')->withTimestamps();
    }

    /**
     * Everybody on this child's record: parents, guardians, the adults who may
     * collect, the emergency numbers. One list, with the ticks saying which of
     * those each person is — see ChildPerson.
     *
     * The three cards on the child page are all drawn from here, which is what
     * stops them disagreeing with each other.
     */
    public function people()
    {
        return $this->belongsToMany(Person::class, 'child_people')
            ->using(ChildPerson::class)
            ->withPivot(['relationship', 'is_guardian', 'can_pickup', 'is_emergency', 'priority', 'restriction', 'source'])
            ->withTimestamps();
    }

    /** The links themselves, for the queries that are about the ticks. */
    public function personLinks()
    {
        // In the order they were added: without it the driver hands them
        // back in whatever order the index it picked keeps them.
        return $this->hasMany(ChildPerson::class)->orderBy('id');
    }

    /**
     * Who may take this child home.
     *
     * A restriction beats the tick — see ChildPerson::allowsPickup. Asked as a
     * query rather than kept as a list, so it is right the moment a tick moves.
     */
    public function pickupPeople()
    {
        return $this->people()
            ->wherePivot('can_pickup', true)
            ->wherePivotNull('restriction')
            ->orderBy('name');
    }

    /** Who to ring, in the order the centre rings them. */
    public function emergencyPeople()
    {
        return $this->people()
            ->wherePivot('is_emergency', true)
            ->orderByPivot('priority');
    }

    public function attendancePunches()
    {
        return $this->hasMany(ChildAttendancePunch::class);
    }

    public function sessions(): array
    {
        return in_array($this->classroom, self::SESSION_ROOMS, true) ? ['AM', 'PM'] : ['FULL'];
    }

    /**
     * Everybody the register should list for one week, leavers included.
     *
     * "Active" alone answers who is here now, which is the wrong question for
     * any week but this one: a child marked Inactive on Friday would disappear
     * from the whole of the year they attended.
     *
     * Three ways onto a week, and a child needs only one of them:
     *
     *  - they are Active, which is the roll as it stands;
     *  - they signed in that week, whatever the record says now;
     *  - they were withdrawn on or after the Monday and had started by the
     *    Friday, which is the span between their first day and their last.
     *
     * Whether each of their five boxes is offered is still isEnrolledOn's
     * business. This decides whose row is on the page; that decides which days
     * in the row are theirs.
     */
    /**
     * Everybody except the archived.
     *
     * The one filter every roster applies. Written once so that a screen
     * cannot forget it: the Children list, its counts and its export, the
     * attendance sheet, the teacher's student count and the kiosk's family
     * screen all read through this. A screen that wants archived children
     * asks for them by name (see ChildArchiveController and Settings ›
     * Children) rather than by leaving this off.
     */
    public function scopeNotArchived($query)
    {
        return $query->where('status', '!=', self::ARCHIVED);
    }

    /** Only the archived, newest archive first. */
    public function scopeArchived($query)
    {
        return $query->where('status', self::ARCHIVED)->orderByDesc('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->status === self::ARCHIVED;
    }

    /**
     * Take the child off every list, keeping what they were.
     *
     * The previous status is kept so Undo restores it exactly; archived_by
     * is whoever is signed in, and null from a seeder or the shell.
     */
    public function archive(?User $by = null): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill([
            'status_before_archive' => $this->status,
            'status' => self::ARCHIVED,
            'archived_at' => now(),
            'archived_by' => $by?->id ?? auth()->id(),
        ])->save();
    }

    /**
     * Put the child back on the Children page.
     *
     * Settings › Children restores them as Inactive — nobody should land back
     * in a room's head count because a record was tidied — while Undo on the
     * toast asks for the previous status, because Undo means "as it was".
     */
    public function unarchive(bool $restorePrevious = false): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $to = $restorePrevious ? ($this->status_before_archive ?: 'Inactive') : 'Inactive';

        $this->forceFill([
            'status' => $to,
            'archived_at' => null,
            'archived_by' => null,
            'status_before_archive' => null,
        ])->save();
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Rooms in the order the centre says them, youngest first, then by the
     * room's name for any room the bands do not know. See
     * ClassroomAssignment::inOrder for why alphabetical is the wrong order.
     */
    public function scopeOrderByRoom($query, string $direction = 'asc')
    {
        return $query
            ->orderByRaw(\App\Services\ClassroomAssignment::orderSql('classroom').' '.($direction === 'desc' ? 'desc' : 'asc'))
            ->orderBy('classroom', $direction);
    }

    /**
     * The children a status tab lists, by the one rule every count uses
     * (App\Services\RoomCounts::isEnrolled) — QA retest, 16 Nov 2026: Pharaoh
     * Lumpkins was Active with a last day of Nov 6, so the Children page
     * counted him (Active 118, PreK 14) and every other page did not (117).
     *
     *   Active    status Active, started (no first day, or on/before today)
     *             and not past the last day (none, or on/after today) — so the
     *             Active tab is exactly "enrolled today".
     *   Pending   status Pending, or Active with a first day still to come.
     *   Inactive  (Withdrawn) status Inactive, or Active past their last day
     *             — the nightly children:sync-enrollments moves them; until it
     *             has, they are listed with the leavers, flagged.
     *
     * Any other status (Archived) is the column as it stands.
     */
    public function scopeInStatusTab($query, string $status, ?string $today = null)
    {
        $today ??= now(config('app.timezone'))->toDateString();
        $started = fn ($q) => $q->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $today);
        $notLeft = fn ($q) => $q->whereNull('withdrawn_on')->orWhereDate('withdrawn_on', '>=', $today);

        return match ($status) {
            'Active' => $query->where('status', 'Active')->where($started)->where($notLeft),
            'Pending' => $query->where(fn ($q) => $q->where('status', 'Pending')
                ->orWhere(fn ($q) => $q->where('status', 'Active')->whereDate('enrolled_on', '>', $today)->where($notLeft))),
            'Inactive' => $query->where(fn ($q) => $q->where('status', 'Inactive')
                ->orWhere(fn ($q) => $q->where('status', 'Active')->whereDate('withdrawn_on', '<', $today))),
            default => $query->where('status', $status),
        };
    }

    public function scopeOnRollDuring($query, string $weekStart, string $weekEnd)
    {
        // An archived child is off the sheet too, whichever week is open: the
        // week they attended would otherwise keep listing them.
        //
        // Active is not enough on its own: the dates have to touch the week
        // too (QA retest, 16 Nov 2026 — an Active child whose last day was
        // Nov 6 was a row on the week of Nov 16, "Showing all 118" under
        // "117 enrolled"). Anybody who attended that week is still listed.
        return $query->notArchived()->where(function ($outer) use ($weekStart, $weekEnd) {
            $outer->where(fn ($active) => $active->where('status', 'Active')
                ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $weekEnd))
                ->where(fn ($q) => $q->whereNull('withdrawn_on')->orWhereDate('withdrawn_on', '>=', $weekStart)))
                ->orWhereHas('attendances', fn ($a) => $a->whereBetween('attendance_date', [$weekStart, $weekEnd]))
                ->orWhere(function ($left) use ($weekStart, $weekEnd) {
                    $left->whereNotNull('withdrawn_on')
                        ->whereDate('withdrawn_on', '>=', $weekStart)
                        ->where(fn ($started) => $started->whereNull('enrolled_on')->orWhereDate('enrolled_on', '<=', $weekEnd));
                });
        });
    }

    /**
     * Whether the child is on the roster on a given day. Null dates mean open-ended,
     * which is how every record behaved before enrolment dates existed.
     */
    public function isEnrolledOn(string $date): bool
    {
        if ($this->enrolled_on && $date < $this->enrolled_on->toDateString()) {
            return false;
        }

        // The time away between a last day and a re-enrollment (see Enrollment::reenroll).
        foreach ((array) ($this->enrollment_gaps ?? []) as $gap) {
            if (isset($gap['from'], $gap['to']) && $date >= $gap['from'] && $date <= $gap['to']) {
                return false;
            }
        }

        return ! ($this->withdrawn_on && $date > $this->withdrawn_on->toDateString());
    }

    /**
     * Children are addressed by their LAN in a URL, not by their row id.
     *
     * The id means nothing off this screen: it is on no paper the centre keeps,
     * and the same child has a different one in every copy of this database.
     * The LAN is what the cabinet, the parent letter and the staff all use, so
     * /children/10063 is a link that can be read out and checked against a file.
     *
     * The route constraint is whereNumber('child'), which is what keeps
     * /children/create from being swallowed by /children/{child}. A LAN is
     * digits, so that still holds.
     */
    public function getRouteKeyName(): string
    {
        return 'lan';
    }

    /** The first LAN the centre issues. Five digits, as the roll is kept. */
    public const LAN_STARTS_AT = 10001;

    /**
     * The next LAN in sequence: one past the highest numeric LAN on file.
     *
     * Floored at the start of the range rather than counting from whatever is
     * there, so a centre whose oldest records are four digits — or whose roll
     * is empty — still issues five. Non-numeric LANs from before the sequence
     * existed have no place in it and are ignored.
     */
    public static function nextLan(): string
    {
        $highest = static::query()
            ->pluck('lan')
            ->filter(fn ($lan) => ctype_digit((string) $lan))
            ->map(fn ($lan) => (int) $lan)
            ->max();

        return (string) max(self::LAN_STARTS_AT, ($highest ?? 0) + 1);
    }

    /**
     * When this child went on the roll, and every change since.
     *
     * Newest first, because the question this answers is almost always "are
     * they with us, and since when" rather than "what happened in 2023".
     */
    public function statusChanges()
    {
        return $this->hasMany(ChildStatusChange::class)->latest();
    }

    /** The day they became what they are now, or null for a record that predates the history. */
    public function statusSince(): ?\Illuminate\Support\Carbon
    {
        return $this->statusChanges()->where('event', 'status')->where('to_status', $this->status)->first()?->created_at;
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    /** Limit records to the classroom assigned to a teacher. */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isAdmin()) {
            return $query;
        }

        // Only a teacher reads children by room. Any other role — a stray
        // "parent" account, a typo — holds no room and sees nobody, rather
        // than whatever its classroom column happens to say.
        $classrooms = $user->role === 'teacher' ? $user->assignedClassrooms() : [];

        if (empty($classrooms)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('classroom', $classrooms);
    }
}
