<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Changing the days a child comes, once the week is already open.
 *
 * The sheet draws its boxes from the week's own ticks, and a week takes those
 * when it is opened. So a child registered for every day *after* the week was
 * opened used to read as attending none of them — a dot, which on that sheet
 * means "not coming", against a day they are booked for.
 *
 * That a week is the authority on itself is right for one that has been
 * worked: it is a record. It is wrong for one that has not happened yet, which
 * is a plan — and a plan should follow the registration.
 */
class RegisteredDaysResyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so the open week has days behind and ahead in it.
        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->child = Child::create([
            'lan' => '10064',
            'first_name' => 'Susan',
            'last_name' => 'Gonzalez',
            'status' => 'Active',
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
            // Nobody has said which days yet, which is how every record that
            // predates the question starts.
            'schedule_days' => null,
        ]);

        // The week is opened before the days are set — the order that caused
        // this in the first place.
        app(WeekSchedule::class)->open('2026-09-21', $this->admin);
    }

    public function test_setting_the_days_ticks_the_open_week(): void
    {
        $this->assertSame(0, $this->ticked());

        app(WeekSchedule::class)->resyncRegisteredDays(
            tap($this->child)->update(['schedule_days' => [1, 2, 3, 4, 5]])
        );

        // Monday to Friday, ticked without anybody opening the week again.
        $this->assertSame(5, $this->ticked());
    }

    public function test_taking_a_day_away_unticks_it(): void
    {
        $this->child->update(['schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->resyncRegisteredDays($this->child);

        $this->assertSame(5, $this->ticked());

        // Fridays dropped.
        $this->child->update(['schedule_days' => [1, 2, 3, 4]]);
        app(WeekSchedule::class)->resyncRegisteredDays($this->child);

        $this->assertSame(4, $this->ticked());
        $this->assertFalse((bool) $this->slotOn('2026-09-25')?->is_scheduled);
    }

    public function test_a_day_the_child_actually_attended_is_never_untucked(): void
    {
        /*
         * They were here. A plan saying otherwise afterwards would make the
         * sheet disagree with itself — a day with an arrival on it and a dot
         * beside it.
         */
        $this->child->update(['schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->resyncRegisteredDays($this->child);

        Attendance::create([
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-21',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-09-21 08:12'),
        ]);

        // Mondays dropped from the registration.
        $this->child->update(['schedule_days' => [2, 3, 4, 5]]);
        app(WeekSchedule::class)->resyncRegisteredDays($this->child);

        $this->assertTrue((bool) $this->slotOn('2026-09-21')?->is_scheduled);
    }

    public function test_a_week_already_worked_is_left_alone(): void
    {
        // Editing a profile in October must not rewrite September.
        app(WeekSchedule::class)->open('2026-09-14', $this->admin);

        $before = ScheduleSlot::where('child_id', $this->child->id)
            ->whereDate('slot_date', '<', '2026-09-21')
            ->where('is_scheduled', true)
            ->count();

        $this->child->update(['schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->resyncRegisteredDays($this->child);

        $after = ScheduleSlot::where('child_id', $this->child->id)
            ->whereDate('slot_date', '<', '2026-09-21')
            ->where('is_scheduled', true)
            ->count();

        $this->assertSame($before, $after);
    }

    public function test_saving_the_record_does_it_without_being_asked(): void
    {
        // The whole point: a director sets the days on the child's record and
        // the sheet agrees with them, rather than needing a second action
        // nobody knows about.
        $this->actingAs($this->admin)->put(route('children.update', $this->child), [
            'first_name' => 'Susan',
            'last_name' => 'Gonzalez',
            'lan' => '10064',
            'status' => 'Active',
            'dob' => $this->child->dob->toDateString(),
            'schedule_days' => [1, 2, 3, 4, 5],
        ])->assertRedirect();

        $this->assertSame(5, $this->ticked());
    }

    public function test_a_child_with_no_registered_days_is_left_as_it_was(): void
    {
        // Null is "nobody has said", which is different from "no days", and it
        // is not a reason to clear a week somebody has ticked by hand.
        $slot = $this->slotOn('2026-09-22');
        $slot?->forceFill(['is_scheduled' => true])->save();

        $this->assertSame(0, app(WeekSchedule::class)->resyncRegisteredDays($this->child));
        $this->assertTrue((bool) $this->slotOn('2026-09-22')?->is_scheduled);
    }

    private function ticked(): int
    {
        return ScheduleSlot::where('child_id', $this->child->id)
            ->whereDate('slot_date', '>=', '2026-09-21')
            ->where('is_scheduled', true)
            ->count();
    }

    private function slotOn(string $date): ?ScheduleSlot
    {
        return ScheduleSlot::where('child_id', $this->child->id)
            ->whereDate('slot_date', $date)
            ->first();
    }
}
