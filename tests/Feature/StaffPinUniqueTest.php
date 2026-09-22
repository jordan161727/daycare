<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No two people on the same four digits.
 *
 * The clock identifies somebody by what they key in, so a shared PIN is not a
 * security hole — it is a screen that cannot tell two people apart and has to
 * stop and ask, every morning, to both of them. Cheaper to refuse it once, at
 * the one screen where it is chosen.
 */
class StaffPinUniqueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function setPin(User $staff, string $pin)
    {
        return $this->actingAs($this->admin)
            ->from(route('teachers.show', $staff))
            ->post(route('staff.card.pin', $staff), [
                'kiosk_pin' => $pin,
                'kiosk_pin_confirmation' => $pin,
            ]);
    }

    public function test_a_pin_somebody_else_holds_is_refused(): void
    {
        $first = User::factory()->create(['role' => 'teacher']);
        $second = User::factory()->create(['role' => 'teacher']);

        $this->setPin($first, '4021')->assertRedirect();
        $this->assertTrue($first->fresh()->hasKioskPin());

        $this->setPin($second, '4021')->assertSessionHasErrors('kiosk_pin');
        $this->assertFalse($second->fresh()->hasKioskPin());
    }

    /** Changing your mind back to your own PIN is not a collision. */
    public function test_somebody_may_keep_their_own_pin(): void
    {
        $staff = User::factory()->create(['role' => 'teacher']);

        $this->setPin($staff, '4021')->assertRedirect();
        $this->setPin($staff, '4021')->assertSessionHasNoErrors();

        $this->assertTrue($staff->fresh()->hasKioskPin());
    }

    public function test_a_free_pin_is_accepted(): void
    {
        $first = User::factory()->create(['role' => 'teacher']);
        $second = User::factory()->create(['role' => 'teacher']);

        $this->setPin($first, '4021')->assertRedirect();
        $this->setPin($second, '4022')->assertSessionHasNoErrors();

        $this->assertTrue($second->fresh()->hasKioskPin());
    }

    /**
     * The message says a PIN is taken, not who has it. A director setting one
     * has no business learning another member of staff's digits by elimination.
     */
    public function test_the_refusal_does_not_name_who_holds_it(): void
    {
        $first = User::factory()->create(['role' => 'teacher', 'name' => 'Grace Hopper']);
        $second = User::factory()->create(['role' => 'teacher']);

        $this->setPin($first, '4021');

        $errors = $this->setPin($second, '4021')->assertSessionHasErrors('kiosk_pin');

        $message = session('errors')->first('kiosk_pin');

        $this->assertStringNotContainsString('Grace', $message);
        $this->assertStringContainsString('Somebody else', $message);
    }

    public function test_the_check_is_the_same_one_the_clock_looks_up_by(): void
    {
        $staff = User::factory()->create(['role' => 'teacher']);
        $staff->setKioskPin('4021');

        $this->assertTrue(User::kioskPinTaken('4021'));
        $this->assertFalse(User::kioskPinTaken('4022'));
        $this->assertFalse(User::kioskPinTaken('4021', $staff), 'Their own PIN is not taken from them.');
    }
}
