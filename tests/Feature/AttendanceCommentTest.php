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

    public function test_nothing_can_be_added_once_the_child_has_been_clocked_out(): void
    {
        $this->attendance->update(['signed_out_at' => now()]);

        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'other', 'body' => 'Too late'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'This child has been clocked out.');

        // Still readable, though.
        $this->actingAs($this->teacher)->getJson(route('attendances.comments.index', $this->attendance))->assertOk();
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

    public function test_the_list_is_newest_first(): void
    {
        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'parent_note', 'body' => 'First']);
        $this->travelTo(now()->addMinutes(3));
        $this->actingAs($this->teacher)->postJson(route('attendances.comments.store', $this->attendance), ['tag' => 'belongings', 'body' => 'Second']);

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
        // …the component is in the dialog, the composer hidden once the child is out…
        $this->assertStringContainsString('data-attendance-comments', $html);
        $this->assertStringContainsString("canAdd: ! slot.out", $html);
        // The signed-in header: the chip and the health panel, who took the check and when.
        $this->assertStringContainsString('"in_at_label":"7:58 AM"', $data);
        $this->assertStringContainsString('data-health-panel', $html);
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
}
