<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Recent sign-ins: a drawer off the right edge, not a slab under the sheet.
 *
 * It used to be a full-width panel below sixty rows of register — the one place
 * on the page nobody looks. You had to scroll past everything the page is for
 * to reach the thing that changes every few minutes.
 */
class RecentSignInsDrawerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 14:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_arrivals_are_behind_a_button_rather_than_under_the_sheet(): void
    {
        $this->signIn($this->makeChild());

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // A button that opens it, and the count on the button — so "how many
        // are in" is answered without opening anything at all.
        $this->assertStringContainsString('@click="recentOpen = true"', $html);
        $this->assertStringContainsString('x-text="recent.length"', $html);

        // The old panel under the register is gone.
        $this->assertStringNotContainsString('LIVE UPDATES', $html);
        $this->assertStringNotContainsString('No sign-ins yet.', $html);
    }

    public function test_the_drawer_is_lifted_out_of_the_card_that_would_clip_it(): void
    {
        $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // The sheet sits in a glass card, and a card carrying a backdrop-blur
        // clips whatever hangs out of it — the same trap the "…" menu and the
        // legend both had to be lifted out of.
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('aria-label="Recent sign-ins"', $html);
        $this->assertStringContainsString('role="dialog"', $html);

        // Closable by the cross, by the backdrop, and by Escape.
        $this->assertStringContainsString('@click="recentOpen = false"', $html);
        $this->assertStringContainsString('@keydown.escape.window="recentOpen = false"', $html);
    }

    public function test_an_arrival_reaches_the_drawer_with_its_room_and_time(): void
    {
        $child = $this->makeChild();
        $this->signIn($child);

        $recent = $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->viewData('recentAttendance');

        $this->assertCount(1, $recent);
        $this->assertSame($child->id, $recent->first()->child_id);

        // Sheet-sized time, the same shape the cells use.
        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('9:31a');
    }

    public function test_an_empty_day_says_so_rather_than_showing_an_empty_box(): void
    {
        $this->makeChild();

        $this->actingAs($this->admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->assertSee('Nobody has signed in yet today.')
            ->assertSee('Tap a cell on the sheet and they will appear here.');
    }

    private function signIn(Child $child): Attendance
    {
        return Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => today()->toDateString(),
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(today()->toDateString().' 09:31:00'),
        ]);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Noah',
            'last_name' => 'Bennett',
            'classroom' => 'Infant',
            'birth_date' => '2026-03-03',
        ]);
    }
}
