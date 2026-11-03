<?php

namespace Tests\Feature;

use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The password on a staff profile's "AngelNest login" card.
 *
 * Jordan, 4 Nov 2026: no emailed temporary password — the director types
 * the new one and hands it over, as before. The RULES:
 *  - Eight or more characters, typed twice; anything else changes nothing.
 *  - The old password stops working; nothing is mailed.
 *  - Login history says who set it.
 *  - A temporary password already out keeps its expiry at sign-in.
 */
class StaffTemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = User::factory()->create(['role' => 'admin', 'name' => 'Rosa Cruz']);
        $this->teacher = User::factory()->create([
            'role' => 'teacher',
            'name' => 'Bea Santos',
            'email' => 'bea@example.com',
            'password' => 'old-password-1',
        ]);
    }

    public function test_the_director_sets_a_password_and_nothing_is_emailed(): void
    {
        Mail::fake();
        $this->app->instance(Mailer::class, new class implements Mailer
        {
            public function sendEmail(string $to, ?string $toName, string $subject, string $html, string $text, string $category, array $inline = []): void
            {
                throw new \LogicException('Nothing should be emailed.');
            }
        });
        $this->teacher->forceFill(['must_change_password' => true, 'temp_password_expires_at' => now()->addDay()])->save();

        $this->actingAs($this->director)
            ->post(route('staff.password', $this->teacher), ['password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
            ->assertRedirect(route('staff.show', [$this->teacher, 'tab' => 'details']))
            ->assertSessionHas('success', 'Bea’s password has been set. Give it to them in person.');

        $teacher = $this->teacher->fresh();
        $this->assertTrue(Hash::check('new-pass-123', $teacher->password));
        $this->assertFalse(Hash::check('old-password-1', $teacher->password));
        $this->assertFalse($teacher->mustChangePassword());
        $this->assertNull($teacher->temp_password_expires_at);
        Mail::assertNothingOutgoing();

        $event = LoginEvent::where('outcome', LoginEvent::PASSWORD_SET)->sole();
        $this->assertSame($this->director->id, $event->by_user_id);
        $this->assertSame('Password set', $event->label());
        $this->assertSame('Set by Rosa Cruz', $event->source());

        // And the new one signs in.
        auth()->logout();
        $this->post(route('login'), ['email' => 'bea@example.com', 'password' => 'new-pass-123']);
        $this->assertAuthenticatedAs($this->teacher);
    }

    public function test_a_short_or_mismatched_password_changes_nothing(): void
    {
        foreach ([['short1', 'short1'], ['new-pass-123', 'new-pass-124'], ['', '']] as [$password, $again]) {
            $this->actingAs($this->director)
                ->from(route('staff.show', [$this->teacher, 'tab' => 'details']))
                ->post(route('staff.password', $this->teacher), ['password' => $password, 'password_confirmation' => $again])
                ->assertSessionHasErrors('password');
        }

        $this->assertTrue(Hash::check('old-password-1', $this->teacher->fresh()->password));
        $this->assertSame(0, LoginEvent::where('outcome', LoginEvent::PASSWORD_SET)->count());
    }

    public function test_the_card_has_the_boxes_and_no_email_button(): void
    {
        $this->actingAs($this->director)
            ->get(route('staff.show', [$this->teacher, 'tab' => 'details']))
            ->assertOk()
            ->assertSee('data-password-form', false)
            ->assertSee('Reset password')
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('Send a new temporary password')
            ->assertDontSee('data-temp-password-dialog', false);
    }

    public function test_a_teacher_cannot_set_anyones_password(): void
    {
        $this->actingAs($this->teacher)
            ->post(route('staff.password', $this->director), ['password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
            ->assertForbidden();

        $this->assertFalse(Hash::check('new-pass-123', $this->director->fresh()->password));
    }

    public function test_an_expired_temporary_password_is_refused_at_sign_in(): void
    {
        $this->teacher->forceFill([
            'password' => 'TEMP-PASS-WORD',
            'must_change_password' => true,
            'temp_password_expires_at' => now()->subMinute(),
        ])->save();

        $this->post(route('login'), ['email' => 'bea@example.com', 'password' => 'TEMP-PASS-WORD'])
            ->assertSessionHasErrors(['email' => 'That temporary password has expired. Ask the director for a new one.']);

        $this->assertGuest();
        $this->assertSame(1, LoginEvent::where('outcome', LoginEvent::FAILED)->count());
    }

    public function test_an_unexpired_temporary_password_signs_in(): void
    {
        $this->teacher->forceFill([
            'password' => 'TEMP-PASS-WORD',
            'must_change_password' => true,
            'temp_password_expires_at' => now()->addHour(),
        ])->save();

        $this->post(route('login'), ['email' => 'bea@example.com', 'password' => 'TEMP-PASS-WORD']);

        $this->assertAuthenticatedAs($this->teacher);
    }
}
