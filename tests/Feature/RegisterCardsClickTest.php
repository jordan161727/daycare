<?php

namespace Tests\Feature;

use App\Models\Attendance;
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

        // Four requests, in the day's order: AM in, AM out, PM in, PM out —
        // and then nothing, because the director's register is one arrival
        // and one departure a session. The fifth and sixth taps open the
        // pop-up, which says the day is done and offers nothing to press.
        $urls = array_map(fn ($p) => preg_replace('~^https?://[^/]+~', '', $p['url']), $result['posted']);
        $this->assertSame([
            '/attendance/sign-in', '/attendance/sign-in/retime',
            '/attendance/sign-in', '/attendance/sign-in/retime',
        ], $urls);

        $this->assertSame('AM', $result['posted'][0]['body']['session']);
        $this->assertSame('AM', $result['posted'][1]['body']['session']);
        // The leaving time is the centre's clock — nine, as the script pinned
        // it — never the device's, which may be half a world away.
        $this->assertSame('09:00', $result['posted'][1]['body']['signed_out_time']);
        $this->assertSame('13:00', $result['posted'][3]['body']['signed_out_time']);
        $this->assertSame('PM', $result['posted'][2]['body']['session']);
        $this->assertSame('PM', $result['posted'][3]['body']['session']);
        $this->assertArrayHasKey('signed_out_time', $result['posted'][3]['body']);

        // What the card said it would do at each tap, and what it read after.
        // Named the way the register names a session: morning, afternoon.
        // Each tap opened the pop-up, which offered the clock alone — no
        // health code — and closed once its button was pressed.
        $this->assertSame(
            ['Clock in · AM', 'Clock out · AM', 'Clock in · PM', 'Clock out · PM', 'Clocked out · PM', 'Clocked out · PM'],
            array_column($result['steps'], 'offered')
        );
        $this->assertSame([false, false, false, false, false, false], array_column($result['steps'], 'hasSelect'));
        // A School Age child's pop-up offers the AM and the PM, as the door
        // screen does, opened on whichever is still to do.
        $this->assertSame(array_fill(0, 6, ['AM', 'PM']), array_column($result['steps'], 'sessions'));
        $this->assertSame(['AM', 'AM', 'PM', 'PM', 'PM', 'PM'], array_column($result['steps'], 'chosen'));
        // Before noon the PM is switched off, from noon the AM — except a
        // block still open, which has to be clocked out whatever the hour.
        $this->assertSame([['PM'], ['PM'], ['AM'], ['AM'], ['AM'], ['AM']], array_column($result['steps'], 'off'));
        // The first four presses close the pop-up; the last two taps find a
        // day already done, with nothing to press, so it stays open to read.
        $this->assertSame([true, true, true, true, false, false], array_column($result['steps'], 'dialogClosed'));

        $this->assertSame('Tap: clock in (morning)', $result['steps'][0]['tapped']);
        $this->assertSame('Tap: clock out (morning)', $result['steps'][1]['tapped']);
        $this->assertSame('Tap: clock in (afternoon)', $result['steps'][2]['tapped']);
        $this->assertSame('Tap: clock out (afternoon)', $result['steps'][3]['tapped']);
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p', $result['steps'][3]['state']);

        // A day with every session out is done. The card still opens — the
        // director can read the day — but says so, and the day does not move.
        $this->assertSame('Clocked out for the day (afternoon)', $result['steps'][4]['tapped']);
        $this->assertFalse($result['steps'][4]['wasDisabled']);
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p', $result['steps'][4]['state']);
        $this->assertSame('Clocked out for the day (afternoon)', $result['steps'][5]['tapped']);
        $this->assertSame('AM 8:05a–11:30a · PM 12:30p–3:00p', $result['steps'][5]['state']);
        $this->assertSame('Clocked out for the day (afternoon)', $result['finalTitle']);
        $this->assertFalse($result['finalDisabled']);

        // The Recent panel is drawn, opens from its button, and lists the
        // sign-ins the cards just made. It once shared a teleport with the
        // card pop-up, and Alpine carries only a template's first element —
        // so the panel was never on the page and the button opened nothing.
        $this->assertTrue($result['recentPanelDrawn'], 'The Recent panel is not on the page.');
        $this->assertTrue($result['recentOpened'], 'The Recent button did not open the panel.');
        $this->assertGreaterThan(0, $result['recentCount'], 'The sign-ins just made are not in Recent.');
    }

    public function test_an_am_never_clocked_out_is_closed_first_then_the_pm_opens(): void
    {
        /*
         * The morning's arrival was never clocked out, and it is ten past
         * four. The pop-up must open on the AM — the open block, whatever the
         * clock says — and offer the clock-out; and once that is done, the
         * next tap must open on the PM with the AM switched off, so the
         * afternoon does not land in the morning's row.
         */
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 16:13:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Julian', 'last_name' => 'Floreno',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        app(WeekSchedule::class)->open('2026-09-21');

        Attendance::create([
            'child_id' => $child->id, 'attendance_date' => '2026-09-23', 'session' => 'AM',
            'signed_in_at' => Carbon::parse('2026-09-23 08:05:00'),
        ]);

        $html = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'register').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-register-cards.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), 'forgot'],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(90)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));

        // First tap: the open AM, offered for clocking out, nothing switched off.
        $this->assertSame('Clock out · AM', $result['steps'][0]['offered']);
        $this->assertSame('AM', $result['steps'][0]['chosen']);
        $this->assertSame([], $result['steps'][0]['off']);

        // Second tap: the PM, with the AM now closed for the afternoon.
        $this->assertSame('Clock in · PM', $result['steps'][1]['offered']);
        $this->assertSame('PM', $result['steps'][1]['chosen']);
        $this->assertSame(['AM'], $result['steps'][1]['off']);

        $urls = array_map(fn ($p) => preg_replace('~^https?://[^/]+~', '', $p['url']), $result['posted']);
        $this->assertSame(['/attendance/sign-in/retime', '/attendance/sign-in'], $urls);
        $this->assertSame('AM', $result['posted'][0]['body']['session']);
        $this->assertSame('PM', $result['posted'][1]['body']['session']);
    }

    public function test_in_table_view_a_box_is_one_in_and_one_out(): void
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
        // On the table a tap is in, then out, then nothing: the AM's third
        // tap finds a session already done. One arrival and one departure.
        $this->assertSame([
            '/attendance/sign-in', '/attendance/sign-in/retime',
            '/attendance/sign-in', '/attendance/sign-in/retime',
        ], $urls, json_encode($result["steps"]));
        $this->assertSame(['AM', 'AM', 'PM', 'PM'], array_column(array_column($result['posted'], 'body'), 'session'));

        // Each box shows its arrival alone — the departure is in the hover —
        // and the hover says what the next tap does.
        $this->assertSame('AM8:05a', $result['steps'][0]['text']);
        $this->assertStringContainsString('tap: clock out', $result['steps'][1]['tapped']);
        $this->assertSame('AM8:05a', $result['steps'][1]['text']);
        $this->assertStringContainsString('clocked out 11:30a', $result['steps'][2]['tapped']);
        $this->assertSame('AM8:05a', $result['steps'][2]['text']);
        $this->assertSame('PM12:30p', $result['steps'][3]['text']);
        $this->assertSame('PM12:30p', $result['steps'][4]['text']);
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
