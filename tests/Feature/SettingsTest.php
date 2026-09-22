<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingController;
use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\StaffKiosk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The settings a director may change without a deploy.
 *
 * What these check is that a setting does something. A toggle that saves and
 * changes nothing is worse than no toggle: somebody switches the scanner off
 * after losing a card, sees it save, and believes the card is dead.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);

        Setting::forget();
    }

    public function test_the_company_name_is_saved_and_shown(): void
    {
        $this->actingAs($this->admin)->put(route('settings.update'), [
            'company_name' => 'Sunrise Learning Centre',
            'attendance_mode' => SettingController::TIME_TRACKING,
        ])->assertRedirect(route('settings.company'));

        Setting::forget();

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sunrise Learning Centre');
    }

    public function test_an_empty_company_name_goes_back_to_the_default(): void
    {
        // A box somebody emptied means "use the default", not "the centre has
        // no name at all".
        Setting::put('company.name', 'Sunrise Learning Centre');

        $this->actingAs($this->admin)->put(route('settings.update'), [
            'company_name' => '',
            'attendance_mode' => SettingController::TIME_TRACKING,
        ]);

        Setting::forget();

        $this->assertDatabaseMissing('settings', ['key' => 'company.name']);
        $this->assertSame(config('app.name'), Setting::get('company.name', config('app.name')));
    }

    public function test_attendance_only_takes_the_clock_out_button_off_the_kiosk(): void
    {
        // Offering a clock-out that nothing reads would invite somebody to
        // press it and believe their hours were being counted.
        $teacher = $this->teacher();

        app(\App\Services\TimeClock::class)->punch($teacher, \App\Models\TimePunch::IN, now());

        Setting::put('attendance.mode', SettingController::TIME_TRACKING);
        $actions = collect(app(StaffKiosk::class)->actionsFor($teacher))->pluck('action');
        $this->assertTrue($actions->contains('clock_out'));

        Setting::put('attendance.mode', SettingController::ATTENDANCE_ONLY);
        $actions = collect(app(StaffKiosk::class)->actionsFor($teacher))->pluck('action');
        $this->assertFalse($actions->contains('clock_out'));
        $this->assertFalse($actions->contains('break_start'));
    }

    public function test_attendance_only_stops_flagging_a_day_with_no_clock_out(): void
    {
        // Every day is open by design in that mode. Flagging them all would put
        // an amber dot against the whole centre every day.
        $teacher = $this->teacher();

        app(\App\Services\TimeClock::class)->punch(
            $teacher, \App\Models\TimePunch::IN, Carbon::parse('2026-09-21 08:00')
        );

        Setting::put('attendance.mode', SettingController::TIME_TRACKING);
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringContainsString('Missing time out', $html);

        Setting::put('attendance.mode', SettingController::ATTENDANCE_ONLY);
        $html = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Missing time out', $html);
    }

    public function test_turning_the_scanner_off_stops_a_card_working(): void
    {
        // The reason somebody turns it off is usually that a card has gone
        // missing, so a saved toggle that still admits the card is the one
        // failure that matters here.
        $teacher = $this->teacher();

        $card = 'CARD-'.str_repeat('A', 30);
        $teacher->forceFill([
            'card_index' => User::kioskIndexFor($card),
            'card_hash' => Hash::make($card),
        ])->save();

        Setting::put('kiosk.scanner', '1');
        $this->assertSame('ok', app(StaffKiosk::class)->identify($card, null)['status']);

        Setting::put('kiosk.scanner', '0');
        $this->assertSame('not_found', app(StaffKiosk::class)->identify($card, null)['status']);
    }

    public function test_an_invented_attendance_mode_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), ['attendance_mode' => 'whatever'])
            ->assertSessionHasErrors('attendance_mode');
    }

    public function test_a_teacher_cannot_reach_the_settings(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get(route('settings.company'))->assertForbidden();
        $this->actingAs($teacher)->get(route('settings.administrators'))->assertForbidden();
        $this->actingAs($teacher)->get(route('settings.history'))->assertForbidden();
        $this->actingAs($teacher)
            ->put(route('settings.update'), ['attendance_mode' => SettingController::ATTENDANCE_ONLY])
            ->assertForbidden();
    }

    public function test_every_settings_tab_renders(): void
    {
        foreach (['settings.company', 'settings.administrators', 'settings.devices', 'settings.history'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
    }

    public function test_the_administrators_tab_lists_only_administrators(): void
    {
        $this->teacher();

        $this->actingAs($this->admin)
            ->get(route('settings.administrators'))
            ->assertOk()
            ->assertSee('Administrator')
            ->assertDontSee('Rachel Kim');
    }

    public function test_devices_are_set_up_inside_settings(): void
    {
        // The whole page, not a summary: adding, pairing, re-pairing and
        // retiring all happen here now.
        $html = $this->actingAs($this->admin)->get(route('settings.devices'))->assertOk()->getContent();

        $this->assertStringContainsString(route('devices.store'), $html);
        $this->assertStringContainsString('Add a device', $html);
    }

    public function test_the_old_devices_page_lands_on_the_settings_tab(): void
    {
        // A director's bookmark to /devices should land on the devices page
        // rather than on a 404.
        $this->actingAs($this->admin)
            ->get(route('devices.index'))
            ->assertRedirect(route('settings.devices'));
    }

    public function test_devices_is_no_longer_its_own_sidebar_link(): void
    {
        // A screen opened twice a year does not belong beside the ones opened
        // every morning.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $nav = substr($html, (int) strpos($html, '<nav'), (int) strpos($html, '</nav>') - (int) strpos($html, '<nav'));

        $this->assertStringNotContainsString(route('devices.index'), $nav);
        $this->assertStringContainsString(route('settings.company'), $nav);
    }

    private function teacher(): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim', 'pay_rate' => 24]);
    }
}
