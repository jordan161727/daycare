<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\ScheduleWeek;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who a week gets opened for.
 *
 * It used to be one centre-wide act: the first person to look at a week built
 * it for every child in the building. That made the Infant teacher's schedule
 * something the Toddler teacher created by opening a page — days ticked, or
 * not ticked, by somebody who cannot see them and did not decide them.
 *
 * Opening now builds the opener's own rooms, and a week reads as open to
 * somebody when their own children have boxes in it.
 */
class WeekOpenScopeTest extends TestCase
{
    use RefreshDatabase;

    private const THIS_WEEK = '2026-09-14';

    private const NEXT_WEEK = '2026-09-21';

    private User $toddlerTeacher;

    private User $infantTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::THIS_WEEK.' 09:00:00'));

        $this->toddlerTeacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);
        $this->infantTeacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']);
    }

    public function test_a_teacher_opening_a_week_builds_only_their_own_rooms(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $infant = $this->makeChild('Infant', '2026-02-10');

        $this->actingAs($this->toddlerTeacher)
            ->post(route('attendance.week.open'), ['week_start' => self::NEXT_WEEK])
            ->assertRedirect();

        $this->assertSame(5, $this->slots($toddler));

        // The Infant room is not theirs to build. Its schedule stays a thing
        // its own teacher decides.
        $this->assertSame(0, $this->slots($infant));
    }

    /**
     * The other teacher still sees "not set up", because as far as their rooms
     * are concerned it is — the week's row existing says only that somebody has
     * been here.
     */
    public function test_the_other_teacher_still_has_a_week_to_open(): void
    {
        $this->makeChild('Toddler', '2024-02-10');
        $this->makeChild('Infant', '2026-02-10');

        app(WeekSchedule::class)->open(self::NEXT_WEEK, $this->toddlerTeacher);

        $this->assertTrue(ScheduleWeek::where('week_start', self::NEXT_WEEK)->exists());

        $this->actingAs($this->infantTeacher)
            ->get(route('attendance.index', ['date' => self::NEXT_WEEK]))
            ->assertOk()
            ->assertSee('has not been set up')
            ->assertSee('Open this week');

        // And the Toddler teacher, who built it, gets the sheet.
        $this->actingAs($this->toddlerTeacher)
            ->get(route('attendance.index', ['date' => self::NEXT_WEEK]))
            ->assertOk()
            ->assertDontSee('has not been set up');
    }

    /**
     * Opening second gives your rooms the same start as opening first: the
     * children's registered days. What the first opener did to THEIR rooms
     * afterwards — copying a week across, say — stays in their rooms. A
     * pattern nobody in these rooms chose must not arrive because somebody
     * down the hall pressed a button.
     */
    public function test_opening_second_starts_your_rooms_from_the_record_not_from_the_first_openers_copy(): void
    {
        $infant = $this->makeChild('Infant', '2026-02-10');
        $infant->forceFill(['schedule_days' => [1, 2, 3]])->save();   // Mon–Wed on the record
        $toddler = $this->makeChild('Toddler', '2024-02-10');

        // Last week, ticked for everybody.
        app(WeekSchedule::class)->open(self::THIS_WEEK);
        ScheduleSlot::where('week_start', self::THIS_WEEK)->update(['is_scheduled' => true]);

        // The Toddler teacher opens next week and copies last week into it.
        app(WeekSchedule::class)->open(self::NEXT_WEEK, $this->toddlerTeacher);
        app(WeekSchedule::class)->copyFrom(self::NEXT_WEEK, self::THIS_WEEK, $this->toddlerTeacher);

        // The Infant teacher follows.
        app(WeekSchedule::class)->open(self::NEXT_WEEK, $this->infantTeacher);

        // Toddler: the five days they copied. Infant: the three on the record.
        $ticked = fn ($child) => ScheduleSlot::where('week_start', self::NEXT_WEEK)
            ->where('child_id', $child->id)->where('is_scheduled', true)->count();

        $this->assertSame(5, $ticked($toddler));
        $this->assertSame(3, $ticked($infant));
    }

    public function test_an_admin_opens_the_whole_centre(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $infant = $this->makeChild('Infant', '2026-02-10');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('attendance.week.open'), ['week_start' => self::NEXT_WEEK])
            ->assertRedirect();

        $this->assertSame(5, $this->slots($toddler));
        $this->assertSame(5, $this->slots($infant));
    }

    /**
     * The current week is the exception it always was: the centre always has
     * today's sheet, and making a teacher press a button before they can sign a
     * child in would be a door where there was none. Scoped like the rest, so
     * it builds their rooms and not the building.
     */
    public function test_this_week_still_builds_itself_for_whoever_looks(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $infant = $this->makeChild('Infant', '2026-02-10');

        $this->actingAs($this->toddlerTeacher)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertDontSee('has not been set up');

        $this->assertSame(5, ScheduleSlot::where('child_id', $toddler->id)->count());
        $this->assertSame(0, ScheduleSlot::where('child_id', $infant->id)->count());
    }

    private function slots(Child $child): int
    {
        return ScheduleSlot::where('week_start', self::NEXT_WEEK)
            ->where('child_id', $child->id)
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
