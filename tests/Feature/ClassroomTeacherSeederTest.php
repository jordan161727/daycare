<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClassroomAssignment;
use Database\Seeders\ClassroomTeacherSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seeder that leaves one teacher a room, each with their own password.
 */
class ClassroomTeacherSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_teacher_a_room_each_with_their_own_password_and_nobody_else(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('password')]);
        // The base seed's Infant account, and a crowd of extras in two rooms.
        $infant = User::factory()->create(['role' => 'teacher', 'email' => 'infant.teacher@daycare.test', 'classroom' => 'Infant', 'password' => Hash::make('password')]);
        User::factory()->count(3)->create(['role' => 'teacher', 'classroom' => 'Infant']);
        User::factory()->count(2)->create(['role' => 'teacher', 'classroom' => 'UPK-4']);

        $this->seed(ClassroomTeacherSeeder::class);

        $rooms = ClassroomAssignment::rooms();
        $teachers = User::teachers()->get();

        // Exactly one teacher a room, named for it, in it and only it.
        $this->assertCount(count($rooms), $teachers);
        foreach ($rooms as $room) {
            $teacher = $teachers->firstWhere('email', ClassroomTeacherSeeder::email($room));
            $this->assertNotNull($teacher, "no account for $room");
            $this->assertSame($room, $teacher->classroom);
            $this->assertSame([$room], $teacher->assignedClassrooms());
            $this->assertTrue(Hash::check(ClassroomTeacherSeeder::PASSWORDS[$room], $teacher->password), "$room has the wrong password");
            $this->assertFalse($teacher->mustChangePassword());
        }

        // The passwords differ from each other, and from the director's.
        $this->assertCount(count($rooms), array_unique(ClassroomTeacherSeeder::PASSWORDS));
        $this->assertNotContains('password', ClassroomTeacherSeeder::PASSWORDS);

        // The Infant account was kept, not replaced; the director was left alone.
        $this->assertTrue($infant->fresh()->exists());
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_each_room_teacher_can_log_in_and_a_rerun_changes_nothing(): void
    {
        $this->seed(ClassroomTeacherSeeder::class);
        $ids = User::teachers()->pluck('id')->sort()->values()->all();

        $this->seed(ClassroomTeacherSeeder::class);
        $this->assertSame($ids, User::teachers()->pluck('id')->sort()->values()->all());

        foreach (ClassroomAssignment::rooms() as $room) {
            $this->post(route('login'), ['email' => ClassroomTeacherSeeder::email($room), 'password' => ClassroomTeacherSeeder::PASSWORDS[$room]])->assertRedirect();
            $this->assertAuthenticated();
            $this->assertSame($room, auth()->user()->classroom);
            auth()->logout();
        }
    }
}
