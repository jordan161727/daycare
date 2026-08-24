<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
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

    public function test_the_sidebar_counts_the_leave_requests_waiting_on_a_director(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $teacher = User::factory()->create(['role' => 'teacher']);

        // Nothing waiting: the link is there, the badge is not.
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Leave Requests')
            ->assertDontSee('bg-amber-500 px-2 py-0.5', escape: false);

        foreach (['2026-09-01', '2026-09-08'] as $date) {
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'leave_type' => 'VACATION',
                'starts_on' => $date,
                'ends_on' => $date,
                'hours_per_day' => 8,
                'status' => LeaveRequest::STATUS_PENDING,
            ]);
        }

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('bg-amber-500 px-2 py-0.5', $html);
        $this->assertMatchesRegularExpression('/bg-amber-500[^>]*>2</', $html);

        // A teacher has no queue to be counted at, so neither link is theirs.
        $this->actingAs($teacher)->get(route('dashboard'))->assertOk()
            ->assertSee('My Leave')
            ->assertDontSee('Leave Requests');
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
