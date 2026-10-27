<?php

namespace Tests\Feature;

use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\DoorAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Comments read back by day: a child's earlier notes in the dialog, a past
 * day on the door screen, and the log of a day's notes across every child.
 */
class PastCommentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Child $child;

    private Child $other;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, so "last school day" is the Friday before.
        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', config('app.timezone')));

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Director Dee']);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Ms. Alvarez', 'classroom' => 'Infant']);
        $this->child = Child::create(['lan' => '10023', 'first_name' => 'Brinton', 'last_name' => 'Sanford', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-06-01']);
        $this->other = Child::create(['lan' => '10024', 'first_name' => 'Ada', 'last_name' => 'Quill', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-03-01']);
    }

    /** A day with a note on it: the row, and the note at a given hour. */
    private function note(Child $child, string $date, string $body, string $at = '08:14', ?User $by = null, string $tag = 'other', ?string $in = '08:11', ?string $out = '17:05', ?int $outCode = 0): AttendanceComment
    {
        $tz = config('app.timezone');
        $row = DoorAttendance::firstOrCreate(
            ['child_id' => $child->id, 'attendance_date' => $date, 'session' => 'FULL'],
            [
                'signed_in_at' => $in ? Carbon::parse("$date $in", $tz) : null,
                'signed_out_at' => $out ? Carbon::parse("$date $out", $tz) : null,
                'health_in_code' => 0,
                'health_out_code' => $outCode,
            ],
        );

        $comment = new AttendanceComment([
            'attendance_id' => $row->id,
            'child_id' => $child->id,
            'user_id' => ($by ?? $this->teacher)->id,
            'tag' => $tag,
            'body' => $body,
        ]);
        $comment->created_at = Carbon::parse("$date $at", $tz);
        $comment->updated_at = $comment->created_at;
        $comment->save();

        return $comment;
    }

    public function test_a_comment_carries_its_day_and_its_authors_role_from_the_moment_it_is_written(): void
    {
        $comment = $this->note($this->child, '2026-10-16', 'Slept badly, says Mom.');

        $this->assertSame('2026-10-16', $comment->fresh()->date->toDateString());
        $this->assertSame('teacher', $comment->fresh()->author_role);
        $this->assertSame('general', $comment->type());
    }

    public function test_a_childs_comments_come_back_by_day_newest_first_with_that_days_clock(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14', in: '08:11', out: '17:05', outCode: 0);
        $this->note($this->child, '2026-10-16', 'Friday, later', '15:40');
        $this->note($this->child, '2026-10-14', 'Wednesday note', '09:02', tag: 'health', in: '07:50', out: '14:05', outCode: 3);
        // A day with an arrival and no comment is not a card.
        DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => now()->subDays(4)]);
        // Another child's note is not this child's.
        $this->note($this->other, '2026-10-16', 'Not Brinton');

        // By row id, as the dialog asks — not by LAN, which is how the rest
        // of /children/... is addressed. The LAN here (10023) is not the id.
        $response = $this->actingAs($this->teacher)->getJson('/children/'.$this->child->id.'/comments')->assertOk();
        $this->assertSame(url('/children/'.$this->child->id.'/comments'), route('children.comments', $this->child));

        $response->assertJsonPath('has_more', false)
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('days.0.date', '2026-10-16')
            ->assertJsonPath('days.0.label', 'Fri, Oct 16')
            ->assertJsonPath('days.0.summary', 'In 8:11a → Out 5:05p · Leaving check 0 · Normal')
            ->assertJsonCount(2, 'days.0.comments')
            ->assertJsonPath('days.0.comments.0.body', 'Friday, later')
            ->assertJsonPath('days.0.comments.0.author', 'Ms. Alvarez')
            ->assertJsonPath('days.0.comments.0.author_role', 'Teacher')
            ->assertJsonPath('days.0.comments.0.time', '3:40 PM')
            ->assertJsonPath('days.1.date', '2026-10-14')
            ->assertJsonPath('days.1.comments.0.type', 'health')
            ->assertJsonPath('days.1.out_code', 3);

        $this->assertStringStartsWith('In 7:50a → Out 2:05p · Leaving check 3', $response->json('days.1.summary'));
    }

    public function test_the_days_are_paged_twenty_at_a_time_and_load_older_continues_under_the_last(): void
    {
        // Twenty-three weekdays of notes, back from the Friday.
        $day = Carbon::parse('2026-10-16');
        for ($i = 0; $i < 23; $i++) {
            while ($day->isWeekend()) {
                $day->subDay();
            }
            $this->note($this->child, $day->toDateString(), 'Note '.$i);
            $day->subDay();
        }

        $first = $this->actingAs($this->teacher)->getJson(route('children.comments', $this->child))->assertOk();
        $first->assertJsonCount(20, 'days')->assertJsonPath('has_more', true);
        $before = $first->json('next_before');
        $this->assertSame($first->json('days.19.date'), $before);

        $second = $this->actingAs($this->teacher)->getJson(route('children.comments', [$this->child, 'before' => $before]))->assertOk();
        $second->assertJsonCount(3, 'days')->assertJsonPath('has_more', false)->assertJsonPath('next_before', null);
        $this->assertLessThan($before, $second->json('days.0.date'));
    }

    public function test_the_show_box_narrows_the_days(): void
    {
        $this->note($this->child, '2026-10-16', 'Three days ago');
        $this->note($this->child, '2026-10-01', 'Eighteen days ago');
        $this->note($this->child, '2026-08-20', 'Last school year');

        $get = fn (array $query) => $this->actingAs($this->teacher)->getJson(route('children.comments', [$this->child, ...$query]))->assertOk()->json('days.*.date');

        $this->assertSame(['2026-10-16', '2026-10-01', '2026-08-20'], $get(['range' => 'all']));
        $this->assertSame(['2026-10-16', '2026-10-01'], $get(['range' => '30d']));
        $this->assertSame(['2026-10-16'], $get(['range' => '7d']));
        $this->assertSame(['2026-10-16', '2026-10-01'], $get(['range' => 'year']));
        $this->assertSame(['2026-10-01'], $get(['date' => '2026-10-01']));
        $this->assertSame([], $get(['date' => '2026-10-02']));

        $this->actingAs($this->teacher)->getJson(route('children.comments', [$this->child, 'range' => 'forever']))->assertUnprocessable();
    }

    public function test_only_staff_who_work_the_door_read_a_childs_comments(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note');

        $this->getJson(route('children.comments', $this->child))->assertUnauthorized();

        $outsider = User::factory()->create(['role' => 'parent']);
        $this->actingAs($outsider)->getJson(route('children.comments', $this->child))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('children.comments', $this->child))->assertOk()->assertJsonCount(1, 'days');
    }

    public function test_the_author_may_change_a_comment_on_its_day_and_not_after(): void
    {
        $comment = $this->note($this->child, '2026-10-16', 'Friday note');
        $row = $comment->attendance;

        // Monday now: Friday's note is read-only to its author.
        $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$row, $comment]), ['body' => 'Changed'])->assertForbidden();
        $this->assertSame('Friday note', $comment->fresh()->body);

        // On the day, it was theirs to change — and the change is stamped.
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', config('app.timezone')));
        $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$row, $comment]), ['body' => 'Changed'])->assertOk()->assertJsonPath('edited', true);
        $this->assertNotNull($comment->fresh()->edited_at);
    }

    public function test_the_door_screen_opens_a_past_day_for_anyone_with_that_days_comments_and_the_last_comment_day(): void
    {
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14');
        $this->note($this->other, '2026-10-16', 'Ada was quiet', '11:30', tag: 'health');
        $this->note($this->child, '2026-10-14', 'Wednesday note');

        $html = $this->actingAs($this->teacher)->get(route('check-in.index', ['date' => '2026-10-16']))->assertOk()->getContent();
        $data = str_replace(chr(92).'u0022', '"',$html);

        // The day, read-only, with the panel's comments newest first.
        $this->assertStringContainsString("day: '2026-10-16'", $html);
        $this->assertStringContainsString('Viewing Friday, Oct 16 — read only.', $html);
        $this->assertStringContainsString('data-latest-comments', $html);
        $this->assertStringNotContainsString('data-with-comments', $html); // parked 26 Oct 2026
        $this->assertStringContainsString('"body":"Ada was quiet","author":"Ms. Alvarez","author_role":"Teacher","time":"11:30 AM"', $data);
        $this->assertMatchesRegularExpression('/dayComments: JSON\.parse\(\'\[\{"id":\d+,"tag":"health".*?"child":"Ada Quill","room":"Toddler"\},\{"id":\d+,"tag":"other".*?"child":"Brinton Sanford"/s', $data);

        // The child's latest comment before that day, for "Last comment: … · View".
        $this->assertStringContainsString('"last_comment_on":"2026-10-14"', $data);
        $this->assertStringContainsString('data-last-comment', $html);
        $this->assertStringContainsString('data-tab-past', $html);
        $this->assertStringContainsString('data-past-comments', $html);

        // Today: the day before Friday's note is Friday itself.
        $today = str_replace(chr(92).'u0022', '"',$this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent());
        $this->assertStringContainsString('"last_comment_on":"2026-10-16"', $today);
        $this->assertStringNotContainsString('data-comments-log-link', $today); // parked 26 Oct 2026
    }

    public function test_the_log_opens_on_today_and_the_presets_pick_the_day(): void
    {
        $this->note($this->child, '2026-10-19', 'Monday note', '08:30');
        $this->note($this->child, '2026-10-16', 'Friday note', '08:14');
        $this->note($this->other, '2026-10-16', 'Ada was quiet', '11:30', tag: 'health', by: $this->admin);
        $this->note($this->other, '2026-10-15', 'Thursday note');

        // Today, as a feed: the day's heading says so, and the count sits
        // beside the filters.
        $this->actingAs($this->teacher)->get(route('comments.index'))->assertOk()
            ->assertSee('1 comment · 1 child')
            ->assertSee('Today · Mon, Oct 19')
            ->assertSee('Monday note')
            ->assertDontSee('Friday note');

        // Monday's "last school day" is Friday — the weekend is skipped.
        $friday = $this->actingAs($this->teacher)->get(route('comments.index', ['preset' => 'last-school-day']))->assertOk();
        $friday->assertSee('2 comments · 2 children')->assertSee('data-comments-day="2026-10-16"', false)->assertSee('Fri, Oct 16')->assertSee('Friday note')->assertSee('Ada was quiet')->assertDontSee('Monday note');
        // Newest first, and who wrote it. No type chip (20 Oct 2026): notes are plain.
        $this->assertLessThan(strpos($friday->getContent(), 'Friday note'), strpos($friday->getContent(), 'Ada was quiet'));
        $friday->assertDontSee('data-type-chip', false)->assertSee('Director Dee · Director')->assertSee('Ms. Alvarez · Infant teacher');

        // A holiday is skipped too: with Friday closed, the last school day is Thursday.
        ClosureDay::create(['closed_on' => '2026-10-16', 'reason' => 'Staff day', 'created_by' => $this->admin->id]);
        $this->actingAs($this->teacher)->get(route('comments.index', ['preset' => 'last-school-day']))->assertOk()
            ->assertSee('1 comment · 1 child')->assertSee('Thu, Oct 15')->assertSee('Thursday note');

        $this->actingAs($this->teacher)->get(route('comments.index', ['preset' => 'yesterday']))->assertOk()
            ->assertSee('0 comments · 0 children')->assertSee('No comments on Sunday, Oct 18');
        $this->actingAs($this->teacher)->get(route('comments.index', ['preset' => 'week']))->assertOk()
            ->assertSee('1 comment · 1 child')->assertSee('Oct 19 – Oct 25');
        $this->actingAs($this->teacher)->get(route('comments.index', ['date' => '2026-10-16']))->assertOk()
            ->assertSee('2 comments · 2 children')->assertSee('Fri, Oct 16');
        $this->actingAs($this->teacher)->get(route('comments.index', ['from' => '2026-10-15', 'to' => '2026-10-16']))->assertOk()
            ->assertSee('3 comments · 2 children')->assertSee('data-comments-day="2026-10-16"', false)->assertSee('data-comments-day="2026-10-15"', false);
    }

    public function test_the_log_narrows_by_class_type_author_and_a_word(): void
    {
        $this->note($this->child, '2026-10-16', 'Spare outfit in the blue bag.', '08:14', tag: 'belongings');
        $this->note($this->other, '2026-10-16', 'Fell off the slide, bumped knee.', '11:30', tag: 'incident', by: $this->admin);
        $this->note($this->other, '2026-10-16', 'Runny nose at pick-up.', '16:30', tag: 'health');

        $log = fn (array $query) => $this->actingAs($this->teacher)->get(route('comments.index', ['date' => '2026-10-16', ...$query]))->assertOk();

        $log(['class' => 'Infant'])->assertSee('1 comment ·')->assertSee('blue bag')->assertDontSee('slide');
        $log(['type' => 'incident'])->assertSee('1 comment ·')->assertSee('slide');
        $log(['type' => 'general'])->assertSee('1 comment ·')->assertSee('blue bag');
        $log(['type' => 'health'])->assertSee('1 comment ·')->assertSee('Runny nose');
        $log(['author' => $this->admin->id])->assertSee('1 comment ·')->assertSee('slide');
        $log(['q' => 'knee'])->assertSee('1 comment ·')->assertSee('slide');
        $log(['q' => 'Ada Quill'])->assertSee('2 comments ·')->assertDontSee('blue bag');
        $log(['q' => 'nobody'])->assertSee('0 comments ·')->assertSee('data-comments-empty', false);
    }

    public function test_the_log_exports_the_same_rows_as_a_csv_and_a_pdf(): void
    {
        $this->note($this->child, '2026-10-16', 'Spare outfit, "blue" bag.', '08:14', tag: 'belongings');
        $this->note($this->other, '2026-10-16', 'Runny nose at pick-up.', '16:30', tag: 'health');
        $this->note($this->other, '2026-10-15', 'Thursday note');

        $csv = $this->actingAs($this->admin)->get(route('comments.export', ['date' => '2026-10-16', 'format' => 'csv']))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));
        $this->assertStringContainsString('comments-2026-10-16.csv', $csv->headers->get('content-disposition'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Date,Time,Child,LAN,Class,Type,Comment,"Written by",Role,Edited', $body);
        $this->assertStringContainsString('2026-10-16,"4:30 PM","Ada Quill",10024,Toddler,Health,"Runny nose at pick-up.","Ms. Alvarez",Teacher,', $body);
        $this->assertStringContainsString('"Spare outfit, ""blue"" bag."', $body);
        $this->assertStringNotContainsString('Thursday note', $body);

        $pdf = $this->actingAs($this->admin)->get(route('comments.export', ['date' => '2026-10-16', 'format' => 'pdf']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('comments-2026-10-16.pdf', $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->admin)->get(route('comments.export', ['from' => '2026-10-15', 'to' => '2026-10-16']))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=comments-2026-10-15-to-2026-10-16.csv');
    }

    public function test_the_log_is_for_staff_who_work_the_door(): void
    {
        $this->get(route('comments.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'parent']))->get(route('comments.index'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('comments.index'))->assertOk();
    }
}
