<?php

namespace Tests\Feature;

use App\Exports\StaffTimesheetExport;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The week grid, taken away as a file.
 *
 * What it has to get right is that the file and the screen agree. A director
 * who filters the grid to one role and exports it is entitled to that grid,
 * not the whole centre — and a file that quietly carries more people than the
 * page it came from is a payroll document with strangers in it.
 */
class StaffTimesheetExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so there are days behind and ahead inside the week — the
        // grid's own tests freeze here, and a status that means "the day is
        // over" cannot be tested against a clock that keeps moving.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin', 'job_role' => 'Director']);
    }

    private function punch(User $staff, string $at, string $type): void
    {
        app(TimeClock::class)->punch($staff, $type, Carbon::parse($at));
    }

    public function test_the_grid_can_be_taken_away_as_a_spreadsheet(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('staff.timesheets.export', ['from' => '2026-09-21', 'to' => '2026-09-25']))
            ->assertOk();

        Excel::assertDownloaded('timesheet-2026-09-21-to-2026-09-25.xlsx');
    }

    public function test_csv_is_offered_as_well(): void
    {
        Excel::fake();

        $this->actingAs($this->admin)
            ->get(route('staff.timesheets.export', ['from' => '2026-09-21', 'to' => '2026-09-21', 'format' => 'csv']))
            ->assertOk();

        Excel::assertDownloaded('timesheet-2026-09-21.csv');
    }

    public function test_a_row_per_person_per_day_worked(): void
    {
        $staff = User::factory()->create([
            'role' => 'teacher', 'name' => 'Aisha Khan', 'job_role' => 'Lead Teacher', 'pay_rate' => 17.25,
        ]);

        $this->punch($staff, '2026-09-21 12:02', TimePunch::IN);
        $this->punch($staff, '2026-09-21 14:31', TimePunch::OUT);

        $rows = $this->exportRows(['from' => '2026-09-21', 'to' => '2026-09-25']);

        $mine = $rows->where(1, 'Aisha Khan')->values();

        $this->assertCount(1, $mine, 'Only the day actually worked belongs in the file.');
        $this->assertSame('Lead Teacher', $mine[0][2]);
        $this->assertSame('2026-09-21', $mine[0][4]);
        $this->assertSame('12:02 PM', $mine[0][6]);
        $this->assertSame('2:31 PM', $mine[0][7]);
    }

    /**
     * A blank status column would read as unchecked rather than as fine, and
     * the colour the grid uses does not survive being put in a spreadsheet.
     */
    public function test_the_dot_is_spelled_out_in_words(): void
    {
        $staff = User::factory()->create(['role' => 'teacher', 'name' => 'Grace Hopper']);

        $this->punch($staff, '2026-09-21 08:00', TimePunch::IN);

        $rows = $this->exportRows(['from' => '2026-09-21', 'to' => '2026-09-21']);

        $mine = $rows->firstWhere(1, 'Grace Hopper');

        $this->assertSame('Missing time out', $mine[8]);
        $this->assertNull($mine[7], 'No time out happened, so the column stays empty rather than inventing one.');
    }

    public function test_the_role_filter_rides_along_so_the_file_is_the_page(): void
    {
        $kept = User::factory()->create(['role' => 'teacher', 'name' => 'Katherine Johnson', 'job_role' => 'Assistant']);
        $dropped = User::factory()->create(['role' => 'teacher', 'name' => 'Mary Jackson', 'job_role' => 'Floater']);

        $this->punch($kept, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($kept, '2026-09-21 16:00', TimePunch::OUT);
        $this->punch($dropped, '2026-09-21 08:00', TimePunch::IN);
        $this->punch($dropped, '2026-09-21 16:00', TimePunch::OUT);

        $rows = $this->exportRows(['from' => '2026-09-21', 'to' => '2026-09-21', 'role' => 'Assistant']);

        $names = $rows->pluck(1);

        $this->assertTrue($names->contains('Katherine Johnson'));
        $this->assertFalse($names->contains('Mary Jackson'));
    }

    public function test_a_teacher_cannot_help_themselves_to_everybody_s_pay_rate(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)
            ->get(route('staff.timesheets.export', ['from' => '2026-09-21', 'to' => '2026-09-25']))
            ->assertForbidden();
    }

    /** The rows the download would contain. */
    private function exportRows(array $query)
    {
        Excel::fake();

        $this->actingAs($this->admin)->get(route('staff.timesheets.export', $query))->assertOk();

        $rows = collect();

        Excel::assertDownloaded(
            'timesheet-'.$query['from'].($query['from'] === $query['to'] ? '' : '-to-'.$query['to']).'.xlsx',
            function (StaffTimesheetExport $export) use (&$rows) {
                $rows = $export->collection();

                return true;
            }
        );

        return $rows;
    }
}
