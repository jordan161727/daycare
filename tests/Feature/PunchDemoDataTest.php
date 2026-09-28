<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffTimesheetController;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * demo:punches, the data the Timesheets grid is looked at with.
 *
 * What matters is not the numbers it invents but that every colour a dot can
 * be has a day behind it, that it never touches a day somebody real punched,
 * and that --undo takes back exactly what it wrote and nothing else.
 */
class PunchDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('daycare.timesheet.clock.enabled', true);

        // A Friday, so the fortnight behind it has days gone by to be wrong on.
        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));

        $this->admin = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => 'password', 'role' => 'admin']);

        // Four, so each of the four problem days lands on a different person.
        foreach (['Infant', 'Toddler', 'PreK', 'UPK-4'] as $room) {
            User::create([
                'name' => $room.' Teacher', 'email' => strtolower($room).'@example.com', 'password' => 'password',
                'role' => 'teacher', 'employment' => 'FT', 'classroom' => $room, 'pay_rate' => 20,
            ]);
        }
    }

    public function test_it_writes_a_day_of_every_colour(): void
    {
        $this->artisan('demo:punches')->assertSuccessful();

        $this->assertGreaterThan(0, TimePunch::count());

        $grid = app(StaffTimesheetController::class);
        $statuses = [];

        foreach (User::where('role', 'teacher')->get() as $person) {
            foreach (Carbon::parse('2026-09-14')->daysUntil('2026-09-25') as $day) {
                $statuses[] = $grid->cell($person, $day->toDateString())['status'];
            }
        }

        $statuses = array_unique(array_filter($statuses));
        sort($statuses);

        // Green, amber, red, and the ring — all four, on one grid.
        $this->assertSame([
            StaffTimesheetController::EDITED,
            StaffTimesheetController::LATE,
            StaffTimesheetController::MISSING_OUT,
            StaffTimesheetController::ON_TIME,
        ], $statuses);

        // The corrected day is a real correction: a void with a reason and a
        // replacement that points back at it.
        $replacement = TimePunch::whereNotNull('corrects_id')->sole();
        $this->assertTrue($replacement->corrects->isVoided());
        $this->assertSame(TimePunch::SOURCE_SUPERVISOR, $replacement->source);
        $this->assertStringContainsString('Punched wrong time', $replacement->reason);

        // And the late day has the roster that makes it late.
        $this->assertSame(1, StaffShift::count());

        // One break nobody came back off: a start whose day has no end.
        $orphans = TimePunch::live()->where('type', TimePunch::BREAK_START)->get()
            ->filter(fn (TimePunch $start) => TimePunch::live()
                ->where('user_id', $start->user_id)
                ->whereDate('work_date', $start->work_date)
                ->where('type', TimePunch::BREAK_END)
                ->doesntExist());

        $this->assertCount(1, $orphans);
    }

    public function test_it_never_touches_a_day_somebody_real_punched(): void
    {
        $teacher = User::where('role', 'teacher')->first();

        // A real punch, recorded to the minute the way the clock does.
        TimePunch::create([
            'user_id' => $teacher->id, 'work_date' => '2026-09-22', 'punched_at' => '2026-09-22 07:31:00',
            'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_CLOCK, 'recorded_by' => $teacher->id,
        ]);

        $this->artisan('demo:punches')->assertSuccessful();

        $this->assertSame(1, TimePunch::where('user_id', $teacher->id)->whereDate('work_date', '2026-09-22')->count());
    }

    public function test_undo_takes_back_exactly_what_it_wrote(): void
    {
        $teacher = User::where('role', 'teacher')->first();

        TimePunch::create([
            'user_id' => $teacher->id, 'work_date' => '2026-09-16', 'punched_at' => '2026-09-16 07:31:00',
            'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_CLOCK, 'recorded_by' => $teacher->id,
        ]);
        TimePunch::create([
            'user_id' => $teacher->id, 'work_date' => '2026-09-16', 'punched_at' => '2026-09-16 15:31:00',
            'type' => TimePunch::OUT, 'source' => TimePunch::SOURCE_CLOCK, 'recorded_by' => $teacher->id,
        ]);

        $this->artisan('demo:punches')->assertSuccessful();
        $this->assertGreaterThan(2, TimePunch::count());

        $this->artisan('demo:punches --undo')->assertSuccessful();

        // The two real punches, and only those.
        $this->assertSame(2, TimePunch::count());
        $this->assertSame(0, TimePunch::where('punched_at', 'like', '%:07')->count());
        $this->assertSame(0, StaffShift::count());

        // The entries the demo punches built are gone with them.
        $this->assertSame(0, TimesheetEntry::where('worked_minutes', '>', 0)->where('user_id', '!=', $teacher->id)->count());
    }
}
