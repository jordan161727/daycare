<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Child extends Model
{
    protected $casts = [
        'dob' => 'date',
        'birth_date' => 'date',
        'mother_ssn' => 'encrypted',
        'father_ssn' => 'encrypted',
    ];

     protected $fillable = [
        'lan', 'child_name', 'nickname', 'address', 'city', 'zip', 'telephone', 'birth_date',
        'status',
        'first_name',
        'last_name',
        'dob',
        'age',
        'classroom',
        'mother_name', 'mother_address', 'mother_home_phone', 'mother_employer', 'mother_work_phone', 'mother_fax', 'mother_cell', 'mother_email', 'mother_title', 'mother_ssn',
        'father_name', 'father_address', 'father_home_phone', 'father_employer', 'father_work_phone', 'father_fax', 'father_cell', 'father_email', 'father_title', 'father_ssn',
        'email_address', 'parents_status', 'responsible_for_payment', 'emergency_contact', 'secondary_emergency_contact', 'emergency_telephone', 'emergency_relationship', 'emergency_license_number',
        'pickup_1_name', 'pickup_1_address', 'pickup_1_telephone', 'pickup_1_alternate', 'pickup_1_relationship', 'pickup_1_license_number',
        'pickup_2_name', 'pickup_2_address', 'pickup_2_telephone', 'pickup_2_alternate', 'pickup_2_relationship', 'pickup_2_license_number',
        'pickup_3_name', 'pickup_3_address', 'pickup_3_telephone', 'pickup_3_alternate', 'pickup_3_relationship', 'pickup_3_license_number', 'other_notes', 'important_notes',
    ];

    public function getFullNameAttribute()
    {
        return "{$this->last_name}, {$this->first_name}";
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
