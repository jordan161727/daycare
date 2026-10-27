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

        // Eight hours thirteen, as hours and minutes, across the whole week.
        $this->assertStringContainsString('8h 13m', $html);
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

    public function test_the_live_list_describes_today_only(): void
    {
        /*
         * The morning question, answered per person: on shift, done for the
         * day, late, due later, or off — read from today's punches against
         * today's shifts, the way the dot on the Staff page is.
         */
        $in = $this->staff('Still Here');
        $out = $this->staff('Gone Home');
        $late = $this->staff('Not In Yet');
        $due = $this->staff('Due Later');
        $this->staff('Day Off');

        $this->punch($in, '2026-09-23 07:00', TimePunch::IN);
        $this->punch($out, '2026-09-23 07:00', TimePunch::IN);
        $this->punch($out, '2026-09-23 09:30', TimePunch::OUT);

        // Due at eight, it is ten, and the clock has not seen them: late.
        $this->shift($late, '2026-09-23', 8 * 60, 16 * 60);
        // Due at noon: just not here yet.
        $this->shift($due, '2026-09-23', 12 * 60, 18 * 60);

        // Monday's punches must not count towards today's numbers.
        $this->punch($in, '2026-09-21 07:00', TimePunch::IN);
        $this->punch($in, '2026-09-21 15:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('data-live-row="'.$in->id.'" data-live-key="in"', $html);
        $this->assertStringContainsString('data-live-row="'.$out->id.'" data-live-key="done"', $html);
        $this->assertStringContainsString('data-live-row="'.$late->id.'" data-live-key="late"', $html);
        $this->assertStringContainsString('data-live-row="'.$due->id.'" data-live-key="due"', $html);

        $this->assertStringContainsString('On shift', $html);
        $this->assertStringContainsString('Done for today', $html);
        $this->assertStringContainsString('Not in · due 8:00 AM', $html);
        $this->assertStringContainsString('Due 12:00 PM', $html);

        // The cards: one in, of three whose shift has started (in, done, late).
        $this->assertStringContainsString('1 <small>of 3 due by now</small>', $html);
        // Due later: one. Off: Day Off alone — the director, with no shift
        // and no punch today, is not a row (26 Oct 2026).
        $this->assertStringContainsString('Due later 1', $html);
        $this->assertStringContainsString('Off 1', $html);
        $this->assertStringNotContainsString('data-live-row="'.$this->admin->id.'"', $html);
    }

    public function test_a_scheduled_day_gone_by_with_no_punch_at_all_is_a_forgotten_clock_in(): void
    {
        // Rostered Monday, never punched, and it is Wednesday. Not a day off:
        // somebody was expected and the clock has nothing. It is red, it is
        // counted as a day to fix, and it opens the panel so the times can be
        // typed in.
        $staff = $this->staff('Patrick Ortiz');
        $this->shift($staff, '2026-09-21', 8 * 60, 16 * 60);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('Forgot to clock in', $html);
        $this->assertStringContainsString('title="Missing clock-in', $html);
        $this->assertStringContainsString('1 to fix', $html);
        $this->assertStringContainsString(route('timesheets.fix', ['user' => $staff->id, 'date' => '2026-09-21']), $html);

        // Without a roster the same empty Monday is simply a day off.
        $other = $this->staff('Sofia Alvarez');
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('timesheets.fix', ['user' => $other->id, 'date' => '2026-09-21']), $html);
    }

    public function test_late_allows_the_grace_the_staff_page_allows(): void
    {
        // Five minutes, the same as the Staff page's attendance tab, so the
        // two screens never disagree about whether somebody was late.
        $staff = $this->staff('Devon Brooks');
        $this->shift($staff, '2026-09-21', 8 * 60, 16 * 60);

        $this->punch($staff, '2026-09-21 08:04', TimePunch::IN);
        $this->punch($staff, '2026-09-21 16:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('title="Late', $html);
        $this->assertStringContainsString('0 late', $html);
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

    public function test_the_week_is_stepped_with_the_arrows_beside_its_name(): void
    {
        // "And the week before that" is the commonest thing to want, and the
        // mock-up gives it two arrows around the week's name. Each is a plain
        // link to the week it lands on, and both keep History open.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('Week of Sep 21 – 25', $html);
        $this->assertStringContainsString(e(route('staff.timesheets', ['view' => 'hist', 'from' => '2026-09-14', 'to' => '2026-09-18'])), $html);
        $this->assertStringContainsString(e(route('staff.timesheets', ['view' => 'hist', 'from' => '2026-09-28', 'to' => '2026-10-02'])), $html);

        // A week across a month boundary names both months.
        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-09-28', 'to' => '2026-10-02']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Week of Sep 28 – Oct 2', $html);
    }

    public function test_live_is_the_front_page_and_history_is_where_a_named_week_lands(): void
    {
        // Opened from the menu, it is the morning's list. Opened with a week
        // or a day in the address — the arrows, a report row — it is the grid.
        $this->staff('Rachel Kim');

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringContainsString('data-view="live"', $html);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets', ['from' => '2026-09-14', 'to' => '2026-09-18']))->assertOk()->getContent();
        $this->assertStringContainsString('data-view="hist"', $html);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets', ['view' => 'hist']))->assertOk()->getContent();
        $this->assertStringContainsString('data-view="hist"', $html);
    }

    public function test_the_week_lists_the_corrections_already_made_to_it(): void
    {
        // Under the panel, so the director sees what has already been put
        // right before opening anything — and whose name is on it.
        $staff = $this->staff('Rachel Kim');
        $this->punch($staff, '2026-09-21 07:52', TimePunch::IN);

        $this->actingAs($this->admin)->postJson(route('timesheets.panel', ['user' => $staff->id, 'date' => '2026-09-21']), [
            'reason' => 'forgot_out',
            'changes' => [['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00']],
        ])->assertOk();

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('Clocked out missing', $html);
        $this->assertStringContainsString('4:00 pm', $html);
        $this->assertStringContainsString('Forgot to clock out', $html);
        $this->assertStringContainsString('1 corrected', $html);
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
        $this->assertStringContainsString('Amber and red days need a fix', $html);
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

        // A day that went fine leads through as well, to be looked at rather
        // than put right — the edit panel opens on any dot, and a green one
        // opens it read-only in spirit: nothing needs changing, but the day is
        // there to review. What it says on the way in is the difference.
        $this->punch($staff, '2026-09-22 08:00', TimePunch::IN);
        $this->punch($staff, '2026-09-22 16:00', TimePunch::OUT);

        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();

        $this->assertStringContainsString('to review', $html);
        $this->assertStringContainsString(
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
    private function shift(User $staff, string $date, int $start, int $end): StaffShift
    {
        return StaffShift::create([
            'week_start' => Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'user_id' => $staff->id,
            'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')),
            'starts_at' => $start,
            'ends_at' => $end,
            'classroom' => 'Toddler',
            'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    public function test_this_week_is_one_press_from_wherever_the_range_wandered(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-03-02', 'to' => '2026-03-06']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>This week</a>', $html);

        // And not offered when the week on screen already is this week.
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('>This week</a>', $html);
    }
}