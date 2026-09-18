<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Exports\ChildrenSheet;
use App\Exports\PeopleSheet;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Taking the roll away as a spreadsheet.
 *
 * What is checked here is mostly that the file holds what the screen held. An
 * export that quietly differs from the page it was downloaded from — a filter
 * ignored, another room's children included, a column added to the table and
 * forgotten on the way out — is the kind of difference nobody notices until it
 * has been sent somewhere.
 */
class ChildrenExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_roster_offers_an_export(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('children.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Export', $html);
        $this->assertStringContainsString(route('children.export'), $html);
    }

    public function test_it_downloads_a_spreadsheet(): void
    {
        $this->makeChild();

        $this->actingAs($this->admin)
            ->get(route('children.export'))
            ->assertOk()
            ->assertDownload();
    }

    public function test_the_file_is_named_for_what_it_holds_and_when(): void
    {
        // They end up in a downloads folder beside last month's.
        $this->travelTo('2026-09-19');
        $this->makeChild();

        $this->actingAs($this->admin)
            ->get(route('children.export'))
            ->assertDownload('children-2026-09-19.xlsx');

        $this->actingAs($this->admin)
            ->get(route('children.export', ['status' => 'Active']))
            ->assertDownload('children-active-2026-09-19.xlsx');
    }

    public function test_the_columns_are_the_ones_on_screen(): void
    {
        $child = $this->makeChild([
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'birth_date' => '2022-12-15',
            'alerts' => [['type' => 'allergy', 'text' => 'Banana']],
        ]);

        $sheet = new ChildrenSheet(collect([$child->load('personLinks.person')]));

        $this->assertSame([
            'LAN', 'Student', 'First name', 'Last name', 'Nickname', 'Gender',
            'Classroom', 'Room set by hand', 'Date of birth', 'Age', 'Status',
            'Enrolled on', 'Withdrawn on', 'Hours', 'Days', 'Expected hours/week',
            'Address', 'Household phone', 'Email', 'DSS case no', 'DSS CIN',
            'Parents status', 'Responsible for payment',
            'Mother', 'Mother phone', 'Father', 'Father phone', 'Parent emails',
            'Guardians', 'Can pick up', 'Emergency contacts', 'Restrictions',
            'Alerts', 'Notes', 'Important notes', 'Import status',
        ], $sheet->headings());

        $row = $sheet->map($child);

        $this->assertCount(count($sheet->headings()), $row, 'a value for every heading');
        $this->assertSame($child->lan, $row[0]);
        $this->assertSame('Adkins', $row[3]);
        $this->assertSame('PreK', $row[6]);
        // The date itself, not the label, so Excel sorts it as a date.
        $this->assertSame('2022-12-15', $row[8]);
        $this->assertSame('Active', $row[10]);
        $this->assertSame('Allergy: Banana', $row[32]);
    }

    public function test_every_field_on_the_child_is_exported(): void
    {
        // The check somebody would otherwise have to do by hand. A column added
        // to the children table and forgotten on the way out is invisible until
        // the day the file is needed and the field is not in it.
        $exported = (new ChildrenSheet(collect()))->headings();

        // What is left out, and why. Anything not named here has to appear.
        $skipped = [
            'id',                            // internal
            'created_at', 'updated_at',      // facts about the record, not the child
            'photo_path',                    // a path on a private disk is no use in a spreadsheet
            'age',                           // derived, and exported as Age
            'child_name',                    // already split across three columns
            'classroom_override_from',       // the override is exported; the date it began is a detail
            'dob',                           // the older birth-date column; exported as Date of birth
            'schedule_days',                 // exported as Days, in words
            'drop_off_time', 'pick_up_time', // exported together as Hours
            'city', 'zip',                   // exported joined, as Address
            'alerts',                        // exported as Alerts, in words
        ];

        // The contact blocks moved to people and child_people and are on the
        // People sheet now. They are still columns until they are dropped.
        $migrated = fn (string $column) => (bool) preg_match(
            '/^(mother_|father_|pickup_|emergency_|secondary_emergency)/',
            $column
        );

        // Columns whose heading reads differently from the column name.
        $aliases = [
            'telephone' => 'Household phone',
            'birth_date' => 'Date of birth',
            'email_address' => 'Email',
            'other_notes' => 'Notes',
            'classroom_override' => 'Room set by hand',
            'expected_hours_per_week' => 'Expected hours/week',
        ];

        $normalise = fn (string $value) => strtolower(preg_replace('/[^a-z0-9]/i', '', $value));

        $missing = collect(Schema::getColumnListing('children'))
            ->reject(fn (string $column) => in_array($column, $skipped, true) || $migrated($column))
            ->reject(function (string $column) use ($exported, $aliases, $normalise) {
                $heading = $aliases[$column] ?? str_replace('_', ' ', $column);

                return collect($exported)->contains(
                    fn (string $candidate) => $normalise($candidate) === $normalise($heading)
                );
            })
            ->values();

        $this->assertSame([], $missing->all(), 'children columns missing from the export: '.$missing->implode(', '));
    }

    public function test_the_parents_are_on_the_child_row(): void
    {
        // The shape every contact list the centre has kept is in: one row per
        // child, with a Mom and a Dad column beside the name.
        $child = $this->makeChild();

        $this->link($child, 'Kaylynn Adkins', '585-820-5029', 'Mother', [
            'is_guardian' => true, 'can_pickup' => true, 'is_emergency' => true,
            'email' => 'kaylynn@example.com',
        ]);
        $this->link($child, 'Brad Adkins', '716-510-5162', 'Father', [
            'is_guardian' => true, 'can_pickup' => true, 'email' => 'brad@example.com',
        ]);

        $row = (new ChildrenSheet(collect([$child->load('personLinks.person')])))->map($child);

        $this->assertSame('Kaylynn Adkins', $row[23]);
        $this->assertSame('585-820-5029', $row[24]);
        $this->assertSame('Brad Adkins', $row[25]);
        $this->assertSame('716-510-5162', $row[26]);
        $this->assertSame("kaylynn@example.com\nbrad@example.com", $row[27]);

        $this->assertSame("Kaylynn Adkins\nBrad Adkins", $row[28], 'guardians');
        $this->assertSame("Kaylynn Adkins\nBrad Adkins", $row[29], 'can pick up');
        $this->assertSame('1. Kaylynn Adkins', $row[30], 'emergency, in call order');
    }

    public function test_a_court_order_keeps_somebody_off_the_pick_up_column(): void
    {
        // The same rule the door is meant to use. A spreadsheet printed for the
        // front desk is exactly where this must not be got wrong.
        $child = $this->makeChild();

        $this->link($child, 'Jordan Adkins', '585-000-0000', 'Other', [
            'can_pickup' => true,
            'restriction' => 'Court order — must not collect',
        ]);

        $row = (new ChildrenSheet(collect([$child->load('personLinks.person')])))->map($child);

        $this->assertSame('', $row[29], 'the tick is on, but a court order beats it');
        $this->assertSame('Jordan Adkins: Court order — must not collect', $row[31]);
    }

    public function test_the_people_sheet_holds_every_adult_and_loses_nothing(): void
    {
        // What the two flat parent columns cannot say: a grandmother who may
        // collect, and who is nobody's mother or father.
        $child = $this->makeChild();

        $this->link($child, 'Kaylynn Adkins', '585-820-5029', 'Mother', ['is_guardian' => true]);
        $this->link($child, 'Amy Crumb', '585-352-8844', 'Grandmother', [
            'can_pickup' => true, 'employer' => 'Retired', 'ssn' => '123-45-4864',
        ]);

        $sheet = new PeopleSheet(collect([$child->load('personLinks.person')]));

        $this->assertSame('People', $sheet->title());
        $this->assertCount(2, $sheet->collection());

        $amy = $sheet->collection()->first(fn (array $row) => $row[1]->person->name === 'Amy Crumb');
        $mapped = $sheet->map($amy);

        $this->assertSame('Amy Crumb', $mapped[3]);
        $this->assertSame('Grandmother', $mapped[4]);
        $this->assertSame('', $mapped[5], 'not a guardian');
        $this->assertSame('Yes', $mapped[6], 'may collect');
        $this->assertSame('585-352-8844', $mapped[13]);
        $this->assertSame('Retired', $mapped[16]);

        // Never the whole number — a spreadsheet of them is one that gets
        // emailed.
        $this->assertSame('4864', $mapped[20]);
    }

    public function test_an_adult_with_no_address_shows_the_household(): void
    {
        $child = $this->makeChild(['address' => '8 Northbrook Ct', 'city' => 'Lancaster', 'zip' => '14086']);

        $this->link($child, 'Kaylynn Adkins', '585-820-5029', 'Mother', []);

        $sheet = new PeopleSheet(collect([$child->load('personLinks.person')]));
        $mapped = $sheet->map($sheet->collection()->first());

        // Rule 5: resolved when shown rather than copied onto the person, so it
        // is still right after the family moves.
        $this->assertSame('8 Northbrook Ct, Lancaster, 14086', $mapped[10]);
    }

    public function test_the_workbook_has_both_sheets(): void
    {
        $child = $this->makeChild();

        $sheets = (new ChildrenExport(collect([$child->load('personLinks.person')])))->sheets();

        $this->assertInstanceOf(ChildrenSheet::class, $sheets[0]);
        $this->assertInstanceOf(PeopleSheet::class, $sheets[1]);
        $this->assertSame('Children', $sheets[0]->title());
        $this->assertSame('People', $sheets[1]->title());
    }

    public function test_the_export_carries_the_filter_the_page_was_showing(): void
    {
        $this->makeChild(['first_name' => 'Active', 'status' => 'Active']);
        $this->makeChild(['first_name' => 'Gone', 'status' => 'Inactive']);

        Excel::fake();

        $this->actingAs($this->admin)->get(route('children.export', ['status' => 'Active']))->assertOk();

        Excel::assertDownloaded('children-active-'.today()->format('Y-m-d').'.xlsx', function (ChildrenExport $export) {
            $names = $export->collection()->pluck('first_name');

            // A button that ignored the chip above it is how a centre sends a
            // funder a list of children who left last year.
            return $names->contains('Active') && ! $names->contains('Gone');
        });
    }

    public function test_the_export_is_in_the_order_the_page_was_sorted(): void
    {
        $this->makeChild(['first_name' => 'Ada', 'last_name' => 'Zeta']);
        $this->makeChild(['first_name' => 'Bea', 'last_name' => 'Alpha']);

        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('children.export', ['sort' => 'last_name', 'direction' => 'desc']))
            ->assertOk();

        Excel::assertDownloaded('children-'.today()->format('Y-m-d').'.xlsx', function (ChildrenExport $export) {
            return $export->collection()->pluck('last_name')->all() === ['Zeta', 'Alpha'];
        });
    }

    public function test_a_teacher_exports_only_their_own_rooms(): void
    {
        $this->travelTo('2026-09-19');

        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        // The room follows the date of birth — writing a classroom onto the row
        // does not stick, because Child::saving recomputes it. So the band is
        // chosen by the date: Toddler is 24 to 36 months, PreK 36 to 48.
        $this->makeChild(['first_name' => 'Mine', 'dob' => '2024-03-19', 'birth_date' => '2024-03-19']);
        $this->makeChild(['first_name' => 'Theirs', 'dob' => '2023-03-19', 'birth_date' => '2023-03-19']);

        $this->assertSame('Toddler', Child::where('first_name', 'Mine')->value('classroom'));
        $this->assertSame('PreK', Child::where('first_name', 'Theirs')->value('classroom'));

        Excel::fake();

        $this->actingAs($teacher)->get(route('children.export'))->assertOk();

        Excel::assertDownloaded('children-'.today()->format('Y-m-d').'.xlsx', function (ChildrenExport $export) {
            $names = $export->collection()->pluck('first_name');

            // The same rule the roster is filtered by: a spreadsheet is not a
            // way round who may read which child.
            return $names->contains('Mine') && ! $names->contains('Theirs');
        });
    }

    public function test_an_invented_status_is_refused_here_too(): void
    {
        // The export shares the roster's query, so it shares its guards.
        $this->actingAs($this->admin)
            ->get(route('children.export', ['status' => 'Invented']))
            ->assertNotFound();
    }

    public function test_signing_in_is_required(): void
    {
        $this->get(route('children.export'))->assertRedirect(route('login'));
    }

    private function link(Child $child, string $name, string $cell, string $relationship, array $flags): void
    {
        $person = Person::create(
            ['name' => $name, 'cell' => $cell]
            + array_intersect_key($flags, array_flip(['email', 'employer', 'ssn']))
        );

        ChildPerson::create([
            'child_id' => $child->id,
            'person_id' => $person->id,
            'relationship' => $relationship,
            'is_guardian' => $flags['is_guardian'] ?? false,
            'can_pickup' => $flags['can_pickup'] ?? false,
            'is_emergency' => $flags['is_emergency'] ?? false,
            'priority' => ($flags['is_emergency'] ?? false) ? 1 : null,
            'restriction' => $flags['restriction'] ?? null,
        ]);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create(array_merge([
            'lan' => (string) (10000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'dob' => '2022-12-15',
        ], $attributes));
    }
}
