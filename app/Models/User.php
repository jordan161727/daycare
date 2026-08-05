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
        ];
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
