<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\DoorHealthAudit;
use App\Models\HealthAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Teacher Attendance and Director Attendance are two records that do not
 * meet.
 *
 * They used to share one table, so a clock-in at the door showed on the
 * register and a sign-in on the register showed at the door. The centre
 * asked for them kept apart (16 Oct 2026). These tests are the fence: each
 * screen writes to and reads from its own tables, the month export carries
 * both as separate sheets, and nothing crosses.
 */
class SeparateRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-16 08:00:00', config('app.timezone')));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->child = Child::create(['lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-06-01']);
        app(\App\Services\WeekSchedule::class)->open('2026-10-12');
    }

    public function test_a_clock_in_at_the_door_does_not_reach_the_register(): void
    {
        $this->actingAs($this->admin)->postJson(route('check-in.store'), ['child_id' => $this->child->id, 'session' => 'FULL', 'health_code' => 3])->assertOk();

        // The door's own tables took it, with its audit…
        $this->assertSame(1, DoorAttendance::count());
        $this->assertSame(1, DoorHealthAudit::count());
        // …and the register's have nothing.
        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, HealthAudit::count());

        // The door shows it; the register does not.
        $door = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent());
        $this->assertStringContainsString('"in":"8:00a","in_code":3', $door);
        $register = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-10-16']))->assertOk()->getContent());
        $this->assertStringNotContainsString('"'.$this->child->id.'":{"2026-10-16"', $register);
    }

    public function test_a_sign_in_on_the_register_does_not_reach_the_door(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), ['child_id' => $this->child->id, 'attendance_date' => '2026-10-16', 'health_code' => 2])->assertOk();

        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, HealthAudit::count());
        $this->assertSame(0, DoorAttendance::count());
        $this->assertSame(0, DoorHealthAudit::count());

        $register = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-10-16']))->assertOk()->getContent());
        $this->assertStringContainsString('"'.$this->child->id.'":{"2026-10-16":{"FULL":"8:00a"', $register);
        $door = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent());
        $this->assertStringContainsString('"bySession":[]', $door);
    }

    public function test_the_two_clocks_can_disagree_and_each_screen_reads_its_own(): void
    {
        // The door says 7:46; the register says 8:30. Neither is corrected by the other.
        DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 07:46', config('app.timezone'))]);
        Attendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 08:30', config('app.timezone'))]);

        $door = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('check-in.index'))->getContent());
        $this->assertStringContainsString('"in":"7:46a"', $door);
        $this->assertStringNotContainsString('"in":"8:30a"', $door);

        $register = str_replace(chr(92).'u0022', '"', $this->actingAs($this->admin)->get(route('attendance.index', ['date' => '2026-10-16']))->getContent());
        $this->assertStringContainsString('"FULL":"8:30a"', $register);
        $this->assertStringNotContainsString('"FULL":"7:46a"', $register);
    }

    public function test_comments_hang_off_the_doors_row(): void
    {
        $door = DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->actingAs($this->admin)->postJson(route('attendances.comments.store', $door), ['tag' => 'health', 'body' => 'Small rash on left cheek.'])->assertCreated();
        $this->assertSame($door->id, AttendanceComment::sole()->attendance_id);
        $this->assertInstanceOf(DoorAttendance::class, AttendanceComment::sole()->attendance);

        // A register row's id is not a door row: nothing to comment on.
        $register = Attendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => now()]);
        DoorAttendance::whereKey($register->id)->delete();
        $this->actingAs($this->admin)->postJson(route('attendances.comments.store', $register->id), ['tag' => 'other', 'body' => 'Hi'])->assertNotFound();
    }

    public function test_the_month_page_reads_either_record_and_the_export_carries_both(): void
    {
        DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 07:46', config('app.timezone')), 'signed_out_at' => Carbon::parse('2026-10-15 16:04', config('app.timezone'))]);
        Attendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 08:30', config('app.timezone'))]);

        // The page: the register by default, the door on request, with the toggle on both.
        $this->actingAs($this->admin)->get(route('attendance.month-sheet', ['month' => 10, 'year' => 2026]))->assertOk()
            ->assertSee('8:30a')->assertDontSee('7:46a')->assertSee('data-source-toggle', false);
        $this->actingAs($this->admin)->get(route('attendance.month-sheet', ['month' => 10, 'year' => 2026, 'source' => 'door']))->assertOk()
            ->assertSee('7:46a')->assertDontSee('8:30a');

        // The workbook: one sheet per room, as the paper form is kept, with
        // the register's day on top and the door's filling what the register
        // left blank (see MonthSheetController::overlay). A read-time
        // overlay for the file only — nothing is written from one record to
        // the other, which the assertions above have already shown.
        $response = $this->actingAs($this->admin)->get(route('attendance.month-sheet.export', ['month' => 10, 'year' => 2026]))->assertOk();
        $path = storage_path('app/test-split.xlsx');
        file_put_contents($path, $response->streamedContent());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $titles = array_map(fn ($sheet) => $sheet->getTitle(), $book->getAllSheets());
        $this->assertSame(['Infant', 'Comments'], $titles);
        $this->assertSame('8:30a', $book->getSheetByName('Infant')->getCell('Q4')->getValue(), 'day 15 is column 2 + 15; the register\'s time wins');
        @unlink($path);

        $this->assertSame(1, Attendance::count(), 'the export wrote nothing onto the register');
        $this->assertSame(1, DoorAttendance::count(), 'nor onto the door');
    }
}
