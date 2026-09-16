<?php

namespace Tests\Feature;

use App\Models\Child;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The one-off renumbering of the roll into five digits.
 *
 * It is a migration, so it runs once on deploy and then never again — which is
 * exactly why it is tested: there is no second chance to notice it put the
 * children in the wrong order or lost the record of what their numbers were.
 */
class LanRenumberTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_17_000001_renumber_lans_to_five_digits.php');
    }

    /** The order the roll was in is the order it stays in. */
    public function test_the_roll_is_renumbered_from_10001_in_its_existing_order(): void
    {
        $this->child('1012', 'Carter');
        $this->child('1003', 'Adams');
        $this->child('1070', 'Zhang');

        $this->migration()->up();

        $this->assertSame('10001', Child::firstWhere('last_name', 'Adams')->lan);
        $this->assertSame('10002', Child::firstWhere('last_name', 'Carter')->lan);
        $this->assertSame('10003', Child::firstWhere('last_name', 'Zhang')->lan);
    }

    /**
     * Records from before the sequence existed hold something that is not a
     * number. They go behind the numbered ones, oldest record first, because
     * there is nothing in them to sort by.
     */
    public function test_a_non_numeric_lan_is_renumbered_last(): void
    {
        $this->child('LAN-OLD', 'Babbage');
        $this->child('1005', 'Lovelace');

        $this->migration()->up();

        $this->assertSame('10001', Child::firstWhere('last_name', 'Lovelace')->lan);
        $this->assertSame('10002', Child::firstWhere('last_name', 'Babbage')->lan);
    }

    /**
     * What the cabinet needs.
     *
     * A LAN is how a paper file names a child, and nothing in the database
     * points at one — so the only thing this migration can break is paper, and
     * the only defence against that is a list of what changed to what.
     */
    public function test_the_old_and_new_numbers_are_written_down(): void
    {
        Storage::fake('local');

        $this->child('1003', 'Adams');
        $this->child('1012', 'Carter');

        $this->migration()->up();

        Storage::disk('local')->assertExists('lan-renumber.csv');

        $csv = Storage::disk('local')->get('lan-renumber.csv');

        $this->assertStringContainsString('child_id,old_lan,new_lan', $csv);
        $this->assertStringContainsString(',1003,10001', $csv);
        $this->assertStringContainsString(',1012,10002', $csv);
    }

    /** And that same list is what puts the numbers back. */
    public function test_the_renumbering_can_be_undone_from_the_list(): void
    {
        Storage::fake('local');

        $this->child('1003', 'Adams');
        $this->child('1012', 'Carter');

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame('1003', Child::firstWhere('last_name', 'Adams')->lan);
        $this->assertSame('1012', Child::firstWhere('last_name', 'Carter')->lan);
    }

    /** With the list gone there is nothing to put back, and it says so. */
    public function test_undoing_without_the_list_refuses_rather_than_guesses(): void
    {
        Storage::fake('local');

        $this->child('1003', 'Adams');

        $migration = $this->migration();
        $migration->up();
        Storage::disk('local')->delete('lan-renumber.csv');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** An empty roll is nothing to renumber, not an error. */
    public function test_an_empty_roll_is_left_alone(): void
    {
        $this->migration()->up();

        $this->assertSame(0, Child::count());
    }

    private function child(string $lan, string $last): Child
    {
        return Child::create([
            'lan' => $lan,
            'status' => 'Active',
            'first_name' => 'Test',
            'last_name' => $last,
            'classroom' => 'Toddler',
        ]);
    }
}
