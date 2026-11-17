<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The side menu at phone width is a dialog (QA, 17 Nov 2026): Escape closes
 * it and puts focus back on the menu button, and Tab stays inside it while it
 * is open. tests/js/sidebar-drawer.cjs does the pressing, against the real
 * resources/js/app-shell.js. Skips where node, jsdom or Alpine is missing.
 */
class SidebarDrawerKeyboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_button_and_drawer_are_wired_as_a_dialog(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $html = $this->actingAs($admin)->get(route('holidays.index'))->assertOk()->getContent();

        $this->assertStringContainsString('@keydown.escape.window="closeMenu()"', $html);
        $this->assertStringContainsString('@keydown.tab.window="trapMenuFocus($event)"', $html);
        $this->assertStringContainsString('x-ref="menuButton" @click="openMenu()" aria-controls="app-sidebar"', $html);
        $this->assertStringContainsString('id="app-sidebar" x-ref="drawer"', $html);
    }

    public function test_escape_closes_the_drawer_returns_focus_and_tab_is_trapped(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/alpinejs/dist/cdn.js'))) {
            $this->markTestSkipped('node, jsdom and Alpine are needed to press keys on the page.');
        }

        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $html = $this->actingAs($admin)->get(route('holidays.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'drawer').'.html';
        file_put_contents($page, $html);

        $process = new Process([$node, base_path('tests/js/sidebar-drawer.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js')], base_path(), ['NODE_PATH' => base_path('node_modules')]);
        $process->setTimeout(60)->run();
        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The drawer script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors']);

        $this->assertSame('false', $result['closedExpanded']);
        $this->assertTrue($result['open']);
        $this->assertSame('true', $result['openExpanded']);
        $this->assertSame('dialog', $result['openRole']);
        $this->assertTrue($result['focusInsideOnOpen']);
        $this->assertGreaterThan(2, $result['focusables']);

        $this->assertTrue($result['tabFromLastPrevented']);
        $this->assertTrue($result['tabFromLastLandsOnFirst']);
        $this->assertTrue($result['shiftTabFromFirstPrevented']);
        $this->assertTrue($result['shiftTabFromFirstLandsOnLast']);
        $this->assertTrue($result['strayFocusPulledBack']);

        $this->assertTrue($result['closedByEscape']);
        $this->assertTrue($result['focusBackOnButton']);
        $this->assertSame('false', $result['expandedAfterEscape']);
        $this->assertFalse($result['tabWhenClosedPrevented']);
    }

    private function node(): ?string
    {
        foreach (['node', 'node.exe'] as $candidate) {
            $which = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', $candidate] : ['which', $candidate]);
            $which->run();

            if ($which->getExitCode() === 0) {
                return trim(strtok($which->getOutput(), "\r\n")) ?: $candidate;
            }
        }

        return null;
    }
}
