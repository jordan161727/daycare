<?php

namespace Tests\Feature;

use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA bug 6 (Nov 2026): the importer skipped the Add form's "Active needs a
 * guardian and an emergency contact" rule, and made a child Active with a
 * guardian and nobody to ring. It now asks the same rule
 * (Child::missingForActiveOnAdd / missingForActive): a new row that fails it
 * is added as Pending and named on the summary, and an update never makes a
 * child Active that fails it.
 */
class ChildImportActiveRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const HEADINGS = ['LAN', 'First Name', 'Last Name', 'DOB', 'Status', 'Mother Name', 'Emergency Contact', 'Emergency Telephone'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_row_with_a_guardian_and_no_emergency_contact_is_created_as_pending_and_reported(): void
    {
        $response = $this->upload([
            self::HEADINGS,
            ['30001', 'Ava', 'Complete', '2023-03-01', 'Active', 'Maria Complete', 'Rosa Complete', '5551112222'],
            ['30002', 'Ben', 'Partial', '2023-04-01', 'Active', 'Lena Partial', '', ''],
        ]);

        $this->assertSame('Active', Child::where('lan', '30001')->value('status'));
        $this->assertSame('Pending', Child::where('lan', '30002')->value('status'));

        $response->assertSessionHas('import_not_active', [
            'Row 3: Ben Partial created as Pending — no emergency contact.',
        ]);
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, '2 created')
            && str_contains($message, '1 child(ren) not ready to be Active'));

        $this->dumpSummary($response);

        // The summary page shows it.
        $this->actingAs($this->admin)->get(route('children.import'))
            ->assertSee('Not ready to be Active')
            ->assertSee('Row 3: Ben Partial created as Pending — no emergency contact.');
    }

    public function test_a_complete_row_is_created_active_and_not_reported(): void
    {
        $response = $this->upload([
            self::HEADINGS,
            ['30010', 'Cleo', 'Ready', '2023-03-01', 'Active', 'Maria Ready', 'Rosa Ready', '5551113333'],
        ]);

        $this->assertSame('Active', Child::where('lan', '30010')->value('status'));
        $response->assertSessionHas('import_not_active', []);
    }

    public function test_a_row_with_no_status_column_is_held_to_the_same_rule(): void
    {
        // The importer defaults a new child to Active; that default is held
        // to the rule too. No guardian, no emergency contact, no birthday.
        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '30020', 'first_name' => 'Dev', 'last_name' => 'Bare'],
        ]));

        $this->assertSame('Pending', Child::where('lan', '30020')->value('status'));
        $this->assertSame(
            ['Row 2: Dev Bare created as Pending — no date of birth, no guardian and no emergency contact.'],
            $import->notActive
        );
    }

    public function test_a_row_marked_pending_is_not_reported(): void
    {
        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '30030', 'first_name' => 'Eli', 'last_name' => 'Waiting', 'status' => 'Pending', 'mother_name' => 'Mum Waiting'],
        ]));

        $this->assertSame('Pending', Child::where('lan', '30030')->value('status'));
        $this->assertSame([], $import->notActive);
    }

    public function test_an_update_does_not_make_an_incomplete_child_active(): void
    {
        Child::create(['lan' => '30040', 'status' => 'Pending', 'first_name' => 'Fay', 'last_name' => 'Update', 'dob' => '2023-01-01']);

        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '30040', 'status' => 'Active', 'mother_name' => 'Mum Update', 'classroom' => null],
        ]));

        $child = Child::where('lan', '30040')->first();
        $this->assertSame('Pending', $child->status);
        // The rest of the row still lands.
        $this->assertTrue($child->load('personLinks')->guardianLink() !== null);
        $this->assertSame(1, $import->updated);
        $this->assertSame(
            ['Row 2: Fay Update kept as Pending, not made Active — no emergency contact.'],
            $import->notActive
        );
    }

    public function test_an_update_that_supplies_the_missing_contact_makes_the_child_active(): void
    {
        Child::create(['lan' => '30050', 'status' => 'Pending', 'first_name' => 'Gus', 'last_name' => 'Now', 'dob' => '2023-01-01']);

        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '30050', 'status' => 'Active', 'mother_name' => 'Mum Now', 'emergency_contact' => 'Gran Now'],
        ]));

        $this->assertSame('Active', Child::where('lan', '30050')->value('status'));
        $this->assertSame([], $import->notActive);
    }

    public function test_a_child_already_active_is_left_active(): void
    {
        // As the edit form does: the rule is asked when a save makes a child
        // Active, not on every save of one already Active but incomplete.
        Child::create(['lan' => '30060', 'status' => 'Active', 'first_name' => 'Hal', 'last_name' => 'Already']);

        $import = new ChildrenImport;
        $import->collection(collect([
            ['lan' => '30060', 'status' => 'Active', 'nickname' => 'H'],
        ]));

        $this->assertSame('Active', Child::where('lan', '30060')->value('status'));
        $this->assertSame([], $import->notActive);
    }

    private function upload(array $rows)
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $this->actingAs($this->admin)->post(route('children.import'), [
            'file' => UploadedFile::fake()->createWithContent('children.csv', $csv),
        ]);
    }

    /** The demo for the QA report, written only when IMPORT_SUMMARY_DUMP names a file. */
    private function dumpSummary($response): void
    {
        $path = getenv('IMPORT_SUMMARY_DUMP');
        if (! $path) {
            return;
        }

        $session = $response->baseResponse->getSession();
        $lines = array_merge(
            [$session->get('success')],
            $session->get('import_not_active') ? ['', 'Not ready to be Active:'] : [],
            array_map(fn ($line) => '  - '.$line, $session->get('import_not_active', [])),
        );

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }
}
