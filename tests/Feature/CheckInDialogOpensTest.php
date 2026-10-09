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
        $this->assertSame('Clock in · AM', $result['button']);

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
        $this->assertSame('Amelia Bennett clocked in at 8:42a (AM)', $result['toast']);
        $this->assertSame('In · 8:42a', $result['cardState']);
    }

    public function test_on_a_day_already_gone_the_dialog_saves_through_the_register(): void
    {
        /*
         * The direct-edit path, end to end in a headless DOM: open a past
         * day, press the child's card, type a leaving time, press Save. What
         * has to come out is one request to the register's retime — not the
         * door, not the health endpoint — and the card reading the new time.
         */
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-25 09:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Amelia', 'last_name' => 'Bennett',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        // An arrival on the 22nd with no departure: the day the director opens.
        \App\Models\Attendance::create([
            'child_id' => $child->id, 'attendance_date' => '2026-09-22', 'session' => 'AM',
            'signed_in_at' => Carbon::parse('2026-09-22 08:05'), 'health_in_code' => 0,
        ]);

        $html = $this->actingAs($admin)->get(route('check-in.index', ['date' => '2026-09-22']))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'checkin').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-check-in.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), 'edit'],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));

        // The dialog opened in correcting mode, on the recorded arrival.
        $this->assertTrue($result['dialog']);
        $this->assertTrue($result['outFieldPresent'], 'a past day shows the time fields');
        $this->assertSame('08:05', $result['inFieldValue'], 'the In field opens on the recorded arrival');
        $this->assertSame('Save changes', $result['saveButton']);
        $this->assertTrue($result['saveEnabled'], 'typing a leaving time lights Save');

        /*
         * Two requests, in the dialog's order. First the register's retime,
         * carrying the typed time. Then the leaving check: a departure has a
         * check taken with it, and the form's "0 · Normal" is that check —
         * a departure with no reading would say nobody looked.
         */
        $this->assertCount(2, $result['posted']);
        $this->assertStringEndsWith('/attendance/sign-in/retime', $result['posted'][0]['url']);
        $this->assertSame(
            ['child_id' => $child->id, 'attendance_date' => '2026-09-22', 'session' => 'AM', 'signed_out_time' => '16:30'],
            $result['posted'][0]['body']
        );
        $this->assertSame('/attendance/1/health', $result['posted'][1]['url']);
        $this->assertSame(['direction' => 'out', 'code' => 0, 'note' => null], $result['posted'][1]['body']);

        // And the card follows the register's answer.
        $this->assertFalse($result['dialogAfter']);
        $this->assertSame('Amelia Bennett: day saved', $result['toast']);
        $this->assertStringContainsString('Out 4:30p', $result['cardState']);
    }

    public function test_a_school_age_child_clocked_out_once_is_listed_and_clocked_in_again(): void
    {
        /*
         * A School Age child has two rows on the register, but the door does
         * not say "morning" and "afternoon" — the client's words: it lists
         * the clock-ins and clock-outs there are. In at 8:05, out at 11:30,
         * so the dialog lists that one entry, reads "Left at 11:30a", offers
         * Clock in again, and the request it makes books the second row.
         */
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Amelia', 'last_name' => 'Bennett',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        \App\Models\Attendance::create([
            'child_id' => $child->id, 'attendance_date' => '2026-09-23', 'session' => 'AM',
            'signed_in_at' => Carbon::parse('2026-09-23 08:05'), 'signed_out_at' => Carbon::parse('2026-09-23 11:30'), 'health_in_code' => 0,
        ]);

        $html = $this->actingAs($admin)->get(route('check-in.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'checkin').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-check-in.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js')],
            base_path(),
            ['NODE_PATH' => base_path('node_modules'), 'CLOCK_HOUR' => '12'],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));
        $this->assertTrue($result['dialog']);

        // Two blocks, AM and PM — not "morning" and "afternoon" — each
        // listing its own entries. The AM is done with its one pair, the PM
        // still to do and chosen. The one status line is for one-session rooms.
        $this->assertSame(
            [
                // From noon the AM is switched off: only the PM clocks in and out.
                ['text' => 'AM ✓ Done 8:05a → 11:30a 1 check-in · 3h 25m', 'selected' => false, 'disabled' => true],
                ['text' => 'PM To do — : — Not clocked in', 'selected' => true, 'disabled' => false],
            ],
            $result['tabs']
        );
        $this->assertNull($result['status']);
        // And the one list under the blocks carries the pair, named for its block, with its check.
        $this->assertSame(['1 AM In 8:05a0 → Out 11:30a 3h 25m'], $result['entries']);
        $this->assertSame('Clock in · PM', $result['button']);

        // And the clock-in books the PM row, not the AM again.
        $this->assertCount(1, $result['posted']);
        $this->assertSame('/check-in', $result['posted'][0]['url']);
        $this->assertSame('PM', $result['posted'][0]['body']['session']);
        $this->assertSame('Amelia Bennett clocked in at 8:42a (PM)', $result['toast']);

        // The roster card reads the day as one: on the premises, second time.
        $this->assertSame('In · 8:42a · 2nd time', $result['cardState']);
    }

    /**
     * The same child, out of the AM at 11:30, but the clock says ten: the
     * block is the clock's, so the press is a second entry in the AM — a
     * "back in" on that row — and not a PM that has not started.
     */
    public function test_before_noon_a_second_clock_in_goes_into_the_am_block(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $admin = User::factory()->create(['role' => 'admin']);

        $child = Child::create([
            'lan' => '3001', 'status' => 'Active', 'first_name' => 'Amelia', 'last_name' => 'Bennett',
            'classroom' => 'School Age', 'birth_date' => '2018-03-04', 'schedule_days' => [1, 2, 3, 4, 5],
        ]);

        $attendance = \App\Models\Attendance::create([
            'child_id' => $child->id, 'attendance_date' => '2026-09-23', 'session' => 'AM',
            'signed_in_at' => Carbon::parse('2026-09-23 08:05'), 'signed_out_at' => Carbon::parse('2026-09-23 09:30'), 'health_in_code' => 0,
        ]);

        $html = $this->actingAs($admin)->get(route('check-in.index'))->assertOk()->getContent();

        $page = tempnam(sys_get_temp_dir(), 'checkin').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-check-in.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js')],
            base_path(),
            ['NODE_PATH' => base_path('node_modules'), 'CLOCK_HOUR' => '10'],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors'], 'The component logged errors: '.implode('; ', $result['errors']));
        $this->assertTrue($result['dialog']);

        // The AM is the chosen block, and the press is a "back in" on it.
        $this->assertTrue($result['tabs'][0]['selected']);
        $this->assertFalse($result['tabs'][1]['selected']);
        // And before noon the PM is switched off.
        $this->assertFalse($result['tabs'][0]['disabled']);
        $this->assertTrue($result['tabs'][1]['disabled']);
        $this->assertSame('Clock in again · AM', $result['button']);

        $this->assertCount(1, $result['posted']);
        $this->assertSame('/check-in/'.$attendance->id.'/back', $result['posted'][0]['url']);
        $this->assertArrayNotHasKey('session', $result['posted'][0]['body']);
        $this->assertSame('Amelia Bennett clocked in again at 8:42a (AM)', $result['toast']);
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
