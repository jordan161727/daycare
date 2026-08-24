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
        'employment',
        'title',
        'legal_name',
        'start_date',
        'aspire_id',
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
