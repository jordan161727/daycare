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
 * A card on the register, tapped through a School Age child's day.
 *
 * In, out, in, out: the morning session's arrival and departure, then the
 * afternoon's, each its own row. Then back in and out again, for the child
 * collected early and brought back. The rendered page can say what a tap is
 * wired to; only a click can show the six taps make the six right requests
 * in the right order.
 *
 * Skips, rather than fails, where node or jsdom is not installed.
 */
class RegisterCardsClickTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_taps_take_a_school_age_child_through_both_sessions_and_back(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 09:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Amelia', 'last_name' => 'Bennett',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        app(WeekSchedule::class)->open('2026-09-21');

        $html = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'register').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-register-cards.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js')],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(90)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertTrue($result['alpine'], 'Alpine did not start.');
        $this->assertTrue($result['cardFound'], 'No card was drawn in Card view.');
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));

        // The six requests, in the day's order: AM in, AM out, PM in, PM out —
        // then, collected early and brought back, PM back in and out again.
        $urls = array_map(fn ($p) => preg_replace('~^https?://[^/]+~', '', $p['url']), $result['posted']);
        $this->assertSame([
            '/attendance/sign-in', '/attendance/sign-in/retime',
            '/attendance/sign-in', '/attendance/sign-in/retime',
            '/attendance/sign-in/return', '/attendance/sign-in/retime',
        ], $urls);

        $this->assertSame('AM', $result['posted'][0]['body']['session']);
        $this->assertSame('AM', $result['posted'][1]['body']['session']);
        $this->assertArrayHasKey('signed_out_time', $result['posted'][1]['body']);
        $this->assertSame('PM', $result['posted'][2]['body']['session']);
        $this->assertSame('PM', $result['posted'][3]['body']['session']);
        $this->assertArrayHasKey('signed_out_time', $result['posted'][3]['body']);
        $this->assertSame('PM', $result['posted'][4]['body']['session']);
        $this->assertSame('PM', $result['posted'][5]['body']['session']);
        $this->assertArrayHasKey('signed_out_time', $result['posted'][5]['body']);

        // What the card said it would do at each tap, and what it read after.
        // Named the way the register names a session: morning, afternoon.
        $this->assertSame('Tap: clock in (morning)', $result['steps'][0]['tapped']);
        $this->assertSame('Tap: clock out (morning)', $result['steps'][1]['tapped']);
        $this->assertSame('Tap: clock in (afternoon)', $result['steps'][2]['tapped']);
        $this->assertSame('Tap: clock out (afternoon)', $result['steps'][3]['tapped']);
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p', $result['steps'][3]['state']);

        // A day with every session out is not a card that stops: a child
        // who left can come back, and the tap says so.
        $this->assertSame('Tap: clock in again (afternoon)', $result['steps'][4]['tapped']);
        $this->assertFalse($result['steps'][4]['wasDisabled']);
        // Back in the room: the afternoon reads open again — no departure on
        // the end of it, the way a first arrival reads — with the trip out on it.
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p, 3:20p', $result['steps'][4]['state']);

        // And out once more, with the last departure on the end of the day.
        $this->assertSame('Tap: clock out (afternoon)', $result['steps'][5]['tapped']);
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p, 3:20p–4:15p', $result['steps'][5]['state']);
        $this->assertSame('Tap: clock in again (afternoon)', $result['finalTitle']);
        $this->assertFalse($result['finalDisabled']);
    }

    public function test_in_table_view_the_am_and_pm_boxes_are_two_ins_and_two_outs(): void
    {
        /*
         * The director's view, the same day. The AM box is tapped three
         * times — in, out, and back in — and the PM box twice. What must come
         * out is the same requests a card makes, a session at a time, and
         * each box reading both of its hours once it has them.
         */
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

        app(WeekSchedule::class)->open('2026-09-21');

        $html = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'register').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-register-cards.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), 'sheet'],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(90)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertTrue($result['alpine'], 'Alpine did not start.');
        $this->assertTrue($result['boxesFound'], "Today's AM and PM boxes were not drawn.");
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));

        $urls = array_map(fn ($p) => preg_replace('~^https?://[^/]+~', '', $p['url']), $result['posted']);
        $this->assertSame([
            '/attendance/sign-in', '/attendance/sign-in/retime', '/attendance/sign-in/return',
            '/attendance/sign-in', '/attendance/sign-in/retime',
        ], $urls, json_encode($result["steps"]));
        $this->assertSame(['AM', 'AM', 'AM', 'PM', 'PM'], array_column(array_column($result['posted'], 'body'), 'session'));

        // Each box says what its next tap does, and shows the day as it goes.
        $this->assertSame('AM8:05a', $result['steps'][0]['text']);
        $this->assertStringContainsString('tap: clock out', $result['steps'][1]['tapped']);
        $this->assertSame('AM8:05a–11:30a', $result['steps'][1]['text']);
        $this->assertStringContainsString('tap: clock in again', $result['steps'][2]['tapped']);
        $this->assertSame('AM8:05a–3:00p, 3:20p', $result['steps'][2]['text']);
        $this->assertSame('PM12:30p', $result['steps'][3]['text']);
        $this->assertSame('PM12:30p–3:00p', $result['steps'][4]['text']);
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
