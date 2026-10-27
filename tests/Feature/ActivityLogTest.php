<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\DssDayMark;
use App\Models\Setting;
use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The activity log (Ray, 28 Oct 2026): "every time someone does something
 * it is logged — 11:00 AM = Farhana checked Saroya in @ 11:00 AM; 11:05 AM
 * = Patrick modified Evana check in time for Sept 15 from Nothing to 10:00 AM".
 *
 * The RULES:
 *  - Every save of a watched record leaves a line, whichever screen made it.
 *  - A line names who did it, and a fixed time says from what to what.
 *  - The page is the director's; it is read a day at a time.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $patrick;

    private User $farhana;

    private Child $saroya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-28 11:00:00', config('app.timezone')));
        $this->patrick = User::factory()->create(['name' => 'Patrick Hale', 'role' => 'admin']);
        $this->farhana = User::factory()->create(['name' => 'Farhana Noor', 'role' => 'teacher']);
        $this->saroya = Child::create(['lan' => '10111', 'first_name' => 'Saroya', 'last_name' => 'Khan', 'status' => 'Active', 'classroom' => 'PreK']);

        // The set-up above is not the story.
        Activity::query()->delete();
    }

    public function test_a_check_in_is_told_in_the_office_words(): void
    {
        $this->actingAs($this->farhana);
        $row = Attendance::create(['child_id' => $this->saroya->id, 'attendance_date' => '2026-10-28', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->assertSame(['Farhana Noor checked Saroya Khan in @ 11:00 AM'], $this->lines());

        $this->travel(6)->hours();
        $row->update(['signed_out_at' => now()]);

        $this->assertSame('Farhana Noor checked Saroya Khan out @ 5:00 PM', $this->lines()[0]);
        $this->assertSame('5:00 PM', Activity::newestFirst()->first()->clock());
    }

    public function test_a_fixed_time_says_from_what_to_what(): void
    {
        $this->actingAs($this->patrick);
        $row = Attendance::create(['child_id' => $this->saroya->id, 'attendance_date' => '2026-09-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-15 09:00', config('app.timezone'))]);
        $this->assertSame(['Patrick Hale checked Saroya Khan in @ 9:00 AM for Sep 15'], $this->lines());

        $row->update(['signed_in_at' => Carbon::parse('2026-09-15 10:00', config('app.timezone'))]);
        $this->assertSame("Patrick Hale modified Saroya Khan's check-in time for Sep 15 from 9:00 AM to 10:00 AM", $this->lines()[0]);

        $row->update(['signed_in_at' => Carbon::parse('2026-09-15 10:15', config('app.timezone')), 'signed_out_at' => Carbon::parse('2026-09-15 16:00', config('app.timezone'))]);
        $this->assertSame("Patrick Hale modified Saroya Khan's times for Sep 15 from 10:00 AM–Nothing to 10:15 AM–4:00 PM", $this->lines()[0]);

        $row->delete();
        $this->assertSame("Patrick Hale removed Saroya Khan's attendance for Sep 15 (was 10:15 AM–4:00 PM)", $this->lines()[0]);

        DssDayMark::create(['child_id' => $this->saroya->id, 'marked_on' => '2026-09-16', 'mark' => DssDayMark::ABSENT, 'created_by' => $this->patrick->id]);
        $this->assertSame('Patrick Hale marked Saroya Khan absent for Sep 16', $this->lines()[0]);
    }

    public function test_staff_punches_and_their_corrections(): void
    {
        $this->actingAs($this->farhana);
        $punch = TimePunch::create(['user_id' => $this->farhana->id, 'work_date' => '2026-10-28', 'punched_at' => now(), 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_CLOCK]);
        $this->assertSame(['Farhana Noor clocked in @ 11:00 AM'], $this->lines());

        $this->actingAs($this->patrick);
        $punch->update(['punched_at' => now()->subMinutes(10), 'reason' => 'forgot']);
        $this->assertSame("Patrick Hale modified Farhana Noor's clock in from 11:00 AM to 10:50 AM — Forgot to punch", $this->lines()[0]);

        TimePunch::create(['user_id' => $this->farhana->id, 'work_date' => '2026-10-27', 'punched_at' => Carbon::parse('2026-10-27 17:00', config('app.timezone')), 'type' => TimePunch::OUT, 'source' => TimePunch::SOURCE_SUPERVISOR, 'recorded_by' => $this->patrick->id, 'reason' => 'forgot_out']);
        $this->assertSame('Patrick Hale added a clock out for Farhana Noor @ 5:00 PM on Oct 27 — Forgot to clock out', $this->lines()[0]);
    }

    public function test_record_and_setting_changes_say_the_field_and_both_values(): void
    {
        $this->actingAs($this->patrick);

        // A room is moved by hand through the override; the record's own
        // saving hook writes classroom from it. One line, about the room.
        $this->saroya->update(['classroom_override' => 'UPK-4']);
        $this->assertSame("Patrick Hale changed Saroya Khan's classroom from PreK to UPK-4", $this->lines()[0]);
        $this->saroya->update(['status' => 'Inactive']);
        $this->assertSame("Patrick Hale changed Saroya Khan's status from Active to Withdrawn", $this->lines()[0]);

        Setting::put('rooms.PreK.ratio', 7);
        $this->assertSame('Patrick Hale set rooms.PreK.ratio to 7', $this->lines()[0]);
        Setting::put('rooms.PreK.ratio', 8);
        $this->assertSame('Patrick Hale changed rooms.PreK.ratio from 7 to 8', $this->lines()[0]);

        $this->farhana->update(['password' => bcrypt('new-secret'), 'phone' => '555-0100']);
        $line = $this->lines()[0];
        $this->assertStringContainsString("Patrick Hale changed Farhana Noor's", $line);
        $this->assertStringContainsString('phone from Nothing to 555-0100', $line);
        $this->assertStringContainsString('password', $line);
        $this->assertStringNotContainsString('new-secret', $line);

        Child::create(['lan' => '10112', 'first_name' => 'Evana', 'last_name' => 'Roy', 'status' => 'Active', 'classroom' => 'Infant']);
        $this->assertSame('Patrick Hale added Evana Roy (LAN 10112)', $this->lines()[0]);
    }

    public function test_nobody_signed_in_is_still_named(): void
    {
        Auth::logout();
        Attendance::create(['child_id' => $this->saroya->id, 'attendance_date' => '2026-10-28', 'session' => 'FULL', 'signed_in_at' => now()]);

        // A test runs in the console; on a kiosk route the name is the screen's.
        $this->assertSame('System checked Saroya Khan in @ 11:00 AM', $this->lines()[0]);
        $this->assertNull(Activity::newestFirst()->first()->actor_id);
    }

    public function test_the_page_is_the_directors_and_reads_a_day_at_a_time(): void
    {
        $this->actingAs($this->farhana);
        Attendance::create(['child_id' => $this->saroya->id, 'attendance_date' => '2026-10-28', 'session' => 'FULL', 'signed_in_at' => now()]);
        $this->actingAs($this->patrick);
        Setting::put('rooms.PreK.ratio', 7);

        $this->travelTo(Carbon::parse('2026-10-27 09:00', config('app.timezone')));
        TimePunch::create(['user_id' => $this->patrick->id, 'work_date' => '2026-10-27', 'punched_at' => now(), 'type' => TimePunch::IN, 'source' => TimePunch::SOURCE_CLOCK]);
        $this->travelTo(Carbon::parse('2026-10-28 12:00', config('app.timezone')));

        $this->actingAs($this->farhana)->get(route('staff.activity'))->assertForbidden();

        $html = $this->actingAs($this->patrick)->get(route('staff.activity'))->assertOk()->getContent();
        $this->assertStringContainsString('Farhana Noor checked Saroya Khan in @ 11:00 AM', $html);
        $this->assertStringContainsString('Patrick Hale set rooms.PreK.ratio to 7', $html);
        $this->assertStringNotContainsString('clocked in @ 9:00 AM', $html, 'yesterday is another page');
        $this->assertStringContainsString('2 lines', $html);

        $this->actingAs($this->patrick)->get(route('staff.activity', ['date' => '2026-10-27']))->assertOk()
            ->assertSee('Patrick Hale clocked in @ 9:00 AM')
            ->assertSee('1 line');

        $this->actingAs($this->patrick)->get(route('staff.activity', ['who' => $this->farhana->id]))->assertOk()
            ->assertSee('checked Saroya Khan in')
            ->assertDontSee('rooms.PreK.ratio')
            ->assertSee('1 of 2 lines');

        $this->actingAs($this->patrick)->get(route('staff.activity', ['q' => 'ratio']))->assertOk()
            ->assertSee('rooms.PreK.ratio')
            ->assertDontSee('checked Saroya Khan in');

        $this->actingAs($this->patrick)->get(route('staff.activity', ['date' => '2026-10-20']))->assertOk()
            ->assertSee('Nothing logged on this day.');
    }

    /** @return list<string> newest first */
    private function lines(): array
    {
        return Activity::newestFirst()->pluck('text')->all();
    }
}
