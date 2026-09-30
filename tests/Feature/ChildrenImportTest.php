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

    public function test_how_the_child_looks_is_read_from_the_columns_after_gender(): void
    {
        /*
         * The centre's sheet writes it two ways at once: a Description column
         * ("blonde long girl") and then, in the columns after Gender, the skin,
         * the hair colour and the hair length. All of it lands in the one
         * description the portrait picker reads — without saying anything
         * twice, and with a skin colour said as skin so "Brown" there is not
         * taken for the hair.
         */
        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '10001', 'first_name' => 'Amaan', 'last_name' => 'Alam', 'gender' => 'Boy', 'description' => 'short hair brown', 'skin' => 'Brown', 'hair_color' => null, 'hair_length' => 'Short'],
            ['lan' => '10002', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'gender' => 'Girl', 'description' => 'blonde long girl', 'skin' => 'White', 'hair_color' => 'Blonde', 'hair_length' => 'Long'],
            ['lan' => '10003', 'first_name' => 'Naomi', 'last_name' => 'Ayala', 'gender' => 'Girl', 'description' => null, 'skin' => 'Hispanic', 'hair_color' => 'Black', 'hair_length' => 'Long'],
        ]));

        $this->assertSame([], $import->errors);
        $this->assertSame('short hair brown, tan skin', Child::where('lan', '10001')->value('description'));
        $this->assertSame('blonde long girl, light skin', Child::where('lan', '10002')->value('description'));
        $this->assertSame('hispanic, black hair, long hair', Child::where('lan', '10003')->value('description'));

        // And the faces those pick: the brown-haired boy, the blonde girl,
        // the girl with the long black braids.
        $this->assertSame(0, \App\Services\Portrait::pick(Child::where('lan', '10001')->first()));
        $this->assertSame(3, \App\Services\Portrait::pick(Child::where('lan', '10002')->first()));
        $this->assertSame(11, \App\Services\Portrait::pick(Child::where('lan', '10003')->first()));
    }
}
