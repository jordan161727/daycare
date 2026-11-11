<?php

namespace Tests\Feature;

use Tests\TestCase;

/** QA #44: the protective headers go out on pages, the API and errors alike. */
class SecurityHeadersTest extends TestCase
{
    public function test_a_page_carries_every_header(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
        $csp = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertNull($response->headers->get('Content-Security-Policy'), 'report-only until switched on');
    }

    public function test_hsts_only_over_https(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_policy_can_be_enforced(): void
    {
        config(['daycare.security.csp_enforce' => true]);

        $response = $this->get('/login');

        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_api_and_error_responses_carry_them_too(): void
    {
        $this->getJson('/api/parent/ping')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/no-such-page-anywhere')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
