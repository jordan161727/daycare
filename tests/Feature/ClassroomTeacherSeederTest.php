<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Database\Seeders\ClassroomTeacherSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seeder that leaves one teacher a room, each with their own password.
 */
class ClassroomTeacherSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_teacher_a_room_each_with_their_own_password_and_nothing_else_touched(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('password')]);
        // The base seed's Infant account, and a crowd of extras in two rooms.
        $infant = User::factory()->create(['role' => 'teacher', 'email' => 'infant.teacher@daycare.test', 'classroom' => 'Infant', 'password' => Hash::make('password')]);
        $extras = User::factory()->count(3)->create(['role' => 'teacher', 'classroom' => 'Infant'])
            ->merge(User::factory()->count(2)->create(['role' => 'teacher', 'classroom' => 'UPK-4']));
        // And children with a day on the record, which the seeder must not touch.
        $child = Child::create(['lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2022-09-23']);
        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-13', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-13 08:05'), 'health_in_code' => 3]);
        $childrenBefore = Child::orderBy('id')->get()->toArray();
        $attendanceBefore = Attendance::orderBy('id')->get()->toArray();

        $this->seed(ClassroomTeacherSeeder::class);

        $rooms = ClassroomAssignment::rooms();
        $teachers = User::teachers()->whereIn('email', array_map([ClassroomTeacherSeeder::class, 'email'], $rooms))->get();

        // One teacher a room, named for it, in it and only it…
        $this->assertCount(count($rooms), $teachers);
        // …and by default nobody is removed: every account that was there
        // still is. (A room with no base account adopts one of its teachers
        // as the room account, so the count of teachers can stay the same;
        // what never happens is a deletion.)
        $this->assertSame($extras->count(), User::whereIn('id', $extras->pluck('id'))->count());
        $this->assertTrue($infant->fresh()->exists());

        // Children and their days are exactly as they were.
        $this->assertSame($childrenBefore, Child::orderBy('id')->get()->toArray());
        $this->assertSame($attendanceBefore, Attendance::orderBy('id')->get()->toArray());
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

    public function test_the_other_teachers_go_only_when_asked(): void
    {
        User::factory()->count(4)->create(['role' => 'teacher', 'classroom' => 'Infant']);

        putenv('TEACHER_PRUNE=1');
        try {
            $this->seed(ClassroomTeacherSeeder::class);
        } finally {
            putenv('TEACHER_PRUNE');
        }

        $this->assertCount(count(ClassroomAssignment::rooms()), User::teachers()->get());
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
