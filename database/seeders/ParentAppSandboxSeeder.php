<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\ChildPerson;
use App\Models\ClosureDay;
use App\Models\MessageThread;
use App\Models\Person;
use App\Models\User;
use App\Services\Messaging;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The parent-app sandbox (scripts/parent-app-sandbox.ps1): invented families
 * and staff on a throwaway SQLite file, for trying Messages from both ends —
 * the staff page in one window, the parent tester in another.
 *
 * Refuses to run anywhere but the sandbox's own file, because migrate:fresh
 * runs first and this centre's real database must never be the one emptied.
 */
class ParentAppSandboxSeeder extends Seeder
{
    public function run(Messaging $messaging): void
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'sqlite' || ! str_ends_with(str_replace('\\', '/', $db), 'parent-sandbox.sqlite')) {
            throw new RuntimeException('ParentAppSandboxSeeder only runs on database/parent-sandbox.sqlite. Use scripts/parent-app-sandbox.ps1.');
        }

        $director = User::create(['name' => 'Ray Kader', 'email' => 'director@sandbox.test', 'password' => 'sandbox-pass', 'role' => 'admin', 'job_role' => 'Director']);
        User::create(['name' => 'Tina Teacher', 'email' => 'teacher@sandbox.test', 'password' => 'sandbox-pass', 'role' => 'teacher', 'classroom' => 'Toddler', 'classrooms' => ['Toddler']]);

        // Two parents on one child, so both see the same conversation.
        $mia = Child::create(['lan' => '90001', 'status' => 'Active', 'first_name' => 'Mia', 'last_name' => 'Testwell', 'classroom' => 'Toddler', 'dob' => now()->subMonths(28)->toDateString()]);
        $kate = $this->parent($mia, 'Kate Testwell', '585-555-0134', 'Mother');
        $this->parent($mia, 'Dan Testwell', '585-555-0135', 'Father');

        // Kate's second child, in another room.
        $leo = Child::create(['lan' => '90002', 'status' => 'Active', 'first_name' => 'Leo', 'last_name' => 'Testwell', 'classroom' => 'PreK', 'dob' => now()->subMonths(40)->toDateString()]);
        ChildPerson::create(['child_id' => $leo->id, 'person_id' => $kate->id, 'relationship' => 'Mother', 'gets_texts' => true, 'is_guardian' => true]);

        // Another family, to prove Kate never sees them.
        $ava = Child::create(['lan' => '90003', 'status' => 'Active', 'first_name' => 'Ava', 'last_name' => 'Othersen', 'classroom' => 'Toddler', 'dob' => now()->subMonths(26)->toDateString()]);
        $this->parent($ava, 'Olivia Othersen', '585-555-0177', 'Mother');

        // People the app must turn away.
        $this->parent($ava, 'Nora NoTexts', '585-555-0188', 'Aunt/Uncle', ['gets_texts' => false]);
        $this->parent($ava, 'Rex Restricted', '585-555-0199', 'Father', ['restriction' => 'Court order — no contact']);

        // Something in every kind of conversation.
        $messaging->sendToFamily($mia, MessageThread::ROOM, 'Picnic Friday, please bring sunscreen.', [], $director);
        $messaging->sendToFamily($mia, MessageThread::OFFICE, 'Tuition reminder for November.', [], $director);
        $messaging->sendToFamily($ava, MessageThread::ROOM, 'Ava had a great nap today.', [], $director);
        $messaging->announce('We are closed Monday for staff training.', [], $director);

        $this->version2($mia, $kate, $messaging, $director);
    }

    /**
     * Version 2 of the app (9 Nov 2026) reads the register, the photos staff
     * send and the closure days, so the sandbox has a little of each: Mia was
     * dropped off this morning, her room sent two photos, and the centre is
     * closed next Monday and on the 26th.
     */
    private function version2(Child $mia, Person $kate, Messaging $messaging, User $director): void
    {
        $today = now()->toDateString();
        $in = now()->setTime(8, 5);
        Attendance::create(['child_id' => $mia->id, 'attendance_date' => $today, 'signed_in_at' => $in, 'session' => 'am']);
        ChildAttendancePunch::create(['child_id' => $mia->id, 'person_id' => $kate->id, 'direction' => 'in', 'occurred_at' => $in, 'service_date' => $today, 'session' => 'am', 'method' => 'pin', 'device' => 'sandbox']);

        // Drawn here, not copied from anywhere, so no real child or form ever ends up in the sandbox.
        $photos = [];
        foreach ([[255, 183, 77], [129, 199, 132]] as $i => $rgb) {
            $png = $this->samplePhoto($rgb, $i);
            if ($png !== null) {
                $path = 'messages/media/sandbox/painting-'.($i + 1).'.png';
                Storage::disk('local')->put($path, $png);
                $photos[] = ['kind' => 'photo', 'path' => $path];
            }
        }
        if ($photos !== []) {
            $messaging->sendToFamily($mia, MessageThread::ROOM, 'Painting with the Toddler room this morning!', $photos, $director);
        }

        ClosureDay::create(['closed_on' => now()->next('Monday')->toDateString(), 'reason' => 'Staff training day']);
        ClosureDay::firstOrCreate(['closed_on' => now()->startOfMonth()->addDays(25)->toDateString()], ['reason' => 'Centre closed']);
    }

    /** A 600×600 "painting": a coloured ground with paint dabs. Null without GD. */
    private function samplePhoto(array $rgb, int $seed): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        mt_srand(42 + $seed);
        $img = imagecreatetruecolor(600, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        $dabs = [[66, 135, 245], [229, 57, 53], [255, 255, 255], [123, 31, 162], [255, 235, 59]];
        for ($n = 0; $n < 14; $n++) {
            [$r, $g, $b] = $dabs[$n % count($dabs)];
            $size = mt_rand(60, 170);
            imagefilledellipse($img, mt_rand(40, 560), mt_rand(40, 560), $size, $size, imagecolorallocate($img, $r, $g, $b));
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function parent(Child $child, string $name, string $cell, string $relationship, array $link = []): Person
    {
        $person = Person::create(['name' => $name, 'cell' => $cell]);
        ChildPerson::create(array_merge([
            'child_id' => $child->id, 'person_id' => $person->id, 'relationship' => $relationship,
            'gets_texts' => true, 'is_guardian' => true, 'can_pickup' => true,
        ], $link));

        return $person;
    }
}
