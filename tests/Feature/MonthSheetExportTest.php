<?php

namespace Tests\Feature;

use App\Exports\MonthRoomSheet;
use App\Exports\MonthSheetExport;
use App\Http\Controllers\MonthSheetController;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

/**
 * The month as the paper form, as a workbook.
 *
 * What these hold is the shape: a sheet per room, the month/year/room across
 * the top, a column a day, four lines a child in the form's order, a time or
 * a code in the right day's column, and the totals along the foot. Built from
 * the same rows the page draws, and that is asserted rather than assumed.
 */
class MonthSheetExportTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        // A room follows the child's age — the month builder re-syncs rooms
        // before grouping — so each child is given a date of birth that lands
        // squarely in the room the test wants them in.
        foreach ([
            ['10064', 'Maeve', 'Adkins', 'PreK'],
            ['10065', 'Mark', 'Allen', 'Toddler'],
        ] as [$lan, $first, $last, $room]) {
            Child::create([
                'lan' => $lan, 'first_name' => $first, 'last_name' => $last, 'status' => 'Active',
                'classroom' => $room, 'dob' => $this->dobForRoom($room), 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
            ]);
        }

        // Maeve on the 9th with codes, and on the 10th; Mark on the 9th only.
        $maeve = Child::where('lan', '10064')->sole();
        $mark = Child::where('lan', '10065')->sole();

        Attendance::create(['child_id' => $maeve->id, 'attendance_date' => '2026-09-09', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-09 08:05'), 'signed_out_at' => Carbon::parse('2026-09-09 17:30'), 'health_in_code' => 0, 'health_out_code' => 4]);
        Attendance::create(['child_id' => $maeve->id, 'attendance_date' => '2026-09-10', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-10 08:20')]);
        Attendance::create(['child_id' => $mark->id, 'attendance_date' => '2026-09-09', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-09 07:45'), 'signed_out_at' => Carbon::parse('2026-09-09 16:00'), 'health_in_code' => 0, 'health_out_code' => 0]);
    }

    public function test_the_workbook_is_a_sheet_per_room(): void
    {
        $export = $this->export();

        $this->assertSame(['PreK', 'Toddler'], array_map(fn (MonthRoomSheet $sheet) => $sheet->title(), $export->sheets()));
    }

    public function test_a_room_sheet_is_laid_out_as_the_form(): void
    {
        $rows = $this->roomSheet('PreK')->array();

        // Across the top: the month, the year, the room.
        $this->assertSame(['Month: September', 'Year: 2026', 'Room: PreK'], $rows[0]);

        // A column a day, headed by its number and then its weekday.
        $this->assertSame('Student', $rows[1][0]);
        $this->assertSame(range(1, 30), array_slice($rows[1], 2));
        $this->assertSame('TUE', $rows[2][2], '1 September 2026 is a Tuesday');

        // Four lines a child, in the form's order, name first and hours second.
        $this->assertSame(['Adkins, Maeve', 'In'], array_slice($rows[3], 0, 2));
        $this->assertSame('health', $rows[4][1]);
        $this->assertStringContainsString('8:00', $rows[4][0]);
        $this->assertSame(['', 'Out'], array_slice($rows[5], 0, 2));
        $this->assertSame(['', 'health'], array_slice($rows[6], 0, 2));
    }

    public function test_times_and_codes_land_in_their_own_day_column(): void
    {
        $rows = $this->roomSheet('PreK')->array();

        // Day 9 is column index 2 + 8; day 10 is one to the right.
        $nine = 2 + 8;
        $ten = $nine + 1;

        $this->assertSame('8:05a', $rows[3][$nine]);
        $this->assertSame('0', $rows[4][$nine], 'nought is a reading and is printed');
        $this->assertSame('5:30p', $rows[5][$nine]);
        $this->assertSame('4', $rows[6][$nine]);

        // The 10th: in, checked nowhere, never out.
        $this->assertSame('8:20a', $rows[3][$ten]);
        $this->assertSame('', $rows[4][$ten], 'no reading is blank, not nought');
        $this->assertSame('', $rows[5][$ten]);

        // A day nobody came is empty right down the block.
        $this->assertSame('', $rows[3][2]);
    }

    public function test_the_totals_run_along_the_foot(): void
    {
        $rows = $this->roomSheet('PreK')->array();

        $foot = $rows[count($rows) - 2];
        $this->assertSame('Total kids per day', $foot[0]);
        $this->assertSame(1, $foot[2 + 8]);
        $this->assertSame(1, $foot[2 + 9]);
        $this->assertSame(0, $foot[2]);

        $this->assertSame(['Total kids for month: 2'], $rows[count($rows) - 1]);
    }

    public function test_the_file_downloads_as_a_workbook(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026]))
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('attendance-2026-09.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_one_room_can_be_asked_for(): void
    {
        $export = $this->export('Toddler');

        $this->assertSame(['Toddler'], array_map(fn (MonthRoomSheet $sheet) => $sheet->title(), $export->sheets()));

        $response = $this->actingAs($this->admin)
            ->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026, 'room' => 'Toddler']))
            ->assertOk();

        $this->assertStringContainsString('attendance-2026-09-toddler.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_the_page_still_draws_the_same_month(): void
    {
        // The page and the file come from one builder; the page did not change.
        $this->actingAs($this->admin)
            ->get(route('attendance.month-sheet', ['month' => 9, 'year' => 2026]))
            ->assertOk()
            ->assertSee('Adkins, Maeve')
            ->assertSee('Total kids for month:');
    }

    /**
     * The back of the paper sheet, typed up: when the month is exported from
     * the page, the comments come as a last sheet — a line a comment, with
     * the day, the child, who wrote it and when — oldest first. A room's
     * export carries that room's alone.
     */
    public function test_the_comments_come_as_a_last_sheet(): void
    {
        $maeve = Child::where('lan', '10064')->sole();
        $mark = Child::where('lan', '10065')->sole();
        $teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Ms. Alvarez']);
        // Comments are the door's, so the days they sit on are door rows.
        $maevesDay = \App\Models\DoorAttendance::create(['child_id' => $maeve->id, 'attendance_date' => '2026-09-09', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-09 08:01')]);
        $marksDay = \App\Models\DoorAttendance::create(['child_id' => $mark->id, 'attendance_date' => '2026-09-09', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-09 07:50')]);

        \App\Models\AttendanceComment::create(['attendance_id' => $maevesDay->id, 'child_id' => $maeve->id, 'user_id' => $teacher->id, 'tag' => 'parent_note', 'body' => 'Mom says he slept poorly last night.'])
            ->forceFill(['created_at' => Carbon::parse('2026-09-09 08:02', config('app.timezone'))])->save();
        \App\Models\AttendanceComment::create(['attendance_id' => $marksDay->id, 'child_id' => $mark->id, 'user_id' => $teacher->id, 'tag' => 'belongings', 'body' => 'Brought 6 diapers.'])
            ->forceFill(['created_at' => Carbon::parse('2026-09-09 08:05', config('app.timezone'))])->save();

        // The whole month: both, oldest first, after the room sheets.
        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026]))->assertOk();
        $path = storage_path('app/test-month.xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $titles = array_map(fn ($sheet) => $sheet->getTitle(), $book->getAllSheets());
        $this->assertSame('Comments', end($titles));
        $comments = $book->getSheetByName('Comments');
        $this->assertSame(['Date', 'Child', 'Room', 'Session', 'Tag', 'Written by', 'Time', 'Comment'], array_map(fn ($c) => $comments->getCell($c.'1')->getValue(), range('A', 'H')));
        $this->assertSame(['2026-09-09', 'Adkins, Maeve', 'PreK', 'Day', 'Parent note', 'Ms. Alvarez', '8:02 AM', 'Mom says he slept poorly last night.'], array_map(fn ($c) => $comments->getCell($c.'2')->getValue(), range('A', 'H')));
        $this->assertSame('Brought 6 diapers.', $comments->getCell('H3')->getValue());

        // One room: that room's comments only.
        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026, 'room' => 'Toddler']))->assertOk();
        file_put_contents($path, $response->streamedContent());
        $comments = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Comments');
        $this->assertSame('Brought 6 diapers.', $comments->getCell('H2')->getValue());
        $this->assertNull($comments->getCell('H3')->getValue());
        @unlink($path);
    }

    /**
     * One sheet per room, named for the room — and the door's record laid
     * under the register's, so a health check taken at the door shows on
     * the room's sheet rather than on a second sheet nobody opens.
     */
    public function test_the_sheets_are_named_for_the_rooms_and_carry_the_doors_health_checks(): void
    {
        $maeve = Child::where('lan', '10064')->sole();
        \App\Models\DoorAttendance::create(['child_id' => $maeve->id, 'attendance_date' => '2026-09-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-16 08:01'), 'health_in_code' => 3]);

        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026]))->assertOk();
        $path = storage_path('app/test-month.xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        $titles = array_map(fn ($sheet) => $sheet->getTitle(), $book->getAllSheets());
        $this->assertContains('PreK', $titles);
        $this->assertEmpty(array_filter($titles, fn ($title) => str_contains($title, 'Register') || str_contains($title, 'Door')));

        // Maeve's health line, in the column for the 16th: the door's code.
        $prek = $book->getSheetByName('PreK');
        $rows = $prek->toArray();
        $nameRow = collect($rows)->search(fn ($row) => ($row[0] ?? null) === 'Adkins, Maeve');
        $this->assertNotFalse($nameRow);
        $this->assertSame('health', $rows[$nameRow + 1][1]);
        $this->assertSame('3', (string) $rows[$nameRow + 1][2 + 15]);
        $this->assertSame('8:01a', $rows[$nameRow][2 + 15]);
        @unlink($path);
    }

    /**
     * A child who left and came back is two entries, and the sheet prints
     * both — one a line inside the day's cell — each with the check taken
     * at it, the earlier one read back out of the audit the row's columns
     * no longer hold.
     */
    public function test_every_clock_in_and_out_of_a_day_is_printed_with_its_own_check(): void
    {
        $mark = Child::where('lan', '10065')->sole();

        // And a Toddler who never came all month: four blank lines, not an error.
        Child::create([
            'lan' => '10066', 'first_name' => 'Nia', 'last_name' => 'Zane', 'status' => 'Active',
            'classroom' => 'Toddler', 'dob' => $this->dobForRoom('Toddler'), 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
        ]);

        // The 14th: in at 8:00 checked 0, out at 11:00 checked 2, back at
        // 1:00 checked 5 — which replaced the arrival code and cleared the
        // leaving one, as the door does — and still in.
        $this->travelTo(Carbon::parse('2026-09-14 08:00:00'));
        $row = Attendance::create(['child_id' => $mark->id, 'attendance_date' => '2026-09-14', 'session' => 'FULL', 'signed_in_at' => now()]);
        $row->healthAudits()->create(['direction' => 'in', 'old_code' => null, 'new_code' => 0]);

        $this->travelTo(Carbon::parse('2026-09-14 11:00:00'));
        $row->healthAudits()->create(['direction' => 'out', 'old_code' => null, 'new_code' => 2]);

        $this->travelTo(Carbon::parse('2026-09-14 13:00:00'));
        $row->returns()->create(['left_at' => Carbon::parse('2026-09-14 11:00'), 'returned_at' => now()]);
        $row->healthAudits()->create(['direction' => 'in', 'old_code' => 0, 'new_code' => 5]);
        $row->forceFill(['health_in_code' => 5, 'health_out_code' => null])->save();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $rows = $this->roomSheet('Toddler')->array();

        // The 14th is two columns wide — the number and weekday over the
        // first — and every later day sits one column further right. Mark
        // still has four lines, and each of his arrivals is headed with
        // which entry it is, on the line above the time in its own cell.
        $first = 2 + 13;
        $second = $first + 1;
        $this->assertSame([14, ''], array_slice($rows[1], $first, 2));
        $this->assertSame(['MON', ''], array_slice($rows[2], $first, 2));
        $this->assertSame(15, $rows[1][$second + 1]);
        $this->assertCount(2 + 31, $rows[1], 'thirty days and one of them twice');

        $this->assertSame(['In', 'health', 'Out', 'health', 'In'], array_map(fn ($row) => $row[1], array_slice($rows, 3, 5)));
        $this->assertSame('Allen, Mark', $rows[3][0]);

        $this->assertSame(["1st\n8:00a", "2nd\n1:00p"], array_slice($rows[3], $first, 2));
        $this->assertSame(['0', '5'], array_slice($rows[4], $first, 2), 'the first arrival keeps the check the second one replaced');
        $this->assertSame(['11:00a', ''], array_slice($rows[5], $first, 2), 'still in: the second entry has no out');
        $this->assertSame(['2', ''], array_slice($rows[6], $first, 2));

        // A day with one entry reads as it always did: no label over the time.
        $this->assertSame('7:45a', $rows[3][2 + 8]);
        $this->assertSame('0', $rows[4][2 + 8]);

        // Nia's four lines follow, empty in both of the 14th's columns; then the foot.
        $this->assertSame(['Zane, Nia', 'In'], array_slice($rows[7], 0, 2));
        $this->assertSame(['', ''], array_slice($rows[7], $first, 2));

        // One child on the 14th, however many times the door opened; the
        // count sits under the first column and nothing under the second.
        $this->assertSame('Total kids per day', $rows[11][0]);
        $this->assertSame([1, ''], array_slice($rows[11], $first, 2));
    }

    /** The workbook itself opens with the day's headers merged across its columns. */
    public function test_a_wide_day_is_headed_once_in_the_file(): void
    {
        $mark = Child::where('lan', '10065')->sole();
        $row = Attendance::create(['child_id' => $mark->id, 'attendance_date' => '2026-09-14', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-09-14 08:00'), 'signed_out_at' => Carbon::parse('2026-09-14 15:00')]);
        $row->returns()->create(['left_at' => Carbon::parse('2026-09-14 11:00'), 'returned_at' => Carbon::parse('2026-09-14 13:00')]);

        // And a Toddler who never came, to sit under Mark.
        Child::create([
            'lan' => '10066', 'first_name' => 'Nia', 'last_name' => 'Zane', 'status' => 'Active',
            'classroom' => 'Toddler', 'dob' => $this->dobForRoom('Toddler'), 'drop_off_time' => '08:00', 'pick_up_time' => '17:00',
        ]);

        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 9, 'year' => 2026, 'room' => 'Toddler']))->assertOk();
        $path = storage_path('app/test-wide-day.xlsx');
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetByName('Toddler');

        // Day 14 starts at column P (2 + 14 = 16th column) and is two wide.
        $this->assertContains('P2:Q2', array_keys($sheet->getMergeCells()));
        $this->assertContains('P3:Q3', array_keys($sheet->getMergeCells()));
        $this->assertSame("1st\n8:00a", $sheet->getCell('P4')->getValue());
        $this->assertSame("2nd\n1:00p", $sheet->getCell('Q4')->getValue());
        $this->assertTrue($sheet->getStyle('P4')->getAlignment()->getWrapText(), 'two lines in the cell, shown as two');
        $this->assertSame('11:00a', $sheet->getCell('P6')->getValue());
        $this->assertSame('3:00p', $sheet->getCell('Q6')->getValue());
        $this->assertSame('C4', $sheet->getFreezePane());

        // Nia never came: her four lines, and the foot's count, span the
        // 14th as one cell each rather than a cell and a blank.
        $merges = array_keys($sheet->getMergeCells());
        foreach ([8, 9, 10, 11] as $row) {
            $this->assertContains("P{$row}:Q{$row}", $merges, 'Nia row '.$row);
        }
        $this->assertContains('P12:Q12', $merges, 'the foot');
        $this->assertNotContains('P4:Q4', $merges, 'Mark used both columns');
        @unlink($path);
    }

    private function export(?string $room = null): MonthSheetExport
    {
        $sheet = app(MonthSheetController::class)->month($this->admin, 9, 2026);

        return new MonthSheetExport($sheet['rows'], $sheet['days'], $sheet['perDay'], 9, 2026, $room);
    }

    private function roomSheet(string $room): MonthRoomSheet
    {
        foreach ($this->export()->sheets() as $sheet) {
            if ($sheet->title() === $room) {
                return $sheet;
            }
        }

        $this->fail('No sheet for '.$room);
    }
}
