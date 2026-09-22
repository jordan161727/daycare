<?php

namespace Tests\Feature;

use App\Models\StaffDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issuing the card and the PIN, and registering the screens they are used at.
 *
 * Both are the director's: a card is somebody's identity at a machine that
 * records paid hours, and a device is the machine.
 */
class StaffCardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim']);
    }

    public function test_the_staff_page_offers_a_card_and_a_pin(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('teachers.show', $this->staff))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Time clock card', $html);
        $this->assertStringContainsString($this->staff->staffId(), $html);
        $this->assertStringContainsString('Issue card', $html);
        $this->assertStringContainsString('Set PIN', $html);
    }

    public function test_issuing_a_card_makes_it_printable_for_a_while(): void
    {
        $this->actingAs($this->admin)
            ->post(route('staff.card.issue', $this->staff))
            ->assertRedirect();

        $this->assertTrue($this->staff->fresh()->hasCard());

        // The QR, while the moment to print it lasts.
        $svg = $this->actingAs($this->admin)->get(route('staff.card.show', $this->staff))->assertOk();
        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $svg->getContent());

        // And the same code as a file to send to a printer.
        $png = $this->actingAs($this->admin)->get(route('staff.card.download', $this->staff))->assertOk();
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $png->headers->get('Content-Disposition'));
    }

    public function test_messages_read_as_words_not_as_entities(): void
    {
        /*
         * Flash messages are escaped on the way out, so an &rsquo; written into
         * one arrives on screen as the six characters "&rsquo;" — which is what
         * "Aisha&rsquo;s card is ready" looked like on the staff page.
         */
        $html = $this->actingAs($this->admin)
            ->post(route('staff.card.issue', $this->staff))
            ->assertRedirect();

        $page = $this->actingAs($this->admin)->get(route('teachers.show', $this->staff))->assertOk()->getContent();

        $this->assertStringContainsString('’s card is ready', $page);

        // Nothing anywhere on the page is showing its own markup.
        foreach (['&amp;rsquo;', '&amp;mdash;'] as $escaped) {
            $this->assertStringNotContainsString($escaped, $page, 'an HTML entity is being printed literally');
        }
    }

    public function test_a_pending_card_can_still_be_reissued(): void
    {
        // The path for a card printed crookedly, or printed and then lost the
        // same afternoon.
        $this->actingAs($this->admin)->post(route('staff.card.issue', $this->staff))->assertRedirect();

        $page = $this->actingAs($this->admin)->get(route('teachers.show', $this->staff))->assertOk()->getContent();

        $this->assertStringContainsString('Issue a new card instead', $page);
    }

    public function test_the_card_image_is_gone_once_the_moment_has_passed(): void
    {
        // The code is hashed on the way in and cannot be read back, so there is
        // nothing to regenerate the picture from. Printing another means
        // issuing another — which is also what should happen to a lost card.
        $this->actingAs($this->admin)->post(route('staff.card.issue', $this->staff))->assertRedirect();

        $this->travel(16)->minutes();

        $this->actingAs($this->admin)->get(route('staff.card.show', $this->staff))->assertNotFound();
        $this->actingAs($this->admin)->get(route('staff.card.download', $this->staff))->assertNotFound();

        // The card itself still works at the clock; it is only the picture that
        // has gone.
        $this->assertTrue($this->staff->fresh()->hasCard());
    }

    public function test_a_mistyped_pin_is_caught_before_it_is_set(): void
    {
        // Otherwise it is discovered by somebody standing at the clock unable
        // to start their shift.
        $this->actingAs($this->admin)
            ->from(route('teachers.show', $this->staff))
            ->post(route('staff.card.pin', $this->staff), [
                'kiosk_pin' => '4821',
                'kiosk_pin_confirmation' => '4812',
            ])
            ->assertSessionHasErrors('kiosk_pin');

        $this->assertFalse($this->staff->fresh()->hasKioskPin());
    }

    public function test_a_teacher_cannot_issue_cards_or_register_devices(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->post(route('staff.card.issue', $this->staff))->assertForbidden();
        $this->actingAs($teacher)->get(route('devices.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('devices.store'), ['name' => 'Mine'])->assertForbidden();
    }

    public function test_a_device_is_paired_once_and_the_link_is_shown_once(): void
    {
        $this->actingAs($this->admin)
            ->post(route('devices.store'), ['name' => 'Front desk kiosk', 'location' => 'Lobby'])
            ->assertRedirect(route('devices.index'))
            ->assertSessionHas('issued_device');

        $device = StaffDevice::firstOrFail();

        $this->assertTrue($device->is_active);

        // The token is hashed like everything else here: a table of live ones
        // would be a table of ways to open a clock from anywhere.
        $this->assertNotEmpty($device->token_hash);

        $row = (array) \Illuminate\Support\Facades\DB::table('staff_devices')->first();
        $link = session('issued_device')['url'] ?? '';
        $token = (string) parse_url($link, PHP_URL_QUERY);

        $this->assertNotSame('', $token);

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(str_replace('token=', '', $token), (string) $value, "the pairing token is stored in plain text in {$column}");
        }
    }

    public function test_a_pairing_link_can_be_looked_at_again(): void
    {
        // It used to be shown once and never again, which left an
        // administrator at a tablet with no way to see the address they were
        // meant to type — and re-pairing to find out would stop whichever
        // screen was already working.
        config(['daycare.kiosk.enabled' => true]);

        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Front desk'])->assertRedirect();

        $device = StaffDevice::firstOrFail();
        $issued = session('issued_device')['url'];

        $html = $this->actingAs($this->admin)->get(route('devices.link', $device))->assertOk()->getContent();

        $this->assertStringContainsString($issued, $html, 'the link shown is the one that was issued');
        $this->assertStringContainsString(route('devices.link.qr', $device), $html);

        // And it still pairs, without anything having been re-issued.
        $this->get($issued)->assertRedirect(route('clock.kiosk'));

        // The QR is the same address, as something a tablet camera can read.
        $svg = $this->actingAs($this->admin)->get(route('devices.link.qr', $device))->assertOk();
        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $svg->headers->get('Cache-Control'));
    }

    public function test_the_token_is_still_not_plain_text_in_the_database(): void
    {
        // Readable by the app, unreadable in a copied database: it is encrypted
        // now rather than hashed, which is what makes showing it possible.
        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Front desk'])->assertRedirect();

        $token = str_replace(
            route('clock.kiosk').'?token=',
            '',
            session('issued_device')['url']
        );

        $row = (array) \Illuminate\Support\Facades\DB::table('staff_devices')->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "the pairing token is stored in plain text in {$column}");
        }

        // And the app can still read it back.
        $this->assertSame($token, StaffDevice::firstOrFail()->token);
    }

    public function test_a_teacher_cannot_see_a_pairing_link(): void
    {
        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Front desk'])->assertRedirect();

        $device = StaffDevice::firstOrFail();
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->get(route('devices.link', $device))->assertForbidden();
        $this->actingAs($teacher)->get(route('devices.link.qr', $device))->assertForbidden();
    }

    public function test_retiring_a_device_keeps_it_for_the_punches_it_recorded(): void
    {
        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Old iPad'])->assertRedirect();

        $device = StaffDevice::firstOrFail();

        $this->actingAs($this->admin)->delete(route('devices.destroy', $device))->assertRedirect();

        // Months of hours were recorded by this row; deleting it would leave
        // them recorded by nothing.
        $this->assertDatabaseHas('staff_devices', ['id' => $device->id, 'is_active' => false]);
    }

    public function test_re_pairing_stops_the_old_tablet_working(): void
    {
        config(['daycare.kiosk.enabled' => true]);

        $this->actingAs($this->admin)->post(route('devices.store'), ['name' => 'Front desk'])->assertRedirect();

        $first = session('issued_device')['url'];
        $device = StaffDevice::firstOrFail();

        $this->actingAs($this->admin)->post(route('devices.repair', $device))->assertRedirect();

        // Read before anything else is requested: flash data survives exactly
        // one request, and the assertions below are requests.
        $second = session('issued_device')['url'];

        $this->assertNotSame($first, $second);

        // The point of re-pairing: a tablet that has walked off stops being a
        // time clock.
        $this->get($first)->assertForbidden();
        $this->get($second)->assertRedirect(route('clock.kiosk'));
    }
}
