<?php

namespace Tests\Unit;

use App\Services\Mail\MailConfigError;
use App\Services\Mail\MailSendError;
use App\Services\Mail\MailtrapMailer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Mailtrap HTTP mailer, with the network faked.
 *
 * The properties worth pinning: it posts to the right inbox in each mode,
 * a refusal is an exception rather than a quiet false, and nothing that could
 * sign somebody in — the token, the password in the body — reaches the log.
 */
class MailtrapMailerTest extends TestCase
{
    private const TOKEN = 'tok_secret_1234567890';

    private const BODY_SECRET = 'ABCD-EFGH-JKMN';

    /** @var list<array{message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['message' => $e->message, 'context' => $e->context];
        });
    }

    public function test_sandbox_posts_to_the_test_inbox_with_the_token_and_body(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->mailer('sandbox')->sendEmail(...$this->email());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://sandbox.api.mailtrap.io/api/send/4242'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->data() === [
                    'from' => ['email' => 'no-reply@angelnest.test', 'name' => 'AngelNest'],
                    'to' => [['email' => 'bea@example.com', 'name' => 'Bea Santos']],
                    'subject' => 'Your temporary AngelNest password',
                    'html' => '<p>'.self::BODY_SECRET.'</p>',
                    'text' => self::BODY_SECRET,
                    'category' => 'temp-password',
                ];
        });
    }

    public function test_live_posts_to_the_sending_api_without_an_inbox(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->mailer('live', inbox: null)->sendEmail(...$this->email());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://send.api.mailtrap.io/api/send'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_an_inline_image_goes_as_a_cid_attachment(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->mailer('sandbox')->sendEmail(...$this->email(), inline: [
            ['cid' => 'angelnest-logo', 'path' => resource_path('mail/logo.png'), 'type' => 'image/png'],
        ]);

        Http::assertSent(fn (Request $request) => $request['attachments'] === [[
            'content' => base64_encode(file_get_contents(resource_path('mail/logo.png'))),
            'filename' => 'logo.png',
            'type' => 'image/png',
            'disposition' => 'inline',
            'content_id' => 'angelnest-logo',
        ]]);
    }

    public function test_mode_defaults_to_sandbox(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->mailer(null)->sendEmail(...$this->email());

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), MailtrapMailer::SANDBOX_URL));
    }

    public function test_a_non_2xx_answer_throws_with_the_status_and_mailtraps_reason(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['Invalid "to" email address']], 422)]);

        try {
            $this->mailer('sandbox')->sendEmail(...$this->email());
            $this->fail('Expected MailSendError');
        } catch (MailSendError $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('Invalid "to" email address', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_a_reason_that_echoes_the_token_has_it_struck_out(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['Bad token '.self::TOKEN]], 401)]);

        try {
            $this->mailer('sandbox')->sendEmail(...$this->email());
            $this->fail('Expected MailSendError');
        } catch (MailSendError $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function test_a_timeout_throws(): void
    {
        Http::fake(fn () => throw new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds', new PsrRequest('POST', MailtrapMailer::LIVE_URL)));

        try {
            $this->mailer('live', inbox: null)->sendEmail(...$this->email());
            $this->fail('Expected MailSendError');
        } catch (MailSendError $e) {
            $this->assertNull($e->status);
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_the_log_has_category_masked_address_status_and_time_and_never_the_token_or_body(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['success' => true], 200)
            ->push(['errors' => ['nope '.self::TOKEN]], 500)]);

        $mailer = $this->mailer('sandbox');
        $mailer->sendEmail(...$this->email());

        try {
            $mailer->sendEmail(...$this->email());
        } catch (MailSendError) {
        }

        $this->assertCount(2, $this->logged);
        $this->assertSame(
            ['category' => 'temp-password', 'to' => 'b***@example.com', 'status' => 200],
            array_intersect_key($this->logged[0]['context'], array_flip(['category', 'to', 'status'])),
        );
        $this->assertSame(500, $this->logged[1]['context']['status']);
        $this->assertArrayHasKey('at', $this->logged[0]['context']);

        $everything = json_encode($this->logged);
        $this->assertStringNotContainsString(self::TOKEN, $everything);
        $this->assertStringNotContainsString(self::BODY_SECRET, $everything);
        $this->assertStringNotContainsString('bea@example.com', $everything);
    }

    public function test_a_missing_token_is_a_config_error(): void
    {
        $this->expectException(MailConfigError::class);
        $this->expectExceptionMessage('MAILTRAP_API_TOKEN');

        new MailtrapMailer(['token' => '', 'inbox_id' => '4242', 'mode' => 'sandbox', 'from' => 'a@b.test']);
    }

    public function test_sandbox_without_an_inbox_is_a_config_error(): void
    {
        $this->expectException(MailConfigError::class);
        $this->expectExceptionMessage('MAILTRAP_INBOX_ID');

        new MailtrapMailer(['token' => self::TOKEN, 'inbox_id' => null, 'mode' => 'sandbox', 'from' => 'a@b.test']);
    }

    public function test_masking(): void
    {
        $this->assertSame('b***@email.com', MailtrapMailer::mask('bea.santos@email.com'));
        $this->assertSame('***', MailtrapMailer::mask('not-an-address'));
    }

    private function mailer(?string $mode, ?string $inbox = '4242'): MailtrapMailer
    {
        return new MailtrapMailer([
            'token' => self::TOKEN,
            'inbox_id' => $inbox,
            'mode' => $mode,
            'from' => 'no-reply@angelnest.test',
            'from_name' => 'AngelNest',
        ]);
    }

    /** @return array<string, string> */
    private function email(): array
    {
        return [
            'to' => 'bea@example.com',
            'toName' => 'Bea Santos',
            'subject' => 'Your temporary AngelNest password',
            'html' => '<p>'.self::BODY_SECRET.'</p>',
            'text' => self::BODY_SECRET,
            'category' => 'temp-password',
        ];
    }
}
