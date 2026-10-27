<?php

namespace Tests\Feature;

use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Past comments tab, pressed.
 *
 * The PHP tests read the rendered page; this hands it to a headless DOM with
 * the project's own Alpine and presses the things a teacher presses on a
 * Monday morning: a card, "Past comments", "View", "Load older", the chip
 * that keeps only the cards with a comment, a comment in the panel beside
 * the cards, and "Open →" on the log. Skips where node or jsdom is missing.
 */
class PastCommentsDialogTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $admin;

    private Child $child;

    private Child $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', config('app.timezone')));
        $this->teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Ms. Alvarez', 'classroom' => 'Infant']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Director Dee']);
        $this->child = Child::create(['lan' => '10023', 'first_name' => 'Brinton', 'last_name' => 'Sanford', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-06-01']);
        $this->other = Child::create(['lan' => '10024', 'first_name' => 'Ada', 'last_name' => 'Quill', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-03-01']);
    }

    private function note(Child $child, string $date, string $body, string $at, string $tag = 'other'): AttendanceComment
    {
        $tz = config('app.timezone');
        $row = DoorAttendance::firstOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $date, 'session' => 'FULL'],
            ['signed_in_at' => Carbon::parse("$date 08:11", $tz), 'signed_out_at' => Carbon::parse("$date 17:05", $tz), 'health_in_code' => 0, 'health_out_code' => 0],
        );
        $comment = new AttendanceComment(['attendance_id' => $row->id, 'child_id' => $child->id, 'user_id' => $this->teacher->id, 'tag' => $tag, 'body' => $body]);
        $comment->created_at = Carbon::parse("$date $at", $tz);
        $comment->updated_at = $comment->created_at;
        $comment->save();

        return $comment;
    }

    public function test_the_past_comments_tab_fetches_the_days_and_load_older_continues_them(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14');

        $result = $this->click($this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent(), 'today');

        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['dialog']);
        $this->assertSame('Today', $result['todayTab']);
        $this->assertTrue($result['todayPanelVisible']);
        $this->assertFalse($result['pastPanelVisible']);
        $this->assertSame('Last comment: Fri, Oct 16 · View', $result['lastComment']);
        $this->assertSame('Clock in', $result['clockButton']);

        // The tab: one request, two day cards, newest first, with the clock.
        $this->assertCount(1, $result['afterTab']['fetched']);
        $this->assertStringContainsString('/children/'.$this->child->id.'/comments?limit=20&range=all', $result['afterTab']['fetched'][0]);
        $this->assertTrue($result['afterTab']['visible']);
        $this->assertFalse($result['todayPanelAfter']);
        $this->assertSame(['2026-10-16', '2026-10-14'], array_column($result['afterTab']['days'], 'date'));
        $this->assertSame('Fri, Oct 16 In 8:11a → Out 5:05p · Leaving check 0 · Normal', $result['afterTab']['days'][0]['head']);
        $this->assertSame('Friday note Ms. Alvarez · Teacher · 8:14 AM', $result['afterTab']['days'][0]['comments'][0]['text']);
        $this->assertSame('Wednesday note Ms. Alvarez · Teacher · 9:02 AM · edited', $result['afterTab']['days'][1]['comments'][0]['text']);
        $this->assertTrue($result['afterTab']['loadOlder']);
        // The clock stays at the bottom of the tab.
        $this->assertSame('Clock in', $result['clockButtonAfter']);

        // Load older: asked for under the last day, appended, and no more.
        $this->assertStringContainsString('before=2026-10-14', $result['afterOlder']['fetched'][1]);
        $this->assertSame(['2026-10-16', '2026-10-14', '2026-09-02'], array_column($result['afterOlder']['days'], 'date'));
        $this->assertFalse($result['afterOlder']['loadOlder']);

        // Back to today, then "View" opens the tab again, scrolled to the day.
        $this->assertTrue($result['backToToday']);
        $this->assertTrue($result['afterView']['visible']);
        $this->assertSame('2026-10-16', $result['afterView']['scrolled']);

        // The Show box asks again with the range.
        $this->assertStringContainsString('range=7d', end($result['afterRange']));
    }

    public function test_a_day_gone_is_read_only_and_a_card_opens_on_that_days_comments(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14');
        $this->note($this->other, '2026-10-16', 'Ada was quiet', '11:30', 'health');
        // A third child who came but has no comment.
        $quiet = Child::create(['lan' => '10025', 'first_name' => 'Noor', 'last_name' => 'Bell', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-05-01']);
        DoorAttendance::create(['child_id' => $quiet->id, 'attendance_date' => '2026-10-16', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-16 07:50', config('app.timezone')), 'signed_out_at' => Carbon::parse('2026-10-16 14:05', config('app.timezone'))]);

        // The director, who opens on every room; a teacher opens on their
        // own, and the panel then reads "Infant on Friday" with that room's.
        $result = $this->click($this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-10-16']))->assertOk()->getContent(), 'past');

        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('Viewing Friday, Oct 16 — read only.', $result['readOnlyBar']);
        $this->assertTrue($result['backToToday']);
        $this->assertSame('Friday at a glance', $result['glance']);
        $this->assertSame(['Attended', 'Absent'], $result['tiles']);

        // The panel: both notes, newest first, and the link to the log.
        $this->assertCount(2, $result['latest']);
        $this->assertStringStartsWith('Ada Quill 11:30 AM Ada was quiet', $result['latest'][0]);
        $this->assertSame('2 on Friday', $result['latestCount']);
        $this->assertStringEndsWith('/comments?date=2026-10-16', $result['seeAll']);

        // The cards: the day's times, a badge where there is a comment. The
        // "With comments only" chip is parked (26 Oct 2026), so the click
        // finds nothing and all three cards stay.
        $this->assertCount(3, $result['cardsBefore']);
        $this->assertContains('In 7:50a · Out 2:05p', $result['cardStates']);
        $this->assertSame(['1', '1'], $result['badges']);
        $this->assertCount(3, $result['cardsWithComments']);

        // A card opens on Past comments, scrolled to the day; no clock, no form.
        $this->assertTrue($result['dialog']);
        $this->assertSame('Fri, Oct 16', $result['todayTab']);
        $this->assertTrue($result['opened']['visible']);
        $this->assertSame('2026-10-16', $result['opened']['scrolled']);
        $this->assertNull($result['clockButton']);
        $this->assertFalse($result['correctingForm']);

        // From the panel: the child named on the note, lit on that comment.
        $this->assertTrue($result['closed']);
        $this->assertSame('Ada Quill', $result['fromPanelHeading']);
        $this->assertSame('2', $result['fromPanel']['scrolled']);
        $this->assertTrue($result['fromPanel']['days'][0]['comments'][0]['lit']);
    }

    public function test_the_log_opens_a_child_on_their_past_comments_and_closing_keeps_the_page(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14');
        $this->note($this->other, '2026-10-16', 'Ada was quiet', '11:30', 'health');

        $result = $this->click($this->actingAs($this->teacher)->get(route('comments.index', ['date' => '2026-10-16']))->assertOk()->getContent(), 'log');

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['rows']);
        $this->assertStringEndsWith('2 comments · 2 children', $result['summary']);
        $this->assertSame([], $result['chips'], 'no type chips on the feed');
        $this->assertTrue($result['dialog']);
        $this->assertSame('Ada Quill', $result['heading']);
        $this->assertSame('2', $result['opened']['scrolled']);
        $this->assertTrue($result['closed']);
        $this->assertSame(2, $result['rowsAfter']);
    }

    private function click(string $html, string $scenario): array
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $page = tempnam(sys_get_temp_dir(), 'past').'.html';
        file_put_contents($page, $html);

        $process = new Process(
            [$node, base_path('tests/js/click-past-comments.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), $scenario],
            base_path(),
            ['NODE_PATH' => base_path('node_modules')],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, $process->getOutput());
        $this->assertTrue($result['alpine'], 'Alpine did not start.');

        return $result;
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
