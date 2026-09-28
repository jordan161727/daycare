<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pressing a card on the Check In roster opens its dialog.
 *
 * The one behaviour on that screen that nothing else here can see. Every
 * other test reads the rendered page; the JS-parse guard proves the script is
 * valid JavaScript. Neither can tell whether a click does anything — and once
 * it did nothing: the loop variable on the cards and the component property
 * the dialog watches shared a name, so Alpine wrote the click's result into
 * the loop's scope and the dialog never saw it. Every assertion stayed green.
 *
 * So this renders the page, hands it to a headless DOM with the project's own
 * Alpine, clicks the first card, and looks for the dialog. It skips, rather
 * than fails, where node or jsdom is not installed.
 */
class CheckInDialogOpensTest extends TestCase
{
    use RefreshDatabase;

    public function test_pressing_a_card_opens_its_dialog(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Amelia', 'last_name' => 'Bennett',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        $html = $this->actingAs($admin)->get(route('check-in.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'checkin').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-check-in.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js')],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertTrue($result['alpine'], 'Alpine did not start.');
        $this->assertSame(1, $result['cards'], 'One child, one card.');
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));

        // The click, and what it opened.
        $this->assertTrue($result['dialog'], 'Pressing the card did not open its dialog.');
        $this->assertSame('Amelia Bennett', $result['heading']);
        $this->assertSame(12, $result['options'], 'Codes 0 to 11.');
        $this->assertSame('Clock in', $result['button']);

        /*
         * Then Clock in. The request the button makes is the whole point of
         * the screen, and the one thing a rendered page cannot show: it once
         * called a helper that no longer existed and would have thrown in a
         * room at eight in the morning, with every other test green.
         */
        $this->assertCount(1, $result['posted'], 'Clock in should make exactly one request.');
        $this->assertSame('/check-in', $result['posted'][0]['url']);
        // School Age books a morning and an afternoon; a card at the door
        // books the child's first session, the way the sheet does.
        $this->assertSame(['child_id' => 1, 'session' => 'AM', 'health_code' => 0, 'health_note' => null], $result['posted'][0]['body']);

        // And the card follows the answer: dialog closed, a toast, the card in.
        $this->assertFalse($result['dialogAfter'], 'The dialog should close once the clock-in is saved.');
        $this->assertSame('Amelia Bennett clocked in at 8:42a', $result['toast']);
        $this->assertSame('In · 8:42a', $result['cardState']);
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
