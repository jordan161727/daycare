<?php

namespace Tests\Feature;

use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\PayPeriod;
use App\Services\TimeClock;
use App\Services\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimeClockTest extends TestCase
{
    use RefreshDatabase;

    /** The second half of August 2026, the same period the timesheet tests use. */
    private const PERIOD = '2026-08-16';

    /** A Thursday inside it, with days behind it to have gone wrong on. */
    private const TODAY = '2026-08-20';

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // The clock is off by default this version; these are its own tests.
        config()->set('daycare.timesheet.clock.enabled', true);

        $this->travelTo(Carbon::parse(self::TODAY.' 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->teacher = User::create([
            'name' => 'Maria Santos', 'email' => 'maria@example.com',
            'password' => 'password', 'role' => 'teacher',
            'employment' => 'FT', 'classroom' => 'Toddler', 'pay_rate' => 20,
        ]);
    }

    // ---------------- punching the clock ----------------

    public function test_a_teacher_punches_in_and_out_and_the_day_reaches_the_timesheet(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $entry = TimesheetEntry::first();

        $this->assertSame(7 * 60, $entry->starts_at);
        $this->assertSame(15 * 60, $entry->ends_at);
        $this->assertSame(8 * 60, $entry->workedMinutes());
        $this->assertTrue($entry->isFromClock());
    }

    /**
     * The only reason lunch and break are different buttons.
     *
     * A meal period is not hours worked; a short rest break is. Collapsing the
     * two into one "away" punch would either pay for lunch or dock the tea
     * break, and both are payroll errors rather than rounding ones.
     */
    public function test_lunch_is_unpaid_and_a_short_break_is_paid(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('10:00', TimePunch::BREAK_START);
        $this->punch('10:15', TimePunch::BREAK_END);       // 15 min, paid
        $this->punch('12:00', TimePunch::LUNCH_START);
        $this->punch('12:30', TimePunch::LUNCH_END);       // 30 min, unpaid
        $this->punch('15:30', TimePunch::OUT);

        $day = app(TimeClock::class)->day($this->teacher->id, self::TODAY);

        $this->assertSame(15, $day['paid_break']);
        $this->assertSame(30, $day['unpaid_break']);
        // 07:00 to 15:30 is eight and a half hours; only the lunch comes off.
        $this->assertSame(8 * 60, $day['worked']);
        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
    }

    public function test_a_break_that_overruns_is_paid_only_to_the_cap(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('10:00', TimePunch::BREAK_START);
        $this->punch('10:45', TimePunch::BREAK_END);        // 45 min "break"
        $this->punch('15:00', TimePunch::OUT);

        $day = app(TimeClock::class)->day($this->teacher->id, self::TODAY);

        $cap = (int) config('daycare.timesheet.clock.paid_break_cap');

        $this->assertSame($cap, $day['paid_break']);
        $this->assertSame(45 - $cap, $day['unpaid_break'], 'past the cap it has stopped being a rest break');
        $this->assertSame(8 * 60 - (45 - $cap), $day['worked']);
    }

    /** Two shifts in a day, with the gap between them unpaid. */
    public function test_a_split_shift_on_the_clock_is_one_day_with_the_gap_as_break(): void
    {
        $this->punch('09:00', TimePunch::IN);
        $this->punch('12:00', TimePunch::OUT);
        $this->punch('13:00', TimePunch::IN);
        $this->punch('17:00', TimePunch::OUT);

        $entry = TimesheetEntry::first();

        $this->assertSame(9 * 60, $entry->starts_at);
        $this->assertSame(17 * 60, $entry->ends_at);
        $this->assertSame(60, $entry->break_minutes, 'the hour in the middle');
        $this->assertSame(7 * 60, $entry->workedMinutes());
    }

    // ---------------- what the clock will not let you do ----------------

    public function test_the_clock_offers_only_the_punches_legal_from_where_somebody_stands(): void
    {
        $clock = app(TimeClock::class);

        $this->assertSame([TimePunch::IN], TimeClock::NEXT[$clock->state($this->teacher, self::TODAY)]);

        $this->punch('07:00', TimePunch::IN);
        $this->assertSame(TimeClock::WORKING, $clock->state($this->teacher, self::TODAY));

        $this->punch('12:00', TimePunch::LUNCH_START);
        $this->assertSame([TimePunch::LUNCH_END], TimeClock::NEXT[$clock->state($this->teacher, self::TODAY)]);
    }

    public function test_an_impossible_punch_is_refused_rather_than_recorded(): void
    {
        // Clocking out having never clocked in — a stale page, not an intention.
        $this->actingAs($this->teacher)
            ->post(route('clock.punch'), ['type' => TimePunch::OUT])
            ->assertSessionHas('warning');

        $this->assertSame(0, TimePunch::count());
    }

    public function test_the_button_records_the_punch(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('clock.punch'), ['type' => TimePunch::IN])
            ->assertSessionHas('success');

        $punch = TimePunch::first();

        $this->assertSame(TimePunch::IN, $punch->type);
        $this->assertSame(TimePunch::SOURCE_CLOCK, $punch->source);
        $this->assertSame($this->teacher->id, $punch->recorded_by, 'they punched it themselves');
    }

    // ---------------- days that do not add up ----------------

    /**
     * The failure a time clock actually has, as against the one it fixes.
     *
     * Paying until midnight, or until their rostered end, would be inventing
     * hours nobody worked out of a button nobody pressed.
     */
    public function test_a_day_with_no_clock_out_is_worth_nothing_rather_than_a_guess(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');    // and nothing else

        $this->assertSame(0, TimesheetEntry::count(), 'no hours were guessed at');

        $problems = app(TimeClock::class)->exceptions(PayPeriod::containing(self::PERIOD));

        $this->assertSame(['never clocked out'], $problems[$this->teacher->id]['2026-08-18']);
    }

    /** Still on the clock at nine in the morning is not a missing punch. */
    public function test_being_clocked_in_today_is_not_an_exception(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $this->assertSame([], app(TimeClock::class)->exceptions(PayPeriod::containing(self::PERIOD)));
    }

    public function test_clocking_in_twice_is_reported_rather_than_added_up(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('09:00', TimePunch::IN, '2026-08-18');
        $this->punch('15:00', TimePunch::OUT, '2026-08-18');

        $problems = app(TimeClock::class)->exceptions(PayPeriod::containing(self::PERIOD));

        $this->assertNotEmpty($problems[$this->teacher->id]['2026-08-18']);
        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_an_impossibly_long_day_is_flagged_rather_than_paid_quietly(): void
    {
        $this->punch('05:00', TimePunch::IN, '2026-08-18');
        $this->punch('22:00', TimePunch::OUT, '2026-08-18');

        // The hours are real until somebody says otherwise — this is a flag,
        // not a cap — but nobody is paid seventeen hours without being asked.
        $this->assertSame(17 * 60, TimesheetEntry::first()->workedMinutes());

        $problems = app(TimeClock::class)->exceptions(PayPeriod::containing(self::PERIOD));

        $this->assertStringContainsString('longer than', $problems[$this->teacher->id]['2026-08-18'][0]);
    }

    public function test_a_day_that_does_not_add_up_stops_the_period_being_approved(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('15:00', TimePunch::OUT, '2026-08-19');   // a different day's hours
        $this->punch('07:00', TimePunch::IN, '2026-08-19');

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('warning');

        $this->assertFalse($period->fresh()->isApproved());
    }

    // ---------------- the supervisor putting it right ----------------

    public function test_a_supervisor_fills_in_the_missing_punch_and_the_day_pays(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.day.punch', ['period' => $period, 'user' => $this->teacher, 'date' => '2026-08-18']), [
                'type' => TimePunch::OUT,
                'at' => '15:00',
                'reason' => 'Forgot to clock out — confirmed with the room lead',
            ])
            ->assertSessionHas('success');

        $entry = TimesheetEntry::first();

        $this->assertSame(8 * 60, $entry->workedMinutes());
        $this->assertSame([], app(TimeClock::class)->exceptions(PayPeriod::containing(self::PERIOD)));

        $added = TimePunch::where('type', TimePunch::OUT)->first();
        $this->assertSame(TimePunch::SOURCE_SUPERVISOR, $added->source);
        $this->assertSame($this->admin->id, $added->recorded_by);
        $this->assertStringContainsString('Forgot to clock out', $added->reason);
    }

    /**
     * The whole point of the audit trail: the original never stops being there.
     *
     * A punch that had simply been updated in place would be indistinguishable
     * from one nobody ever questioned.
     */
    public function test_a_correction_voids_the_original_rather_than_editing_it(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('19:00', TimePunch::OUT, '2026-08-18');

        $wrong = TimePunch::where('type', TimePunch::OUT)->first();
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $wrong]), [
                'action' => 'correct',
                'at' => '15:00',
                'reason' => 'Clocked out for the closing teacher by mistake',
            ])
            ->assertSessionHas('success');

        $wrong->refresh();

        $this->assertTrue($wrong->isVoided());
        $this->assertSame($this->admin->id, $wrong->voided_by);
        $this->assertStringContainsString('closing teacher', $wrong->void_reason);
        $this->assertSame(19 * 60, $wrong->minutes(), 'the original still says 19:00');

        $replacement = TimePunch::live()->where('type', TimePunch::OUT)->first();

        $this->assertSame(15 * 60, $replacement->minutes());
        $this->assertSame($wrong->id, $replacement->corrects_id, 'the chain reads back to what it replaced');
        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
    }

    public function test_voiding_a_punch_keeps_it_on_the_day(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('07:02', TimePunch::OUT);
        $this->punch('07:03', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        // The two-minute out-and-back in the middle: both halves of it go.
        $period = TimesheetPeriod::forDate(self::PERIOD);

        foreach (TimePunch::whereIn('id', [2, 3])->get() as $stray) {
            $this->actingAs($this->admin)
                ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $stray]), [
                    'action' => 'void',
                    'reason' => 'Pressed the wrong button, straight back in',
                ]);
        }

        $this->assertSame(4, TimePunch::count(), 'nothing is ever deleted');
        $this->assertSame(2, TimePunch::live()->count());

        // Rebuilt from what is left, so the day is worth what it would have
        // been if the wrong button had never been pressed.
        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $punch = TimePunch::first();
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $punch]), [
                'action' => 'void',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($punch->fresh()->isVoided());
    }

    public function test_voiding_every_punch_leaves_the_day_as_if_it_never_happened(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('15:00', TimePunch::OUT, '2026-08-18');

        $this->assertSame(1, TimesheetEntry::count());

        $clock = app(TimeClock::class);

        foreach (TimePunch::all() as $punch) {
            $clock->void($punch, $this->admin, 'Clocked in on the wrong account');
        }

        $this->assertSame(0, TimesheetEntry::count(), 'rebuilt from nothing, so it says nothing');
        $this->assertSame(2, TimePunch::count(), 'and the punches are still on the record');
    }

    public function test_a_teacher_cannot_correct_their_own_punches(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $punch = TimePunch::first();
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->teacher)
            ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $punch]), [
                'action' => 'void',
                'reason' => 'I would rather this said something else',
            ])
            ->assertForbidden();

        $this->assertFalse($punch->fresh()->isVoided());
    }

    // ---------------- where the clock sits against everything else ----------------

    /** A punch is better evidence than the roster's guess, so it wins. */
    public function test_the_clock_overwrites_the_rosters_word(): void
    {
        StaffShift::create([
            'week_start' => Carbon::parse(self::TODAY)->startOfWeek(Carbon::MONDAY)->toDateString(),
            'user_id' => $this->teacher->id,
            'shift_date' => self::TODAY,
            'day' => 'THU',
            'starts_at' => 7 * 60,
            'ends_at' => 15 * 60,
            'classroom' => 'Toddler',
        ]);

        app(Timesheet::class)->seed(TimesheetPeriod::forDate(self::PERIOD));

        $this->assertSame(TimesheetEntry::SOURCE_SCHEDULE, TimesheetEntry::first()->source);

        $this->punch('07:20', TimePunch::IN);
        $this->punch('16:00', TimePunch::OUT);

        $entry = TimesheetEntry::first();

        $this->assertTrue($entry->isFromClock());
        $this->assertSame(7 * 60 + 20, $entry->starts_at, 'what happened, not what was planned');
    }

    /** But it does not win against somebody who looked at the day and said so. */
    public function test_the_clock_does_not_overwrite_a_day_somebody_typed(): void
    {
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $this->teacher]), [
                'days' => [self::TODAY => ['starts_at' => '07:00', 'ends_at' => '15:00', 'note' => 'Agreed with Maria']],
            ]);

        $this->punch('11:00', TimePunch::IN);
        $this->punch('12:00', TimePunch::OUT);

        $entry = TimesheetEntry::first();

        $this->assertTrue($entry->isConfirmed());
        $this->assertSame(8 * 60, $entry->workedMinutes(), 'the person who looked at it still wins');
        $this->assertSame($this->admin->id, $entry->confirmed_by);
    }

    /** A supervisor correcting a punch is acting on the day deliberately. */
    public function test_a_supervisor_correction_overrules_a_hand_typed_day(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->put(route('timesheets.update', ['period' => $period, 'user' => $this->teacher]), [
                'days' => [self::TODAY => ['starts_at' => '07:00', 'ends_at' => '17:00']],
            ]);

        $this->assertSame(10 * 60, TimesheetEntry::first()->workedMinutes());

        $out = TimePunch::live()->where('type', TimePunch::OUT)->first();

        $this->actingAs($this->admin)
            ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $out]), [
                'action' => 'correct',
                'at' => '16:00',
                'reason' => 'CCTV shows her leaving at four',
            ]);

        $entry = TimesheetEntry::first();

        $this->assertTrue($entry->isFromClock());
        $this->assertSame(9 * 60, $entry->workedMinutes());
        $this->assertNull($entry->confirmed_by, 'it is the clock speaking again, not the earlier note');
    }

    /**
     * A punched day is the employee's own account of it, so it is not the
     * guess the confirm step exists to catch.
     */
    public function test_a_punched_day_does_not_have_to_be_confirmed_before_approval(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('15:00', TimePunch::OUT, '2026-08-18');

        $this->assertFalse(TimesheetEntry::first()->needsConfirming());

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->post(route('timesheets.approve', $period))
            ->assertSessionHas('success');

        $this->assertTrue($period->fresh()->isApproved());
    }

    public function test_punches_never_change_an_approved_period(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('15:00', TimePunch::OUT, '2026-08-18');

        $this->travelTo(Carbon::parse('2026-09-02 09:00:00'));

        $period = TimesheetPeriod::forDate(self::PERIOD);
        $this->actingAs($this->admin)->post(route('timesheets.approve', $period));

        $out = TimePunch::live()->where('type', TimePunch::OUT)->first();

        $this->actingAs($this->admin)
            ->post(route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $out]), [
                'action' => 'correct',
                'at' => '19:00',
                'reason' => 'Trying it on after payday',
            ])
            ->assertSessionHas('warning');

        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
        $this->assertFalse($out->fresh()->isVoided());
    }

    public function test_punched_hours_count_towards_overtime_like_any_other(): void
    {
        // Mon 17th to Fri 21st, nine hours a day on the clock — 45 in the week.
        foreach (['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21'] as $date) {
            $this->punch('07:00', TimePunch::IN, $date);
            $this->punch('16:00', TimePunch::OUT, $date);
        }

        $line = app(Timesheet::class)
            ->summary(TimesheetPeriod::forDate(self::PERIOD))
            ->firstWhere('user.id', $this->teacher->id);

        $this->assertSame(40.0, $line['regular_hours']);
        $this->assertSame(5.0, $line['overtime_hours']);
    }

    // ---------------- who sees what ----------------

    public function test_a_teacher_sees_their_own_clock(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $this->actingAs($this->teacher)
            ->get(route('clock.index'))
            ->assertOk()
            ->assertSee('On the clock')
            ->assertSee('Start lunch');
    }

    public function test_a_teacher_cannot_reach_another_persons_punches(): void
    {
        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->teacher)
            ->get(route('timesheets.day', ['period' => $period, 'user' => $this->teacher, 'date' => self::TODAY]))
            ->assertForbidden();
    }

    public function test_the_export_says_how_many_days_are_still_unresolved(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');    // and never out

        $rows = app(Timesheet::class)->exportRows(TimesheetPeriod::forDate(self::PERIOD));

        $this->assertSame('Unresolved punch days', $rows[0][14]);
        $this->assertSame('Maria Santos', $rows[1][0], 'no payable hours, and in the file anyway');
        $this->assertSame('0.00', $rows[1][10], 'nothing was guessed at');
        $this->assertSame('1', $rows[1][14]);
    }

    public function test_the_grid_marks_the_day_that_does_not_add_up(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');

        $this->actingAs($this->admin)
            ->get(route('timesheets.index', ['date' => self::PERIOD]))
            ->assertOk()
            ->assertSee('1 day(s) of punches that do not add up')
            ->assertSee('Maria Santos', 'somebody with no payable hours at all is still on the grid');
    }

    public function test_the_day_form_shows_the_clocks_own_account_of_each_day(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $period = TimesheetPeriod::forDate(self::PERIOD);

        $this->actingAs($this->admin)
            ->get(route('timesheets.edit', ['period' => $period, 'user' => $this->teacher]))
            ->assertOk()
            ->assertSee('Clock')
            ->assertSee('8.00');
    }

    public function test_the_day_screen_shows_the_punches_and_why_they_were_changed(): void
    {
        $this->punch('07:00', TimePunch::IN, '2026-08-18');
        $this->punch('19:00', TimePunch::OUT, '2026-08-18');

        $period = TimesheetPeriod::forDate(self::PERIOD);
        $wrong = TimePunch::where('type', TimePunch::OUT)->first();

        $this->actingAs($this->admin)->post(
            route('timesheets.punch.amend', ['period' => $period, 'user' => $this->teacher, 'punch' => $wrong]),
            ['action' => 'correct', 'at' => '15:00', 'reason' => 'Clocked out for the closing teacher'],
        );

        $this->actingAs($this->admin)
            ->get(route('timesheets.day', ['period' => $period, 'user' => $this->teacher, 'date' => '2026-08-18']))
            ->assertOk()
            ->assertSee('7:00 pm')                             // the original, struck through
            ->assertSee('3:00 pm')                             // and what replaced it
            ->assertSee('Clocked out for the closing teacher')  // and why
            ->assertSee('Director');                            // and who
    }

    // ---------------- helpers ----------------

    /** A punch as the employee themselves, at a wall-clock time on a day. */
    private function punch(string $time, string $type, ?string $date = null): TimePunch
    {
        return app(TimeClock::class)->punch(
            user: $this->teacher,
            type: $type,
            at: Carbon::parse(($date ?? self::TODAY).' '.$time.':00'),
        );
    }
}
