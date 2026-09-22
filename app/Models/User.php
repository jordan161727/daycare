<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'emergency_contact',
        'emergency_phone',
        'dob',
        'transport',
        'avatar_path',
        'password',
        'must_change_password',
        'password_changed_at',
        'role',
        'job_role',
        'classroom',
        'classrooms',
        'name_format',
        'employment',
        'title',
        'legal_name',
        'start_date',
        'aspire_id',
        // The teacher form validates and posts these, so they have to be
        // assignable or an admin's pay rate is dropped on the way in and
        // payroll then has no rate to work an estimated gross out of. Every
        // route that writes them is behind role:admin.
        'direct_deposit',
        'pay_rate',
        'evaluation_score',
        'staff_notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // The clock's own credentials, which no screen ever needs.
        'card_index', 'card_hash', 'kiosk_pin_index', 'kiosk_pin_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'classrooms' => 'array',
            'dob' => 'date:Y-m-d',
            'start_date' => 'date:Y-m-d',
            'direct_deposit' => 'boolean',
            // Decimal rather than float: MySQL hands a DECIMAL column back as
            // '15.50' and SQLite as 15.5, and a pay rate that loses its second
            // place on one driver and keeps it on the other is a payslip that
            // reads differently depending on where it was printed. Readers that
            // do arithmetic on it already cast at the point they use it.
            'pay_rate' => 'decimal:2',
            'evaluation_score' => 'decimal:1',
            'card_issued_at' => 'datetime',
            'kiosk_locked_until' => 'datetime',
            'kiosk_failed_attempts' => 'integer',
        ];
    }

    /**
     * Still signing in with a password somebody else chose for them.
     *
     * Read by the middleware that pins such an account to the change form: the
     * login works, but nothing else does until they have picked their own.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    /** Every scheduling constraint on this person. */
    public function staffRules()
    {
        return $this->hasMany(StaffRule::class);
    }

    public function shifts()
    {
        return $this->hasMany(StaffShift::class);
    }

    /** Every time-off request they have made, decided or not. */
    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** Every movement on their leave balances. The balance is the sum. */
    public function leaveLedgerEntries()
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** Teachers, in the order the roster shows them. */
    public function scopeTeachers($query)
    {
        return $query->where('role', 'teacher')->orderBy('name');
    }

    /**
     * The name payroll prints, which is not always the name they go by.
     *
     * Falls back to the display name so a staff member with no legal name on
     * file can still be matched — imperfectly, but better than not at all.
     */
    public function payrollName(): string
    {
        return filled($this->legal_name) ? $this->legal_name : $this->name;
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }

    /**
     * Weekly hours this person is owed.
     *
     * A REQUIRED_HOURS rule wins; a biweekly one is halved so the generator
     * only ever reasons about a single week. Without a rule, the employment
     * type's default from config applies.
     */
    public function weeklyHours(): float
    {
        $rule = $this->staffRules->firstWhere('rule_type', 'REQUIRED_HOURS');

        if ($rule && $rule->number) {
            return $rule->value_text === 'BIWEEKLY'
                ? (float) $rule->number / 2
                : (float) $rule->number;
        }

        return (float) (config('daycare.weekly_hours')[$this->employment] ?? 0);
    }

    public function isSubstitute(): bool
    {
        return $this->employment === 'SUB';
    }

    public function assignedClassrooms(): array
    {
        $classrooms = array_filter(array_merge(
            $this->classrooms ?? [],
            [$this->classroom ?? null]
        ));

        return array_values(array_unique($classrooms));
    }

    public function canAccessClassroom(?string $classroom): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($classroom, $this->assignedClassrooms(), true);
    }

    /**
     * The two ways a child's name is written, and what each is called on the
     * screen that offers the choice.
     *
     * Keyed by what goes in the column, so a stored value that is no longer
     * offered — a third format tried and dropped — falls back rather than
     * printing a name in a shape nothing here defines.
     */
    public const NAME_FORMATS = [
        'first_last' => 'Ada Lovelace',
        'last_first' => 'Lovelace, Ada',
    ];

    public const NAME_FORMAT_DEFAULT = 'first_last';

    /** How this person reads a child's name, always one of NAME_FORMATS. */
    public function nameFormat(): string
    {
        return array_key_exists($this->name_format, self::NAME_FORMATS)
            ? $this->name_format
            : self::NAME_FORMAT_DEFAULT;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function students()
    {
        return $this->hasMany(Child::class, 'classroom', 'classroom');
    }

    /**
     * Where the browser can fetch this user's photo, or null when they have
     * none and the initials badge should stand in for it.
     *
     * Built with asset() rather than the disk's own url(), which is pinned to
     * APP_URL: that hands a local browser the live site's address and the photo
     * comes back broken. asset() follows the host the page was served from.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (blank($this->avatar_path) || ! Storage::disk('public')->exists($this->avatar_path)) {
            return null;
        }

        return asset('storage/'.$this->avatar_path);
    }

    /** First letters of the first and last name, for the fallback badge. */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '?';
        }

        $initials = Str::substr($words[0], 0, 1).(count($words) > 1 ? Str::substr(end($words), 0, 1) : '');

        return Str::upper($initials);
    }

    /* ---- the time clock at the door ----

       Credentials that open the clock and nothing else. Not the password: the
       screen is in a lobby with a queue behind it, and a password typed there
       stops being one. Same shape as the guardians' door PINs — an HMAC index
       to find the row by, a hash to prove it with — so the table on its own is
       neither a list of cards nor a list of PINs. */

    /**
     * What somebody does, as distinct from what they may open.
     *
     * `role` is the account — admin or teacher — and it decides which screens
     * they see. This is the job, and it decides nothing: it is what a rota, a
     * timesheet and a staff list read to tell one teacher from another.
     *
     * Validated against this list so the filters on the staff screens have
     * something finite to offer, rather than a column of one-off spellings.
     */
    public const JOB_ROLES = [
        'Director',
        'Lead Teacher',
        'Assistant',
        'Floater',
        'Substitute',
        'Cook',
        'Administrator',
    ];

    /**
     * The job, falling back to the account role for anybody without one.
     *
     * Every existing staff member is in that position, so the fallback is the
     * difference between a staff list that reads sensibly the moment this
     * ships and one that is a column of dashes until somebody edits sixteen
     * records.
     */
    public function jobRole(): string
    {
        return filled($this->job_role) ? $this->job_role : ucfirst((string) $this->role);
    }

    /**
     * Narrow to people whose job reads as this.
     *
     * Has to match jobRole() rather than the column alone. Everybody hired
     * before the field existed has it empty and reads as their account role,
     * so a filter that only looked at the column would offer "Teacher" and
     * then return nobody at all — which is exactly what it did.
     */
    public function scopeJobRoleIs($query, string $role)
    {
        return $query->where(fn ($outer) => $outer
            ->where('job_role', $role)
            ->orWhere(fn ($fallback) => $fallback
                ->where(fn ($unset) => $unset->whereNull('job_role')->orWhere('job_role', ''))
                ->where('role', strtolower($role))));
    }

    /**
     * The jobs a given set of people actually read as, for a filter to offer.
     *
     * Built from the people rather than from JOB_ROLES, so the list never
     * offers a job nobody holds and filters to an empty page.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $people
     */
    public static function jobRolesAmong($people)
    {
        return $people->map(fn (self $person) => $person->jobRole())->unique()->sort()->values();
    }
    /** How many wrong PINs before the clock stops answering to that number. */
    public const KIOSK_MAX_ATTEMPTS = 5;

    public const KIOSK_LOCKOUT_MINUTES = 5;

    /**
     * The staff number as it is printed and read aloud — S-1001.
     *
     * Derived from the row rather than stored, so it cannot drift from it and
     * there is no second thing to allocate when somebody is hired.
     */
    public function staffId(): string
    {
        return 'S-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * What a secret is looked up by.
     *
     * Keyed on the app key, so a stolen table is not a list of four-digit
     * numbers to try offline. Deliberately not unique for PINs: two people
     * choosing 1234 is a collision to resolve at the screen, not an error to
     * refuse at the form.
     */
    public static function kioskIndexFor(string $secret): string
    {
        return hash_hmac('sha256', $secret, config('app.key'));
    }

    /**
     * Whether somebody else already answers to these four digits.
     *
     * A single indexed lookup, which is the other thing the HMAC index buys:
     * the hash cannot be searched, but the index can, so asking "is this PIN
     * taken" costs one row rather than a bcrypt check against every member of
     * staff.
     *
     * It does tell whoever is setting it that *somebody* holds that number.
     * With four digits and a handful of staff that is a small thing to give
     * away, and much smaller than the alternative: two people on one PIN means
     * the clock cannot tell them apart, so it has to stop and ask — every
     * morning, to both of them, for as long as the collision stands.
     */
    public static function kioskPinTaken(string $pin, ?self $except = null): bool
    {
        return static::where('kiosk_pin_index', static::kioskIndexFor($pin))
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }
    /**
     * Issue a card, and hand its code back once.
     *
     * The code is what the QR encodes. It is returned rather than stored,
     * because a card whose number can be read back out of the database is one
     * anybody with database access can clone. Reprinting means regenerating,
     * which also — correctly — retires the old card.
     */
    public function issueCard(): string
    {
        $code = Str::random(40);

        $this->forceFill([
            'card_index' => static::kioskIndexFor($code),
            'card_hash' => Hash::make($code),
            'card_issued_at' => now(),
        ])->save();

        return $code;
    }

    public function hasCard(): bool
    {
        return filled($this->card_hash);
    }

    /** Set the four digits somebody keys in when the card is in a coat pocket. */
    public function setKioskPin(string $pin): void
    {
        $this->forceFill([
            'kiosk_pin_index' => static::kioskIndexFor($pin),
            'kiosk_pin_hash' => Hash::make($pin),
            'kiosk_failed_attempts' => 0,
            'kiosk_locked_until' => null,
        ])->save();
    }

    public function hasKioskPin(): bool
    {
        return filled($this->kiosk_pin_hash);
    }

    public function kioskIsLocked(): bool
    {
        return $this->kiosk_locked_until !== null && $this->kiosk_locked_until->isFuture();
    }

    /** Count a wrong try, and stop answering after too many. */
    public function noteKioskFailure(): void
    {
        $attempts = (int) $this->kiosk_failed_attempts + 1;

        $this->forceFill([
            'kiosk_failed_attempts' => $attempts,
            'kiosk_locked_until' => $attempts >= self::KIOSK_MAX_ATTEMPTS
                ? now()->addMinutes(self::KIOSK_LOCKOUT_MINUTES)
                : $this->kiosk_locked_until,
        ])->save();
    }

    public function clearKioskFailures(): void
    {
        if ($this->kiosk_failed_attempts === 0 && $this->kiosk_locked_until === null) {
            return;
        }

        $this->forceFill(['kiosk_failed_attempts' => 0, 'kiosk_locked_until' => null])->save();
    }
}
