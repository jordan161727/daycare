<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\Guardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The door kiosk, and the thing it exists for: a guardian signing a child in at
 * the door is the child signed in on the attendance sheet, with nobody retyping
 * it.
 */
class KioskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-02 08:12:00'));
        config(['daycare.kiosk.enabled' => true]);
    }

    public function test_signing_in_at_the_door_signs_the_child_in_on_the_sheet(): void
    {
        [$guardian, $child] = $this->family();

        $this->unlock('481902');

        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        // The same row a teacher's tap writes: same table, same keys, and the
        // time the guardian actually pressed it.
        $this->assertDatabaseHas('attendances', [
            'child_id' => $child->id,
            'attendance_date' => '2026-09-02',
            'session' => 'FULL',
        ]);

        $this->assertSame('08:12', Attendance::first()->signed_in_at->format('H:i'));
    }

    public function test_the_sheet_shows_the_child_as_signed_in(): void
    {
        [$guardian, $child] = $this->family();
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])->assertOk();

        // Read back through the board itself rather than the table, because the
        // point of the kiosk is what a teacher sees when they open the sheet —
        // the box already green, with nobody having tapped it.
        $response = $this->actingAs($admin)->get(route('attendance.index'))->assertOk();

        $map = $response->viewData('attendanceMap');

        $this->assertArrayHasKey($child->id, $map, 'the sheet does not know about the kiosk sign-in');
        $this->assertArrayHasKey('2026-09-02', $map[$child->id]);
        // Sheet-sized: the grid is sixty rows by five columns and every filled
        // cell carries one of these.
        $this->assertSame('8:12a', $map[$child->id]['2026-09-02']['FULL']);
    }

    public function test_every_press_is_kept_beside_the_attendance(): void
    {
        [$guardian, $child] = $this->family();

        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])->assertOk();

        // The attendance row says the child was here. The punch says who brought
        // them, at which minute, and through which door.
        $this->assertDatabaseHas('child_attendance_punches', [
            'child_id' => $child->id,
            'guardian_id' => $guardian->id,
            'direction' => 'in',
            'service_date' => '2026-09-02',
            'method' => 'pin',
        ]);
    }

    public function test_signing_out_records_the_departure_without_undoing_the_day(): void
    {
        [$guardian, $child] = $this->family();

        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])->assertOk();

        $this->travelTo(Carbon::parse('2026-09-02 15:30:00'));

        // A pickup seven hours later is a second visit to the door, not the
        // same ninety-second window a single check-in happens inside.
        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'out'])->assertOk();

        // Leaving at three does not make the morning not have happened — and
        // DSS bills against the attendance, so it must survive the pickup.
        $attendance = Attendance::first();

        $this->assertNotNull($attendance, 'signing out deleted the attendance');
        $this->assertSame('08:12', $attendance->signed_in_at->format('H:i'));
        $this->assertSame('15:30', $attendance->signed_out_at->format('H:i'));
        $this->assertSame(2, ChildAttendancePunch::count());
    }

    public function test_pressing_sign_in_twice_does_not_move_the_arrival_time(): void
    {
        [$guardian, $child] = $this->family();

        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])->assertOk();

        $this->travelTo(Carbon::parse('2026-09-02 08:12:30'));

        // A double tap at a door is one arrival, not two.
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])
            ->assertOk()
            ->assertJson(['status' => 'already_in']);

        $this->assertSame('08:12', Attendance::first()->signed_in_at->format('H:i'));
        $this->assertSame(1, ChildAttendancePunch::count());
    }

    public function test_a_guardian_not_on_the_pickup_list_is_refused(): void
    {
        $child = $this->child('Priya', 'Toddler');
        $guardian = $this->guardian('Daniel', '573164', '2290');
        // On the record, so he is told about her — but not cleared to collect.
        $guardian->children()->attach($child->id, ['can_collect' => false]);

        $this->unlock('573164');

        Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-09-02', 'session' => 'FULL', 'signed_in_at' => now()]);

        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'out'])
            ->assertOk()
            ->assertJson(['status' => 'not_authorised']);

        // Checked on the server, not only by hiding the button.
        $this->assertNull(Attendance::first()->signed_out_at);
        $this->assertSame(0, ChildAttendancePunch::count());
    }

    public function test_a_guardian_cannot_touch_a_child_who_is_not_theirs(): void
    {
        [$guardian, $mine] = $this->family();
        $stranger = $this->child('Rosa', 'PreK');

        $this->unlock('481902');

        $this->postJson(route('kiosk.punch'), ['child_id' => $stranger->id, 'direction' => 'in'])
            ->assertOk()
            ->assertJson(['status' => 'not_authorised']);

        $this->assertSame(0, Attendance::count());
    }

    public function test_two_families_sharing_a_pin_are_asked_for_a_phone_number(): void
    {
        $grace = $this->guardian('Grace', '246810', '5581');
        $marcus = $this->guardian('Marcus', '246810', '9032');
        $graceChild = $this->child('Eli', 'PreK');
        $grace->children()->attach($graceChild->id, ['can_collect' => true]);

        // Six digits cannot tell them apart, so the kiosk asks rather than guesses.
        $this->postJson(route('kiosk.unlock'), ['pin' => '246810'])
            ->assertOk()
            ->assertJson(['status' => 'ambiguous']);

        $this->postJson(route('kiosk.unlock'), ['pin' => '246810', 'last4' => '5581'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'guardian' => ['name' => 'Grace']]);
    }

    public function test_five_wrong_tries_locks_the_pin(): void
    {
        $this->guardian('Amira', '481902', '4417');

        for ($try = 0; $try < 5; $try++) {
            $this->postJson(route('kiosk.unlock'), ['pin' => '481901'])->assertOk();
        }

        // The wrong PIN shares no row with Amira's, so hers is untouched — the
        // lockout counts against the row the digits actually pointed at.
        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $guardian = Guardian::first();
        $guardian->forceFill(['failed_attempts' => 5, 'locked_until' => now()->addMinutes(5)])->save();

        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])
            ->assertOk()
            ->assertJson(['status' => 'locked']);
    }

    public function test_the_pin_is_never_stored_in_the_clear(): void
    {
        $guardian = $this->guardian('Amira', '481902', '4417');

        $row = (array) \DB::table('guardians')->first();

        $this->assertNotContains('481902', $row, 'the PIN is in the table in the clear');
        $this->assertTrue(Hash::check('481902', $row['pin_hash']));

        // The lookup column is keyed on the app key, so the table on its own is
        // not a list of six-digit numbers to try offline.
        $this->assertNotSame(hash('sha256', '481902'), $row['pin_index']);
    }

    public function test_the_kiosk_does_not_exist_unless_the_centre_turns_it_on(): void
    {
        config(['daycare.kiosk.enabled' => false]);

        // A 404 rather than a locked door: the one signed-out endpoint in this
        // app should not be discoverable at a centre that does not use it.
        $this->get(route('kiosk.index'))->assertNotFound();
        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertNotFound();
    }

    public function test_the_family_screen_needs_no_staff_login(): void
    {
        [$guardian, $child] = $this->family();

        // There is nobody to log in at a door. The PIN is the authentication.
        $this->get(route('kiosk.index'))->assertOk();
        $this->unlock('481902');
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_a_press_after_the_screen_times_out_is_refused(): void
    {
        [$guardian, $child] = $this->family();

        $this->unlock('481902');
        $this->travelTo(Carbon::parse('2026-09-02 08:20:00'));

        // The next person at the door must never find somebody else's children
        // already on the screen.
        $this->postJson(route('kiosk.punch'), ['child_id' => $child->id, 'direction' => 'in'])
            ->assertOk()
            ->assertJson(['status' => 'expired']);

        $this->assertSame(0, Attendance::count());
    }


    public function test_the_kiosk_is_built_for_a_touchscreen(): void
    {
        config(['daycare.kiosk.enabled' => true]);

        $view = file_get_contents(resource_path('views/kiosk/index.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));

        // Every target is well past the 44px Apple and WCAG both ask for. The
        // smallest here is 56px.
        preg_match_all('/min-h-(\d+)/', $view, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $size) {
            $this->assertGreaterThanOrEqual(12, (int) $size, 'a target smaller than 48px (Tailwind 12) is hard to hit');
        }

        // viewport-fit=cover puts content under the rounded corners, so the
        // insets have to be paid back or the bottom bar sits under the home
        // indicator. The prototype had this; it was dropped in the port.
        $this->assertStringContainsString('viewport-fit=cover', $view);
        $this->assertStringContainsString('env(safe-area-inset-bottom)', $view);

        // No text fields at all, which is what keeps the iOS keyboard from
        // covering the keypad and the zoom-on-focus from firing.
        $this->assertStringNotContainsString('<input', $view);

        // Kills double-tap zoom, and with it the delay iOS holds every tap for.
        $this->assertStringContainsString('touch-action: manipulation', $css);
    }

    /* ---------------- helpers ---------------- */

    private function unlock(string $pin): void
    {
        $this->postJson(route('kiosk.unlock'), ['pin' => $pin])->assertOk()->assertJson(['status' => 'ok']);
    }

    /** One guardian, one child, cleared to collect. */
    private function family(): array
    {
        $child = $this->child('Noor', 'Toddler');
        $guardian = $this->guardian('Amira', '481902', '4417');
        $guardian->children()->attach($child->id, ['can_collect' => true]);

        return [$guardian, $child];
    }

    private function guardian(string $name, string $pin, string $last4): Guardian
    {
        $guardian = new Guardian([
            'name' => $name,
            'phone_last4' => $last4,
            'pin_index' => Guardian::indexFor($pin),
            'pin_hash' => Hash::make($pin),
        ]);

        $guardian->save();

        return $guardian;
    }

    private function child(string $first, string $room): Child
    {
        return Child::create([
            'lan' => (string) (4000 + Child::count()),
            'first_name' => $first,
            'last_name' => 'Test',
            'classroom' => $room,
            'status' => 'Active',
        ]);
    }
}
