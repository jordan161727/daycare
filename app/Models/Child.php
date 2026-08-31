<?php

namespace App\Models;

use App\Services\ClassroomAssignment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'drop_off_time',
        'pick_up_time',
        'first_name',
        'last_name',
        'photo_path',
        'gender',
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
     * The date of birth written the way the roster reads it: 2022/12/15.
     *
     * The column is headed "Age", but what the office reads off it is the date
     * itself. Year first, so a column of them sorts by eye the way it sorts by
     * click, and so 3/4 is never one date to one reader and another to the next.
     * Formatted on every read rather than stored, so it cannot drift out of
     * step with the date it comes from.
     */
    public static function ageLabelFor(?CarbonInterface $birthDate): ?string
    {
        return $birthDate?->format('Y/n/j');
    }

    /** This child's date of birth as the roster shows it, or null when none is on file. */
    public function ageLabel(): ?string
    {
        return static::ageLabelFor($this->birthDate());
    }

    /**
     * How old the child is, said the way the room says it: "3 years 2 months".
     *
     * Worked out on every read and never stored, because it is different next
     * month. The unit that matters follows the age: years and months for a
     * child old enough to have both, months alone for a baby, days for one who
     * is only days old — nobody describes a fortnight-old as nought years.
     *
     * Days are dropped once there are months to report. They matter to a parent
     * and not to a roster, and "3 years 2 months 14 days" in a column is three
     * facts where one was wanted.
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

        $said = fn (int $count, string $unit) => $count.' '.Str::plural($unit, $count);

        if ($age->y > 0) {
            return $age->m > 0
                ? $said($age->y, 'year').' '.$said($age->m, 'month')
                : $said($age->y, 'year');
        }

        return $age->m > 0 ? $said($age->m, 'month') : $said($age->d, 'day');
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

        return route('children.photo', $this);
    }

    /** The initial the avatar falls back to while there is no photograph. */
    public function initial(): string
    {
        return strtoupper(substr($this->first_name ?? '?', 0, 1));
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
