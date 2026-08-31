<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The two facts the office reads off a child record: the date of birth, which
 * the roster's Age column shows as 2026/3/15, and the hours of the day the
 * child is contracted for.
 */
class ChildScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_age_column_is_the_date_of_birth(): void
    {
        $child = $this->makeChild(['birth_date' => '2026-03-15']);

        $this->assertSame('2026/3/15', $child->ageLabel());
    }

    public function test_a_child_with_no_date_of_birth_has_no_age(): void
    {
        $this->assertNull($this->makeChild(['birth_date' => null, 'dob' => null])->ageLabel());
    }

    /**
     * The roster pages at ten, and until now it drew no pager — so a centre
     * with sixty children could see the first ten and had no way to the rest.
     */
    public function test_the_roster_can_be_paged_past_the_first_ten(): void
    {
        collect(range(1, 12))->each(fn (int $number) => $this->makeChild([
            'lan' => (string) (4000 + $number),
            'last_name' => 'Child'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        ]));

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Child01')
            ->assertDontSee('Child11')
            ->assertSee(route('children.index', ['page' => 2]), escape: false);

        $this->actingAs($admin)
            ->get(route('children.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Child11');
    }

    public function test_the_roster_counts_the_whole_roll_not_the_page(): void
    {
        collect(range(1, 12))->each(fn (int $number) => $this->makeChild([
            'lan' => (string) (5000 + $number),
            'status' => $number > 9 ? 'Inactive' : 'Active',
        ]));

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSeeInOrder(['12', 'on the roll', '9', 'active']);
    }

    public function test_the_roster_shows_the_date_year_first(): void
    {
        $this->makeChild(['birth_date' => '2023-03-15', 'first_name' => 'Ada']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('2023/3/15')
            // Not the American order it used to be written in, which read as a
            // different date to half the people looking at it.
            ->assertDontSee('3/15/2023');
    }

    public function test_the_roster_carries_the_age_beside_the_date(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));
        $this->makeChild(['birth_date' => '2023-06-15', 'first_name' => 'Ada']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('2023/6/15')
            ->assertSee('3 years 2 months');
    }

    public function test_the_age_is_said_in_the_unit_that_fits_it(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        $this->assertSame('3 years 2 months', $this->makeChild(['birth_date' => '2023-06-15'])->ageInWords());
        // No trailing "0 months" on a birthday, and no "0 years" on a baby.
        $this->assertSame('3 years', $this->makeChild(['birth_date' => '2023-08-28'])->ageInWords());
        $this->assertSame('1 year 1 month', $this->makeChild(['birth_date' => '2025-07-28'])->ageInWords());
        $this->assertSame('7 months', $this->makeChild(['birth_date' => '2026-01-28'])->ageInWords());
        // Days only while there is not a month to report, since nobody calls a
        // fortnight-old nought years old.
        $this->assertSame('12 days', $this->makeChild(['birth_date' => '2026-08-16'])->ageInWords());
        $this->assertSame('1 day', $this->makeChild(['birth_date' => '2026-08-27'])->ageInWords());
        $this->assertSame('0 days', $this->makeChild(['birth_date' => '2026-08-28'])->ageInWords());
    }

    public function test_an_age_needs_a_date_of_birth_that_has_happened(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        $this->assertNull($this->makeChild(['birth_date' => null, 'dob' => null])->ageInWords());
        // A date of birth ahead of today is somebody's typo, not a negative age.
        $this->assertNull($this->makeChild(['birth_date' => '2027-01-01'])->ageInWords());
    }

    public function test_the_age_moves_with_the_calendar_rather_than_being_stored(): void
    {
        $child = $this->makeChild(['birth_date' => '2023-03-15']);

        $this->travelTo(Carbon::parse('2026-03-14'));
        $this->assertSame('2 years 11 months', $child->ageInWords());

        $this->travelTo(Carbon::parse('2026-03-15'));
        $this->assertSame('3 years', $child->ageInWords());
    }

    public function test_the_attendance_sheet_shows_the_date_beside_the_room(): void
    {
        // The room comes from the date of birth and the ratio is checked
        // against it, so a child who has grown out of theirs is worth seeing at
        // the sheet rather than on a report next month.
        $this->makeChild(['birth_date' => '2023-03-15', 'first_name' => 'Ada']);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));

        $rows = json_decode(json_decode('"'.$matches[1].'"'), associative: true);

        $this->assertSame('2023/3/15', $rows[0]['birth_date']);
        $this->assertNotNull($rows[0]['age']);
    }

    public function test_the_stored_age_column_follows_the_date_that_was_saved(): void
    {
        $child = $this->makeChild(['birth_date' => '2020-01-02']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, ['birth_date' => '2026-03-15']))
            ->assertRedirect();

        $this->assertSame('2026/3/15', $child->fresh()->age);
    }

    public function test_the_day_is_saved_and_read_back_as_it_is_spoken(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, [
                'drop_off_time' => '07:00',
                'pick_up_time' => '20:00',
            ]))
            ->assertRedirect();

        $this->assertSame('7:00 AM – 8:00 PM', $child->fresh()->scheduleLabel());
    }

    public function test_a_child_with_no_times_agreed_has_no_schedule(): void
    {
        $this->assertNull($this->makeChild()->scheduleLabel());
        $this->assertNull($this->makeChild(['drop_off_time' => '07:00'])->scheduleLabel());
    }

    public function test_a_drop_off_before_the_centre_opens_is_rejected(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, ['drop_off_time' => '06:30']))
            ->assertSessionHasErrors('drop_off_time');
    }

    public function test_a_pick_up_after_the_centre_closes_is_rejected(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, [
                'drop_off_time' => '08:00',
                'pick_up_time' => '20:30',
            ]))
            ->assertSessionHasErrors('pick_up_time');
    }

    public function test_a_pick_up_before_the_drop_off_is_rejected(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, [
                'drop_off_time' => '15:00',
                'pick_up_time' => '09:00',
            ]))
            ->assertSessionHasErrors('pick_up_time');
    }

    public function test_a_time_that_comes_back_with_seconds_still_saves(): void
    {
        $child = $this->makeChild();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, [
                'drop_off_time' => '07:30:00',
                'pick_up_time' => '17:45:00',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('7:30 AM – 5:45 PM', $child->fresh()->scheduleLabel());
    }

    public function test_the_add_form_opens_on_the_hours_the_centre_keeps(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.create'))
            ->assertOk()
            ->assertSee('name="drop_off_time" value="07:00"', false)
            ->assertSee('name="pick_up_time" value="20:00"', false);
    }

    public function test_the_edit_form_keeps_the_times_already_agreed(): void
    {
        $child = $this->makeChild(['drop_off_time' => '08:15', 'pick_up_time' => '16:30']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('name="drop_off_time" value="08:15"', false)
            ->assertSee('name="pick_up_time" value="16:30"', false);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) fake()->unique()->numberBetween(1000, 9999),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'status' => 'Active',
            'classroom' => 'Infant',
            'birth_date' => '2025-06-01',
        ]);
    }

    /** The whole form, since an update posts every field the page carries. */
    private function formFor(Child $child, array $overrides = []): array
    {
        return $overrides + [
            'lan' => $child->lan,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birthDate()?->toDateString(),
        ];
    }
}
