<?php

namespace Tests\Feature;

use App\Imports\ChildrenImport;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA retest, 18 Nov 2026: a Days value of "M,W,F" was dropped without a
 * word and the child was made with no days. Single letters, names in any
 * case and Th / Thu / Thur now read; "T" alone is Tuesday and "R" Thursday
 * (the MTWRF timetable convention); anything still unreadable is left blank
 * and listed on the import summary, with every other problem on the row.
 */
class ChildImportDaysTest extends TestCase
{
    use RefreshDatabase;

    public static function days(): array
    {
        return [
            'letters with commas' => ['M,W,F', [1, 3, 5]],
            'letters with spaces' => ['M W F', [1, 3, 5]],
            'names with spaces' => ['Mon, Wed, Fri', [1, 3, 5]],
            'run together, lower case' => ['mwf', [1, 3, 5]],
            'Tu and Th' => ['Tu,Th', [2, 4]],
            'numbers' => ['1,3,5', [1, 3, 5]],
            'M-F' => ['M-F', [1, 2, 3, 4, 5]],
            'full names, any case' => ['MONDAY wednesday Friday', [1, 3, 5]],
            'Thu, Thur, Thurs' => ['Thu', [4]],
            'Thur' => ['thur', [4]],
            'Thurs' => ['Thurs', [4]],
            'T alone is Tuesday' => ['T', [2]],
            'R is Thursday' => ['M,R', [1, 4]],
            'TTh run together' => ['TTh', [2, 4]],
            'MTWThF' => ['MTWThF', [1, 2, 3, 4, 5]],
            'a range with spaces' => ['Mon - Thu', [1, 2, 3, 4]],
            'an en dash' => ['Tue–Thu', [2, 3, 4]],
            'and' => ['Monday and Friday', [1, 5]],
            'daily' => ['Daily', [1, 2, 3, 4, 5]],
            'blank is left alone' => ['', []],
            'unknown word' => ['Xyz', null],
            'a weekend day' => ['Sat', null],
            'one bad part spoils it' => ['Mon, Xyz', null],
            'a backwards range' => ['F-M', null],
        ];
    }

    #[DataProvider('days')]
    public function test_the_days_column_is_read(string $text, ?array $expected): void
    {
        $this->assertSame($expected, ChildrenImport::parseDays($text));
    }

    public function test_the_summary_lists_unreadable_days_and_rows_with_no_lan_with_every_problem(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));
        $admin = User::factory()->create(['role' => 'admin']);

        $csv = implode("\n", [
            'LAN,First Name,Last Name,DOB,Status,Days',
            '40001,Ana,Commas,2023-03-01,Pending,"M,W,F"',
            ',Ben,NoLan,2023-04-01,Pending,MWF',
            '40003,Cara,BadDays,2023-05-01,Pending,Xyz',
            '40004,Dev,TwoProblems,not a date,Pending,Sometimes',
        ]);

        $response = $this->actingAs($admin)->post(route('children.import'), [
            'file' => UploadedFile::fake()->createWithContent('children.csv', $csv),
        ]);

        // "M,W,F" is read, not dropped.
        $this->assertSame([1, 3, 5], Child::where('lan', '40001')->first()->schedule_days);
        // Imported with no days, and said so.
        $this->assertNull(Child::where('lan', '40003')->first()->schedule_days ?: null);
        $given = Child::where('last_name', 'NoLan')->value('lan');

        $response->assertSessionHas('import_notes', [
            "Row 3: Ben NoLan — no LAN on the sheet — given {$given}.",
            "Row 4: Cara BadDays — days 'Xyz' not recognised — left blank.",
        ]);
        // Both problems on row 5, not just the first.
        $response->assertSessionHas('import_errors', fn (array $errors) => count($errors) === 1
            && str_starts_with($errors[0], 'Row 5:')
            && str_contains($errors[0], 'not a date the importer can read')
            && str_contains($errors[0], "Days 'Sometimes' not recognised — left blank."));
        $response->assertSessionHas('success', fn (string $m) => str_contains($m, '2 row(s) imported with something to check'));

        $this->actingAs($admin)->get(route('children.import'))
            ->assertSee('Imported — check these:')
            ->assertSee('Row 4: Cara BadDays — days &#039;Xyz&#039; not recognised — left blank.', false);
    }
}
