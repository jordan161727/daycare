<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'department_id',
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

    /** The director's one-off changes to their days (Phase 5 Staff → Time off). */
    public function timeOff()
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    /** "FT", "PT flex", "Substitute" — the employment type as the Staff page says it. */
    public function employmentLabel(): string
    {
        return StaffRule::employmentLabel($this->employment);
    }

    /** On the books but not on the schedule: inactive, or not a teaching job. */
    public function isOffSchedule(): bool
    {
        return $this->employment === 'INACTIVE'
            || $this->role !== 'teacher'
            || in_array($this->jobRole(), ['Director', 'Cook', 'Administrator'], true);
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
        'first_last' => 'John Smith',
        'last_first' => 'Smith, John',
    ];

    public const NAME_FORMAT_DEFAULT = 'first_last';

    /** The centre-wide setting's key (Settings → Children → Names, 27 Oct 2026). */
    public const NAME_FORMAT_SETTING = 'names.format';

    /**
     * How a child's name reads, always one of NAME_FORMATS.
     *
     * The centre's setting (Settings → Children) decides for everyone since
     * 27 Oct 2026 (Jordan: "move that into setting"); before that each
     * reader chose from the register's ⋯ menu, and that per-person column is
     * still honoured where the centre has not chosen.
     */
    public function nameFormat(): string
    {
        $centre = rescue(fn () => Setting::get(self::NAME_FORMAT_SETTING), null, false);

        if (is_string($centre) && array_key_exists($centre, self::NAME_FORMATS)) {
            return $centre;
        }

        return array_key_exists($this->name_format, self::NAME_FORMATS)
            ? $this->name_format
            : self::NAME_FORMAT_DEFAULT;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * The director: an admin account whose job is Director.
     *
     * There is no separate role for it — directors and office admins share
     * "admin" — so the job title is what tells them apart.
     */
    public function isDirector(): bool
    {
        return $this->isAdmin() && $this->job_role === 'Director';
    }

    /** Pay rates and direct deposit are the director's alone. */
    public function canSeePay(): bool
    {
        return $this->isDirector();
    }

    /** Who can see pay, by name, for the line that says so to everyone else. */
    public static function payViewerName(): ?string
    {
        return static::where('role', 'admin')->where('job_role', 'Director')->orderBy('id')->value('name');
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

        // A route rather than a path under public/storage: that is a symlink
        // the host has to allow, and on the staging server it did not — every
        // face answered 403. The route streams the file through PHP instead
        // (see AvatarController), and the stamp changes with the file so a
        // new upload is never shown from the browser's cache of the old one.
        return route('avatars.show', ['user' => $this, 'v' => substr(md5($this->avatar_path), 0, 8)]);
    }

    /**
     * The badge letters: two of them, and never the same as a colleague's.
     *
     * Lana is LA and LaQuora is LQ, not L and L. Worked out across the whole
     * team at once (see staffInitials()), so every screen that prints
     * $user->initials agrees on who is who.
     */
    public function getInitialsAttribute(): string
    {
        if ($this->id !== null && in_array($this->role, ['admin', 'teacher'], true)) {
            $map = self::staffInitials();

            if (isset($map[$this->id]) && (self::$initialsNames[$this->id] ?? null) === $this->name) {
                return $map[$this->id];
            }

            $map = self::staffInitials(fresh: true);

            if (isset($map[$this->id])) {
                return $map[$this->id];
            }
        }

        return self::initialsOptions((string) $this->name)[0];
    }

    /** A new face, a new name or a departure can change somebody else's letters. */
    protected static function booted(): void
    {
        $forget = fn () => self::$initialsMap = null;

        static::saved($forget);
        static::deleted($forget);
    }

    /** @var array<int, string>|null */
    private static ?array $initialsMap = null;

    /** @var array<int, string> */
    private static array $initialsNames = [];

    /**
     * Everyone on the team's initials, no two alike.
     *
     * Earliest account first keeps its first choice; a later one with the
     * same letters takes its next option. Cached for the request, and redone
     * whenever somebody's name has changed since.
     *
     * @return array<int, string>
     */
    public static function staffInitials(bool $fresh = false): array
    {
        if (! $fresh && self::$initialsMap !== null) {
            return self::$initialsMap;
        }

        $taken = [];
        $map = [];
        $names = static::whereIn('role', ['admin', 'teacher'])->orderBy('id')->pluck('name', 'id');

        foreach ($names as $id => $name) {
            $options = self::initialsOptions((string) $name);
            $pick = collect($options)->first(fn (string $option) => ! isset($taken[$option])) ?? $options[0];
            $taken[$pick] = true;
            $map[$id] = $pick;
        }

        self::$initialsNames = $names->all();

        return self::$initialsMap = $map;
    }

    /**
     * The letters a name could go by, best first.
     *
     * Two words: first and last initial, then first initial with each later
     * letter of the last name. One word: the first letter with the next
     * capital ("LaQuora" → LQ) or else the next letter ("Lana" → LA), then
     * each later letter in turn.
     *
     * @return list<string>
     */
    public static function initialsOptions(string $name): array
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^\pL\s]/u', '', $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return ['?'];
        }

        $first = Str::upper(Str::substr($words[0], 0, 1));
        $rest = count($words) > 1 ? end($words) : Str::substr($words[0], 1);
        $options = [];

        if (count($words) > 1) {
            $options[] = $first.Str::upper(Str::substr($rest, 0, 1));
        } elseif (preg_match('/\p{Lu}/u', $rest, $capital)) {
            $options[] = $first.$capital[0];
        }

        foreach (mb_str_split($rest) as $letter) {
            $options[] = $first.Str::upper($letter);
        }

        $options = array_values(array_unique($options));

        return $options === [] ? [$first] : $options;
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
    /**
     * The part of the centre they belong to, where the centre runs departments.
     *
     * Nullable and staying that way: a centre with no departments should not be
     * made to invent one to hire somebody, and every report that groups by
     * department prints the unassigned as exactly that.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

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
