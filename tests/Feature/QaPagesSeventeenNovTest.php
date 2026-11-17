<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA, 17 Nov 2026: the Profile page's note for administrators (#15), the
 * page header on Holidays, Import children and Profile (#16), and one way of
 * writing a phone number on screen (#17).
 */
class QaPagesSeventeenNovTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_is_not_told_to_ask_the_director(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($admin)->get(route('profile.edit'))->assertOk()
            ->assertDontSee('Ask the director')
            ->assertSee('Change these on your')
            ->assertSee(route('staff.show', $admin), false);

        $this->actingAs($teacher)->get(route('profile.edit'))->assertOk()
            ->assertSee('Ask the director if something here is wrong.')
            ->assertDontSee('Change these on your');
    }

    public function test_holidays_import_and_profile_use_the_current_page_header(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([
            route('holidays.index') => 'Holidays &amp; Closures',
            route('children.import.form') => 'Import children',
            route('profile.edit') => 'My Profile',
        ] as $url => $title) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<h1 class="m-0 text-[28px] font-bold leading-tight', $html, $url);
            $this->assertMatchesRegularExpression('/<h1[^>]*>'.preg_quote($title, '/').'<\/h1>/', $html, $url);
            $this->assertStringNotContainsString('DAYCARE MANAGEMENT', $html, $url);
        }

        // The import banner, and the second <main> it sat in, are gone.
        $html = $this->actingAs($admin)->get(route('children.import.form'))->assertOk()->getContent();
        $this->assertStringNotContainsString('bg-gradient-to-r from-blue-600 to-indigo-600', $html);
        $this->assertSame(1, substr_count($html, '<main'));
    }

    public function test_phone_numbers_read_one_way_without_changing_what_is_stored(): void
    {
        $this->assertSame('(716) 555-0143', Person::displayPhone('+17165550143'));
        $this->assertSame('(716) 555-0143', Person::displayPhone('716.555.0143'));
        $this->assertSame('555-0100', Person::displayPhone('555-0100'));
        $this->assertSame('ext. 12', Person::displayPhone('ext. 12'));
        $this->assertNull(Person::displayPhone(null));

        $admin = User::factory()->create(['role' => 'admin', 'phone' => '7165550199']);
        $child = Child::create(['lan' => '20001', 'first_name' => 'Ella', 'last_name' => 'Foster', 'status' => 'Active']);
        $person = Person::create(['name' => 'Kate Foster', 'cell' => '+17165550116']);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $person->id, 'relationship' => 'Mother', 'is_guardian' => true, 'is_emergency' => true, 'priority' => 1]);

        $this->actingAs($admin)->get(route('children.index'))->assertOk()
            ->assertSee('(716) 555-0116')
            ->assertDontSee('+17165550116');

        $this->actingAs($admin)->get(route('profile.edit'))->assertOk()->assertSee('(716) 555-0199');

        // Display only: the stored values are as they were.
        $this->assertSame('+17165550116', $person->fresh()->cell);
        $this->assertSame('7165550199', $admin->fresh()->phone);
    }
}
