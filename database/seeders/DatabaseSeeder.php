<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@daycare.test'], [
            'name' => 'Administrator',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $teachers = [
            ['name' => 'Infant Teacher', 'email' => 'infant.teacher@daycare.test', 'classroom' => 'Infant'],
            ['name' => 'PreK Teacher', 'email' => 'prek.teacher@daycare.test', 'classroom' => 'PreK'],
            ['name' => 'School Age Teacher', 'email' => 'schoolage.teacher@daycare.test', 'classroom' => 'School Age'],
            ['name' => 'Toddler Teacher', 'email' => 'toddler.teacher@daycare.test', 'classroom' => 'Toddler'],
            ['name' => 'Transition Teacher', 'email' => 'transition.teacher@daycare.test', 'classroom' => 'Transition'],
            ['name' => 'UPK-4 Teacher', 'email' => 'upk4.teacher@daycare.test', 'classroom' => 'UPK-4'],
        ];

        foreach ($teachers as $teacher) {
            Classroom::updateOrCreate(
                ['name' => $teacher['classroom']],
                ['description' => $teacher['classroom'].' classroom']
            );

            User::updateOrCreate(['email' => $teacher['email']], [
                'name' => $teacher['name'],
                'password' => Hash::make('password'),
                'role' => 'teacher',
                'classroom' => $teacher['classroom'],
            ]);
        }
    }
}
