<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hours a child is contracted for, on the attendance sheet.
 *
 * Set once on their record at registration and read at both ends of the day:
 * the sheet is what is open at drop-off and at pick-up, and "when is this one
 * due?" is the question being asked at both. The day columns say which days; a
 * column of its own says the hours of them.
 */
class AttendanceHoursColumnTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pinned to a Wednesday.
     *
     * Left on the real clock these ran inside whatever week today fell in, and
     * on a Saturday or Sunday that is a week which has already ended, so the
     * Set-schedule view is correctly withheld and half of this failed. A test
     * about a column should not also be a test of what day it is run on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 09:00:00'));
    }

    public function test_the_sheet_carries_each_childs_own_hours(): void
    {
        $this->makeChild(['drop_off_time' => '08:30', 'pick_up_time' => '17:30']);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // A column beside the boxes, after the age, before the week.
        $this->assertStringContainsString('>Hours</th>', $html);
        $this->assertStringContainsString("x-text=\"child.schedule_hours || '—'\"", $html);

        // The hours themselves reach the browser, straight off the record.
        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));
        $rows = json_decode(json_decode('"'.$matches[1].'"'), associative: true);

        $this->assertSame('8:30 AM – 5:30 PM', $rows[0]['schedule_hours']);
    }

    /**
     * Both readings of the same week carry it. The set-schedule view already
     * mirrors the sheet's DOB and Age columns for exactly this reason.
     */
    public function test_the_set_schedule_view_shows_them_too(): void
    {
        $this->makeChild(['drop_off_time' => '07:30', 'pick_up_time' => '16:30']);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, '>Hours</th>'));
    }

    public function test_a_child_with_no_hours_agreed_reads_as_a_dash(): void
    {
        // Blank is a real answer — nobody has agreed the hours yet — and the
        // sheet says so rather than inventing a default day.
        $this->makeChild();

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));
        $rows = json_decode(json_decode('"'.$matches[1].'"'), associative: true);

        $this->assertNull($rows[0]['schedule_hours']);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => '1001',
            'status' => 'Active',
            'first_name' => 'Levi',
            'last_name' => 'Manney',
            'classroom' => 'Toddler',
            'birth_date' => '2024-06-01',
        ]);
    }
}
