<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CentreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * QA retest, 18 Nov 2026: the header said Tuesday, Nov 17 (New York) while
 * the Holidays calendar marked the 18th, because the director's browser was
 * in Manila and every date picker asked the browser for today. Today is the
 * centre's: CentreSettings::today() on the server, and the page head hands
 * the centre's timezone to every picker (resources/js/centre-today.js).
 */
class CentreTodayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_centre_today_is_the_centre_date_whatever_the_server_clock_says(): void
    {
        // 22:00 in New York on the 17th: 03:00 UTC, 11:00 in Manila, on the 18th.
        $this->travelTo(Carbon::parse('2026-11-18 03:00:00', 'UTC'));

        $this->assertSame('America/New_York', CentreSettings::timezone());
        $this->assertSame('2026-11-17', CentreSettings::today());

        config(['app.timezone' => 'Asia/Manila']);
        $this->assertSame('2026-11-18', CentreSettings::today(), 'follows the centre timezone setting');
    }

    public function test_every_page_hands_the_centre_today_to_its_date_pickers(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 03:00:00', 'UTC'));
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('holidays.index'))
            ->assertOk()
            ->assertSee('<meta name="centre-timezone" content="America/New_York">', false)
            ->assertSee('<meta name="centre-today" content="2026-11-17">', false)
            ->assertSee('root.centreTodayDate = function', false);
    }

    public function test_the_date_picker_marks_the_centre_today_in_a_browser_eight_hours_ahead(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/flatpickr/package.json'))) {
            $this->markTestSkipped('node, jsdom and flatpickr are needed to draw the picker.');
        }

        $process = new Process([$node, base_path('tests/js/centre-today.cjs')], base_path(), ['NODE_PATH' => base_path('node_modules'), 'TZ' => 'Asia/Manila']);
        $process->setTimeout(60)->run();

        $this->assertSame(0, $process->getExitCode(), "The picker script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());

        // The browser really is in Manila, where it is already the 18th —
        // and a page without the centre's timezone shows that.
        $this->assertSame('2026-11-18', $result['browserOnly']['browserDate']);
        $this->assertSame('2026-11-18', $result['browserOnly']['highlighted']);

        // With it, the picker's today is New York's.
        $this->assertSame([], $result['centre']['errors']);
        $this->assertSame('2026-11-17', $result['centre']['centreToday']);
        $this->assertSame('2026-11-17', $result['centre']['pickerNow']);
        $this->assertSame('2026-11-17', $result['centre']['highlighted']);
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
