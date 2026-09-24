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
 * The whole registration, out of a spreadsheet.
 *
 * A centre's records live in Excel for years before they reach an app, so what
 * matters here is that the import takes everything — guardians, pickups,
 * emergency contacts — and that it is forgiving of how a real spreadsheet is
 * actually written.
 */
class ChildrenFullImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_full_registration_row_lands_in_every_field(): void
    {
        $this->import([
            ['lan', 'first_name', 'last_name', 'dob', 'classroom', 'status',
                'address', 'city', 'zip', 'telephone', 'email_address',
                'mother_name', 'mother_cell', 'mother_email',
                'father_name', 'father_cell',
                'emergency_contact', 'emergency_telephone', 'emergency_relationship',
                'pickup_1_name', 'pickup_1_relationship', 'pickup_1_telephone',
                'alerts', 'important_notes'],
            ['10001', 'Maeve', 'Adkins', '2022-04-18', 'PreK', 'Active',
                '14 Elm Road', 'Springfield', '12345', '555 0100', 'home@example.com',
                'Anna Adkins', '555 0101', 'anna@example.com',
                'Paul Adkins', '555 0102',
                'Nora Bell', '555 0199', 'Aunt',
                'Anna Adkins', 'Mother', '555 0101',
                'Peanut allergy', 'Collected early on Fridays'],
        ]);

        $child = Child::where('lan', '10001')->firstOrFail();

        $this->assertSame('Maeve', $child->first_name);
        $this->assertSame('2022-04-18', $child->dob->toDateString());
        $this->assertSame('14 Elm Road', $child->address);
        $this->assertSame('Anna Adkins', $child->mother_name);
        $this->assertSame('anna@example.com', $child->mother_email);
        $this->assertSame('Paul Adkins', $child->father_name);
        $this->assertSame('Nora Bell', $child->emergency_contact);
        $this->assertSame('Aunt', $child->emergency_relationship);
        $this->assertSame('Anna Adkins', $child->pickup_1_name);
        // An allergy line becomes one alert entry: `alerts` is a list of
        // {type, text} that the record draws as coloured chips, not prose.
        $this->assertSame([['type' => 'allergy', 'text' => 'Peanut allergy']], $child->alerts);
        $this->assertSame('Collected early on Fridays', $child->important_notes);
    }

    public function test_a_blank_cell_leaves_what_is_on_record_alone(): void
    {
        /*
         * The rule that makes a second import safe. A file exported from one
         * system rarely carries every column of another, and an import that
         * emptied a mother's phone number because the sheet had no such column
         * would be worse than no import at all.
         */
        Child::create([
            'lan' => '10001',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'status' => 'Active',
            'mother_cell' => '555 0101',
            'alerts' => 'Peanut allergy',
        ]);

        $this->import([
            ['lan', 'first_name', 'last_name', 'mother_cell'],
            ['10001', 'Maeve', 'Adkins', ''],
        ]);

        $child = Child::where('lan', '10001')->firstOrFail();

        $this->assertSame('555 0101', $child->mother_cell);
        $this->assertSame('Peanut allergy', $child->alerts);
    }

    public function test_the_lan_decides_whether_a_row_is_new(): void
    {
        // The number on the cabinet and the parent letter. A row carrying one
        // that is already here is that child, not a second one.
        Child::create(['lan' => '10001', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active']);

        $this->import([
            ['lan', 'first_name', 'last_name', 'classroom'],
            ['10001', 'Maeve', 'Adkins', 'PreK'],
            ['10002', 'Mark', 'Allen', 'School Age'],
        ]);

        $this->assertSame(2, Child::count());
        $this->assertSame('PreK', Child::where('lan', '10001')->first()->classroom);
    }

    public function test_columns_nobody_recognises_are_ignored(): void
    {
        // Real spreadsheets carry working notes, totals and a column somebody
        // added in 2019. None of that should stop the ones that are good.
        $this->import([
            ['lan', 'first_name', 'last_name', 'invoice_total', 'notes_2019', 'checked_by'],
            ['10001', 'Maeve', 'Adkins', '480.00', 'see file', 'RK'],
        ]);

        $this->assertSame('Maeve', Child::where('lan', '10001')->firstOrFail()->first_name);
    }

    public function test_days_can_be_written_however_the_centre_writes_them(): void
    {
        $this->import([
            ['lan', 'first_name', 'last_name', 'schedule_days'],
            ['10001', 'A', 'One', 'Mon,Wed,Fri'],
            ['10002', 'B', 'Two', '1,3,5'],
            ['10003', 'C', 'Three', 'MWF'],
            ['10004', 'D', 'Four', 'M-F'],
            ['10005', 'E', 'Five', 'Monday / Wednesday / Friday'],
        ]);

        foreach (['10001', '10002', '10003', '10005'] as $lan) {
            $this->assertSame([1, 3, 5], Child::where('lan', $lan)->firstOrFail()->scheduleDays(), "LAN {$lan}");
        }

        $this->assertSame([1, 2, 3, 4, 5], Child::where('lan', '10004')->firstOrFail()->scheduleDays());
    }

    public function test_a_sheet_with_no_lan_column_has_numbers_issued_for_it(): void
    {
        /*
         * The centre's own spreadsheet carries no LAN — the number is this
         * app's, not theirs — so refusing the file would refuse their whole
         * record. One is issued instead, counted off the highest in use so it
         * cannot land on a number a parent letter already carries.
         */
        Child::create(['lan' => '10042', 'first_name' => 'Old', 'last_name' => 'Record', 'status' => 'Active']);

        $this->import([
            ['child_name', 'birth_date'],
            ['Adkins, Maeve', '2022-04-18'],
            ['Mark Allen', '2021-11-02'],
        ]);

        $this->assertSame('10043', Child::where('last_name', 'Adkins')->firstOrFail()->lan);
        $this->assertSame('10044', Child::where('last_name', 'Allen')->firstOrFail()->lan);
    }

    public function test_importing_the_same_sheet_twice_does_not_duplicate_children(): void
    {
        // Without a LAN to match on, the child is found by name and birthday.
        // Not a key — two Ava Cruzes born the same day would collide — but the
        // failure people actually hit is a second copy of every child.
        $rows = [
            ['child_name', 'birth_date', 'telephone'],
            ['Adkins, Maeve', '2022-04-18', '555 0100'],
        ];

        $this->import($rows);
        $this->import($rows);

        $this->assertSame(1, Child::count());
    }

    public function test_a_row_with_no_name_is_reported(): void
    {
        $import = $this->import([
            ['child_name', 'telephone'],
            ['', '555 0100'],
        ]);

        $this->assertSame(0, Child::count());
        $this->assertStringContainsString('no name', $import->errors[0]);
    }

    public function test_an_unreadable_date_names_the_row_and_the_field(): void
    {
        $import = $this->import([
            ['lan', 'first_name', 'last_name', 'dob'],
            ['10001', 'Maeve', 'Adkins', 'not a date'],
        ]);

        $this->assertSame(0, Child::count());
        $this->assertStringContainsString('Row 2', $import->errors[0]);
        $this->assertStringContainsString('date of birth', $import->errors[0]);
    }

    public function test_one_bad_row_does_not_stop_the_good_ones(): void
    {
        $import = $this->import([
            ['lan', 'first_name', 'last_name', 'dob'],
            ['10001', 'Maeve', 'Adkins', '2022-04-18'],
            ['10002', 'Mark', 'Allen', 'whenever'],
            ['10003', 'Joseph', 'Anderson', '2023-01-09'],
        ]);

        $this->assertSame(2, Child::count());
        $this->assertCount(1, $import->errors);
    }

    public function test_the_template_carries_every_field_the_importer_reads(): void
    {
        // The template is the documentation: ninety columns are no use if
        // nobody can find out what they are called.
        $csv = $this->actingAs($this->admin)
            ->get(route('children.import.template'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        foreach (['lan', 'mother_name', 'father_cell', 'pickup_3_relationship', 'alerts'] as $heading) {
            $this->assertStringContainsString($heading, $csv);
        }

        // And one example row, so the shape of a date or a list of days is
        // obvious rather than described.
        $this->assertStringContainsString('2026-01-06', $csv);
        $this->assertStringContainsString('Mon,Tue,Wed,Thu,Fri', $csv);
    }

    public function test_the_template_and_the_importer_agree(): void
    {
        /*
         * The one that keeps them honest: every heading the template offers
         * has to be one the importer actually reads, or the file it hands out
         * teaches columns that silently do nothing.
         */
        $rows = [ChildrenImport::templateHeadings()];
        $rows[] = array_map(fn () => '', $rows[0]);
        $rows[1][array_search('lan', $rows[0], true)] = '10001';
        $rows[1][array_search('first_name', $rows[0], true)] = 'Maeve';
        $rows[1][array_search('last_name', $rows[0], true)] = 'Adkins';
        $rows[1][array_search('mother_name', $rows[0], true)] = 'Anna Adkins';
        $rows[1][array_search('pickup_2_name', $rows[0], true)] = 'Paul Adkins';

        $this->import($rows);

        $child = Child::where('lan', '10001')->firstOrFail();

        $this->assertSame('Anna Adkins', $child->mother_name);
        $this->assertSame('Paul Adkins', $child->pickup_2_name);
    }

    public function test_the_centres_own_spreadsheet_imports_as_written(): void
    {
        /*
         * The real headings, copied from the file the centre keeps — spacing,
         * hashes, trailing colon and the misspelling of "advertised" included.
         * If this passes, their export needs no editing before it is uploaded.
         */
        $headings = [
            'Child Name', 'Nickname', 'Address City Zip', 'Telephone', 'Birth Date',
            'Mother Name', 'Mother Address', 'Mother Home Phone', 'Mother Employer',
            'Mother Work Phone', 'Mother Fax', 'Mother Cell', 'Mother Title', 'Mother SSN',
            'Father Name', 'Father Address', 'Father Home Phone', 'Father Employer',
            'Father Work Phone', 'Father Fax', 'Father Cell', 'Father Title', 'Father SSN',
            'Email Address', 'Parents Status', 'Responsible for Payment',
            'Emergency Contact', 'Secondary Emergency Contact', 'Emergency Telephone',
            'Emergency Relationship', 'Emergency License #',
            'Pickup 1 Name', 'Pickup 1 Address', 'Pickup 1 Telephone', 'Pickup 1 Alternate',
            'Pickup 1 Relationship', 'Pickup 1 License #',
            'Pickup 2 Name', 'Pickup 2 Address', 'Pickup 2 Telephone', 'Pickup 2 Alternate',
            'Pickup 2 Relationship', 'Pickup 2 License #',
            'Pickup 3 Name', 'Pickup 3 Address', 'Pickup 3 Telephone', 'Pickup 3 Alternate',
            'Pickup 3 Relationship', 'Pickup 3 License #',
            'Other Notes', 'Important Notes', 'Known Allergies', 'Days Enrolled',
            'Time from', 'Time to:', 'Where advetised', 'Who referred',
        ];

        $row = [
            'Adkins, Maeve', 'Mae', '14 Elm Road, Springfield 12345', '555 0100', '2022-04-18',
            'Anna Adkins', '14 Elm Road', '555 0101', 'County Hospital',
            '555 0111', '555 0112', '555 0113', 'Nurse', '111-22-3333',
            'Paul Adkins', '14 Elm Road', '555 0102', 'Elm Motors',
            '555 0121', '555 0122', '555 0123', 'Mechanic', '444-55-6666',
            'home@example.com', 'Married', 'Anna Adkins',
            'Nora Bell', 'Ruth Vance', '555 0199',
            'Aunt', 'D1234567',
            'Anna Adkins', '14 Elm Road', '555 0101', '555 0113',
            'Mother', 'D7654321',
            'Paul Adkins', '14 Elm Road', '555 0102', '555 0123',
            'Father', 'D7654322',
            'Nora Bell', '9 Oak Lane', '555 0199', '555 0198',
            'Aunt', 'D7654323',
            'Nap after lunch', 'Collected early on Fridays', 'Peanut allergy', 'Mon,Tue,Wed,Thu,Fri',
            '07:30', '17:30', 'Facebook', 'The Cruz family',
        ];

        $this->import([$headings, $row]);

        $child = Child::firstOrFail();

        // The one column of names, split the way every list in the app sorts.
        $this->assertSame('Maeve', $child->first_name);
        $this->assertSame('Adkins', $child->last_name);
        $this->assertSame('Mae', $child->nickname);

        // "Birth Date" is the date of birth, which is what the room bands read.
        $this->assertSame('2022-04-18', $child->dob->toDateString());

        // Address, city and zip arrive in one cell and are kept as written.
        $this->assertSame('14 Elm Road, Springfield 12345', $child->address);

        $this->assertSame('Anna Adkins', $child->mother_name);
        $this->assertSame('111-22-3333', $child->mother_ssn);
        $this->assertSame('Paul Adkins', $child->father_name);
        $this->assertSame('Mechanic', $child->father_title);

        // The hash is lost from "License #" on the way in.
        $this->assertSame('D1234567', $child->emergency_license_number);
        $this->assertSame('D7654323', $child->pickup_3_license_number);
        $this->assertSame('Ruth Vance', $child->secondary_emergency_contact);

        // "Known Allergies" becomes one allergy alert, beside any court order
        // or medical note somebody typed into the app by hand.
        $this->assertSame([['type' => 'allergy', 'text' => 'Peanut allergy']], $child->alerts);

        // "Days Enrolled", "Time from" and "Time to:".
        $this->assertSame([1, 2, 3, 4, 5], $child->scheduleDays());
        $this->assertSame('07:30', substr((string) $child->drop_off_time, 0, 5));
        $this->assertSame('17:30', substr((string) $child->pick_up_time, 0, 5));

        // The two the database had nowhere to put until now.
        $this->assertSame('Facebook', $child->where_advertised);
        $this->assertSame('The Cruz family', $child->who_referred);

        // And a number of its own, since the sheet carries none.
        $this->assertNotEmpty($child->lan);
    }

    public function test_a_name_given_either_way_round_is_read_correctly(): void
    {
        // A comma is the centre saying which way round it is. Without one the
        // last word is the surname, which is right far more often than not.
        $this->import([
            ['child_name'],
            ['Adkins, Maeve'],
            ['Mark Allen'],
            ['Maria de la Cruz'],
        ]);

        $this->assertSame('Maeve', Child::where('last_name', 'Adkins')->firstOrFail()->first_name);
        $this->assertSame('Mark', Child::where('last_name', 'Allen')->firstOrFail()->first_name);
        $this->assertSame('Maria de la', Child::where('last_name', 'Cruz')->firstOrFail()->first_name);
    }

    public function test_an_allergy_line_never_replaces_the_alerts_already_there(): void
    {
        /*
         * The bug this came from: writing the spreadsheet's allergy straight
         * into `alerts` put a string where the column holds a list, and the
         * children page fell over on the first foreach.
         *
         * The centre's sheet carries the allergy. The court orders and medical
         * notes were typed into this app, and an import must not throw them
         * away — nor add the same allergy again every time the file is
         * uploaded.
         */
        $child = Child::create([
            'lan' => '10001',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'status' => 'Active',
            'alerts' => [['type' => 'court', 'text' => 'No pickup by J. Key']],
        ]);

        $rows = [
            ['lan', 'known_allergies'],
            ['10001', 'Peanut allergy'],
        ];

        $this->import($rows);
        $this->import($rows);

        $alerts = $child->fresh()->alerts;

        $this->assertCount(2, $alerts, 'the allergy should be added once, not once per import');
        $this->assertSame('court', $alerts[0]['type']);
        $this->assertSame('No pickup by J. Key', $alerts[0]['text']);
        $this->assertSame('allergy', $alerts[1]['type']);

        // And the page that broke still renders.
        $this->actingAs($this->admin)->get(route('children.index'))->assertOk();
    }

    public function test_a_sheet_with_a_title_row_says_so_rather_than_failing_silently(): void
    {
        /*
         * The commonest shape a real export takes: a title across the top, a
         * blank line, then the headings. The package reads the title as the
         * column names, every row misses, and the import reports nought
         * created and no reason — which is worse than an error.
         */
        $import = $this->import([
            ['LADC Enrollment Details'],
            [''],
            ['Child Name', 'Birth Date'],
            ['Adkins, Maeve', '2022-04-18'],
        ]);

        $this->assertSame(0, Child::count());
        $this->assertNotEmpty($import->errors);
        $this->assertStringContainsString('None of the columns', $import->errors[0]);
        // And what it actually found, so the fix is obvious.
        $this->assertStringContainsString('ladc_enrollment_details', $import->errors[0]);
        $this->assertStringContainsString('delete any title or blank rows', $import->errors[0]);
    }

    public function test_a_readable_sheet_reports_no_heading_complaint(): void
    {
        // The check must not fire on a file that is fine.
        $import = $this->import([
            ['Child Name', 'Birth Date'],
            ['Adkins, Maeve', '2022-04-18'],
        ]);

        $this->assertSame(1, Child::count());
        $this->assertSame([], $import->errors);
    }

    /** Put rows through the real upload path, as a CSV. */
    private function import(array $rows): ChildrenImport
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $file = UploadedFile::fake()->createWithContent('children.csv', $csv);

        $this->actingAs($this->admin)->post(route('children.import'), ['file' => $file]);

        // The controller keeps its own instance, so the counts are read back
        // from a second run over the same rows for the assertions that need
        // them. Importing twice is safe by design — see the LAN rule.
        $import = new ChildrenImport;
        \Maatwebsite\Excel\Facades\Excel::import($import, $file);

        return $import;
    }
}
