<?php

namespace Tests\Feature;

use App\Models\ClosureDay;
use App\Models\HolidayRule;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Database\Seeders\CanadianHolidaySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Canadian statutory calendar: the rules that find a moving date, and the
 * seeder that puts the whole list on the board.
 */
class CanadianHolidayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Before the year under test, so a full calendar year is still ahead:
        // the seeder will not close a week that has already finished.
        $this->travelTo(Carbon::parse('2025-12-01 09:00:00'));
    }

    /**
     * Checked against the published dates rather than against the algorithm —
     * a holiday calendar that is self-consistently wrong is the failure worth
     * guarding, and Easter in particular is not a formula anybody eyeballs.
     */
    public static function movingDates(): array
    {
        return [
            'Good Friday' => [['type' => HolidayRule::EASTER, 'offset_days' => -2],
                ['2026-04-03', '2027-03-26', '2028-04-14', '2029-03-30', '2030-04-19']],
            'Victoria Day' => [['type' => HolidayRule::ON_OR_BEFORE, 'month' => 5, 'day' => 24, 'weekday' => 1],
                ['2026-05-18', '2027-05-24', '2028-05-22', '2029-05-21', '2030-05-20']],
            'Labour Day' => [['type' => HolidayRule::NTH_WEEKDAY, 'month' => 9, 'weekday' => 1, 'nth' => 1],
                ['2026-09-07', '2027-09-06', '2028-09-04', '2029-09-03', '2030-09-02']],
            'Thanksgiving' => [['type' => HolidayRule::NTH_WEEKDAY, 'month' => 10, 'weekday' => 1, 'nth' => 2],
                ['2026-10-12', '2027-10-11', '2028-10-09', '2029-10-08', '2030-10-14']],
            'Family Day' => [['type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => 1, 'nth' => 3],
                ['2026-02-16', '2027-02-15', '2028-02-21', '2029-02-19', '2030-02-18']],
        ];
    }

    #[DataProvider("movingDates")]
    public function test_a_moving_holiday_lands_on_the_published_date(array $attributes, array $expected): void
    {
        $rule = new HolidayRule($attributes + ['reason' => 'Test']);

        $actual = array_map(fn ($year) => $rule->dateIn($year), range(2026, 2030));

        $this->assertSame($expected, $actual);
    }

    public function test_the_seeder_puts_the_federal_list_on_the_calendar(): void
    {
        config(['daycare.province' => '']);

        $this->seed(CanadianHolidaySeeder::class);

        $this->assertSame(10, HolidayRule::count());

        // The 2026 dates, as published. This is the list a parent would check.
        $this->assertSame([
            '2026-01-01', // New Year's Day
            '2026-04-03', // Good Friday
            '2026-05-18', // Victoria Day
            '2026-07-01', // Canada Day
            '2026-09-07', // Labour Day
            '2026-09-30', // Truth and Reconciliation
            '2026-10-12', // Thanksgiving
            '2026-11-11', // Remembrance Day
            '2026-12-25', // Christmas Day
            '2026-12-28', // Boxing Day — the 26th is a Saturday, kept on Monday
        ], ClosureDay::whereBetween('closed_on', ['2026-01-01', '2026-12-31'])
            ->orderBy('closed_on')->pluck('closed_on')->map->toDateString()->all());
    }

    public function test_a_holiday_at_the_weekend_is_kept_on_the_next_working_day(): void
    {
        config(['daycare.province' => '']);

        $this->seed(CanadianHolidaySeeder::class);

        // Christmas 2027 is a Saturday and Boxing Day the Sunday after it. Both
        // are still holidays: one takes the Monday, the other the Tuesday. A
        // centre that got no Christmas closure at all would be the real failure.
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2027-12-25']);
        $this->assertSame('Christmas Day', ClosureDay::firstWhere('closed_on', '2027-12-27')?->reason);
        $this->assertSame('Boxing Day', ClosureDay::firstWhere('closed_on', '2027-12-28')?->reason);
    }

    public function test_a_province_adds_its_own_days(): void
    {
        config(['daycare.province' => 'ON']);

        $this->seed(CanadianHolidaySeeder::class);

        $this->assertSame(12, HolidayRule::count());
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2026-02-16']); // Family Day
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2026-08-03']); // Civic Holiday
    }

    public function test_quebec_replaces_victoria_day_rather_than_doubling_it(): void
    {
        config(['daycare.province' => 'QC']);

        $this->seed(CanadianHolidaySeeder::class);

        $this->assertNull(HolidayRule::firstWhere('key', 'ca-victoria-day'));
        $this->assertNotNull(HolidayRule::firstWhere('key', 'ca-qc-patriots-day'));

        // One closed day on the Monday, under Quebec's name for it.
        $day = ClosureDay::firstWhere('closed_on', '2026-05-18');
        $this->assertSame("National Patriots' Day", $day->reason);
        $this->assertDatabaseHas('closure_days', ['closed_on' => '2026-06-24']); // Fête nationale
    }

    public function test_seeding_twice_does_not_duplicate_the_calendar(): void
    {
        config(['daycare.province' => 'ON']);

        $this->seed(CanadianHolidaySeeder::class);
        $rules = HolidayRule::count();
        $days = ClosureDay::count();

        $this->seed(CanadianHolidaySeeder::class);

        $this->assertSame($rules, HolidayRule::count());
        $this->assertSame($days, ClosureDay::count());
    }

    public function test_a_seeded_holiday_closes_the_attendance_schedule(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada');
        app(WeekSchedule::class)->open('2026-07-27');
        ScheduleSlot::where('week_start', '2026-07-27')->update(['is_scheduled' => true]);

        config(['daycare.province' => 'ON']);
        $this->seed(CanadianHolidaySeeder::class);

        // Labour Day 2026 is Monday 7 September. Its week is built after the
        // seeder ran and must come out with that Monday closed.
        app(WeekSchedule::class)->open('2026-09-07');

        $this->assertSame(0, ScheduleSlot::where('slot_date', '2026-09-07')->where('is_scheduled', true)->count());
        $this->assertSame(4, ScheduleSlot::where('week_start', '2026-09-07')->where('is_scheduled', true)->count());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get(route('attendance.index', ['date' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Labour Day');
    }

    public function test_a_day_reopened_by_hand_is_not_seeded_back(): void
    {
        config(['daycare.province' => 'ON']);
        $this->seed(CanadianHolidaySeeder::class);

        $admin = User::factory()->create(['role' => 'admin']);
        $canadaDay = ClosureDay::firstWhere('closed_on', '2027-07-01');

        $this->actingAs($admin)->delete(route('holidays.destroy', $canadaDay));
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2027-07-01']);

        $this->seed(CanadianHolidaySeeder::class);

        // The centre decided to open that day. Re-running the seeder states the
        // rules again; it must not overrule that decision.
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2027-07-01']);
    }


    public function test_a_holiday_on_a_day_already_closed_does_not_shift(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // The centre already shut Canada Day 2026 under its own reason. The
        // seeder must claim that same day, not close 2 July as well.
        $this->actingAs($admin)->post(route('holidays.store'), [
            'closed_on' => '2026-07-01',
            'reason' => 'Summer shutdown',
        ]);

        config(['daycare.province' => '']);
        $this->seed(CanadianHolidaySeeder::class);

        $this->assertSame('Canada Day', ClosureDay::firstWhere('closed_on', '2026-07-01')?->reason);
        $this->assertDatabaseMissing('closure_days', ['closed_on' => '2026-07-02']);
    }

    private function makeChild(string $last, string $first): \App\Models\Child
    {
        return \App\Models\Child::create([
            'lan' => (string) (1000 + \App\Models\Child::count() + 1),
            'first_name' => $first,
            'last_name' => $last,
            'classroom' => 'Toddler',
            'status' => 'Active',
            // Full-week registration: a week built after the holiday opens with
            // five ticks from the record for the closure to take one of.
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }
}
