<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function routeThatExpires(): void
    {
        Route::post('/_expired', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        })->middleware('web');
    }

    public function test_long_open_pages_can_fetch_a_fresh_token(): void
    {
        $response = $this->getJson('/csrf-token');

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_the_token_endpoint_is_reachable_while_signed_out(): void
    {
        $this->getJson('/csrf-token')->assertOk();
    }

    public function test_a_stale_form_returns_to_the_page_instead_of_the_page_expired_screen(): void
    {
        $this->routeThatExpires();

        $response = $this->from('/children/import')->post('/_expired', ['name' => 'Ada']);

        $response->assertRedirect('/children/import');
        $response->assertSessionHas('error');
        $this->assertStringContainsString('timed out', session('error'));
    }

    public function test_a_stale_form_keeps_what_was_typed_but_not_the_password(): void
    {
        $this->routeThatExpires();

        $this->from('/login')->post('/_expired', [
            'email' => 'teacher@example.com',
            'password' => 'secret-value',
        ]);

        $this->assertSame('teacher@example.com', session('_old_input.email'));
        $this->assertArrayNotHasKey('password', session('_old_input'));
    }

    public function test_a_stale_background_request_gets_a_419_it_can_retry(): void
    {
        $this->routeThatExpires();

        $response = $this->postJson('/_expired', ['child_id' => 1]);

        $response->assertStatus(419);
        $this->assertStringContainsString('timed out', $response->json('message'));
    }
}
