<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
        'password',
        'role',
        'classroom',
        'classrooms',
        'employment',
        'title',
        'legal_name',
        'phone',
        'emergency_contact',
        'emergency_phone',
        'start_date',
        'dob',
        'transport',
        'aspire_id',
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
            'classrooms' => 'array',
            'start_date' => 'date:Y-m-d',
            'dob' => 'date:Y-m-d',
            'direct_deposit' => 'boolean',
            'pay_rate' => 'decimal:2',
            'evaluation_score' => 'float',
        ];
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
}
