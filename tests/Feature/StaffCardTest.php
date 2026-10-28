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

    public function test_a_teacher_cannot_issue_cards(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher)->post(route('staff.card.issue', $this->staff))->assertForbidden();
    }

    public function test_the_devices_pages_and_pairing_qr_are_gone(): void
    {
        // Jordan, 29 Oct 2026: the client does not want Settings → Devices or
        // its pairing QR. The addresses answer nothing at all.
        foreach (['/settings/devices', '/devices', '/devices/1/link', '/devices/1/link.svg'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertNotFound();
        }

        $this->assertStringNotContainsString('Devices', $this->actingAs($this->admin)->get(route('settings.children'))->getContent());
    }

    public function test_a_kiosk_pairs_itself_and_the_token_is_not_plain_text(): void
    {
        config(['daycare.kiosk.enabled' => true]);

        $this->get(route('clock.kiosk'))->assertOk()->assertCookie(StaffDevice::COOKIE);

        $device = StaffDevice::sole();
        $this->assertTrue($device->is_active);
        $this->assertSame(StaffDevice::KIND_CLOCK, $device->kind);
        $this->assertNotEmpty($device->token_hash);

        // Readable by the app, unreadable in a copied database.
        $row = (array) \Illuminate\Support\Facades\DB::table('staff_devices')->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($device->token, (string) $value, "the pairing token is stored in plain text in {$column}");
        }
    }

    public function test_the_same_browser_stays_the_same_device(): void
    {
        config(['daycare.kiosk.enabled' => true]);

        $this->get(route('clock.kiosk'))->assertOk();
        $token = StaffDevice::sole()->token;

        // A new session (Monday morning) with the cookie still on the tablet.
        $this->flushSession();
        $this->withCookie(StaffDevice::COOKIE, $token)->get(route('clock.kiosk'))->assertOk();

        $this->assertSame(1, StaffDevice::count());
    }

    public function test_one_browser_can_be_the_clock_and_a_room_tablet_at_once(): void
    {
        // The Kiosks page frames both in the director's browser; they must
        // stay two devices, or the tablet's room would land on the clock.
        config(['daycare.kiosk.enabled' => true]);

        $this->get(route('clock.kiosk'))->assertOk();
        $this->get(route('room.kiosk'))->assertOk();
        $this->get(route('clock.kiosk'))->assertOk();
        $this->get(route('room.kiosk'))->assertOk();

        $this->assertEqualsCanonicalizing([StaffDevice::KIND_CLOCK, StaffDevice::KIND_ROOM], StaffDevice::pluck('kind')->all());
    }
}
