<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The two facts the office reads off a child record: the date of birth, which
 * the roster's Age column shows as 2026/03/15, and the hours of the day the
 * child is contracted for.
 */
class ChildScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_age_column_is_the_date_of_birth(): void
    {
        $child = $this->makeChild(['birth_date' => '2026-03-15']);

        $this->assertSame('2026/03/15', $child->ageLabel());
    }

    public function test_a_child_with_no_date_of_birth_has_no_age(): void
    {
        $this->assertNull($this->makeChild(['birth_date' => null, 'dob' => null])->ageLabel());
    }

    /**
     * The whole roll on one page. It used to page at ten, which put a
     * sixty-child centre six clicks from the child they wanted and left the
     * search box able to find only the children on the page it was on.
     */
    public function test_the_roster_shows_every_child_on_one_page(): void
    {
        collect(range(1, 12))->each(fn (int $number) => $this->makeChild([
            'lan' => (string) (4000 + $number),
            'last_name' => 'Child'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        ]));

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('Child01')
            ->assertSee('Child11')
            ->assertSee('Child12')
            ->getContent();

        $this->assertStringNotContainsString('?page=', $html);
        $this->assertStringNotContainsString('aria-label="Pagination', $html);
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
            ->assertSee('2023/03/15')
            // Not the American order it used to be written in, which read as a
            // different date to half the people looking at it.
            ->assertDontSee('3/15/2023');
    }

    /**
     * The roster draws a child the way the sheet does: a round avatar and the
     * name, in the same classes.
     *
     * The two tables list the same children and are read one after the other,
     * so a row that looks different on each is a row the eye has to learn
     * twice. They share the classes, which is what stops them drifting.
     *
     * Left-aligned on both. Centred, the block floated — a long name pushed
     * its avatar right and a short one pulled it left — and a column of names
     * is scanned down the edge they start at.
     */
    public function test_the_roster_draws_the_child_the_way_the_sheet_does(): void
    {
        $this->makeChild(['birth_date' => '2023-03-15', 'first_name' => 'Ada', 'classroom' => 'Toddler']);
        $admin = User::factory()->create(['role' => 'admin']);

        $roster = $this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent();
        $sheet = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        foreach (['att-person', 'att-avatar', 'att-name'] as $class) {
            $this->assertStringContainsString($class, $roster, "the roster does not use {$class}");
            $this->assertStringContainsString($class, $sheet, "the sheet does not use {$class}");
        }

        // Neither repeats the room under the name: both give it a column, and
        // a row that says the same thing twice is a row saying it twice.
        $this->assertStringNotContainsString('att-room', $roster);
        $this->assertStringNotContainsString('att-room', $sheet);
        $this->assertStringContainsString('>Classroom', $roster);
        $this->assertStringContainsString('>Classroom</th>', $sheet);

        // Both read from the left, which is the only alignment either uses —
        // so there is no modifier asking for it on one and not the other.
        $this->assertStringNotContainsString('att-person-start', $roster);
        $this->assertStringNotContainsString('att-person-start', $sheet);
    }

    /**
     * The roster and the attendance sheet list the same children and are read
     * one after the other. A column that sits in a different place on each is
     * one the eye has to hunt for every time it changes screen.
     */
    public function test_the_roster_orders_its_columns_like_the_attendance_sheet(): void
    {
        $this->makeChild(['birth_date' => '2023-03-15', 'first_name' => 'Ada']);
        $admin = User::factory()->create(['role' => 'admin']);

        $order = ['LAN', 'Student', 'Classroom', 'DOB', 'Age'];

        $this->actingAs($admin)->get(route('children.index'))->assertOk()->assertSeeInOrder($order);
        $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->assertSeeInOrder($order);
    }

    public function test_the_roster_carries_the_age_beside_the_date(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));
        $this->makeChild(['birth_date' => '2023-06-15', 'first_name' => 'Ada']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('2023/06/15')
            ->assertSee('3y 2m');
    }

    /**
     * One shape wherever an age is shown. Both halves are always said, so a
     * column of them lines up years under years and months under months.
     */
    public function test_the_age_is_always_years_and_months(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28'));

        $this->assertSame('3y 2m', $this->makeChild(['birth_date' => '2023-06-15'])->ageInWords());
        // A birthday keeps its "0m", and a baby keeps its "0y" — dropping
        // either is what made two ages in a column impossible to compare.
        $this->assertSame('3y 0m', $this->makeChild(['birth_date' => '2023-08-28'])->ageInWords());
        $this->assertSame('1y 1m', $this->makeChild(['birth_date' => '2025-07-28'])->ageInWords());
        $this->assertSame('0y 7m', $this->makeChild(['birth_date' => '2026-01-28'])->ageInWords());
        // The one exception: "0y 0m" is not an age, and the infant room takes
        // babies at six weeks. Days, in the same shape, until there is a month.
        $this->assertSame('12d', $this->makeChild(['birth_date' => '2026-08-16'])->ageInWords());
        $this->assertSame('1d', $this->makeChild(['birth_date' => '2026-08-27'])->ageInWords());
        $this->assertSame('0d', $this->makeChild(['birth_date' => '2026-08-28'])->ageInWords());
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
        $this->assertSame('2y 11m', $child->ageInWords());

        $this->travelTo(Carbon::parse('2026-03-15'));
        $this->assertSame('3y 0m', $child->ageInWords());
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

        $this->assertSame('2023/03/15', $rows[0]['birth_date']);
        $this->assertNotNull($rows[0]['age']);
    }

    public function test_the_stored_age_column_follows_the_date_that_was_saved(): void
    {
        $child = $this->makeChild(['birth_date' => '2020-01-02']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, ['birth_date' => '2026-03-15']))
            ->assertRedirect();

        $this->assertSame('2026/03/15', $child->fresh()->age);
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
