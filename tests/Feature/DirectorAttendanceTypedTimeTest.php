<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use App\Services\DssAttendance;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A time typed into a box on Director Attendance that is not a time
 * (QA of 29 Oct 2026, B5).
 *
 * The RULES:
 *  - "abc", "25:00", "7:61a" are refused with a message, and the hour stays.
 *    They used to close the box without a word, or save "7:61a" as 7:59a.
 *  - A real time still saves.
 *  - The server reads "9abc" as not a time, not as nine.
 *
 * The browser half skips, rather than fails, where node or jsdom is missing.
 */
class DirectorAttendanceTypedTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_typed_time_must_be_a_time_from_end_to_end(): void
    {
        $this->assertNull(DssAttendance::minutes('9abc'));
        $this->assertNull(DssAttendance::minutes('8:05xyz'));
        $this->assertNull(DssAttendance::minutes('7:61a'));
        $this->assertNull(DssAttendance::minutes('25:00'));
        $this->assertSame(17 * 60 + 30, DssAttendance::minutes('5:30 p.m.'));
        $this->assertSame(9 * 60, DssAttendance::minutes(' 9a '));
    }

    public function test_the_box_refuses_what_is_not_a_time_and_says_so(): void
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to type into the page.');
        }

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00'));
        $admin = User::create(['name' => 'Ray K', 'email' => 'ray@example.com', 'password' => 'password', 'role' => 'admin']);
        $ava = Child::create(['lan' => '10009', 'first_name' => 'Ava', 'last_name' => 'Cruz', 'status' => 'Active', 'classroom' => 'Toddler', 'classroom_override' => 'Toddler', 'dob' => '2024-04-05', 'schedule_days' => [1, 2, 3, 4, 5]]);
        app(WeekSchedule::class)->open('2026-10-12', $admin);
        Attendance::create(['child_id' => $ava->id, 'attendance_date' => '2026-10-13', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-10-13 07:45'), 'signed_out_at' => Carbon::parse('2026-10-13 17:30')]);

        $html = $this->actingAs($admin)->get(route('attendance.index', ['date' => '2026-10-13']))->assertOk()->getContent();
        $page = tempnam(sys_get_temp_dir(), 'register').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/type-register-time.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'),
                $ava->id.'|2026-10-13|FULL', json_encode([['abc', '5:30p'], ['7:45a', '25:00'], ['7:61a', '5:30p'], ['8:10a', '5:30p']])],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(90)->run();
        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The script failed:\n".$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'No JSON: '.$process->getOutput());
        $this->assertTrue($result['alpine']);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));

        [$abc, $late, $sixtyOne, $good] = $result['steps'];
        $this->assertSame(2, $abc['boxes'], 'A recorded day opens both hours.');

        // Refused, said, and nothing sent.
        $this->assertStringContainsString('“abc” is not a time', $abc['notice']);
        $this->assertStringContainsString('“25:00” is not a time', $late['notice']);
        $this->assertStringContainsString('“7:61a” is not a time', $sixtyOne['notice']);
        $this->assertSame(0, $sixtyOne['sent']);

        // A real time still goes.
        $this->assertSame('', $good['notice']);
        $this->assertCount(1, $result['posted']);
        $this->assertSame('08:10', $result['posted'][0]['body']['signed_in_time']);
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
