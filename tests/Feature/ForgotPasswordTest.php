<?php

namespace Tests\Feature;

use App\Http\Controllers\ForgotPasswordController;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Mail\MailConfigError;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailSendError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Forgot password? on the sign-in page: an emailed link, the same answer for
 * every address, and nothing changes until the link is followed.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'role' => 'teacher', 'name' => 'Bea Santos', 'email' => 'bea@example.com', 'password' => 'old-password-1',
        ]);

        $this->mailer = new class implements Mailer
        {
            public array $sent = [];

            public ?\Throwable $fail = null;

            public function sendEmail(string $to, ?string $toName, string $subject, string $html, string $text, string $category, array $inline = []): void
            {
                if ($this->fail) {
                    throw $this->fail;
                }
                $this->sent[] = compact('to', 'subject', 'html', 'text', 'category', 'inline');
            }
        };
        $this->app->instance(Mailer::class, $this->mailer);
    }

    public function test_the_sign_in_page_links_to_it(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertDontSee('Passwords are reset by an administrator');

        $this->get(route('password.request'))->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_known_address_gets_a_link_and_the_old_password_still_works(): void
    {
        $this->post(route('password.email'), ['email' => 'bea@example.com'])
            ->assertSessionHas('status', ForgotPasswordController::SENT);

        $this->assertCount(1, $this->mailer->sent);
        $sent = $this->mailer->sent[0];
        $this->assertSame('bea@example.com', $sent['to']);
        $this->assertSame('Reset your AngelNest password', $sent['subject']);
        $this->assertSame('password-reset', $sent['category']);
        $this->assertStringContainsString('Hi Bea,', $sent['text']);
        $this->assertStringContainsString('60 minutes', $sent['text']);
        $this->assertStringNotContainsString('{{', $sent['text'].$sent['html']);
        $this->assertMatchesRegularExpression('#/reset-password/[A-Za-z0-9]{40,}\?email=bea%40example.com#', $sent['text']);

        // Asking changed nothing.
        $this->assertTrue(Hash::check('old-password-1', $this->teacher->fresh()->password));
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        $this->post(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', ForgotPasswordController::SENT);

        $this->assertCount(0, $this->mailer->sent);
    }

    public function test_a_second_request_within_the_minute_does_not_send_again(): void
    {
        $this->post(route('password.email'), ['email' => 'bea@example.com']);
        $this->post(route('password.email'), ['email' => 'bea@example.com'])
            ->assertSessionHas('status', ForgotPasswordController::SENT);

        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_a_refused_send_answers_the_same_and_withdraws_the_token(): void
    {
        $this->mailer->fail = new MailSendError(422, 'bad address');

        $this->post(route('password.email'), ['email' => 'bea@example.com'])
            ->assertSessionHas('status', ForgotPasswordController::SENT);

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_no_mail_settings_says_so_for_every_address(): void
    {
        $this->app->bind(Mailer::class, fn () => throw new MailConfigError('MAILTRAP_API_TOKEN is not set'));

        foreach (['bea@example.com', 'nobody@example.com'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'not set up'));
        }
    }

    public function test_the_link_sets_a_new_password_once_and_clears_a_temporary_one(): void
    {
        $this->teacher->forceFill(['must_change_password' => true, 'temp_password_expires_at' => now()->addDay()])->save();
        $token = $this->requestLink();

        $this->get(route('password.reset', ['token' => $token, 'email' => 'bea@example.com']))
            ->assertOk()->assertSee('Choose a new password')->assertSee('value="bea@example.com"', false);

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.com',
            'password' => 'NewPass123', 'password_confirmation' => 'NewPass123',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $fresh = $this->teacher->fresh();
        $this->assertTrue(Hash::check('NewPass123', $fresh->password));
        $this->assertFalse($fresh->mustChangePassword());
        $this->assertNull($fresh->temp_password_expires_at);
        $this->assertSame('Email link', LoginEvent::where('outcome', LoginEvent::PASSWORD_RESET)->sole()->source());

        // Used once: the same link does nothing the second time.
        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.com',
            'password' => 'Another123', 'password_confirmation' => 'Another123',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewPass123', $this->teacher->fresh()->password));

        // And the new password signs in.
        $this->post(route('login.store'), ['email' => 'bea@example.com', 'password' => 'NewPass123']);
        $this->assertAuthenticatedAs($this->teacher->fresh());
    }

    public function test_an_expired_or_forged_link_is_refused(): void
    {
        $token = $this->requestLink();
        $this->travel(61)->minutes();

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.com',
            'password' => 'NewPass123', 'password_confirmation' => 'NewPass123',
        ])->assertSessionHasErrors(['email' => 'That link has expired or was already used. Ask for a new one below.']);

        $this->post(route('password.store'), [
            'token' => str_repeat('a', 64), 'email' => 'bea@example.com',
            'password' => 'NewPass123', 'password_confirmation' => 'NewPass123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-1', $this->teacher->fresh()->password));
    }

    public function test_the_new_password_follows_the_same_rules(): void
    {
        $token = $this->requestLink();

        $this->post(route('password.store'), [
            'token' => $token, 'email' => 'bea@example.com',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    private function requestLink(): string
    {
        $this->post(route('password.email'), ['email' => 'bea@example.com']);
        preg_match('#/reset-password/([A-Za-z0-9]+)\?#', end($this->mailer->sent)['text'], $m);

        return $m[1];
    }
}
