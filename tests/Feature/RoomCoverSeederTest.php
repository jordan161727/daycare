<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\StaffScheduleWeek;
use App\Models\User;
use App\Services\StaffSchedule;
use Database\Seeders\RoomCoverSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The staff top-up that lets a week generate clean.
 *
 * A room with children in it all day needs an opener and a closer for every
 * ratio slot. The seeder reads the roll, counts who already holds each shift,
 * and adds only the people missing — so the first generated week after it
 * has nothing to look at, and a second run adds nobody.
 */
class RoomCoverSeederTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\PlacesChildrenInRooms;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
    }

    public function test_a_seeded_roll_generates_a_week_with_nothing_to_look_at(): void
    {
        // Eleven toddlers (1 to 6) and seven infants (1 to 4): two staff each, all day.
        foreach (range(1, 11) as $i) $this->child("T$i", 'Toddler');
        foreach (range(1, 7) as $i) $this->child("I$i", 'Infant');

        $this->seed(RoomCoverSeeder::class);

        $this->assertSame(4, User::teachers()->where('title', 'Toddler')->count());
        $this->assertSame(4, User::teachers()->where('title', 'Infant')->count());
        $this->assertSame(0, User::teachers()->where('title', 'PreK')->count(), 'a room with no children got staff');

        $week = app(StaffSchedule::class)->generate(StaffScheduleWeek::startOf('2026-10-05'));

        $this->assertSame([], $week->warnings ?? [], implode("\n", $week->warnings ?? []));
        // Eight people, five days. Counted on the table rather than through the
        // week's relation: SQLite compares the cast date as a datetime string.
        $this->assertSame(8 * 5, \App\Models\StaffShift::where('week_start', '2026-10-05')->count());
    }

    public function test_running_it_again_adds_nobody(): void
    {
        foreach (range(1, 5) as $i) $this->child("T$i", 'Toddler');

        $this->seed(RoomCoverSeeder::class);
        $before = [User::count(), \App\Models\StaffRule::count()];

        $this->seed(RoomCoverSeeder::class);

        $this->assertSame($before, [User::count(), \App\Models\StaffRule::count()]);
    }

    public function test_the_rooms_own_teacher_is_counted_as_its_opener(): void
    {
        foreach (range(1, 5) as $i) $this->child("T$i", 'Toddler');
        $teacher = User::create(['name' => 'Toddler Teacher', 'email' => 't@daycare.test', 'password' => 'x', 'role' => 'teacher', 'employment' => 'FT', 'title' => 'Toddler', 'classroom' => 'Toddler']);

        $this->seed(RoomCoverSeeder::class);

        // One slot: the existing teacher opens, one new person closes.
        $this->assertSame(2, User::teachers()->where('title', 'Toddler')->count());
        $this->assertTrue($teacher->staffRules()->where('rule_type', 'FIXED_SHIFT')->where('time_1', 7 * 60)->exists());
        $this->assertTrue($teacher->staffRules()->where('rule_type', 'CAN_OPEN')->exists());
    }

    /** A child on the roll, booked all day every day of the week under test. */
    private function child(string $name, string $room): Child
    {
        // The room follows the date of birth on save, so it is set that way.
        $child = Child::create([
            'lan' => (string) (1000 + Child::count()), 'first_name' => $name, 'last_name' => 'Test',
            'status' => 'Active', 'dob' => $this->dobForRoom($room), 'drop_off_time' => '07:00', 'pick_up_time' => '18:00',
        ]);

        foreach (range(0, 4) as $offset) {
            \App\Models\ScheduleSlot::create([
                'week_start' => '2026-10-05',
                'child_id' => $child->id,
                'slot_date' => Carbon::parse('2026-10-05')->addDays($offset)->toDateString(),
                'session' => 'FULL',
                'is_scheduled' => true,
            ]);
        }

        return $child;
    }
}
