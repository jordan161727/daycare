<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffTimesheetController;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The week read every morning.
 *
 * What it has to get right is the colour of a dot. Everything else on the page
 * is a time somebody can check against their own memory; the dot is the part
 * that claims something — that a day is missing an out, or that somebody was
 * late — and a wrong claim there is an accusation.
 */
class StaffWeekGridTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so there are days behind and ahead inside the week.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
    }

    public function test_the_week_shows_each_day_in_and_out(): void
    {
        $staff = $this->staff('Rachel Kim');

        $this->punch($staff, '2026-09-21 07:52', TimePunch::IN);
        $this->punch($staff, '2026-09-21 16:05', TimePunch::OUT);

        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rachel Kim', $html);
        $this->assertStringContainsString($staff->staffId(), $html);
        $this->assertStringContainsString('7:52 AM', $html);
        $this->assertStringContainsString('4:05 PM', $html);

        // Eight hours thirteen, to one place, across the whole week.
        $this->assertStringContainsString('8.2h', $html);
    }

    public function test_a_day_gone_by_with_no_clock_out_is_flagged(): void
    {
        // The one that stops a pay period being approved, and the one somebody
        // has to chase while the answer is still rememberable.
        $staff = $this->staff('Patrick Ortiz');

        $this->punch($staff, '2026-09-21 08:12', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('Missing time out', $html);
    }

    public function test_today_being_open_is_not_a_missing_out(): void
    {
        // Somebody still at work is not a fault. Flagging it would put an amber
        // dot against half the centre every morning, and a warning everybody
        // sees daily is one nobody reads.
        $staff = $this->staff('Maya Lindqvist');

        $this->punch($staff, '2026-09-23 07:45', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Missing time out', $html);
        $this->assertStringContainsString('On time', $html);
    }

    public function test_late_is_only_claimed_where_a_shift_says_what_time_they_were_due(): void
    {
        $staff = $this->staff('Devon Brooks');

        // No roster: arriving at ten is not late, because nothing said nine.
        $this->punch($staff, '2026-09-21 10:00', TimePunch::IN);
        $this->punch($staff, '2026-09-21 18:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('title="Late', $html);

        // Rostered for eight, in at ten. Now it means something.
        StaffShift::create([
            'week_start' => '2026-09-21',
            'user_id' => $staff->id,
            'shift_date' => '2026-09-21',
            'day' => 'MON',
            'starts_at' => 8 * 60,
            'ends_at' => 16 * 60,
            'classroom' => 'Toddler',
            'role' => StaffShift::ROLE_STAFF,
        ]);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringContainsString('title="Late', $html);
    }

    public function test_a_day_nobody_worked_carries_no_dot(): void
    {
        // A day off is not an exception, and a mark against it would read as
        // one.
        $this->staff('Sofia Alvarez');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringNotContainsString('title="On time"', $html);
        $this->assertStringNotContainsString('title="Late', $html);
    }

    public function test_the_counters_describe_today_only(): void
    {
        $in = $this->staff('Still Here');
        $out = $this->staff('Gone Home');
        $this->staff('Not In Yet');

        $this->punch($in, '2026-09-23 07:00', TimePunch::IN);
        $this->punch($out, '2026-09-23 07:00', TimePunch::IN);
        $this->punch($out, '2026-09-23 09:30', TimePunch::OUT);

        // Monday's punches must not count towards today's numbers.
        $this->punch($in, '2026-09-21 07:00', TimePunch::IN);
        $this->punch($in, '2026-09-21 15:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('>1</span> clocked in', $html);
        $this->assertStringContainsString('>1</span> clocked out', $html);
        // The admin themselves has punched nothing, so two are "not in".
        $this->assertStringContainsString('>2</span> not in', $html);
    }

    public function test_any_range_can_be_asked_for(): void
    {
        $staff = $this->staff('Rachel Kim');

        $this->punch($staff, '2026-09-14 07:52', TimePunch::IN);
        $this->punch($staff, '2026-09-14 16:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-09-14', 'to' => '2026-09-18']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('7:52 AM', $html);
        $this->assertStringContainsString('Sep 14', $html);
        $this->assertStringContainsString('Sep 18', $html);
    }

    public function test_a_range_given_backwards_is_swapped_rather_than_refused(): void
    {
        // Dragging right to left across a calendar is a way of choosing a
        // range, not a mistake to be told about.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-09-18', 'to' => '2026-09-14']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sep 14', $html);
        $this->assertStringContainsString('Sep 18', $html);
    }

    public function test_a_range_longer_than_the_cap_is_cut_and_said_so(): void
    {
        // A column a day: a year of them is a table nobody can read, and a
        // query per person per day that nobody wants to run.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('longer than '.StaffTimesheetController::MAX_DAYS.' days', $html);
        $this->assertStringContainsString('Jan 31', $html);
        $this->assertStringNotContainsString('Feb 1', $html);
    }

    public function test_with_no_range_it_opens_on_this_week(): void
    {
        // What somebody opening the page each morning wants.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        // The Monday and Friday of the week holding Wednesday 23 September.
        $this->assertStringContainsString('Sep 21', $html);
        $this->assertStringContainsString('Sep 25', $html);
    }

    public function test_an_invented_date_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => 'whenever', 'to' => '2026-09-14']))
            ->assertSessionHasErrors('from');
    }

    public function test_the_range_is_chosen_by_name_or_by_calendar(): void
    {
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        // The named periods, which is how most ranges are actually asked for.
        foreach (['Today', 'Yesterday', 'Last 7 Days', 'Last 30 Days', 'This Month', 'Last Month'] as $preset) {
            $this->assertStringContainsString($preset, $html);
        }

        // And two months of calendar beside them, because nearly every range
        // anybody asks for crosses a month boundary.
        $this->assertStringContainsString('dateRange(', $html);
        $this->assertStringContainsString('monthCells(offset)', $html);
    }

    public function test_the_open_calendar_sits_above_the_grid(): void
    {
        /*
         * A real bug, and an invisible one to anything but the eye.
         *
         * .glass-card carries backdrop-blur, and a backdrop-filter creates a
         * stacking context — so the panel's own z-40 only competed inside its
         * own card. The grid below is a sibling card at the same automatic
         * level, and being later in the document it painted straight over an
         * open calendar, which read as the picker being broken.
         */
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('glass-card relative z-20', $html, 'the filter card must outrank the grid below it');
    }

    public function test_the_grid_never_offers_to_edit_a_punch(): void
    {
        // Corrections go through the punch screens, which record who made them
        // and why. A grid somebody can type into has no audit behind it.
        $staff = $this->staff('Rachel Kim');
        $this->punch($staff, '2026-09-23 07:52', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        // Scoped to the grid rather than the page: the layout around it has a
        // sign-out form and this screen has a search box, and neither is a way
        // to alter a punch.
        $body = substr($html, (int) strpos($html, '<tbody'), (int) strpos($html, '</tbody>') - (int) strpos($html, '<tbody'));

        $this->assertStringNotContainsString('<form', $body);
        $this->assertStringNotContainsString('<input', $body);
        $this->assertStringNotContainsString('<button', $body);

        // It does point at the screen that can, though. The grid's whole job is
        // to show what did not go in properly, and until this said where to put
        // that right, the answer was a sentence nobody read.
        $this->assertStringContainsString('Click an amber or red dot', $html);
    }

    public function test_a_flagged_day_leads_to_the_screen_that_fixes_it(): void
    {
        /*
         * The dot is what the eye lands on, so the dot is what takes somebody
         * there. Only the ones that mean something are links: making every
         * cell one would bury the handful that need it.
         */
        $staff = $this->staff('Patrick Ortiz');

        // Monday, in and never out: the amber dot.
        $this->punch($staff, '2026-09-21 08:12', TimePunch::IN);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString(
            route('timesheets.fix', ['user' => $staff->id, 'date' => '2026-09-21']),
            $html,
        );

        // A day that went fine stays a dot and nothing more.
        $this->punch($staff, '2026-09-22 08:00', TimePunch::IN);
        $this->punch($staff, '2026-09-22 16:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            route('timesheets.fix', ['user' => $staff->id, 'date' => '2026-09-22']),
            $html,
        );
    }

    public function test_the_correction_screen_can_be_found_by_person_and_day(): void
    {
        // The grid knows who and when, and nothing about which fortnight a
        // Tuesday falls in. This works that out and hands over — creating the
        // period if the fortnight has not been opened yet, which is the usual
        // case when the thing being fixed happened this week.
        $staff = $this->staff('Rachel Kim');

        $this->actingAs($this->admin)
            ->get(route('timesheets.fix', ['user' => $staff->id, 'date' => '2026-09-21']))
            ->assertRedirectContains('/day/2026-09-21');
    }

    private function staff(string $name): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => $name, 'title' => 'Lead Teacher', 'pay_rate' => 24]);
    }

    private function punch(User $staff, string $at, string $type): void
    {
        app(TimeClock::class)->punch($staff, $type, Carbon::parse($at));
    }
    /**
     * "And the week before that" is the commonest thing to want from a range,
     * and it used to mean opening the calendar and counting.
     */
    public function test_the_range_can_be_stepped_without_opening_the_calendar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('shift(-1)', $html);
        $this->assertStringContainsString('shift(1)', $html);

        // Named by where they land, not "previous" and "next", so somebody on
        // a screen reader is told the date rather than the direction.
        $this->assertStringContainsString("'Earlier: ' + shiftedLabel(-1)", $html);
        $this->assertStringContainsString("'Later: ' + shiftedLabel(1)", $html);
    }

    public function test_this_week_is_one_press_from_wherever_the_range_wandered(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-03-02', 'to' => '2026-03-06']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>This week</a>', $html);
    }
}