<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA round of 12 Nov 2026 — the menu, the Holidays page and the Schedule
 * page's wording. Each test names the QA item it pins.
 */
class QaNovemberChromeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-12 09:00:00'));
        $this->admin = User::create(['name' => 'Ray Director', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
    }

    /** QA #11: the header escapes its own title, so the call site passes a plain "&". */
    public function test_the_holidays_heading_is_escaped_once(): void
    {
        $html = $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>Holidays &amp; Closures<\/h1>/', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /** QA #42: US holidays as the examples, and nothing about seeders. */
    public function test_the_holidays_help_names_us_holidays_and_no_internals(): void
    {
        $html = $this->actingAs($this->admin)->get(route('holidays.index'))->assertOk()->getContent();
        $visible = preg_replace('/<script\b.*?<\/script>/s', '', $html);

        $this->assertStringContainsString('Memorial Day, Labor Day, Thanksgiving', $visible);
        $this->assertStringNotContainsString('Victoria Day', $visible);
        $this->assertStringNotContainsString('Labour Day', $visible);
        $this->assertStringNotContainsStringIgnoringCase('seeder', $visible);
    }

    /**
     * QA #14: below the breakpoint the drawer arrives closed from the server —
     * not closed only once Alpine runs — and its dimmer arrives hidden.
     */
    public function test_the_drawer_is_closed_before_alpine_boots(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div x-show="mobileOpen" x-cloak /', $html);

        preg_match('/<aside [^>]*>/', $html, $aside);
        $this->assertNotEmpty($aside);
        preg_match('/ class="([^"]*)"/', $aside[0], $static);
        $classes = explode(' ', $static[1]);
        $this->assertContains('-translate-x-full', $classes);
        $this->assertContains('desktop:translate-x-0', $classes);
        $this->assertContains('desktop:w-48', $classes);

        // The object form of :class, which can take a server class away again.
        $this->assertStringContainsString("'-translate-x-full': !mobileOpen", $aside[0]);
        $this->assertStringContainsString("'translate-x-0': mobileOpen", $aside[0]);
    }

    /** QA #22: the product's name as it is spelt everywhere else, from config. */
    public function test_the_wordmark_reads_the_product_name_from_config(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-wordmark>AngelNest</span>', $html);
        $this->assertStringNotContainsString('Angel Nest', $html);

        config(['daycare.parent_app.name' => 'BrightNest']);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee('data-wordmark>BrightNest</span>', false);
    }

    /** QA #19 and #17/#33: no sentence with a hole for a name; the bar is named. */
    public function test_the_schedule_time_off_lines_and_bars_never_lose_their_name(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff-schedule.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('All of <span', $html);
        $this->assertStringNotContainsString('Time off for <span', $html);
        $this->assertMatchesRegularExpression('/<a x-show="selShift\(\) &amp;&amp; selShift\(\)\.name"|<a x-show="selShift\(\) && selShift\(\)\.name"/', $html);

        $this->assertMatchesRegularExpression('/class="bar"[^>]*aria-label="Shift"[^>]*:aria-label="\'Shift: \' \+ l\.name/s', $html);
    }
}
