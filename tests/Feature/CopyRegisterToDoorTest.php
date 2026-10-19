<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The copy-to-door command, now that the door reads the register's own table.
 *
 * It was written for the four days (16–20 Oct 2026) when Teacher Attendance
 * had tables of its own. With one record there is nothing to copy, and a
 * copy would double the rows — so the command says so and writes nothing.
 */
class CopyRegisterToDoorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_says_the_records_are_one_and_writes_nothing(): void
    {
        $maeve = Child::create(['lan' => '10064', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active', 'classroom' => 'PreK']);
        Attendance::create(['child_id' => $maeve->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 08:01'), 'health_in_code' => 3]);

        $this->artisan('attendance:copy-register-to-door', ['date' => '2026-10-15'])
            ->expectsOutputToContain('one record now')
            ->assertSuccessful();

        $this->assertSame(1, Attendance::count(), 'no row was doubled');
    }
}
