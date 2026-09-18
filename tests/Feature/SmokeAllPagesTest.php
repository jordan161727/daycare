<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every screen this session touched, opened once.
 *
 * Not a substitute for the tests that check what the screens say — it only
 * asks whether they render at all. That is worth its own test because most of
 * the work here was moving markup between partials, and a Blade file that no
 * longer compiles is a 500 that no unit test would notice.
 */
class SmokeAllPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_touched_screen_opens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '10001', 'status' => 'Active',
            'first_name' => 'Maeve', 'last_name' => 'Adkins', 'dob' => '2022-12-15',
        ]);

        $pages = [
            'children roster' => route('children.index'),
            'children filtered' => route('children.index', ['status' => 'Active']),
            'children sorted' => route('children.index', ['sort' => 'classroom', 'direction' => 'desc']),
            'child record' => route('children.show', $child),
            'child edit (People step)' => route('children.edit', $child),
            'child create' => route('children.create'),
            'attendance sheet' => route('attendance.index'),
            'attendance print' => route('attendance.print'),
            'dashboard' => route('dashboard'),
        ];

        foreach ($pages as $label => $url) {
            $this->actingAs($admin)->get($url)->assertOk("{$label} did not open");
        }

        // And the two that hand back a file rather than a page.
        $this->actingAs($admin)->get(route('children.export'))->assertOk()->assertDownload();
    }

    public function test_the_people_endpoints_answer(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '10002', 'status' => 'Active',
            'first_name' => 'Maeve', 'last_name' => 'Adkins', 'dob' => '2022-12-15',
        ]);

        $this->actingAs($admin)->getJson(route('people.search', ['q' => 'kay']))->assertOk();
        $this->actingAs($admin)->getJson(route('children.people', $child))->assertOk();

        $created = $this->actingAs($admin)->postJson(route('people.store'), [
            'child_id' => $child->id, 'name' => 'Kaylynn Adkins', 'cell' => '585-820-5029',
        ])->assertOk();

        $personId = $created->json('person.id');

        $this->actingAs($admin)->getJson(route('people.show', $personId))->assertOk();
        $this->actingAs($admin)->postJson(route('people.link', $personId), [
            'child_id' => $child->id, 'is_guardian' => true,
        ])->assertOk();
        $this->actingAs($admin)->postJson(route('people.update', $personId), [
            'name' => 'Kaylynn Adkins', 'cell' => '585-820-5029',
        ])->assertOk();
        $this->actingAs($admin)->postJson(route('people.unlink', $personId), ['child_id' => $child->id])->assertOk();
        $this->actingAs($admin)->postJson(route('people.destroy', $personId))->assertOk();
    }
}
