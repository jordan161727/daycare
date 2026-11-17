<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\DoorAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Check in page's QA fixes of 12 Nov 2026.
 *
 *  #7  A day gone with an arrival and no clock-out reads "Missing sign-out",
 *      not "Checked in", and the director can put the time in from there.
 *  #13 "1 child", not "1 children".
 *  #16 The room chosen survives a change of day.
 *  #36 Words typed in the dialog's comment box go in with the clock-out.
 *
 * The clicks are in tests/js/check-in-qa.cjs, run in jsdom where node and
 * jsdom are installed, and skipped where they are not.
 */
class CheckInQaNovemberTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday afternoon, on the centre's clock.
        $this->travelTo(Carbon::parse('2026-11-12 15:00:00', config('app.timezone')));

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler', 'classrooms' => ['Toddler']]);

        // A Toddler by age, booked every weekday.
        $this->child = Child::create([
            'lan' => '4101', 'first_name' => 'Maeve', 'last_name' => 'Adkins', 'status' => 'Active',
            'classroom' => 'Toddler', 'dob' => '2024-09-01', 'schedule_days' => [1, 2, 3, 4, 5],
            'start_date' => '2026-01-05',
        ]);
    }

    /* ---------------------------------------------------------------- #36 */

    public function test_a_comment_sent_with_the_clock_out_is_saved_on_the_row(): void
    {
        $row = DoorAttendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-11-12', 'session' => 'FULL',
            'signed_in_at' => now()->setTime(8, 0), 'health_in_code' => 0,
        ]);

        $data = $this->actingAs($this->teacher)->postJson(route('check-in.out', $row), [
            'health_code' => 0,
            'comment_body' => 'Going home with grandma',
        ])->assertOk()->json();

        $this->assertNotNull($row->fresh()->signed_out_at);
        $this->assertSame(['Going home with grandma'], $row->comments()->pluck('body')->all());
        $this->assertSame(1, $data['comments_count']);
        $this->assertSame('Going home with grandma', $data['comments'][0]['body']);

        // One note a day: a second clock (back in, then out again) with
        // words changes the note rather than adding another.
        $this->actingAs($this->teacher)->postJson(route('check-in.back', $row), ['health_code' => 0])->assertOk();
        $this->actingAs($this->teacher)->postJson(route('check-in.out', $row), [
            'health_code' => 0, 'comment_body' => 'Grandma came at three',
        ])->assertOk();

        $this->assertSame(['Grandma came at three'], $row->comments()->pluck('body')->all());
    }

    public function test_the_clock_out_request_carries_the_comment_box(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString("? { health_code: code, health_note: this.note.trim() || null, comment_body: comment || null }", $html);
        $this->assertStringContainsString('pendingComment()', $html);
    }

    public function test_clock_out_posts_the_unsaved_comment_in_a_browser(): void
    {
        DoorAttendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-11-12', 'session' => 'FULL',
            'signed_in_at' => now()->setTime(8, 0), 'health_in_code' => 0,
        ]);

        $result = $this->click($this->actingAs($this->admin)->get(route('check-in.index'))->getContent(), 'comment');

        $this->assertTrue($result['composer'], 'The comment box did not open.');
        $this->assertSame('Clock out', $result['button']);
        $this->assertCount(1, $result['posted']);
        $this->assertStringEndsWith('/out', $result['posted'][0]['url']);
        $this->assertSame('Going home with grandma', $result['posted'][0]['body']['comment_body']);
        // The panel's list follows without a reload.
        $this->assertStringContainsString('Going home with grandma', implode(' ', $result['latest']));
        $this->assertSame([], $result['errors']);
    }

    /* ----------------------------------------------------------------- #7 */

    public function test_a_past_day_without_a_clock_out_is_flagged_not_checked_in(): void
    {
        DoorAttendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-11-11', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-11-11 08:00', config('app.timezone')), 'health_in_code' => 0,
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-11-11']))->assertOk()->getContent();

        // "In" is today's word only.
        $this->assertStringContainsString('isIn(row) { const day = this.dayOf(row); return !! (this.day === this.today && day && day.in && ! day.out); },', $html);
        $this->assertStringContainsString('data-missing-sign-out', $html);
        $this->assertStringContainsString('data-fix-sign-out', $html);

        // A teacher is told who can fix it, and offered no button.
        $teacher = $this->actingAs($this->teacher)->get(route('check-in.index', ['date' => '2026-11-11']))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-fix-sign-out', $teacher);
        $this->assertStringContainsString('A director can add the time.', $teacher);
    }

    public function test_the_director_fixes_a_missing_sign_out_from_the_card(): void
    {
        DoorAttendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-11-11', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-11-11 08:00', config('app.timezone')), 'health_in_code' => 0,
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-11-11']))->getContent();
        $result = $this->click($html, 'missing');

        $this->assertFalse($result['checkedInBadge'], 'A day gone still says "Checked in".');
        $this->assertTrue($result['missingBadge'], 'No "Missing sign-out" badge on the card.');
        $this->assertSame('In 8:00a · Missing sign-out', $result['cardState']);
        $this->assertStringContainsString('missing sign-out', $result['cardLabel']);
        $this->assertStringContainsString('Missing sign-out.', $result['note']);
        $this->assertStringContainsString('no sign-out recorded', $result['status']);

        // The fix: Edit's own form, on the Out time.
        $this->assertTrue($result['fixButton']);
        $this->assertTrue($result['outField'], 'The correcting form did not open.');
        // (Focus on the Out field is checked in Chrome: jsdom's Alpine has no $nextTick.)
        $this->assertSame('08:00', $result['inValue']);
        $this->assertFalse($result['noteAfterFix']);
        $this->assertSame('/check-in/day/retime', parse_url($result['posted'][0]['url'], PHP_URL_PATH));
        $this->assertSame(['child_id' => $this->child->id, 'attendance_date' => '2026-11-11', 'session' => 'FULL', 'signed_out_time' => '16:30'], $result['posted'][0]['body']);
        $this->assertFalse($result['missingBadgeAfter'], 'The flag stayed after the time was saved.');
        $this->assertSame('In 8:00a · Out 4:30p', $result['cardStateAfter']);
        $this->assertSame([], $result['errors']);

        // And the endpoint it posts to puts the time on the row.
        $this->actingAs($this->admin)->postJson(route('check-in.day.retime'), $result['posted'][0]['body'])->assertOk();
        $this->assertSame('16:30', DoorAttendance::first()->signed_out_at->timezone(config('app.timezone'))->format('H:i'));
    }

    /* ---------------------------------------------------------------- #13 */

    public function test_one_child_is_one_child(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->assertOk()->getContent();

        $this->assertStringContainsString("childCount(n) { return n + (n === 1 ? ' child' : ' children'); },", $html);
        $this->assertStringNotContainsString("' children'\"", $html);
        $this->assertStringNotContainsString('</span> children <span', $html);
    }

    /* ---------------------------------------------------------------- #16 */

    public function test_the_room_is_kept_when_the_day_changes(): void
    {
        Child::create([
            'lan' => '4102', 'first_name' => 'Ivy', 'last_name' => 'Baker', 'status' => 'Active',
            'classroom' => 'Infant', 'dob' => '2026-06-01', 'schedule_days' => [1, 2, 3, 4, 5],
            'start_date' => '2026-07-01',
        ]);

        $html = $this->actingAs($this->admin)->get(route('check-in.index', ['date' => '2026-11-11']))->getContent();
        $result = $this->click($html, 'room', 'http://localhost/check-in?date=2026-11-11');

        $this->assertSame('/check-in?infant&date=2026-11-11', $result['address'], 'Picking a room dropped the day from the address.');
        $this->assertStringEndsWith('/check-in?date=2026-11-10&room=Infant', $result['prev']);
        // The next school day is today, which needs no date.
        $this->assertStringEndsWith('/check-in?room=Infant', $result['next']);
        $this->assertStringEndsWith('/check-in?room=Infant', $result['today']);
        $this->assertStringStartsWith('Infant 1 child', $result['heading']);
        $this->assertStringEndsWith('/check-in?date=2026-11-10', $result['prevAll']);
        $this->assertStringStartsWith('All children 2 children', $result['headingAll']);

        // The server opens the next day on that room.
        $this->assertMatchesRegularExpression("/room: (&#039;|')Infant/", $this->actingAs($this->admin)->get('/check-in?date=2026-11-10&room=Infant')->getContent());
    }

    public function test_a_teacher_who_chose_all_rooms_keeps_all_rooms(): void
    {
        $room = fn (string $query) => preg_match("/room: '([^']*)'/", str_replace('"', "'", $this->actingAs($this->teacher)->get('/check-in'.$query)->assertOk()->getContent()), $m) ? $m[1] : null;

        $this->assertSame('Toddler', $room(''));
        $this->assertSame('', $room('?room=all'));
        $this->assertSame('', $room('?date=2026-11-11&room=all'));
    }

    /* ----------------------------------------------------- page weight */

    public function test_the_sheet_is_not_built_on_the_cards_view(): void
    {
        $html = $this->actingAs($this->admin)->get(route('check-in.index'))->getContent();
        $result = $this->click($html, 'weight');

        $this->assertSame(1, $result['cards']);
        $this->assertSame(0, $result['sheetCells'], 'The hidden sheet is being built on the cards view.');

        // On ?view=sheet it is, one cell a line a day, plus the line label.
        $sheet = $this->click($this->actingAs($this->admin)->get(route('check-in.index', ['view' => 'sheet']))->getContent(), 'weight');
        $this->assertSame(4 * (7 + 1), $sheet['sheetCells']);
    }

    public function test_the_cards_view_sends_only_the_day(): void
    {
        DoorAttendance::create([
            'child_id' => $this->child->id, 'attendance_date' => '2026-11-10', 'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-11-10 08:00', config('app.timezone')),
        ]);

        $rows = $this->actingAs($this->admin)->get(route('check-in.index'))->viewData('rows');
        $this->assertSame([], $rows->first()['byDay']);
        $this->assertSame([], $rows->first()['bySession']);
        $this->assertSame(['2026-11-12'], array_keys($rows->first()['booked']));

        // The sheet still has the week.
        $rows = $this->actingAs($this->admin)->get(route('check-in.index', ['view' => 'sheet']))->viewData('rows');
        $this->assertSame(['2026-11-10'], array_keys($rows->first()['byDay']));
        $this->assertCount(7, $rows->first()['booked']);
    }

    /* ---------------------------------------------------------------- helpers */

    private function click(string $html, string $scenario, ?string $url = null): array
    {
        $node = $this->node();

        if (! $node || ! is_file(base_path('node_modules/jsdom/package.json'))) {
            $this->markTestSkipped('node and jsdom are needed to click the page.');
        }

        $page = tempnam(sys_get_temp_dir(), 'checkin').'.html';
        file_put_contents($page, $html);

        if ($keep = getenv('CHECKIN_QA_KEEP')) {
            file_put_contents($keep.'/'.$scenario.'.html', $html);
        }

        $process = new Process(
            [$node, base_path('tests/js/check-in-qa.cjs'), $page, base_path('node_modules/alpinejs/dist/cdn.js'), $scenario],
            base_path(),
            ['NODE_PATH' => base_path('node_modules'), 'PAGE_URL' => $url ?? 'http://localhost/check-in'],
        );
        $process->setTimeout(60)->run();

        @unlink($page);

        $this->assertSame(0, $process->getExitCode(), "The click script failed:\n".$process->getErrorOutput());

        $result = json_decode($process->getOutput(), true);
        $this->assertIsArray($result, 'The click script returned no JSON: '.$process->getOutput());
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
