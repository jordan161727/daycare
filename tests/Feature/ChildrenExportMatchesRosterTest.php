<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The export is the roll, from the same database the roll is read from.
 *
 * Pinned because it was doubted: a room set by hand showed on the child's
 * page while a downloaded file still had the old room. The file had come
 * from a different server. There is no second connection, cache or copy on
 * the export path — the button on the roll links to the same host the roll
 * was served from, and the workbook is built from the roll's own query on
 * the request's own connection, so whatever the page says, the file says.
 */
class ChildrenExportMatchesRosterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_export_carries_what_the_page_shows_after_a_room_is_set_by_hand(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '10023', 'status' => 'Active', 'first_name' => 'Brinton', 'last_name' => 'Sanford',
            'birth_date' => '2021-10-10', 'classroom' => 'UPK-4', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        // The director moves the child up through the form.
        $this->actingAs($admin)->put(route('children.update', $child), [
            'lan' => '10023', 'first_name' => 'Brinton', 'last_name' => 'Sanford', 'status' => 'Active',
            'birth_date' => '2021-10-10', 'classroom_override' => 'School Age', 'gender' => 'Boy',
            'description' => 'Race / Skin Tone: Black',
        ])->assertRedirect();

        // The page says School Age, set by hand.
        $page = $this->actingAs($admin)->get(route('children.show', $child))->assertOk()->getContent();
        $this->assertStringContainsString('School Age', $page);
        $this->assertStringContainsString('Set by hand', $page);

        // The export button on the roll points at this same host, not another.
        $roll = $this->actingAs($admin)->get(route('children.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/href="([^"]*children\/export[^"]*)"/', $roll, $href));
        $this->assertStringStartsWith(config('app.url'), $href[1]);

        // And the file the button downloads carries the same answer.
        Excel::fake();
        $this->actingAs($admin)->get(route('children.export'))->assertOk();

        Excel::assertDownloaded('children-'.today()->format('Y-m-d').'.xlsx', function (ChildrenExport $export) {
            $sheet = $export->sheets()[0];
            $headings = $sheet->headings();
            $row = $sheet->map($export->collection()->firstWhere('lan', '10023'));
            $at = fn (string $heading) => $row[array_search($heading, $headings, true)];

            return $at('Classroom') === 'School Age'
                && $at('Room set by hand') === 'School Age'
                && $at('Gender') === 'Boy'
                && $at('Description') === 'Race / Skin Tone: Black';
        });
    }
}
