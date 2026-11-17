<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Children list's search, typed into in a headless DOM (tester report,
 * Nov 2026): "Showing X of Y" and the room and pay chips follow it, a search
 * that finds nobody says so and counts nought, and the search is written to
 * the address as ?search= — and to the links back to the list — so Back, a
 * refresh and a shared link keep it. tests/js/children-search.cjs does the
 * typing. Skips where node, jsdom or Alpine is not installed.
 */
class ChildrenSearchFollowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_footer_and_the_chips_follow_the_search_and_the_address_keeps_it(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/alpinejs/dist/cdn.js'))) {
            $this->markTestSkipped('node, jsdom and Alpine are needed to type into the page.');
        }

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        Child::create(['lan' => '10001', 'first_name' => 'Ava', 'last_name' => 'Stone', 'status' => 'Active', 'classroom_override' => 'Toddler', 'dss_case_no' => 'S1177706D']);
        Child::create(['lan' => '10002', 'first_name' => 'Ben', 'last_name' => 'Brook', 'status' => 'Active', 'classroom_override' => 'Toddler']);
        Child::create(['lan' => '10003', 'first_name' => 'Cleo', 'last_name' => 'Stone', 'status' => 'Active', 'classroom_override' => 'Infant']);

        $address = route('children.index', ['status' => 'Active', 'q' => 'brook']);
        $html = $this->actingAs($admin)->get($address)->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'children').'.html';
        file_put_contents($page, $html);

        $process = new Process([$node, base_path('tests/js/children-search.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), $address], base_path(), ['NODE_PATH' => base_path('node_modules')]);
        $process->setTimeout(60)->run();
        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The search script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors']);

        // Opened on the old ?q= spelling: the search is on, written back as ?search=.
        $this->assertSame(['10002'], $result['onLoad']['rows']);
        $this->assertStringStartsWith('Showing 1 of 3', $result['onLoad']['showing']);
        $this->assertStringContainsString('search=brook', $result['onLoad']['address']);
        $this->assertStringNotContainsString('q=', $result['onLoad']['address']);

        // Typed: rows, footer and chips follow; the tabs carry it.
        $this->assertSame(['10001', '10003'], $result['stone']['rows']);
        $this->assertStringStartsWith('Showing 2 of 3', $result['stone']['showing']);
        $this->assertSame(['1', '1', '2', '1'], [$result['stone']['toddler'], $result['stone']['infant'], $result['stone']['allRooms'], $result['stone']['dss']]);
        $this->assertStringContainsString('search=stone', $result['stone']['address']);
        $this->assertStringContainsString('search=stone', $result['stone']['tabLink']);

        // Nobody: says so, and counts nought everywhere.
        $this->assertSame([], $result['nobody']['rows']);
        $this->assertTrue($result['nobody']['empty']);
        $this->assertStringStartsWith('Showing 0 of 3', $result['nobody']['showing']);
        $this->assertSame(['0', '0', '0', '0'], [$result['nobody']['toddler'], $result['nobody']['infant'], $result['nobody']['allRooms'], $result['nobody']['dss']]);

        // Cleared: the server's counts back, and the address clean.
        $this->assertCount(3, $result['cleared']['rows']);
        $this->assertStringStartsWith('Showing 3 of 3', $result['cleared']['showing']);
        $this->assertSame(['2', '1', '3', '1'], [$result['cleared']['toddler'], $result['cleared']['infant'], $result['cleared']['allRooms'], $result['cleared']['dss']]);
        $this->assertStringNotContainsString('search=', $result['cleared']['address']);
        $this->assertFalse($result['cleared']['empty']);
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
