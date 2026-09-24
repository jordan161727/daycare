<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Switching between Live and Edit reloads the sheet.
 *
 * It used to flip a flag. Now it asks the server for the whole sheet again,
 * so the mode you arrive in shows exactly what is stored — every tick, every
 * hour, every arrival — and not what this one tab has been keeping up to
 * date on its own. The mode itself travels in the address so it is still the
 * mode on the other side of the reload.
 */
class ModeSwitchReloadsTest extends TestCase
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

    public function test_the_sheet_opens_in_live_mode_by_default(): void
    {
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringContainsString('editing: false,', $html);
    }

    public function test_mode_edit_in_the_address_opens_the_sheet_in_edit_mode(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['mode' => 'edit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('editing: true,', $html);
    }

    public function test_the_address_cannot_put_a_reader_into_a_mode_they_are_not_allowed(): void
    {
        // A parent-facing or view-only role has no switch; the URL must not
        // be a back door into one.
        $viewer = User::factory()->create(['role' => 'viewer']);

        $html = $this->actingAs($viewer)
            ->get(route('attendance.index', ['mode' => 'edit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('editing: false,', $html);
    }

    public function test_the_switch_reloads_with_the_mode_in_the_address(): void
    {
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        // The button calls the switch, and the switch is a navigation.
        $this->assertStringContainsString('@click="switchMode()"', $html);
        $this->assertStringContainsString("url.searchParams.set('mode', 'edit');", $html);
        $this->assertStringContainsString("url.searchParams.delete('mode');", $html);
        $this->assertStringContainsString('window.location.assign(url.toString());', $html);
    }

    public function test_the_switch_waits_for_a_save_still_on_the_wire(): void
    {
        /*
         * A tap saves itself, but the request takes a moment. Reloading with
         * one still out would lose the tap — so every save is counted and the
         * switch waits for the count to reach zero before it navigates.
         */
        $html = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringContainsString('while (this.inFlight > 0)', $html);

        // And every save on the sheet goes through the counted door — none
        // reach for window.postJson directly, or the count would be wrong.
        $this->assertStringNotContainsString('await window.postJson(', $html);
        $this->assertStringContainsString('return window.postJson(url, body).finally(', $html);
    }
}
