<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The days a child is registered to attend.
 *
 * The hours were already on the record — when in the day a child is here — but
 * nothing said which days, so a Mon/Wed/Fri child and a full-week child were
 * the same record and every newly enrolled child's first week came up blank.
 * This is the standing arrangement agreed at registration; a week is still
 * ticked and corrected on its own.
 */
class ChildScheduleDaysTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-14';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_pattern_reads_as_a_phrase(): void
    {
        $this->assertSame('Mon, Wed, Thu, Fri', $this->makeChild(['schedule_days' => [1, 3, 4, 5]])->scheduleDaysLabel());

        // The commonest arrangement there is gets the shortest label, since it
        // is the one read most often down a column of sixty children.
        $this->assertSame('Every day', $this->makeChild(['schedule_days' => [1, 2, 3, 4, 5]])->scheduleDaysLabel());

        // Null and [] are different answers: nobody has said, versus somebody
        // saying this child is on the roll and not currently coming.
        $this->assertNull($this->makeChild()->scheduleDaysLabel());
        $this->assertSame('No days', $this->makeChild(['schedule_days' => []])->scheduleDaysLabel());
    }

    public function test_the_days_are_asked_for_on_the_record_and_saved(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('Days they attend')
            ->assertSee('name="schedule_days[]" value="3"', false);

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, ['schedule_days' => ['1', '3', '5']]))
            ->assertSessionHasNoErrors();

        $this->assertSame([1, 3, 5], $child->fresh()->scheduleDays());
    }

    /**
     * Unticking the last box has to mean something different from never
     * touching the field, which is what the hidden empty input in front of the
     * boxes is for.
     */
    public function test_clearing_every_day_is_saved_as_no_days(): void
    {
        $child = $this->makeChild(['schedule_days' => [1, 2, 3, 4, 5]]);

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, ['schedule_days' => ['']]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $child->fresh()->scheduleDays());
    }

    public function test_a_day_outside_the_week_is_refused(): void
    {
        $child = $this->makeChild();

        // Saturday. The centre does not open, so there is no box for it and
        // nothing should be able to post one.
        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->form($child, ['schedule_days' => ['6']]))
            ->assertSessionHasErrors('schedule_days.0');
    }

    public function test_a_first_week_opens_on_the_days_the_child_is_registered_for(): void
    {
        $child = $this->makeChild(['schedule_days' => [1, 3, 4, 5]]);

        app(WeekSchedule::class)->open(self::MONDAY);

        // Mon, Wed, Thu, Fri ticked; Tuesday not. Nobody had to notice.
        $this->assertSame(
            ['2026-09-14', '2026-09-16', '2026-09-17', '2026-09-18'],
            $this->ticked($child, self::MONDAY)
        );
    }

    public function test_a_child_nobody_has_answered_for_still_opens_blank(): void
    {
        $child = $this->makeChild();

        app(WeekSchedule::class)->open(self::MONDAY);

        // A day nobody has asked for is never silently scheduled.
        $this->assertSame([], $this->ticked($child, self::MONDAY));
    }

    public function test_a_closed_day_is_never_ticked_from_the_registration(): void
    {
        $child = $this->makeChild(['schedule_days' => [1, 2, 3, 4, 5]]);
        ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);

        app(WeekSchedule::class)->open(self::MONDAY);

        // The closure is a fact about that date; the pattern is an arrangement.
        $this->assertNotContains(self::MONDAY, $this->ticked($child, self::MONDAY));
        $this->assertCount(4, $this->ticked($child, self::MONDAY));
    }

    /**
     * The registration seeds a child's first week. It never overrules what a
     * later week inherited — including a week somebody deliberately cleared.
     */
    /**
     * A copied week beats the registration.
     *
     * The record seeds a week when it opens; a copy is somebody choosing
     * another week's pattern over that, and the choice stands even where the
     * pattern they chose is emptier than the record.
     */
    public function test_a_copied_week_beats_the_registration(): void
    {
        $child = $this->makeChild(['schedule_days' => [1, 2, 3, 4, 5]]);

        app(WeekSchedule::class)->open(self::MONDAY);
        // Somebody clears this week by hand.
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => false]);

        // Next week opens from the record — five days — and is then made to
        // match the cleared one on purpose.
        app(WeekSchedule::class)->open('2026-09-21');
        $this->assertCount(5, $this->ticked($child, '2026-09-21'));

        app(WeekSchedule::class)->copyFrom('2026-09-21', self::MONDAY);

        $this->assertSame([], $this->ticked($child, '2026-09-21'));
    }

    public function test_a_child_enrolled_after_the_week_was_opened_gets_their_days(): void
    {
        $this->makeChild(['first_name' => 'Ada', 'lan' => '2001']);
        app(WeekSchedule::class)->open(self::MONDAY);

        // Registered on Tuesday and Thursday, added once the week was already up.
        $late = $this->makeChild(['first_name' => 'Alan', 'lan' => '2002', 'schedule_days' => [2, 4]]);

        app(WeekSchedule::class)->open(self::MONDAY);

        $this->assertSame(['2026-09-15', '2026-09-17'], $this->ticked($late, self::MONDAY));
    }

    public function test_the_room_page_reads_each_childs_own_arrangement(): void
    {
        $this->makeChild([
            'first_name' => 'Levi',
            'last_name' => 'Manney',
            'schedule_days' => [1, 3, 5],
            'drop_off_time' => '08:30',
            'pick_up_time' => '17:30',
        ]);

        // Not the room's hours: the child's own, and the days they come.
        $this->actingAs($this->admin)
            ->get(route('room-schedule.index'))
            ->assertOk()
            ->assertSeeInOrder(['Manney', '8:30 AM', '5:30 PM', 'Mon, Wed, Fri']);
    }

    /** @return array<int, string> the dates ticked for this child that week */
    private function ticked(Child $child, string $weekStart): array
    {
        return ScheduleSlot::where('week_start', $weekStart)
            ->where('child_id', $child->id)
            ->where('is_scheduled', true)
            ->orderBy('slot_date')
            ->pluck('slot_date')
            ->map(fn ($date) => $date->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'classroom' => 'PreK',
            'birth_date' => '2022-12-15',
        ]);
    }

    /** The whole form, since an update posts every field the page carries. */
    private function form(Child $child, array $overrides = []): array
    {
        return $overrides + [
            'lan' => $child->lan,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birthDate()?->toDateString(),
        ];
    }
}
