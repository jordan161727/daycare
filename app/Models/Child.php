<?php

namespace App\Models;

use App\Services\ClassroomAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use App\Models\User;

class Child extends Model
{
    /** Rooms signed in by half day rather than one full-day stamp. */
    public const SESSION_ROOMS = ['School Age'];

    protected $casts = [
        'dob' => 'date',
        'birth_date' => 'date',
        'enrolled_on' => 'date:Y-m-d',
        'withdrawn_on' => 'date:Y-m-d',
        'classroom_override_from' => 'date:Y-m-d',
        // Float rather than decimal: the projection does arithmetic with this on
        // every read, and a decimal cast hands back a string.
        'expected_hours_per_week' => 'float',
        'mother_ssn' => 'encrypted',
        'father_ssn' => 'encrypted',
    ];

     protected $fillable = [
        'lan', 'child_name', 'nickname', 'address', 'city', 'zip', 'telephone', 'birth_date',
        'status',
        'enrolled_on',
        'withdrawn_on',
        'expected_hours_per_week',
        'first_name',
        'last_name',
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
        'pickup_3_name', 'pickup_3_address', 'pickup_3_telephone', 'pickup_3_alternate', 'pickup_3_relationship', 'pickup_3_license_number', 'other_notes', 'important_notes',
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

            $child->classroom = $child->classroomOn();
        });
    }

    public function getFullNameAttribute()
    {
        return "{$this->last_name}, {$this->first_name}";
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
     * How old a child born on a date is, worded the way the roster reads it:
     * "1 year and 3 months". Worked out on every read rather than stored, so it
     * cannot drift out of step with the date it comes from.
     */
    public static function ageLabelFor(?CarbonInterface $birthDate, ?CarbonInterface $asOf = null): ?string
    {
        if (! $birthDate) {
            return null;
        }

        $birthDate = $birthDate->copy()->startOfDay();
        $asOf = ($asOf ? $asOf->copy() : Carbon::today())->startOfDay();

        // A date of birth in the future is a typo, not an age. diff() reports
        // the gap whichever way round it is, so it has to be ruled out here
        // instead of coming back as a plausible-looking number of months.
        if ($birthDate->gt($asOf)) {
            return null;
        }

        $difference = $birthDate->diff($asOf);
        $plural = fn (int $count, string $word) => $count.' '.($count === 1 ? $word : $word.'s');

        if ($difference->y > 0) {
            return $difference->m > 0
                ? $plural($difference->y, 'year').' and '.$plural($difference->m, 'month')
                : $plural($difference->y, 'year');
        }

        if ($difference->m > 0) {
            return $plural($difference->m, 'month');
        }

        // Infants arrive at six weeks, so their first months are read in weeks.
        return $difference->days >= 7
            ? $plural(intdiv($difference->days, 7), 'week')
            : $plural($difference->days, 'day');
    }

    /** This child's age today, or null when no date of birth is on file. */
    public function ageLabel(?Carbon $asOf = null): ?string
    {
        return static::ageLabelFor($this->birthDate(), $asOf);
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

    public function scheduleSlots()
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    /** The sessions this child's day splits into. */
    public function sessions(): array
    {
        return in_array($this->classroom, self::SESSION_ROOMS, true) ? ['AM', 'PM'] : ['FULL'];
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

        return ! ($this->withdrawn_on && $date > $this->withdrawn_on->toDateString());
    }

    /**
     * The next LAN in sequence: one past the highest numeric LAN on file.
     * Non-numeric LANs from older records are ignored.
     */
    public static function nextLan(): string
    {
        $highest = static::query()
            ->pluck('lan')
            ->filter(fn ($lan) => ctype_digit((string) $lan))
            ->map(fn ($lan) => (int) $lan)
            ->max();

        return (string) (($highest ?? 1000) + 1);
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

        $classrooms = $user->assignedClassrooms();

        if (empty($classrooms)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('classroom', $classrooms);
    }
}
