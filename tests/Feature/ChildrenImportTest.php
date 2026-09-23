<?php

namespace Tests\Feature;

use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChildrenImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_import_page_is_available_to_an_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/children/import')->assertOk();
    }

    public function test_the_import_page_requires_signing_in(): void
    {
        $this->get('/children/import')->assertRedirect(route('login'));
    }

    public function test_it_creates_and_updates_active_children_from_rows(): void
    {
        Child::create([
            'lan' => 'LAN-1',
            'status' => 'Active',
            'first_name' => 'Old name',
            'last_name' => 'Santos',
            'age' => 4,
            'classroom' => 'Sunflowers',
        ]);

        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => 'LAN-1', 'status' => 'Active', 'first_name' => 'Ana', 'last_name' => 'Santos', 'dob' => 45292, 'age' => 4, 'classroom' => 'Sunflowers'],
            ['lan' => 'LAN-2', 'status' => 'Active', 'first_name' => 'Ben', 'last_name' => 'Cruz', 'dob' => '2022-01-15', 'age' => 4, 'classroom' => 'Sunflowers'],
            ['lan' => 'LAN-3', 'status' => 'Inactive', 'first_name' => 'Cara', 'last_name' => 'Reyes', 'dob' => null, 'age' => 5, 'classroom' => 'Roses'],
        ]));

        /*
         * All three, including the inactive one.
         *
         * This used to drop any row not marked Active, which quietly threw
         * away a centre's history: a child who left in June is still a child
         * the centre has records for, and the app already has a status field
         * and a withdrawal date to say so. Every screen filters on status, so
         * they clutter nothing — and an import that silently discards rows is
         * one nobody can reconcile against the file they uploaded.
         */
        $this->assertDatabaseCount('children', 3);
        $this->assertDatabaseHas('children', ['lan' => 'LAN-1', 'first_name' => 'Ana', 'classroom' => 'Sunflowers']);
        $this->assertDatabaseHas('children', ['lan' => 'LAN-2', 'last_name' => 'Cruz']);
        $this->assertDatabaseHas('children', ['lan' => 'LAN-3', 'status' => 'Inactive']);
        $this->assertSame(2, $import->created);
        $this->assertSame(1, $import->updated);
        $this->assertSame(0, $import->skipped);
    }
}
