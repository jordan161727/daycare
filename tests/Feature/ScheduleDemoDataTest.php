<?php

namespace Tests\Feature;

use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * demo:schedule, the roster the Week Schedule is looked at with.
 *
 * What matters is that a fresh box — teachers with no employment type, no
 * keyholder — comes out with a chart that has everybody on it, and that
 * --undo takes back the weeks and rules it wrote and leaves a director's own
 * week alone.
 */
class ScheduleDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday: this week's Monday is the 21st, next week's the 28th.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        foreach (['Infant', 'Toddler', 'PreK'] as $room) {
            User::create([
                'name' => $room.' Teacher', 'email' => strtolower($room).'@example.com', 'password' => 'password',
                'role' => 'teacher', 'classroom' => $room,
            ]);
        }
    }

    public function test_it_rosters_every_teacher_for_this_week_and_next(): void
    {
        $this->artisan('demo:schedule')->assertSuccessful();

        $this->assertSame(['2026-09-21', '2026-09-28'], StaffScheduleWeek::orderBy('week_start')->pluck('week_start')->map->toDateString()->all());
        $this->assertGreaterThan(0, StaffShift::count());

        // Nobody left off: every teacher has shifts in both weeks.
        foreach (User::teachers()->get() as $teacher) {
            $this->assertSame('FT', $teacher->employment, $teacher->name.' was given an employment type');
            $this->assertGreaterThan(0, StaffShift::where('user_id', $teacher->id)->where('week_start', '2026-09-21')->count(), $teacher->name.' works this week');
            $this->assertGreaterThan(0, StaffShift::where('user_id', $teacher->id)->where('week_start', '2026-09-28')->count(), $teacher->name.' works next week');
        }

        // Two keyholders, marked as this command's, so undo can find them.
        $this->assertSame(2, StaffRule::where('rule_type', 'CAN_OPEN')->where('source_note', 'demo:schedule')->count());
    }

    public function test_undo_takes_back_its_weeks_and_rules_and_leaves_a_directors_week_alone(): void
    {
        $director = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => 'password', 'role' => 'admin']);

        $this->artisan('demo:schedule')->assertSuccessful();

        // A week the director generated themselves, with their name on it.
        $own = StaffScheduleWeek::create(['week_start' => '2026-10-05', 'generated_at' => now(), 'generated_by' => $director->id, 'warnings' => []]);
        StaffShift::create([
            'week_start' => '2026-10-05', 'user_id' => User::teachers()->first()->id, 'shift_date' => '2026-10-05', 'day' => 'MON',
            'starts_at' => 8 * 60, 'ends_at' => 16 * 60, 'classroom' => 'Infant', 'role' => StaffShift::ROLE_STAFF,
        ]);

        $this->artisan('demo:schedule --undo')->assertSuccessful();

        $this->assertSame([$own->id], StaffScheduleWeek::pluck('id')->all());
        $this->assertSame(1, StaffShift::count());
        $this->assertSame(0, StaffRule::where('source_note', 'demo:schedule')->count());

        // The employment type stays: it is a fact on the profile now.
        $this->assertSame('FT', User::teachers()->first()->employment);
    }

    public function test_a_keyholder_the_director_set_is_not_added_twice_or_removed(): void
    {
        $lead = User::teachers()->first();
        $lead->staffRules()->create(['rule_type' => 'CAN_OPEN', 'priority' => 'HARD']);

        $this->artisan('demo:schedule')->assertSuccessful();

        $this->assertSame(1, $lead->staffRules()->where('rule_type', 'CAN_OPEN')->count());

        $this->artisan('demo:schedule --undo')->assertSuccessful();

        $this->assertSame(1, $lead->staffRules()->where('rule_type', 'CAN_OPEN')->count());
    }
}
