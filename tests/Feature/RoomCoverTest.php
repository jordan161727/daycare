<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\RoomCover;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who is on the floor with a child: their room's rostered teachers, over the
 * hours the child is contracted for, on the days they are ticked.
 */
class RoomCoverTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
    }

    public function test_the_teacher_in_the_room_over_the_childs_hours_is_named(): void
    {
        $child = $this->bookedChild(['drop_off_time' => '08:00', 'pick_up_time' => '15:00']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 15 * 60);

        $cover = $this->cover()[$child->id];

        $this->assertSame(['Grace H.'], $cover['names']);
        $this->assertFalse($cover['partial']);
    }

    public function test_a_teacher_in_another_room_is_not_theirs(): void
    {
        $child = $this->bookedChild();
        $this->shift('Alan Turing', 'PreK', 'MON', 7 * 60, 18 * 60);

        $this->assertArrayNotHasKey($child->id, $this->cover());
    }

    public function test_a_teacher_who_leaves_before_the_child_arrives_is_left_off(): void
    {
        $child = $this->bookedChild(['drop_off_time' => '13:00', 'pick_up_time' => '17:00']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 13 * 60);
        $this->shift('Maria Santos', 'Toddler', 'MON', 13 * 60, 18 * 60);

        $this->assertSame(['Maria S.'], $this->cover()[$child->id]['names']);
    }

    public function test_a_day_the_child_is_not_ticked_brings_nobody(): void
    {
        $child = $this->bookedChild(days: ['2026-07-27']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 18 * 60);
        $this->shift('Alan Turing', 'Toddler', 'TUE', 7 * 60, 18 * 60);

        $this->assertSame(['Grace H.'], $this->cover()[$child->id]['names']);
    }

    public function test_a_booked_day_with_nobody_rostered_is_flagged(): void
    {
        $child = $this->bookedChild(days: ['2026-07-27', '2026-07-28']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 18 * 60);

        $cover = $this->cover()[$child->id];

        $this->assertSame(['Grace H.'], $cover['names']);
        $this->assertTrue($cover['partial'], 'Tuesday is booked with nobody in the room');
    }

    public function test_a_child_with_no_hours_agreed_is_read_against_the_whole_day(): void
    {
        $child = $this->bookedChild();
        $this->shift('Grace Hopper', 'Toddler', 'MON', 16 * 60, 18 * 60);

        $this->assertSame(['Grace H.'], $this->cover()[$child->id]['names']);
    }

    public function test_no_roster_generated_means_nothing_is_claimed(): void
    {
        $this->bookedChild();

        $this->assertSame([], $this->cover());
    }

    public function test_the_breakdown_names_the_day_and_the_hours(): void
    {
        $child = $this->bookedChild(days: ['2026-07-27']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 15 * 60);

        $this->assertSame('Mon Grace H. 7:00 AM – 3:00 PM', $this->cover()[$child->id]['detail']);
    }

    public function test_the_week_schedule_carries_the_cover_onto_the_row(): void
    {
        $this->bookedChild(days: ['2026-07-27']);
        $this->shift('Grace Hopper', 'Toddler', 'MON', 7 * 60, 15 * 60);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));

        $rows = json_decode(json_decode('"'.$matches[1].'"'), associative: true);

        $this->assertSame(['Grace H.'], $rows[0]['cover']['names']);
    }

    /** A child ticked for the given dates, in the Toddler room. */
    private function bookedChild(array $attributes = [], ?array $days = null): Child
    {
        $child = Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
        ]);

        app(WeekSchedule::class)->open(self::MONDAY);

        ScheduleSlot::where('week_start', self::MONDAY)
            ->where('child_id', $child->id)
            ->whereIn('slot_date', $days ?? ['2026-07-27'])
            ->update(['is_scheduled' => true]);

        return $child;
    }

    private function shift(string $name, string $room, string $day, int $from, int $until): StaffShift
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'teacher', 'classroom' => $room]);

        return StaffShift::create([
            'week_start' => self::MONDAY,
            'user_id' => $user->id,
            'shift_date' => StaffScheduleWeek::datesOf(self::MONDAY)[$day]->toDateString(),
            'day' => $day,
            'starts_at' => $from,
            'ends_at' => $until,
            'classroom' => $room,
            'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    private function cover(): array
    {
        return app(RoomCover::class)->forWeek(self::MONDAY, Child::all());
    }
}
