<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_is_a_drawer_until_the_desktop_breakpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        // Off-canvas by default, pinned only under the `desktop` variant.
        $this->assertStringContainsString('-translate-x-full desktop:translate-x-0', $html);
        $this->assertStringContainsString('desktop:sticky', $html);
        $this->assertStringContainsString('desktop:h-screen', $html);

        // The hamburger is the only way in below that breakpoint.
        $this->assertStringContainsString('@click="mobileOpen = true"', $html);
        $this->assertStringContainsString('desktop:hidden', $html);

        // The old width-only breakpoint is gone, so tablets never pin the sidebar.
        $this->assertStringNotContainsString('xl:translate-x-0', $html);
        $this->assertStringNotContainsString('xl:sticky', $html);
    }

    public function test_the_desktop_variant_requires_width_and_a_fine_pointer(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            '@custom-variant desktop (@media (min-width: 1441px) or ((min-width: 1280px) and (pointer: fine)));',
            $css,
            'a touch device must keep the drawer no matter how wide its screen is'
        );
    }

    public function test_reports_hide_the_app_chrome_when_printed(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/@media print\s*\{[^}]*aside, header, \.no-print\s*\{\s*display: none/', $css);
    }
}
