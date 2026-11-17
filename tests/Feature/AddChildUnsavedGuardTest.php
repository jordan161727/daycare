<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * QA retest, 16 Nov 2026: after typing in Add child the tab could not be
 * left. The "Leave site?" prompt stays, but only for a real unsaved change:
 * not on opening, not for events the date pickers fire on their own, not
 * once what was typed is deleted again, and never after Cancel. (A save that
 * goes ahead clears it — ChildAddAndListTesterReportTest.)
 *
 * Driven in a headless DOM by tests/js/unsaved-guard.cjs.
 */
class AddChildUnsavedGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_add_child_is_held_only_for_a_real_unsaved_change(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/alpinejs/dist/cdn.js'))) {
            $this->markTestSkipped('node, jsdom and Alpine are needed to type into the page.');
        }

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);

        $address = route('children.create');
        $html = $this->actingAs($admin)->get($address)->assertOk()->getContent();
        $page = tempnam(sys_get_temp_dir(), 'addchild').'.html';
        file_put_contents($page, $html);

        $process = new Process([$node, base_path('tests/js/unsaved-guard.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), $address], base_path(), ['NODE_PATH' => base_path('node_modules')]);
        $process->setTimeout(60)->run();
        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The guard script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors']);

        $this->assertFalse($result['untouched'], 'opened and left alone');
        $this->assertFalse($result['eventsOnly'], 'input/change fired with nothing changed');
        $this->assertTrue($result['typed'], 'a name typed and not saved');
        $this->assertFalse($result['typedAndDeleted'], 'typed and deleted again');
        $this->assertFalse($result['afterCancel'], 'Cancel leaves without being held');
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
