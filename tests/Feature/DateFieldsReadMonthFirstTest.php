<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every date field reads mm/dd/yyyy, whoever is looking.
 *
 * A plain date input is drawn by the browser in the language the computer
 * is set to — dd/mm/yyyy on one machine — and a director who keeps the roll
 * in Excel as 12/15/2022 was reading 15/12/2022 under it. The page draws the
 * field itself now (resources/js/dates.js) while the form still posts Y-m-d.
 *
 * Skips, rather than fails, where node or jsdom is not installed.
 */
class DateFieldsReadMonthFirstTest extends TestCase
{
    public function test_a_date_field_shows_month_first_and_still_posts_year_first(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json')) || ! is_file(base_path('node_modules/flatpickr/package.json'))) {
            $this->markTestSkipped('node, jsdom and flatpickr are needed to drive the field.');
        }

        $process = new Process([$node, base_path('tests/js/date-fields.cjs')], base_path(), ['NODE_PATH' => base_path('node_modules')]);
        $process->setTimeout(60)->run();

        $this->assertSame(0, $process->getExitCode(), "The date field script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);

        $this->assertIsArray($result, 'The script returned no JSON: '.$process->getOutput());
        $this->assertSame([], $result['errors']);

        // What the reader sees, and what the form holds.
        $this->assertSame('12/15/2022', $result['rendered']);
        $this->assertSame('2022-12-15', $result['posts']);
        $this->assertSame('hidden', $result['hidden'], 'The original field still posts, out of sight.');
        $this->assertSame('mm/dd/yyyy', $result['placeholder']);

        // A value written by script — x-model, a reset — reaches the box.
        $this->assertSame('03/02/2024', $result['afterScriptWrite']);

        // A date typed in reaches the form year-first, and the change fires once.
        $this->assertSame('2023-06-15', $result['afterTyping']);
        $this->assertSame(1, $result['changes']);

        // A field that arrives after load is picked up.
        $this->assertTrue($result['lateUpgraded']);
        $this->assertSame('10/03/2026', $result['lateShown']);

        // Tester report, Nov 2026. A birth date's calendar is in the page,
        // under its box, covering no field; other fields keep 'auto'.
        $this->assertTrue($result['dobStatic']);
        $this->assertTrue($result['dobCalendarInFlow']);
        $this->assertSame('auto', $result['enrPosition']);

        // Escape, Tab, the form being sent and a click elsewhere close it.
        $this->assertTrue($result['openedOnce']);
        $this->assertTrue($result['closedByEscape']);
        $this->assertTrue($result['closedByTab']);
        $this->assertTrue($result['closedBySubmit']);
        $this->assertTrue($result['labelClickCloses']);

        // Nonsense is said under the field while typing — with a past
        // example for a birth date — and the calendar closes off it.
        $this->assertSame('Type the date as mm/dd/yyyy, for example 06/16/2021.', $result['whileTypingMessage']);
        $this->assertTrue($result['whileTypingShown']);
        $this->assertSame('true', $result['whileTypingInvalid']);
        $this->assertTrue($result['whileTypingClosed']);
        $this->assertFalse($result['halfTypedShown'], 'a date still being typed is not yet wrong');
        $this->assertNull($result['fixedInvalid'], 'typing it right clears the mark');

        // Left half typed, any other field says so on leaving the box, and
        // the form does not send.
        $this->assertFalse($result['enrHalfTypedShown']);
        $this->assertSame('Type the date as mm/dd/yyyy, for example 06/16/2026.', $result['blurMessage']);
        $this->assertSame('true', $result['blurInvalid']);
        $this->assertFalse($result['submittedWithBadDate']);
    }

    public function test_the_page_loads_the_date_field_upgrade(): void
    {
        $app = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString("import { watchDateInputs } from './dates';", $app);
        $this->assertStringContainsString("import 'flatpickr/dist/flatpickr.min.css';", $app);
        $this->assertStringContainsString('watchDateInputs();', $app);
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
