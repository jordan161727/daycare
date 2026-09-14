<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
}
