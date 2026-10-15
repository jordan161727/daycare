<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\DoorAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A day worked on the director's register, carried onto the door's record
 * so Teacher Attendance shows it too — without touching what the door
 * already had.
 */
class CopyRegisterToDoorTest extends TestCase
{
    use RefreshDatabase;

    private Child $maeve;

    private Child $mark;

    protected function setUp(): void
    {
        parent::setUp();

        // Set aside on 16 Oct 2026: the door reads the register's tables for
        // now, so there is nothing to copy between them. Lift this with the
        // skip in SeparateRecordsTest when the split comes back.
        $this->markTestSkipped("The door reads the register's tables for now (16 Oct 2026).");

        $this->maeve = Child::create(['lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active', 'classroom' => 'PreK']);
        $this->mark = Child::create(['lan' => '10065', 'first_name' => 'Mark', 'last_name' => 'Bell', 'status' => 'Active', 'classroom' => 'Toddler']);

        $maeve = Attendance::create(['child_id' => $this->maeve->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 08:01'), 'signed_out_at' => Carbon::parse('2026-10-15 16:30'), 'health_in_code' => 3, 'health_in_note' => 'Runny nose', 'health_out_code' => 0]);
        $maeve->returns()->create(['left_at' => Carbon::parse('2026-10-15 11:00'), 'returned_at' => Carbon::parse('2026-10-15 12:00')]);
        Attendance::create(['child_id' => $this->mark->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 07:50'), 'health_in_code' => 0]);
    }

    public function test_a_days_register_rows_are_copied_onto_the_door(): void
    {
        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-15'])
            ->expectsOutputToContain('2 copied to the door, 0 replaced, 0 already on the door')
            ->assertSuccessful();

        $door = DoorAttendance::where('child_id', $this->maeve->id)->whereDate('attendance_date', '2026-10-15')->sole();
        $this->assertSame(3, $door->health_in_code);
        $this->assertSame('Runny nose', $door->health_in_note);
        $this->assertSame(0, $door->health_out_code);
        $this->assertSame('08:01', $door->signed_in_at->format('H:i'));
        $this->assertSame('16:30', $door->signed_out_at->format('H:i'));
        $this->assertCount(1, $door->returns);

        $this->assertSame(2, DoorAttendance::count());
        // The register is untouched.
        $this->assertSame(2, Attendance::count());
    }

    public function test_rows_the_door_already_has_are_kept_unless_overwrite_is_asked_for(): void
    {
        DoorAttendance::create(['child_id' => $this->mark->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 07:55'), 'health_in_code' => 2]);

        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-15'])
            ->expectsOutputToContain('1 copied to the door, 0 replaced, 1 already on the door')
            ->assertSuccessful();

        $this->assertSame(2, DoorAttendance::where('child_id', $this->mark->id)->sole()->health_in_code, 'the door\'s own check stands');

        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-15', '--overwrite' => true])
            ->expectsOutputToContain('0 copied to the door, 2 replaced, 0 already on the door')
            ->assertSuccessful();

        $this->assertSame(0, DoorAttendance::where('child_id', $this->mark->id)->sole()->health_in_code, 'now the register\'s');
        $this->assertSame(2, DoorAttendance::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-15', '--dry-run' => true])
            ->expectsOutputToContain('[dry run] 2026-10-15: 2 register rows — 2 copied')
            ->assertSuccessful();

        $this->assertSame(0, DoorAttendance::count());
    }

    public function test_a_day_with_no_register_rows_is_said_so(): void
    {
        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-14'])
            ->expectsOutputToContain('No register rows on 2026-10-14')
            ->assertSuccessful();
    }
}
