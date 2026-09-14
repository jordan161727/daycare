<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\HolidayRule;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\HolidayCalendar;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Holidays set from the admin side, and the attendance schedule closing itself
 * for them — including for weeks that do not exist when the holiday is entered.
 */
class HolidayTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27';

    private const WEDNESDAY = '2026-07-29';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A week is only editable until its Friday has passed, so the test has
        // to stand inside the week it is closing days in.
        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_only_an_admin_reaches_the_holidays_page(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk();
        $this->actingAs($teacher)->get(route('holidays.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Not theirs to set',
        ])->assertForbidden();

        $this->assertDatabaseCount('closure_days', 0);
    }

    public function test_setting_a_holiday_closes_that_day_for_every_room(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $this->makeChild('Turing', 'Alan', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)
            ->post(route('holidays.store'), [
                'closed_on' => self::WEDNESDAY,
                'reason' => 'Independence Day',
            ])
            ->assertRedirect(route('holidays.index'));

        $this->assertDatabaseHas('closure_days', [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
            'created_by' => $this->admin->id,
        ]);

        // Both children, both rooms, in one move — and only that day.
        $this->assertSame(0, ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('is_scheduled', true)->count());
        $this->assertSame(8, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
    }

    public function test_the_holiday_shows_on_the_attendance_board(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::MONDAY]))
            ->assertOk()
            ->assertSee('Independence Day');
    }

    public function test_a_child_cannot_be_ticked_onto_a_holiday(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.schedule.update'), [
                'week_start' => self::MONDAY,
                'is_scheduled' => true,
                'slots' => [['child_id' => $child->id, 'slot_date' => self::WEDNESDAY, 'session' => 'FULL']],
            ])
            ->assertOk()
            ->assertJson(['updated' => 0]);

        $this->assertSame(0, ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('is_scheduled', true)->count());
    }

    public function test_a_holiday_set_before_the_week_exists_closes_it_when_it_is_built(): void
    {
        // Registered for the full week: opening no longer copies the week
        // before, so the five ticks the closure takes one of come from here.
        $this->makeChild('Lovelace', 'Ada', 'Toddler')->forceFill(['schedule_days' => [1, 2, 3, 4, 5]])->save();

        // This week is set up as the pattern every later week copies from.
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $futureMonday = '2026-08-10';
        $futureWednesday = '2026-08-12';

        // The holiday is entered while that week is still nothing at all.
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => $futureWednesday,
            'reason' => 'Staff training',
        ]);

        $this->assertDatabaseMissing('schedule_weeks', ['week_start' => $futureMonday]);

        // Building the week later must not inherit the normal week's ticks on
        // the closed day — this is the whole point of setting one in advance.
        app(WeekSchedule::class)->open($futureMonday);

        $this->assertSame(0, ScheduleSlot::where('slot_date', $futureWednesday)->where('is_scheduled', true)->count());
        $this->assertSame(4, ScheduleSlot::where('week_start', $futureMonday)->where('is_scheduled', true)->count());
    }

    public function test_a_range_closes_the_weekdays_in_it_and_skips_the_weekend(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        // Thursday to the following Tuesday: five weekdays, two weekend days.
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => '2026-07-30',
            'ends_on' => '2026-08-04',
            'reason' => 'Summer break',
        ])->assertRedirect(route('holidays.index'));

        $this->assertSame(
            ['2026-07-30', '2026-07-31', '2026-08-03', '2026-08-04'],
            ClosureDay::orderBy('closed_on')->pluck('closed_on')->map->toDateString()->all()
        );

        // Thursday and Friday of the open week lost their ticks; Mon–Wed kept theirs.
        $this->assertSame(3, ScheduleSlot::where('week_start', self::MONDAY)->where('is_scheduled', true)->count());
    }

    public function test_a_holiday_cannot_be_set_in_a_week_that_has_already_ended(): void
    {
        $lastWednesday = '2026-07-22';

        $this->actingAs($this->admin)
            ->post(route('holidays.store'), ['closed_on' => $lastWednesday, 'reason' => 'Too late'])
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('closure_days', 0);
    }


    public function test_reopening_a_day_puts_the_cleared_ticks_back(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $alan = $this->makeChild('Turing', 'Alan', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Ada comes on Wednesday, Alan does not. That difference is exactly what
        // reopening has to preserve — an untouched restore would tick both.
        ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $ada->id)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        $this->assertSame(0, ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('is_scheduled', true)->count());

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);
        $this->assertSame(1, $holiday->clearedCount());

        $this->actingAs($this->admin)
            ->delete(route('holidays.destroy', $holiday))
            ->assertRedirect(route('holidays.index'));

        $this->assertDatabaseCount('closure_days', 0);
        $this->assertTrue(ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $ada->id)->value('is_scheduled'));
        $this->assertFalse((bool) ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $alan->id)->value('is_scheduled'));
    }

    public function test_reopening_a_day_nobody_was_scheduled_on_restores_nothing(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);

        $this->actingAs($this->admin)->delete(route('holidays.destroy', $holiday));

        $this->assertSame(0, ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('is_scheduled', true)->count());
    }

    public function test_the_board_toggle_also_restores_what_it_cleared(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        // The snow-day toggle on the attendance board writes the same closure,
        // so the undo has to work from there too.
        $this->actingAs($this->admin)->postJson(route('attendance.schedule.closure'), [
            'date' => self::WEDNESDAY, 'closed' => true, 'reason' => 'Snow day',
        ])->assertJson(['cleared' => 1, 'restored' => 0]);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.closure'), [
            'date' => self::WEDNESDAY, 'closed' => false,
        ])->assertJson(['cleared' => 0, 'restored' => 1]);

        $this->assertTrue((bool) ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $ada->id)->value('is_scheduled'));
    }

    public function test_an_annual_holiday_closes_that_date_for_years_ahead(): void
    {
        $this->actingAs($this->admin)
            ->post(route('holidays.rules.store'), ['month' => 12, 'day' => 25, 'reason' => 'Christmas Day'])
            ->assertRedirect(route('holidays.index'));

        $rule = HolidayRule::firstWhere('reason', 'Christmas Day');
        $this->assertSame(2026 + HolidayCalendar::HORIZON_YEARS, $rule->materialised_through);

        // Every year to the horizon except 2027, when Christmas is a Saturday
        // and there is no school day to close.
        $this->assertSame(
            ['2026-12-25', '2028-12-25', '2029-12-25', '2030-12-25', '2031-12-25'],
            ClosureDay::orderBy('closed_on')->pluck('closed_on')->map->toDateString()->all()
        );

        $this->assertSame(5, ClosureDay::whereNotNull('holiday_rule_id')->count());
    }

    public function test_an_annual_holiday_closes_a_week_built_years_later(): void
    {
        // Registered for the full week: opening no longer copies the week
        // before, so the five ticks the closure takes one of come from here.
        $this->makeChild('Lovelace', 'Ada', 'Toddler')->forceFill(['schedule_days' => [1, 2, 3, 4, 5]])->save();
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.rules.store'), [
            'month' => 12, 'day' => 25, 'reason' => 'Christmas Day',
        ]);

        // Christmas 2028 is a Monday. Its week is built two years after the rule
        // was entered and must still come out closed.
        app(WeekSchedule::class)->open('2028-12-25');

        $this->assertSame(0, ScheduleSlot::where('slot_date', '2028-12-25')->where('is_scheduled', true)->count());
        $this->assertSame(4, ScheduleSlot::where('week_start', '2028-12-25')->where('is_scheduled', true)->count());
    }

    public function test_reopening_one_year_of_an_annual_holiday_stays_reopened(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.rules.store'), [
            'month' => 12, 'day' => 25, 'reason' => 'Christmas Day',
        ]);

        $christmas2028 = ClosureDay::firstWhere('closed_on', '2028-12-25');
        $this->actingAs($this->admin)->delete(route('holidays.destroy', $christmas2028));

        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2028-12-25']);

        // Revisiting the page tops the rules up. A year already written must not
        // be revisited, or the day the director just reopened comes back.
        $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk();

        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2028-12-25']);
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2029-12-25']);
    }

    public function test_removing_a_rule_reopens_its_future_days_but_keeps_the_past(): void
    {
        // Stand in 2029 so the rule has days behind it as well as ahead.
        $this->travelTo(Carbon::parse('2029-06-04 09:00:00'));

        $this->actingAs($this->admin)->post(route('holidays.rules.store'), [
            'month' => 12, 'day' => 25, 'reason' => 'Christmas Day',
        ]);

        $this->travelTo(Carbon::parse('2030-06-03 09:00:00'));

        $rule = HolidayRule::firstWhere('reason', 'Christmas Day');

        $this->actingAs($this->admin)
            ->delete(route('holidays.rules.destroy', $rule))
            ->assertRedirect(route('holidays.index'));

        $this->assertDatabaseCount('holiday_rules', 0);

        // 2029 has happened; it stays as the record it is. Everything from here
        // on goes, and the days lose their link cleanly rather than dangling.
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2029-12-25']);
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2030-12-25']);
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2034-12-25']);
    }

    public function test_an_impossible_annual_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('holidays.rules.store'), ['month' => 2, 'day' => 31, 'reason' => 'Never'])
            ->assertSessionHasErrors('day');

        $this->assertDatabaseCount('holiday_rules', 0);
    }

    public function test_the_same_annual_date_cannot_be_added_twice(): void
    {
        $payload = ['month' => 12, 'day' => 25, 'reason' => 'Christmas Day'];

        $this->actingAs($this->admin)->post(route('holidays.rules.store'), $payload);
        $this->actingAs($this->admin)
            ->post(route('holidays.rules.store'), $payload)
            ->assertSessionHasErrors('day');

        $this->assertDatabaseCount('holiday_rules', 1);
    }

    public function test_a_teacher_cannot_set_an_annual_holiday(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->post(route('holidays.rules.store'), ['month' => 12, 'day' => 25, 'reason' => 'Christmas Day'])
            ->assertForbidden();

        $this->assertDatabaseCount('holiday_rules', 0);
    }

    public function test_a_range_running_the_wrong_way_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('holidays.store'), [
                'closed_on' => '2026-07-31',
                'ends_on' => self::WEDNESDAY,
            ])
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('closure_days', 0);
    }


    /**
     * A holiday on the Monday of a week used to be dropped by the range query
     * behind every closure lookup, so the day greyed out nowhere and children
     * stayed ticked onto it. Mondays are exactly where public holidays land.
     */
    public function test_a_holiday_on_a_monday_closes_the_day(): void
    {
        // Registered for the full week: opening no longer copies the week
        // before, so the five ticks the closure takes one of come from here.
        $this->makeChild('Lovelace', 'Ada', 'Toddler')->forceFill(['schedule_days' => [1, 2, 3, 4, 5]])->save();
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $monday = '2026-08-03';

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => $monday,
            'reason' => 'Bank Holiday',
        ]);

        $this->assertSame([$monday], ClosureDay::inWeek($monday));

        // Built after the holiday was set: the Monday must come out unticked.
        app(WeekSchedule::class)->open($monday);

        $this->assertSame(0, ScheduleSlot::where('slot_date', $monday)->where('is_scheduled', true)->count());
        $this->assertSame(4, ScheduleSlot::where('week_start', $monday)->where('is_scheduled', true)->count());

        // And it is named on the board rather than silently grey.
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => $monday]))
            ->assertOk()
            ->assertSee('Bank Holiday');
    }


    public function test_the_reopen_dialog_names_the_children_who_come_back(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        $alan = $this->makeChild('Turing', 'Alan', 'PreK');
        app(WeekSchedule::class)->open(self::MONDAY);

        // Ada comes on Wednesday, Alan does not.
        ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $ada->id)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);

        $response = $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk();

        // The dialog is driven by this map, so it is what has to be right: Ada
        // is in it and restorable, Alan is not in it at all.
        $restores = $response->viewData('restores');

        $this->assertSame([
            ['name' => 'Ada Lovelace', 'session' => 'FULL', 'restorable' => true],
        ], $restores[$holiday->id]);
        $this->assertStringNotContainsString('Alan Turing', json_encode($restores));
    }

    public function test_the_dialog_marks_a_child_who_can_no_longer_come_back(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('slot_date', self::WEDNESDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY,
            'reason' => 'Independence Day',
        ]);

        // Moved to School Age, which splits the day into AM and PM. The full-day
        // box the closure cleared no longer exists, so nothing can be ticked.
        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $ada->id,
            'classroom' => 'School Age',
        ])->assertOk();

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);
        $restores = $this->actingAs($this->admin)->get(route('holidays.index'))->viewData('restores');

        $this->assertFalse($restores[$holiday->id][0]['restorable']);
    }


    public function test_renaming_a_day_leaves_the_roster_alone(): void
    {
        $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY, 'reason' => 'Staf trainng',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);

        $this->actingAs($this->admin)
            ->put(route('holidays.update', $holiday), [
                'closed_on' => self::WEDNESDAY,
                'reason' => 'Staff training',
            ])
            ->assertRedirect(route('holidays.index'));

        $this->assertSame('Staff training', $holiday->fresh()->reason);

        // A typo is not a change of plan: the day stays closed and nothing is
        // handed back to the board.
        $this->assertSame(0, ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('is_scheduled', true)->count());
        $this->assertSame(1, $holiday->fresh()->clearedCount());
    }

    public function test_moving_a_day_reopens_the_old_one_and_closes_the_new(): void
    {
        $ada = $this->makeChild('Lovelace', 'Ada', 'Toddler');
        app(WeekSchedule::class)->open(self::MONDAY);
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY, 'reason' => 'Sports day',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);
        $thursday = '2026-07-30';

        $this->actingAs($this->admin)
            ->put(route('holidays.update', $holiday), ['closed_on' => $thursday, 'reason' => 'Sports day'])
            ->assertRedirect(route('holidays.index'));

        $this->assertDatabaseMissing('closure_days', ['closed_on' => self::WEDNESDAY]);
        $this->assertDatabaseHas('closure_days', ['closed_on' => $thursday, 'reason' => 'Sports day']);

        // The ticks follow the day in both directions.
        $this->assertTrue((bool) ScheduleSlot::where('slot_date', self::WEDNESDAY)->where('child_id', $ada->id)->value('is_scheduled'));
        $this->assertFalse((bool) ScheduleSlot::where('slot_date', $thursday)->where('child_id', $ada->id)->value('is_scheduled'));
    }

    public function test_a_day_cannot_be_moved_onto_a_weekend(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY, 'reason' => 'Sports day',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);

        $this->actingAs($this->admin)
            ->put(route('holidays.update', $holiday), ['closed_on' => '2026-08-01', 'reason' => 'Sports day'])
            ->assertSessionHasErrors('closed_on');

        $this->assertDatabaseHas('closure_days', ['closed_on' => self::WEDNESDAY]);
    }

    public function test_renaming_an_annual_holiday_renames_the_days_it_wrote(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.rules.store'), [
            'month' => 12, 'day' => 25, 'reason' => 'Xmas',
        ]);

        $rule = HolidayRule::firstWhere('reason', 'Xmas');
        $through = $rule->materialised_through;

        $this->actingAs($this->admin)
            ->put(route('holidays.rules.update', $rule), [
                'reason' => 'Christmas Day', 'month' => 12, 'day' => 25,
            ])
            ->assertRedirect(route('holidays.index'));

        $this->assertSame('Christmas Day', $rule->fresh()->reason);
        $this->assertSame('Christmas Day', ClosureDay::firstWhere('closed_on', '2028-12-25')?->reason);

        // A rename moves no dates, so the horizon is untouched and the days it
        // already wrote are not torn down and rebuilt.
        $this->assertSame($through, $rule->fresh()->materialised_through);
    }

    public function test_retiming_an_annual_holiday_moves_every_year_it_wrote(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.rules.store'), [
            'month' => 12, 'day' => 25, 'reason' => 'Founders Day',
        ]);

        $rule = HolidayRule::firstWhere('reason', 'Founders Day');
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2028-12-25']);

        $this->actingAs($this->admin)
            ->put(route('holidays.rules.update', $rule), [
                'reason' => 'Founders Day', 'month' => 3, 'day' => 4,
            ])
            ->assertRedirect(route('holidays.index'));

        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2028-12-25']);
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2027-03-04']);
        // 4 March 2028 is a Saturday, so that year is kept on the Monday.
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2028-03-06']);
    }

    public function test_a_moving_holiday_can_be_renamed_but_not_retimed(): void
    {
        $this->seed(\Database\Seeders\CanadianHolidaySeeder::class);

        $rule = HolidayRule::firstWhere('key', 'ca-good-friday');

        // Good Friday is where Easter puts it. A month and day sent anyway are
        // ignored rather than quietly turning it into a fixed date.
        $this->actingAs($this->admin)
            ->put(route('holidays.rules.update', $rule), [
                'reason' => 'Good Friday (closed)', 'month' => 1, 'day' => 5,
            ])
            ->assertRedirect(route('holidays.index'));

        $rule = $rule->fresh();

        $this->assertSame('Good Friday (closed)', $rule->reason);
        $this->assertSame(HolidayRule::EASTER, $rule->type);
        $this->assertNull($rule->month);
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2028-04-14', 'reason' => 'Good Friday (closed)']);
    }

    public function test_an_annual_holiday_cannot_be_moved_onto_another_one(): void
    {
        $this->actingAs($this->admin)->post(route('holidays.rules.store'), ['month' => 12, 'day' => 25, 'reason' => 'Christmas Day']);
        $this->actingAs($this->admin)->post(route('holidays.rules.store'), ['month' => 12, 'day' => 26, 'reason' => 'Boxing Day']);

        $boxing = HolidayRule::firstWhere('reason', 'Boxing Day');

        $this->actingAs($this->admin)
            ->put(route('holidays.rules.update', $boxing), ['reason' => 'Boxing Day', 'month' => 12, 'day' => 25])
            ->assertSessionHasErrors('day');

        $this->assertSame(26, $boxing->fresh()->day);
    }

    public function test_a_teacher_cannot_edit_a_holiday(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($this->admin)->post(route('holidays.store'), [
            'closed_on' => self::WEDNESDAY, 'reason' => 'Sports day',
        ]);

        $holiday = ClosureDay::firstWhere('closed_on', self::WEDNESDAY);

        $this->actingAs($teacher)
            ->put(route('holidays.update', $holiday), ['closed_on' => self::WEDNESDAY, 'reason' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Sports day', $holiday->fresh()->reason);
    }

    private function makeChild(string $last, string $first, string $room): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => $room,
            'status' => 'Active',
        ]);
    }
}
