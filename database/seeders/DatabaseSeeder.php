<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\StaffRule;
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

        // One full-time teacher per room, with the details the week schedule
        // is built from. A record with no employment type is an account, not a
        // shift: the generator leaves it off the roster and says so, which is
        // what happened the first time a week was generated from this seed.
        // Two of them hold keys, so a Monday has somebody to open the door.
        $teachers = [
            ['name' => 'Infant Teacher', 'email' => 'infant.teacher@daycare.test', 'classroom' => 'Infant', 'opens' => true],
            ['name' => 'PreK Teacher', 'email' => 'prek.teacher@daycare.test', 'classroom' => 'PreK'],
            ['name' => 'School Age Teacher', 'email' => 'schoolage.teacher@daycare.test', 'classroom' => 'School Age'],
            ['name' => 'Toddler Teacher', 'email' => 'toddler.teacher@daycare.test', 'classroom' => 'Toddler', 'opens' => true],
            ['name' => 'Transition Teacher', 'email' => 'transition.teacher@daycare.test', 'classroom' => 'Transition'],
            ['name' => 'UPK-4 Teacher', 'email' => 'upk4.teacher@daycare.test', 'classroom' => 'UPK-4'],
        ];

        foreach ($teachers as $teacher) {
            Classroom::updateOrCreate(
                ['name' => $teacher['classroom']],
                ['description' => $teacher['classroom'].' classroom']
            );

            $user = User::updateOrCreate(['email' => $teacher['email']], [
                'name' => $teacher['name'],
                'password' => Hash::make('password'),
                'role' => 'teacher',
                'classroom' => $teacher['classroom'],
                'employment' => 'FT',
                'title' => $teacher['classroom'],
            ]);

            // Owed a full week, in their own room. firstOrCreate so a re-seed
            // tops up a record the director has since added rules to rather
            // than doubling them.
            StaffRule::firstOrCreate(
                ['user_id' => $user->id, 'rule_type' => 'REQUIRED_HOURS'],
                ['priority' => 'HARD', 'number' => 40, 'value_text' => 'WEEKLY']
            );
            StaffRule::firstOrCreate(
                ['user_id' => $user->id, 'rule_type' => 'ROOM_PREFERENCE'],
                ['priority' => 'SOFT', 'value_text' => $teacher['classroom']]
            );
            if ($teacher['opens'] ?? false) {
                StaffRule::firstOrCreate(
                    ['user_id' => $user->id, 'rule_type' => 'CAN_OPEN'],
                    ['priority' => 'HARD', 'source_note' => 'Keyholder.']
                );
            }
        }

        // Every room opens with the centre until somebody says otherwise, so
        // the children's rows read a class time from the first run.
        $this->call(RoomScheduleSeeder::class);

        // Enough staff, with set shifts, for the week to generate without a
        // room going short. Reads the roll, so on an empty database it adds
        // nobody; re-run it after importing children.
        $this->call(RoomCoverSeeder::class);
    }
}
