<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Whose week "Copy from another week" rewrites.
 *
 * Ticking one box is checked room by room — a teacher may only touch their own.
 * Copying a week rewrites every box at once, so it has to answer to the same
 * rule, or the smaller action is guarded and the larger one is not.
 */
class ScheduleCopyScopeTest extends TestCase
{
    use RefreshDatabase;

    private const THIS_WEEK = '2026-09-14';

    private const LAST_WEEK = '2026-09-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::THIS_WEEK.' 09:00:00'));
    }

    /**
     * A teacher copying a week forward must not reach into rooms they cannot
     * see — replace mode clears the target first, so an unscoped copy does not
     * merely overwrite another room's days, it deletes them.
     */
    public function test_a_teacher_copying_a_week_leaves_other_rooms_alone(): void
    {
        $mine = $this->makeChild('Toddler', '2024-02-10');
        $theirs = $this->makeChild('Infant', '2026-02-10');

        // Last week: nobody ticked. This week: both rooms are fully ticked.
        app(WeekSchedule::class)->open(self::LAST_WEEK);
        app(WeekSchedule::class)->open(self::THIS_WEEK);
        ScheduleSlot::where('week_start', self::THIS_WEEK)->update(['is_scheduled' => true]);

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        $this->actingAs($teacher)->post(route('attendance.schedule.copy'), [
            'week_start' => self::THIS_WEEK,
            'source_week_start' => self::LAST_WEEK,
        ])->assertRedirect();

        // Their own room followed the copy: last week was blank, so this week is.
        $this->assertSame(0, $this->ticked($mine));

        // The Infant room is not theirs to rewrite. It was ticked before the
        // copy and it is ticked after it.
        $this->assertSame(5, $this->ticked($theirs), 'a teacher rewrote a room they cannot even see');
    }

    /** An admin holds the whole centre, so a copy is centre-wide for them. */
    public function test_an_admin_copying_a_week_moves_every_room(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $infant = $this->makeChild('Infant', '2026-02-10');

        app(WeekSchedule::class)->open(self::LAST_WEEK);
        app(WeekSchedule::class)->open(self::THIS_WEEK);
        ScheduleSlot::where('week_start', self::THIS_WEEK)->update(['is_scheduled' => true]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('attendance.schedule.copy'), [
                'week_start' => self::THIS_WEEK,
                'source_week_start' => self::LAST_WEEK,
                ])
            ->assertRedirect();

        $this->assertSame(0, $this->ticked($toddler));
        $this->assertSame(0, $this->ticked($infant));
    }

    /**
     * The count reported back is the count of what actually moved. A teacher
     * told "8 days added" when four of them were another room's is being told
     * about work they did not do and cannot see.
     */
    public function test_the_teacher_is_told_what_moved_in_their_own_rooms(): void
    {
        $this->makeChild('Toddler', '2024-02-10');
        $this->makeChild('Infant', '2026-02-10');

        app(WeekSchedule::class)->open(self::LAST_WEEK);
        ScheduleSlot::where('week_start', self::LAST_WEEK)->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::THIS_WEEK);
        ScheduleSlot::where('week_start', self::THIS_WEEK)->update(['is_scheduled' => false]);

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        $this->actingAs($teacher)
            ->post(route('attendance.schedule.copy'), [
                'week_start' => self::THIS_WEEK,
                'source_week_start' => self::LAST_WEEK,
                ])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '5 day(s) ticked'));
    }

    /**
     * The picker counts what a copy would actually move.
     *
     * The copy is scoped, so a centre-wide count beside it was a number from a
     * different question: a Toddler teacher choosing "62 days ticked" and
     * getting ten was being shown the whole building's week to decide their own
     * room's by.
     */
    public function test_the_week_picker_counts_only_the_rooms_the_reader_holds(): void
    {
        $this->makeChild('Toddler', '2024-02-10');
        $this->makeChild('Infant', '2026-02-10');
        $this->makeChild('Infant', '2026-03-03');

        app(WeekSchedule::class)->open(self::LAST_WEEK);
        ScheduleSlot::where('week_start', self::LAST_WEEK)->update(['is_scheduled' => true]);
        app(WeekSchedule::class)->open(self::THIS_WEEK);

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        // One Toddler child, five days: five, not the fifteen the centre has.
        $this->assertSame(5, $this->pickerCount($teacher));

        // The admin holds the building, so they are shown the building.
        $this->assertSame(15, $this->pickerCount(User::factory()->create(['role' => 'admin'])));
    }

    /** What the copy dialog says a given source week holds, for this reader. */
    private function pickerCount(User $user): int
    {
        return $this->actingAs($user)
            ->get(route('attendance.index', ['date' => self::THIS_WEEK]))
            ->assertOk()
            ->viewData('sourceWeeks')
            ->firstWhere('value', self::LAST_WEEK)['ticked'];
    }

    private function ticked(Child $child): int
    {
        return ScheduleSlot::where('week_start', self::THIS_WEEK)
            ->where('child_id', $child->id)
            ->where('is_scheduled', true)
            ->count();
    }

    private function makeChild(string $room, string $birthDate): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Child'.Child::count(),
            'classroom' => $room,
            'birth_date' => $birthDate,
        ]);
    }
}
