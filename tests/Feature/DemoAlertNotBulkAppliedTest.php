<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Database\Seeders\ChildRecordDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "No pickup by Jordan Key" on ~10 unrelated children (QA, 16 Nov 2026).
 *
 * No app path writes an alert to more than the one child it was posted for;
 * the copies came from ChildRecordDemoSeeder, which put its example court
 * order on every 19th child on the roll. On a big roll it now lands once.
 */
class DemoAlertNotBulkAppliedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_puts_its_court_order_on_one_child_at_most(): void
    {
        User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);

        for ($i = 1; $i <= 80; $i++) {
            Child::create([
                'lan' => (string) (20000 + $i),
                'first_name' => 'Kid'.$i, 'last_name' => 'Test', 'status' => 'Active', 'classroom' => 'Toddler',
                'birth_date' => '2023-01-01', 'dob' => '2023-01-01',
            ]);
        }

        $this->seed(ChildRecordDemoSeeder::class);

        $withOrder = Child::all()->filter(fn (Child $c) => collect($c->alerts ?? [])->contains(fn ($a) => str_contains((string) ($a['text'] ?? ''), 'Jordan Key')));

        $this->assertLessThanOrEqual(1, $withOrder->count());
    }

    public function test_adding_an_alert_on_one_record_touches_no_other(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
        $a = Child::create(['lan' => '10501', 'first_name' => 'Ann', 'last_name' => 'One', 'status' => 'Active', 'classroom' => 'Toddler']);
        $b = Child::create(['lan' => '10502', 'first_name' => 'Ben', 'last_name' => 'Two', 'status' => 'Active', 'classroom' => 'Toddler']);

        $this->actingAs($admin)->post(route('children.alerts.store', $a), ['type' => 'court', 'text' => 'No pickup by TEST Person'])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $a->fresh()->alerts);
        $this->assertEmpty($b->fresh()->alerts ?? []);
    }
}
