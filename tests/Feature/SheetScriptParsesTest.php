<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The register's own JavaScript parses.
 *
 * The sheet is one Alpine component of about two thousand lines written inside
 * a Blade file, and a single bad character in it does not break a cell or a
 * column — it throws at parse time, Alpine never initialises, and the whole
 * page renders as an empty white panel under a working header. Nothing else in
 * this suite notices: every assertion is on the markup, and the markup is
 * perfectly correct. It shipped that way once, from one newline typed into a
 * string instead of an escape.
 *
 * So: render the page, pull the inline scripts out of it, and hand them to
 * node. It is the cheapest possible check and it is the only one that would
 * have caught that.
 */
class SheetScriptParsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registers_inline_script_is_valid_javascript(): void
    {
        $node = $this->node();

        if (! $node) {
            $this->markTestSkipped('node is not on PATH, so the script cannot be parsed here.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
            'drop_off_time' => '08:30',
        ]);

        // An open week, so the sheet draws its cells and every helper they call
        // is rendered rather than skipped.
        app(WeekSchedule::class)->open('2026-09-21');

        $html = $this->actingAs($admin)
            ->get(route('attendance.index'))
            ->assertOk()
            ->getContent();

        // Inline only. A src= script is a built asset and Vite has already had
        // an opinion about whether it parses.
        preg_match_all('~<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>~s', $html, $matches);

        $this->assertNotEmpty($matches[1], 'The register rendered no inline script at all.');

        foreach ($matches[1] as $index => $script) {
            if (trim($script) === '') {
                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'sheet').'.js';
            file_put_contents($path, $script);

            $process = new Process([$node, '--check', $path]);
            $process->run();

            $output = trim($process->getErrorOutput() ?: $process->getOutput());

            @unlink($path);

            $this->assertSame(
                0,
                $process->getExitCode(),
                "Inline script #{$index} on the register does not parse:\n\n".$output
            );
        }
    }

    /** node, if this machine has one. CI may not, and a skip beats a failure. */
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
