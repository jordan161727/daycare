<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA #27: a date of birth is read strictly, US order first, and an impossible one is refused. */
class ChildDateOfBirthStrictTest extends TestCase
{
    use RefreshDatabase;

    private function add(string $birthDate)
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from(route('children.create'))
            ->post(route('children.store'), ['first_name' => 'Mia', 'last_name' => 'Cole', 'status' => 'Pending', 'birth_date' => $birthDate]);
    }

    public function test_an_impossible_date_is_refused_with_a_message_not_rolled_over(): void
    {
        $this->add('13/45/2023')->assertSessionHasErrors(['birth_date' => 'That is not a real date. Type the date of birth as mm/dd/yyyy, for example 02/14/2024.']);
        $this->assertSame(0, Child::count());

        $this->add('02/30/2024')->assertSessionHasErrors('birth_date');
        $this->add('next tuesday')->assertSessionHasErrors('birth_date');
        $this->assertSame(0, Child::count());
    }

    public function test_a_real_date_is_read_in_us_order(): void
    {
        $this->add('06/07/2023')->assertSessionHasNoErrors();
        $this->assertSame('2023-06-07', Child::sole()->birth_date->format('Y-m-d'));
    }

    public function test_the_picker_value_and_a_day_first_date_that_can_only_be_one_thing_still_work(): void
    {
        $this->add('2024-02-14')->assertSessionHasNoErrors();
        $this->assertSame('2024-02-14', Child::sole()->birth_date->format('Y-m-d'));

        Child::query()->delete();
        $this->add('14/02/2024')->assertSessionHasNoErrors();
        $this->assertSame('2024-02-14', Child::sole()->birth_date->format('Y-m-d'));
    }
}
