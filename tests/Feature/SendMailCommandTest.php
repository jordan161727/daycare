<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMailCommandTest extends TestCase
{
    public function test_it_posts_to_the_sandbox_inbox_and_never_prints_the_token(): void
    {
        config(['services.mailtrap' => ['token' => 'tok_secret', 'inbox_id' => '4990646', 'mode' => 'sandbox', 'from' => 'hello@example.com', 'from_name' => 'AngelNest']]);
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->artisan('send-mail', ['email' => 'someone@example.com'])
            ->expectsOutputToContain('inbox: 4990646')
            ->expectsOutputToContain('token: set')
            ->doesntExpectOutputToContain('tok_secret')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://sandbox.api.mailtrap.io/api/send/4990646'
            && $r['category'] === 'Integration Test'
            && $r['to'] === [['email' => 'someone@example.com']]);
    }

    public function test_a_missing_token_fails_with_the_reason(): void
    {
        config(['services.mailtrap' => ['token' => null, 'inbox_id' => '4990646', 'mode' => 'sandbox', 'from' => 'hello@example.com', 'from_name' => 'AngelNest']]);
        Http::fake();

        $this->artisan('send-mail', ['email' => 'someone@example.com'])
            ->expectsOutputToContain('MAILTRAP_API_TOKEN is not set')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}
