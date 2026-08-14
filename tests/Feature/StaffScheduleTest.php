<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffRule;
use App\Models\StaffShift;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

class StaffScheduleTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

    private const WEEK = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul

    private StaffSchedule $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        // The week is a fixed date; without pinning the clock it drifts into
        // the past and the assertions quietly change meaning.
        $this->travelTo(Carbon::parse(self::WEEK.' 09:00:00'));

        $this->scheduler = app(StaffSchedule::class);
    }

    public function test_a_fixed_shift_is_worked_exactly(): void
    {
        $aisha = $this->teacher('Aisha Khan', 'FT', 'UPK-4');
        $this->rule($aisha, 'FIXED_SHIFT', ['day' => 'ALL', 'time_1' => 8 * 60, 'time_2' => 16 * 60]);

        $this->scheduler->generate(self::WEEK);

        $shifts = StaffShift::where('user_id', $aisha->id)->get();

        $this->assertCount(5, $shifts);
        $this->assertTrue($shifts->every(fn ($shift) => $shift->starts_at === 480 && $shift->ends_at === 960));
    }

    public function test_an_unavailable_day_is_never_scheduled(): void
    {
        $olivia = $this->teacher('Olivia Turner', 'FT');
        $this->rule($olivia, 'UNAVAILABLE_DAY', ['day' => 'WED']);

        $this->scheduler->generate(self::WEEK);

        $days = StaffShift::where('user_id', $olivia->id)->pluck('day');

        $this->assertNotContains('WED', $days);
        $this->assertContains('MON', $days);
    }

    public function test_an_availability_window_bounds_the_shift(): void
    {
        $grace = $this->teacher('Grace Lee', 'PT');
        $this->rule($grace, 'AVAILABLE_WINDOW', ['day' => 'ALL', 'time_1' => 7 * 60, 'time_2' => 12 * 60]);

        $this->scheduler->generate(self::WEEK);

        $shifts = StaffShift::where('user_id', $grace->id)->get();

        $this->assertNotEmpty($shifts);
        $this->assertTrue($shifts->every(fn ($shift) => $shift->starts_at >= 420 && $shift->ends_at <= 720));
    }

    public function test_only_a_keyholder_may_start_at_opening(): void
    {
        $hannah = $this->teacher('Hannah Brooks', 'FT');
        $this->rule($hannah, 'CAN_OPEN');
        $emily = $this->teacher('Emily Carter', 'FT');

        // Everyone starts at opening under the default config, so the rule is
        // only observable once the floor is raised for non-keyholders.
        config(['daycare.default_earliest' => 8 * 60]);

        $this->scheduler->generate(self::WEEK);

        $this->assertSame(420, StaffShift::where('user_id', $hannah->id)->min('starts_at'));
        $this->assertSame(480, StaffShift::where('user_id', $emily->id)->min('starts_at'));
    }

    public function test_a_week_with_no_keyholder_is_reported(): void
    {
        $this->teacher('Emily Carter', 'FT');

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertStringContainsString('no keyholder', implode(' ', $week->warnings));
    }

    public function test_a_room_short_of_its_ratio_is_reported(): void
    {
        // Twelve infants at 1:4 need three staff. Only one exists.
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $this->bookChildren('Infant', 12);

        $week = $this->scheduler->generate(self::WEEK);

        $shortfalls = collect($week->warnings)->filter(fn ($warning) => str_contains($warning, 'Infant') && str_contains($warning, 'short by'));

        $this->assertNotEmpty($shortfalls, 'Expected a ratio shortfall warning for Infant.');
    }

    public function test_a_substitute_is_pulled_in_only_to_cover_a_gap(): void
    {
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $olivia = $this->teacher('Olivia Turner', 'SUB');

        $this->scheduler->generate(self::WEEK);
        $this->assertSame(0, StaffShift::where('user_id', $olivia->id)->count(), 'A substitute should not be rostered when nothing is short.');

        $this->bookChildren('Infant', 12);
        $this->scheduler->generate(self::WEEK);

        $patches = StaffShift::where('user_id', $olivia->id)->get();

        $this->assertNotEmpty($patches, 'A substitute should cover a ratio gap.');
        $this->assertTrue($patches->every(fn ($shift) => $shift->role === StaffShift::ROLE_PATCH));
    }

    public function test_an_empty_room_needs_nobody(): void
    {
        $this->teacher('Maria Santos', 'FT', 'Infant');

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertEmpty(
            collect($week->warnings)->filter(fn ($warning) => str_contains($warning, 'short by')),
            'A room with no children booked is not a shortfall.'
        );
    }

    public function test_half_day_bookings_only_raise_demand_for_that_half(): void
    {
        $this->teacher('David Nguyen', 'FT', 'School Age');

        // 1:15, so sixteen in the morning needs two staff and eight in the
        // afternoon needs one. Only the morning should be reported short.
        $this->bookChildren('School Age', 16, session: 'AM');
        $this->bookChildren('School Age', 8, session: 'PM', startFrom: 100);

        $week = $this->scheduler->generate(self::WEEK);
        $shortfalls = collect($week->warnings)->filter(fn ($w) => str_contains($w, 'School Age') && str_contains($w, 'short by'));

        $this->assertNotEmpty($shortfalls);

        // Reported against the morning headcount, never the afternoon one:
        // sixteen at 1:15 needs two staff, eight needs one, and only the
        // morning is short. Asserted on the count rather than the times,
        // because a morning gap legitimately ends at "12:00 PM".
        $this->assertTrue(
            $shortfalls->every(fn ($warning) => str_contains($warning, '16 children')),
            'Only the morning is short; the afternoon is within ratio. Got: '.$shortfalls->implode(' | ')
        );
    }

    public function test_a_gap_lasting_all_afternoon_is_reported_once_rather_than_per_half_hour(): void
    {
        // One teacher missing from a room all afternoon is one problem. Printing
        // it once per coverage slice buries everything else in the list.
        $grace = $this->teacher('Grace Lee', 'PT', 'PreK');
        $this->rule($grace, 'AVAILABLE_WINDOW', ['day' => 'ALL', 'time_1' => 7 * 60, 'time_2' => 12 * 60]);
        $this->bookChildren('PreK', 3);

        $week = $this->scheduler->generate(self::WEEK);
        $monday = collect($week->warnings)->filter(fn ($w) => str_starts_with($w, 'MON PreK'));

        $this->assertCount(1, $monday, 'Expected one merged warning. Got: '.$monday->implode(' | '));
        $this->assertStringContainsString('12:00 PM–6:00 PM', $monday->first());
    }

    public function test_cover_prefers_whoever_has_hours_left(): void
    {
        // Emily is already at her day's share; Olivia has done nothing. Pulling
        // Emily instead would book overtime while a substitute stood idle.
        $emily = $this->teacher('Emily Carter', 'FT', 'Infant');
        $olivia = $this->teacher('Olivia Turner', 'SUB');
        $this->bookChildren('Infant', 4);

        $this->scheduler->generate(self::WEEK);

        $this->assertGreaterThan(
            0,
            StaffShift::where('user_id', $olivia->id)->count(),
            'The substitute with hours to spare should have been used for cover.'
        );
    }

    public function test_overtime_says_how_much_of_it_was_cover(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT', 'Infant');
        $this->rule($emily, 'REQUIRED_HOURS', ['number' => 10, 'value_text' => 'WEEKLY']);
        $this->bookChildren('Infant', 4);

        $week = $this->scheduler->generate(self::WEEK);
        $overtime = collect($week->warnings)->first(fn ($w) => str_starts_with($w, 'Emily Carter:'));

        $this->assertNotNull($overtime);
        $this->assertStringContainsString('covering other rooms', $overtime);
    }

    public function test_a_soft_preferred_day_off_is_worked_but_reported(): void
    {
        // The whole practical difference between SOFT and HARD. A soft rule
        // that produced no output at all would be a preference the director
        // believes was considered.
        $emily = $this->teacher('Emily Carter', 'FT', 'Toddler');
        $this->rule($emily, 'PREFERRED_DAY_OFF', ['day' => 'FRI', 'priority' => 'SOFT']);

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertGreaterThan(0, StaffShift::where('user_id', $emily->id)->where('day', 'FRI')->count());
        $this->assertStringContainsString('prefers FRI off', implode(' ', $week->warnings));
    }

    public function test_a_hard_preferred_day_off_closes_the_day(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT', 'Toddler');
        $this->rule($emily, 'PREFERRED_DAY_OFF', ['day' => 'FRI', 'priority' => 'HARD']);

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertSame(0, StaffShift::where('user_id', $emily->id)->where('day', 'FRI')->count());
        $this->assertStringNotContainsString('prefers FRI off', implode(' ', $week->warnings));
    }

    public function test_a_broken_preference_is_summarised_once_not_once_per_day(): void
    {
        $hannah = $this->teacher('Hannah Brooks', 'FT', 'Toddler');
        $this->rule($hannah, 'PREFERRED_START', ['time_1' => 6 * 60, 'priority' => 'SOFT']);

        $week = $this->scheduler->generate(self::WEEK);
        $lines = collect($week->warnings)->filter(fn ($w) => str_contains($w, 'preferred start time'));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('every day', $lines->first());
    }

    public function test_two_people_marked_no_pair_are_reported_when_they_overlap(): void
    {
        $sofia = $this->teacher('Sofia Reyes', 'PT');
        $this->teacher('Olivia Turner', 'PT');
        $this->rule($sofia, 'NO_PAIR', ['value_text' => 'Olivia Turner', 'priority' => 'SOFT']);

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertStringContainsString('NO_PAIR', implode(' ', $week->warnings));
    }

    public function test_a_no_pair_rule_naming_a_stranger_is_reported_rather_than_ignored(): void
    {
        $sofia = $this->teacher('Sofia Reyes', 'PT');
        $this->rule($sofia, 'NO_PAIR', ['value_text' => 'Someone Who Left', 'priority' => 'SOFT']);

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertStringContainsString('not on staff', implode(' ', $week->warnings));
    }

    public function test_somebody_needing_supervision_alone_on_the_floor_is_reported(): void
    {
        $sofia = $this->teacher('Sofia Reyes', 'PT');
        $this->rule($sofia, 'NEEDS_SUPERVISION');

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertStringContainsString('needs supervision', implode(' ', $week->warnings));
    }

    public function test_a_forbidden_room_is_never_assigned(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT', 'Infant');
        $this->rule($emily, 'ROOM_FORBIDDEN', ['value_text' => 'Infant']);

        $this->scheduler->generate(self::WEEK);

        $this->assertSame(0, StaffShift::where('user_id', $emily->id)->where('classroom', 'Infant')->count());
    }

    public function test_a_room_preference_beats_the_title(): void
    {
        $maria = $this->teacher('Maria Santos', 'FT', 'Toddler');
        $this->rule($maria, 'ROOM_PREFERENCE', ['value_text' => 'Infant', 'priority' => 'SOFT']);

        $this->scheduler->generate(self::WEEK);

        $this->assertTrue(StaffShift::where('user_id', $maria->id)->get()->every(fn ($shift) => $shift->classroom === 'Infant'));
    }

    public function test_somebody_left_short_of_their_hours_is_reported(): void
    {
        $grace = $this->teacher('Grace Lee', 'PT');
        $this->rule($grace, 'AVAILABLE_WINDOW', ['day' => 'ALL', 'time_1' => 7 * 60, 'time_2' => 9 * 60]);
        $this->rule($grace, 'REQUIRED_HOURS', ['number' => 40, 'value_text' => 'WEEKLY']);

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertStringContainsString('Grace Lee: scheduled', implode(' ', $week->warnings));
    }

    public function test_a_biweekly_requirement_counts_as_half_in_one_week(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT');
        $this->rule($emily, 'REQUIRED_HOURS', ['number' => 80, 'value_text' => 'BIWEEKLY']);

        $this->assertSame(40.0, $emily->fresh()->load('staffRules')->weeklyHours());
    }

    public function test_regenerating_replaces_the_previous_week_rather_than_adding_to_it(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT');

        $this->scheduler->generate(self::WEEK);
        $first = StaffShift::where('week_start', self::WEEK)->count();

        $this->scheduler->generate(self::WEEK);

        $this->assertSame($first, StaffShift::where('week_start', self::WEEK)->count());
        $this->assertGreaterThan(0, $first);
    }

    public function test_a_closure_day_is_left_unstaffed(): void
    {
        $this->teacher('Emily Carter', 'FT');

        $this->actingAs($this->admin())
            ->post(route('attendance.schedule.closure'), [
                'date' => '2026-07-29',
                'closed' => 1,
                'reason' => 'Holiday',
            ]);

        $this->scheduler->generate(self::WEEK);

        $this->assertSame(0, StaffShift::where('week_start', self::WEEK)->where('day', 'WED')->count());
    }

    public function test_the_schedule_screen_shows_the_generated_week(): void
    {
        $this->teacher('Emily Carter', 'FT', 'Toddler');
        $this->scheduler->generate(self::WEEK);

        $this->actingAs($this->admin())
            ->get(route('staff-schedule.index', ['week' => self::WEEK]))
            ->assertOk()
            ->assertSee('Emily Carter')
            ->assertSee('Week Schedule');
    }

    public function test_nobody_is_ever_scheduled_in_two_rooms_at_once(): void
    {
        // Cover has a length, so being free at the moment a gap opens is not
        // enough — the patch has to end before whatever they are already down
        // for later. Several rooms short at once is what exposes it.
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $this->teacher('Emily Carter', 'FT', 'Toddler');
        $hannah = $this->teacher('Hannah Brooks', 'FT', 'School Age');
        $this->rule($hannah, 'CAN_OPEN');
        $this->teacher('Olivia Turner', 'SUB');

        foreach (['Infant' => 12, 'Toddler' => 14, 'School Age' => 20, 'PreK' => 22] as $room => $count) {
            $this->bookChildren($room, $count, startFrom: crc32($room) % 1000);
        }

        $this->scheduler->generate(self::WEEK);

        $clashes = StaffShift::where('week_start', self::WEEK)->get()
            ->groupBy(fn (StaffShift $shift) => $shift->user_id.'|'.$shift->day)
            ->flatMap(function ($theirs) {
                $theirs = $theirs->sortBy('starts_at')->values();

                return $theirs->slice(0, -1)->filter(
                    fn (StaffShift $shift, int $i) => $shift->ends_at > $theirs[$i + 1]->starts_at
                )->map(fn (StaffShift $shift) => "{$shift->user->name} {$shift->day} {$shift->classroom} {$shift->label()}");
            });

        // Guard against the test passing because nothing was scheduled: the
        // point is that plenty of cover was handed out and none of it clashed.
        $this->assertGreaterThan(
            10,
            StaffShift::where('week_start', self::WEEK)->where('role', '!=', StaffShift::ROLE_STAFF)->count(),
            'This scenario is meant to generate cover shifts; without them it proves nothing.'
        );

        $this->assertEmpty($clashes, 'Somebody is in two rooms at once: '.$clashes->implode(', '));
    }

    public function test_somebody_with_no_employment_type_is_left_off_the_roster(): void
    {
        // A record with no contract is an account, not a shift — a placeholder
        // login, or somebody half set up. Rostering them anyway silently
        // doubles a room's cover and hides the shortfall the chart is for.
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $placeholder = $this->teacher('Infant Teacher', null, 'Infant');

        $week = $this->scheduler->generate(self::WEEK);

        $this->assertSame(0, StaffShift::where('user_id', $placeholder->id)->count());
        $this->assertStringContainsString('no employment type', implode(' ', $week->warnings));
    }

    public function test_somebody_with_no_employment_type_is_not_used_for_cover_either(): void
    {
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $placeholder = $this->teacher('Infant Teacher', null, 'Infant');
        $this->bookChildren('Infant', 12);

        $this->scheduler->generate(self::WEEK);

        $this->assertSame(
            0,
            StaffShift::where('user_id', $placeholder->id)->count(),
            'A gap closed by somebody with no contract is a gap reported as solved by nobody.'
        );
    }

    public function test_the_week_chart_labels_every_shift_it_cannot_fit_in_a_bar(): void
    {
        // Aisha's eight-hour bar holds its own times; the two-hour cover shift
        // does not, so the caption under the lane has to carry it. Nothing is
        // reachable only by hovering.
        $aisha = $this->teacher('Aisha Khan', 'FT', 'UPK-4');
        $this->rule($aisha, 'FIXED_SHIFT', ['day' => 'ALL', 'time_1' => 8 * 60, 'time_2' => 16 * 60]);
        $this->bookChildren('UPK-4', 4);
        $this->scheduler->generate(self::WEEK);

        $response = $this->actingAs($this->admin())
            ->get(route('staff-schedule.index', ['week' => self::WEEK, 'mode' => 'teacher']));

        $response->assertOk()->assertSee('8a → 4p', escape: false);

        // Every shift on the page is named somewhere in the markup, bar or caption.
        foreach (StaffShift::where('week_start', self::WEEK)->get() as $shift) {
            $response->assertSee(\App\Models\StaffRule::compactTime($shift->starts_at), escape: false);
        }
    }

    public function test_the_room_view_shows_the_ratio_bar_for_the_chosen_day(): void
    {
        $this->teacher('Maria Santos', 'FT', 'Infant');
        $this->bookChildren('Infant', 8);
        $this->scheduler->generate(self::WEEK);

        $this->actingAs($this->admin())
            ->get(route('staff-schedule.index', ['week' => self::WEEK, 'mode' => 'room', 'day' => 'MON']))
            ->assertOk()
            ->assertSee('Infant')
            ->assertSee('8 children at peak')
            ->assertSee('Under ratio');
    }

    public function test_a_teacher_can_read_the_roster_but_not_regenerate_it(): void
    {
        $emily = $this->teacher('Emily Carter', 'FT', 'Toddler');
        $this->scheduler->generate(self::WEEK);

        $this->actingAs($emily)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk();
        $this->actingAs($emily)->post(route('staff-schedule.generate'), ['week' => self::WEEK])->assertForbidden();
    }

    // ---------------------------------------------------------------- helpers

    private function teacher(string $name, ?string $employment = null, ?string $title = null): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => $employment,
            'title' => $title,
            'classroom' => $title,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Director',
            'email' => 'director@example.com',
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function rule(User $teacher, string $type, array $attributes = []): StaffRule
    {
        return $teacher->staffRules()->create($attributes + [
            'rule_type' => $type,
            'priority' => 'HARD',
        ]);
    }

    /** Book children into a room for every day of the week. */
    private function bookChildren(string $room, int $count, string $session = 'FULL', int $startFrom = 0): void
    {
        $dates = collect(range(0, 4))->map(fn ($offset) => Carbon::parse(self::WEEK)->addDays($offset)->toDateString());

        for ($i = $startFrom; $i < $startFrom + $count; $i++) {
            $child = Child::create([
                'lan' => (string) (1000 + Child::count() + 1),
                'first_name' => 'Child',
                'last_name' => "Number{$i}",
                'dob' => $this->dobForRoom($room),
                'status' => 'Active',
            ]);

            foreach ($dates as $date) {
                ScheduleSlot::create([
                    'week_start' => self::WEEK,
                    'child_id' => $child->id,
                    'slot_date' => $date,
                    'session' => $session,
                    'is_scheduled' => true,
                ]);
            }
        }
    }
}
