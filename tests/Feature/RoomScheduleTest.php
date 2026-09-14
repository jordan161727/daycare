<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\RoomSchedule;
use App\Models\User;
use Database\Seeders\RoomScheduleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A room's standing hours, the children who are in it, and the children's
 * records that read those hours back.
 */
class RoomScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_room_reads_as_its_hours(): void
    {
        $this->assertSame('8:00 AM – 5:00 PM', $this->roomSchedule('Infant', '08:00', '17:00')->hoursLabel());
    }

    public function test_a_room_with_only_one_end_set_reads_as_nothing(): void
    {
        $this->assertNull(RoomSchedule::create(['room' => 'Infant', 'opens_at' => '08:00'])->hoursLabel());
    }

    public function test_a_room_nothing_has_been_set_on_reads_as_nothing(): void
    {
        $this->assertNull(RoomSchedule::create(['room' => 'Infant'])->hoursLabel());
    }

    public function test_the_page_saves_every_room_in_one_go(): void
    {
        $this->actingAs($this->admin)->put(route('room-schedule.update'), [
            'rooms' => [
                ['room' => 'Infant', 'opens_at' => '08:00', 'closes_at' => '17:00'],
                ['room' => 'Toddler', 'opens_at' => '07:00', 'closes_at' => '18:00'],
            ],
        ])->assertRedirect(route('room-schedule.index'))->assertSessionHas('success');

        $this->assertSame('8:00 AM – 5:00 PM', RoomSchedule::firstWhere('room', 'Infant')->hoursLabel());
        $this->assertSame('7:00 AM – 6:00 PM', RoomSchedule::firstWhere('room', 'Toddler')->hoursLabel());
    }

    public function test_saving_again_updates_rather_than_duplicating(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');

        $this->actingAs($this->admin)->put(route('room-schedule.update'), [
            'rooms' => [['room' => 'Infant', 'opens_at' => '09:00', 'closes_at' => '16:00']],
        ])->assertRedirect();

        $this->assertSame(1, RoomSchedule::where('room', 'Infant')->count());
        $this->assertSame('9:00 AM – 4:00 PM', RoomSchedule::firstWhere('room', 'Infant')->hoursLabel());
    }

    public function test_a_closing_time_before_the_opening_one_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('room-schedule.update'), [
            'rooms' => [['room' => 'Infant', 'opens_at' => '17:00', 'closes_at' => '08:00']],
        ])->assertSessionHasErrors('rooms.0.closes_at');
    }

    public function test_a_room_that_is_not_a_real_room_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('room-schedule.update'), [
            'rooms' => [['room' => 'Broom Cupboard', 'opens_at' => '08:00', 'closes_at' => '17:00']],
        ])->assertSessionHasErrors('rooms.0.room');
    }

    public function test_a_teacher_cannot_set_the_rooms(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get(route('room-schedule.index'))->assertForbidden();
        $this->actingAs($teacher)->put(route('room-schedule.update'), ['rooms' => []])->assertForbidden();
    }

    public function test_the_page_lists_every_room_with_its_hours(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');
        $this->child('Infant');

        $this->actingAs($this->admin)
            ->get(route('room-schedule.index'))
            ->assertOk()
            ->assertSee('Infant')
            ->assertSee('Toddler')
            ->assertSee('8:00 AM – 5:00 PM')
            ->assertSee('1 child');
    }

    public function test_the_page_shows_the_children_in_a_room_and_their_hours(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');
        $this->child('Infant', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'drop_off_time' => '07:00', 'pick_up_time' => '17:30']);
        $this->child('Toddler', ['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $page = $this->actingAs($this->admin)->get(route('room-schedule.index'))->assertOk();

        $page->assertSee('Ada Lovelace')
            // The child whose day runs past the room's is what this page is for.
            ->assertSee('7:00 AM – 5:30 PM')
            ->assertSee('Grace Hopper')
            ->assertSee('No hours agreed');
    }

    public function test_a_child_who_has_left_is_not_in_the_room(): void
    {
        $this->child('Infant', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'Inactive']);

        $this->actingAs($this->admin)
            ->get(route('room-schedule.index'))
            ->assertOk()
            ->assertDontSee('Ada Lovelace');
    }

    /**
     * The roster's Schedule column is about the child, not the room.
     *
     * The room's hours used to sit under every child's own. Every child in a
     * room shares them, so down a column of sixty they were the same line sixty
     * times — and the one thing the column could not tell you was which child
     * was different. They are still set and read on the Room Schedules page,
     * where a room is the subject rather than the backdrop.
     */
    public function test_the_roster_shows_the_childs_own_arrangement_only(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');
        $this->child('Infant', [
            'drop_off_time' => '07:00',
            'pick_up_time' => '17:30',
            'schedule_days' => [1, 3, 5],
        ]);

        $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            // Their hours and their days, which differ child to child.
            ->assertSee('7:00 AM – 5:30 PM')
            ->assertSee('Mon, Wed, Fri')
            // And not the room's, repeated on every row.
            ->assertDontSee('Class 8:00 AM – 5:00 PM')
            ->assertDontSee('8:00 AM – 5:00 PM');
    }

    public function test_the_room_page_still_owns_the_rooms_hours(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');
        $this->child('Infant');

        // Removed from the roster, not from the app: this is the page where a
        // room's own day is set and read.
        $this->actingAs($this->admin)
            ->get(route('room-schedule.index'))
            ->assertOk()
            ->assertSee('8:00 AM – 5:00 PM');
    }

    public function test_the_seeder_opens_every_room_with_the_centre(): void
    {
        $this->seed(RoomScheduleSeeder::class);

        $this->assertSame(
            ['7:00 AM – 6:00 PM'],
            RoomSchedule::all()->map->hoursLabel()->unique()->values()->all()
        );
        $this->assertSame(count(\App\Services\ClassroomAssignment::rooms()), RoomSchedule::count());
    }

    public function test_the_seeder_fills_a_room_whose_row_exists_but_is_blank(): void
    {
        RoomSchedule::create(['room' => 'Infant']);

        $this->seed(RoomScheduleSeeder::class);

        $this->assertSame('7:00 AM – 6:00 PM', RoomSchedule::firstWhere('room', 'Infant')->hoursLabel());
        $this->assertSame(1, RoomSchedule::where('room', 'Infant')->count());
    }

    public function test_the_seeder_leaves_hours_somebody_has_already_set(): void
    {
        $this->roomSchedule('Infant', '08:00', '17:00');

        $this->seed(RoomScheduleSeeder::class);

        $this->assertSame('8:00 AM – 5:00 PM', RoomSchedule::firstWhere('room', 'Infant')->hoursLabel());
    }

    private function roomSchedule(string $room, string $opens, string $closes): RoomSchedule
    {
        return RoomSchedule::create([
            'room' => $room,
            'opens_at' => $opens,
            'closes_at' => $closes,
        ]);
    }

    private function child(string $room, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => $room,
            'classroom_override' => $room,
        ]);
    }
}
