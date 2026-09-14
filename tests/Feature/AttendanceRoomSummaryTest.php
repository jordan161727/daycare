<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three numbers in the header follow the room being looked at.
 *
 * Clicking "Infant 3" used to leave the centre's totals sitting above a sheet
 * showing three children — a room of three with "13 enrolled" over it. Now the
 * header answers "how is Infant doing this morning" instead.
 */
class AttendanceRoomSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counts_are_scoped_to_the_room_in_view(): void
    {
        $this->makeChild('Infant');
        $this->makeChild('Toddler');

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // All three read off one scope, so they can never disagree with each
        // other or with the sheet under them.
        $this->assertStringContainsString('x-text="enrolledCount"', $html);
        $this->assertStringContainsString('x-text="presentCount"', $html);
        $this->assertStringContainsString('x-text="absentCount"', $html);

        $this->assertStringContainsString(
            "return this.room === ''\n            ? this.childrenData\n            : this.childrenData.filter(child => child.classroom === this.room);",
            $html
        );

        // And the header names the room, so three numbers that changed meaning
        // when a filter was clicked are not three numbers that changed silently.
        $this->assertStringContainsString('x-show="room !== \'\'"', $html);
    }

    /**
     * Typing a name is looking something up, not changing what you are
     * responsible for — watching the roll fall to one as you type would be
     * alarming for no reason.
     */
    public function test_the_search_box_does_not_move_the_counts(): void
    {
        $this->makeChild('Infant');

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // filteredChildren is the search-and-room list the sheet draws;
        // scopeChildren is the room-only list the header counts.
        $this->assertStringContainsString('get scopeChildren()', $html);
        $this->assertStringNotContainsString('this.filteredChildren.filter(child => this.hasAnyAttendanceForDate', $html);
    }

    /**
     * Counted off the attendance the page was given rather than carried as a
     * running total, so a sign-in updates the header by updating the sheet and
     * there is nothing to keep in step by hand.
     */
    public function test_the_present_count_is_derived_not_tallied(): void
    {
        $this->makeChild('Infant');

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('hasAnyAttendanceForDate(child.id, this.countDate)', $html);
        $this->assertStringNotContainsString('this.presentCount++', $html);
    }

    private function makeChild(string $room): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Child'.Child::count(),
            'classroom' => $room,
        ]);
    }
}
