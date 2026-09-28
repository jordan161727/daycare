<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "I want to count who is expected to come."
 *
 * The sentence above the sheet said enrolled, in, not in. "Not in" is every
 * child on the roll who has not arrived — including the ones who were never
 * coming today — so it never answered the question a room asks at nine:
 * how many of the children we booked are still to walk in.
 *
 * Two numbers now sit between enrolled and in: expected, and still to come.
 * Both are counted off the same ticks the boxes are painted from and the
 * same attendance "in" is counted from, so the sentence cannot disagree with
 * the sheet under it. That is the property these pin — the getters read the
 * sheet's own state, and a tap or a sign-in moves them by moving the sheet.
 */
class ExpectedCountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        app(WeekSchedule::class)->open('2026-09-21');
    }

    public function test_the_strip_says_expected_and_still_to_come(): void
    {
        $html = $this->sheet();

        // In reading order: what was planned, who has come, the gap, then the
        // roll-wide absence that was already there.
        $this->assertStringContainsString('x-text="expectedCount"', $html);
        $this->assertStringContainsString('x-text="awaitedCount"', $html);

        $this->assertMatchesRegularExpression(
            '/x-text="enrolledCount".*?x-text="expectedCount".*?x-text="presentCount".*?x-text="awaitedCount".*?x-text="absentCount"/s',
            $html
        );

        $this->assertStringContainsString('</b> expected</span>', $html);
        $this->assertStringContainsString('</b> still to come</span>', $html);
    }

    public function test_expected_is_read_off_the_ticks_the_boxes_are_painted_from(): void
    {
        $html = $this->sheet();

        // Any ticked session on the counted day, in the room being looked at.
        $this->assertStringContainsString(
            "return Object.values(this.schedule?.[childId]?.[date] ?? {}).some(ticked => ticked === true);",
            $html
        );
        $this->assertStringContainsString(
            'return this.scopeChildren.filter(child => this.isExpectedOn(child.id, this.countDate)).length;',
            $html
        );
    }

    public function test_still_to_come_is_expected_minus_arrived(): void
    {
        $html = $this->sheet();

        // Expected and not yet in — the number a room acts on. Read off the
        // same attendance map "in" is counted from, so the two move together.
        $this->assertStringContainsString(
            'this.isExpectedOn(child.id, this.countDate) && ! this.hasAnyAttendanceForDate(child.id, this.countDate)',
            $html
        );
    }

    public function test_nothing_is_kept_as_a_running_total(): void
    {
        // The same rule "in" already lives by: the sentence is derived from the
        // sheet, never incremented beside it, so it cannot drift.
        $html = $this->sheet();

        $this->assertStringNotContainsString('this.expectedCount++', $html);
        $this->assertStringNotContainsString('this.awaitedCount++', $html);
        $this->assertStringNotContainsString('this.expectedCount =', $html);
    }

    private function sheet(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();
    }
}
