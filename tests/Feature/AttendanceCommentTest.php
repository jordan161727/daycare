<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\DoorAttendance;
use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The notes on a child's day — the back of the paper sign-in sheet, typed.
 */
class AttendanceCommentTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Child $child;

    private DoorAttendance $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 08:02:00', config('app.timezone')));
        $this->teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Ms. Alvarez', 'classroom' => 'Infant']);
        $this->child = Child::create(['lan' => '10023', 'first_name' => 'Brinton', 'last_name' => 'Sanford', 'status' => 'Active', 'classroom' => 'Infant', 'dob' => '2025-06-01']);
        $this->attendance = DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => now()->subMinutes(4), 'health_in_code' => 0]);
    }

    public function test_staff_can_add_a_comment_and_it_comes_back_shaped_for_the_screen(): void
    {
        $response = $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), [
            'tag' => 'parent_note',
            'body' => '  Mom says he slept poorly last night.  ',
        ]);

        $response->assertCreated()->assertJson([
            'tag' => 'parent_note',
            'tag_label' => 'Parent note',
            'body' => 'Mom says he slept poorly last night.',
            'author' => 'Ms. Alvarez',
            'time' => '8:02 AM',
        ]);
        $this->assertDatabaseHas('attendance_comments', [
            'attendance_id' => $this->attendance->id,
            'child_id' => $this->child->id,
            'user_id' => $this->teacher->id,
            'tag' => 'parent_note',
            'body' => 'Mom says he slept poorly last night.',
        ]);
    }

    public function test_an_empty_body_or_an_unknown_tag_fails_validation(): void
    {
        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'health', 'body' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['body']);

        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'gossip', 'body' => 'Hello'])
            ->assertUnprocessable()->assertJsonValidationErrors(['tag']);

        $this->assertSame(0, AttendanceComment::count());
    }

    /** A note can be added at any point in the day — after the clock-out too. */
    public function test_a_comment_can_still_be_added_once_the_child_has_been_clocked_out(): void
    {
        $this->attendance->update(['signed_out_at' => now()]);

        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'other', 'body' => 'Mum rang: sick in the car on the way home.'])
            ->assertCreated();

        $this->assertSame(1, AttendanceComment::where('attendance_id', $this->attendance->id)->count());
    }

    public function test_the_author_and_the_child_come_from_the_server_not_the_request(): void
    {
        $other = User::factory()->create(['role' => 'teacher']);
        $otherChild = Child::create(['lan' => '10099', 'first_name' => 'Other', 'last_name' => 'Child', 'status' => 'Active', 'classroom' => 'Toddler', 'dob' => '2024-01-01']);

        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), [
            'tag' => 'belongings', 'body' => 'Brought 6 diapers.', 'user_id' => $other->id, 'child_id' => $otherChild->id,
        ])->assertCreated();

        $comment = AttendanceComment::firstOrFail();
        $this->assertSame($this->teacher->id, $comment->user_id);
        $this->assertSame($this->child->id, $comment->child_id);
    }

    /** Rows typed before there was one note a day still list newest first. */
    public function test_the_list_is_newest_first(): void
    {
        $this->comment($this->teacher, 'parent_note', 'First');
        $this->travelTo(now()->addMinutes(3));
        $this->comment($this->teacher, 'belongings', 'Second');

        $this->actingAs($this->teacher)->getJson(route('attendances.comments.index', $this->attendance))
            ->assertOk()
            ->assertJsonPath('0.body', 'Second')
            ->assertJsonPath('0.time', '8:05 AM')
            ->assertJsonPath('1.body', 'First');
    }

    public function test_the_door_screen_carries_the_comments_and_the_composer_only_while_the_child_is_in(): void
    {
        AttendanceComment::create(['attendance_id' => $this->attendance->id, 'child_id' => $this->child->id, 'user_id' => $this->teacher->id, 'tag' => 'belongings', 'body' => 'Spare outfit in the blue bag.']);

        $html = $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent();
        $data = str_replace('\u0022', '"', $html);

        // The row carries its notes, so the dialog opens on them without a second request…
        $this->assertStringContainsString('"comments_count":1', $data);
        $this->assertStringContainsString('"tag_label":"Belongings"', $data);
        $this->assertStringContainsString('Spare outfit in the blue bag.', $data);
        // …the component is in the dialog, its composer offered all day, and the
        // clock form carries no box of its own (17 Oct 2026): the card is the one place…
        $this->assertStringContainsString('data-attendance-comments', $html);
        $this->assertStringNotContainsString("canAdd: ! slot.out", $html);
        $this->assertStringNotContainsString('data-clock-comment', $html);
        $this->assertStringNotContainsString('data-add-clock-comment', $html);
        // The signed-in header: the chip, with the arrival check on the entries card, not a panel of its own.
        $this->assertStringContainsString('"in_at_label":"7:58 AM"', $data);
        $this->assertStringNotContainsString('data-health-panel', $html);
        $this->assertStringContainsString("'In since ' + (dayOf(child)?.in_at_label || lastEntry(child)?.in)", $html);
        $this->assertStringContainsString('placeholder="What should the next teacher or the parent know?"', $html);
        // …and the roster card wears the count.
        $this->assertStringContainsString('data-comment-badge', $html);
    }

    public function test_somebody_signed_out_gets_nothing(): void
    {
        $this->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'other', 'body' => 'Hi'])->assertUnauthorized();
        $this->getJson(route('attendances.comments.index', $this->attendance))->assertUnauthorized();
    }

    /** A note the author got wrong is fixed in place, and says it was. */
    public function test_the_author_can_change_their_own_comment_on_the_day(): void
    {
        $comment = $this->comment($this->teacher, 'parent_note', 'Mom says he slept porly.');

        $this->travel(2)->hours();
        $response = $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), [
            'tag' => 'health',
            'body' => '  Mom says he slept poorly.  ',
        ]);

        $response->assertOk()->assertJson([
            'id' => $comment->id,
            'tag' => 'health',
            'tag_label' => 'Health',
            'body' => 'Mom says he slept poorly.',
            'author' => 'Ms. Alvarez',
            // The time stays the time it was written.
            'time' => '8:02 AM',
            'edited' => true,
            'can_edit' => true,
        ]);
        $this->assertSame('Mom says he slept poorly.', $comment->fresh()->body);
    }

    public function test_a_comment_saved_unchanged_is_not_marked_edited(): void
    {
        $comment = $this->comment($this->teacher, 'parent_note', 'As written.');

        $this->travel(1)->hour();
        $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), ['tag' => 'parent_note', 'body' => 'As written.'])
            ->assertOk()->assertJsonPath('edited', false);
    }

    /** Somebody else's note is not yours to change — unless you are the director. */
    public function test_only_the_author_or_the_director_may_change_a_comment(): void
    {
        $comment = $this->comment($this->teacher, 'other', 'Brought six diapers.');
        $colleague = User::factory()->create(['role' => 'teacher', 'classroom' => 'Infant']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($colleague)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), ['tag' => 'other', 'body' => 'Five.'])->assertForbidden();
        $this->assertSame('Brought six diapers.', $comment->fresh()->body);

        // The list tells each reader which notes are theirs to press.
        $this->actingAs($colleague)->getJson(route('attendances.comments.index', $this->attendance))->assertJsonPath('0.can_edit', false);
        $this->actingAs($this->teacher)->getJson(route('attendances.comments.index', $this->attendance))->assertJsonPath('0.can_edit', true);

        $this->travel(5)->minutes();
        $this->actingAs($admin)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), ['tag' => 'belongings', 'body' => 'Brought five diapers.'])
            ->assertOk()->assertJsonPath('body', 'Brought five diapers.')->assertJsonPath('edited', true);
    }

    /** Yesterday's note is part of yesterday's record: the author's hands are off it, the director's are not. */
    public function test_the_author_cannot_change_a_comment_after_the_day(): void
    {
        $comment = $this->comment($this->teacher, 'other', 'Left his hat.');

        $this->travelTo(Carbon::parse('2026-10-16 07:30:00', config('app.timezone')));
        $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), ['tag' => 'other', 'body' => 'Found his hat.'])->assertForbidden();
        $this->actingAs($this->teacher)->getJson(route('attendances.comments.index', $this->attendance))->assertJsonPath('0.can_edit', false);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson(route('attendances.comments.update', [$this->attendance, $comment]), ['tag' => 'other', 'body' => 'Found his hat.'])->assertOk();
    }

    /** A comment is changed on the row it belongs to, not through some other row's address. */
    public function test_a_comment_cannot_be_reached_through_another_row(): void
    {
        $comment = $this->comment($this->teacher, 'other', 'On this row.');
        $other = DoorAttendance::create(['child_id' => $this->child->id, 'attendance_date' => '2026-10-14', 'session' => 'FULL', 'signed_in_at' => now()->subDay(), 'health_in_code' => 0]);

        $this->actingAs($this->teacher)->patchJson(route('attendances.comments.update', [$other, $comment]), ['tag' => 'other', 'body' => 'Moved.'])->assertNotFound();
    }

    public function test_the_dialog_carries_the_edit_affordance(): void
    {
        $this->comment($this->teacher, 'other', 'Pressable.');

        $html = $this->actingAs($this->teacher)->get(route('check-in.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-edit-comment', $html);
        $this->assertStringNotContainsString('data-comment-edited', $html);
        $this->assertStringNotContainsString('Edit comment', $html);
        // One note, no kinds: the "Add a comment…" line, and no tag chips anywhere.
        $this->assertStringContainsString('Add a comment', $html);
        // Before the first clock-in the same box is offered, and its words ride
        // with the clock-in request (17 Oct 2026).
        $this->assertStringContainsString('data-first-comment', $html);
        $this->assertStringContainsString('data-add-first-comment', $html);
        $this->assertStringContainsString("comment_body: this.firstComment.trim() || null", $html);
        $this->assertStringNotContainsString('data-comment-tag', $html);
        $this->assertStringNotContainsString('data-clock-comment-tag', $html);
    }

    /** One note a day: adding to a row that has one changes it rather than adding a second. */
    public function test_adding_to_a_row_that_already_has_a_note_changes_it(): void
    {
        $first = $this->comment($this->teacher, 'other', 'Not feeling well.');

        $this->travel(10)->minutes();
        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['body' => 'Not feeling well, picked up early by mom.'])
            ->assertOk()
            ->assertJsonPath('id', $first->id)
            ->assertJsonPath('body', 'Not feeling well, picked up early by mom.')
            ->assertJsonPath('edited', true);

        $this->assertSame(1, AttendanceComment::count());
    }

    /** No tag needed any more; one sent is still kept, for the rows typed before. */
    public function test_a_comment_needs_no_tag(): void
    {
        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['body' => 'Just words.'])
            ->assertCreated()->assertJsonPath('tag', 'other');
    }

    private function comment(User $author, string $tag, string $body): AttendanceComment
    {
        return AttendanceComment::create([
            'attendance_id' => $this->attendance->id,
            'child_id' => $this->child->id,
            'user_id' => $author->id,
            'tag' => $tag,
            'body' => $body,
        ]);
    }
}
