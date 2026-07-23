<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Child extends Model
{
    protected $casts = [
        'dob' => 'date',
    ];

     protected $fillable = [
        'lan',
        'status',
        'first_name',
        'last_name',
        'dob',
        'age',
        'classroom',
    ];

    public function getFullNameAttribute()
    {
        return "{$this->last_name}, {$this->first_name}";
    }

        public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}
