<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two facts the office reads off a child record: the date of birth, which
 * the roster's Age column shows as 3/15/2026, and the hours of the day the
 * child is contracted for.
 */
class ChildScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_age_column_is_the_date_of_birth(): void
    {
        $child = $this->makeChild(['birth_date' => '2026-03-15']);

        $this->assertSame('3/15/2026', $child->ageLabel());
    }

    public function test_a_child_with_no_date_of_birth_has_no_age(): void
    {
        $this->assertNull($this->makeChild(['birth_date' => null, 'dob' => null])->ageLabel());
    }

    public function test_the_roster_shows_the_date_rather_than_the_years_and_months(): void
    {
        $this->makeChild(['birth_date' => '2026-03-15', 'first_name' => 'Ada']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('children.index'))
            ->assertOk()
            ->assertSee('3/15/2026')
            ->assertDontSee('Born Mar 15, 2026');
    }

    public function test_the_stored_age_column_follows_the_date_that_was_saved(): void
    {
        $child = $this->makeChild(['birth_date' => '2020-01-02']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('children.update', $child), $this->formFor($child, ['birth_date' => '2026-03-15']))
            ->assertRedirect();

        $this->assertSame('3/15/2026', $child->fresh()->age);
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
