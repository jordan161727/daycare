<?php

namespace Tests\Unit;

use App\Services\Mail\TemporaryPasswordEmail;
use Tests\TestCase;

class TemporaryPasswordEmailTest extends TestCase
{
    public function test_every_placeholder_is_filled_in_both_versions(): void
    {
        $email = TemporaryPasswordEmail::render($this->values());

        foreach (['html', 'text'] as $part) {
            $this->assertStringNotContainsString('{{', $email[$part], "$part still has a placeholder");
            $this->assertStringContainsString('Bea', $email[$part]);
            $this->assertStringContainsString('Ms. Cruz', $email[$part]);
            $this->assertStringContainsString('Little Angels', $email[$part]);
            $this->assertStringContainsString('ABCD-EFGH-JKMN', $email[$part]);
            $this->assertStringContainsString('Saturday, Nov 1 at 3:00 PM', $email[$part]);
            $this->assertStringContainsString('https://angelnest.test/login', $email[$part]);
        }

        $this->assertSame('Your temporary AngelNest password', $email['subject']);
        $this->assertSame('temp-password', $email['category']);

        // The logo is shown from inside the email, and goes with it.
        $this->assertStringContainsString('src="cid:angelnest-logo"', $email['html']);
        $this->assertSame([['cid' => 'angelnest-logo', 'path' => resource_path('mail/logo.png'), 'type' => 'image/png']], $email['inline']);
        $this->assertFileExists($email['inline'][0]['path']);
    }

    public function test_html_in_a_name_is_escaped_in_the_html_version_only(): void
    {
        $email = TemporaryPasswordEmail::render($this->values([
            'first_name' => '<b>Bea</b>',
            'director_name' => '<script>alert(1)</script>',
            'center_name' => 'Tom & Jerry "Care"',
        ]));

        $this->assertStringNotContainsString('<b>Bea</b>', $email['html']);
        $this->assertStringNotContainsString('<script>', $email['html']);
        $this->assertStringContainsString('&lt;b&gt;Bea&lt;/b&gt;', $email['html']);
        $this->assertStringContainsString('Tom &amp; Jerry &quot;Care&quot;', $email['html']);

        // Plain text has no markup to inject into; it reads as typed.
        $this->assertStringContainsString('Hi <b>Bea</b>,', $email['text']);
    }

    public function test_a_value_that_looks_like_a_placeholder_is_left_alone(): void
    {
        $email = TemporaryPasswordEmail::render($this->values(['first_name' => '{{signin_url}}']));

        $this->assertStringContainsString('Hi {{signin_url}},', $email['text']);
    }

    public function test_a_missing_value_is_refused(): void
    {
        $values = $this->values();
        unset($values['temp_password']);

        $this->expectException(\InvalidArgumentException::class);

        TemporaryPasswordEmail::render($values);
    }

    /** @return array<string, string> */
    private function values(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Bea',
            'director_name' => 'Ms. Cruz',
            'center_name' => 'Little Angels',
            'temp_password' => 'ABCD-EFGH-JKMN',
            'expires_at' => 'Saturday, Nov 1 at 3:00 PM',
            'signin_url' => 'https://angelnest.test/login',
        ], $overrides);
    }
}
