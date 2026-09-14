<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every rule a sign-in has to pass, and what it says when it refuses.
 *
 * The sheet already prevents each of these — no cell is drawn for a day outside
 * a child's enrolment, and the halves of the day come from the child's own room
 * — so none of them is reached by tapping. They are reached by a stale tab: a
 * page opened on Friday, a leaving date entered on Monday, and the Friday tab
 * still offering cells that no longer exist.
 *
 * Each refusal names what was wrong rather than failing generically, because
 * the person who trips one is looking at a page that disagrees with the server
 * and needs telling which of them is out of date.
 */
class AttendanceSignInRulesTest extends TestCase
{
    use RefreshDatabase;

    private const WEDNESDAY = '2026-09-16';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEDNESDAY.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_a_day_ahead_of_today_is_refused(): void
    {
        $child = $this->makeChild();

        $message = $this->signIn($child, ['attendance_date' => '2026-09-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date')
            ->json('errors.attendance_date.0');

        // An arrival that has not happened is a guess, not a correction.
        $this->assertStringContainsString('Thursday, Sep 17 has not happened yet', $message);
        $this->assertSame(0, Attendance::count());
    }

    public function test_a_day_before_the_child_started_is_refused(): void
    {
        $child = $this->makeChild(['enrolled_on' => '2026-09-15']);

        $message = $this->signIn($child, ['attendance_date' => '2026-09-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date')
            ->json('errors.attendance_date.0');

        // Named, because the tab that offered the cell still shows it.
        $this->assertStringContainsString('Ada Lovelace was not on the roll on Monday, Sep 14', $message);
        $this->assertSame(0, Attendance::count());
    }

    public function test_a_day_after_the_child_left_is_refused(): void
    {
        $child = $this->makeChild(['withdrawn_on' => '2026-09-14']);

        $this->signIn($child, ['attendance_date' => '2026-09-15'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_date');

        // The last day they attend is theirs; the one after it is not.
        $this->signIn($child, ['attendance_date' => '2026-09-14'])->assertOk();

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_whole_day_room_cannot_be_signed_in_by_half(): void
    {
        $child = $this->makeChild(['classroom' => 'Toddler', 'birth_date' => '2024-02-10']);

        $message = $this->signIn($child, ['session' => 'AM'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session')
            ->json('errors.session.0');

        // An AM row against a child who has no half days is a billing figure
        // that cannot be reconciled against anything.
        $this->assertStringContainsString('Toddler is signed in once for the whole day', $message);
        $this->assertSame(0, Attendance::count());
    }

    public function test_a_half_day_room_cannot_be_signed_in_for_the_whole_day(): void
    {
        $child = $this->makeChild(['classroom' => 'School Age', 'birth_date' => '2019-03-02']);

        $message = $this->signIn($child, ['session' => 'FULL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('session')
            ->json('errors.session.0');

        $this->assertStringContainsString('School Age is signed in by half day', $message);

        // And the halves it does use are taken.
        $this->signIn($child, ['session' => 'AM'])->assertOk();
        $this->signIn($child, ['session' => 'PM'])->assertOk();

        $this->assertSame(2, Attendance::count());
    }

    /**
     * A session left out is the whole day, which is what every room but School
     * Age uses — so the commonest post carries no session at all.
     */
    public function test_leaving_the_session_out_means_the_whole_day(): void
    {
        $child = $this->makeChild();

        $this->actingAs($this->admin)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => self::WEDNESDAY,
            ])
            ->assertOk();

        $this->assertSame('FULL', Attendance::sole()->session);
    }

    public function test_a_child_this_person_may_not_see_is_not_found(): void
    {
        $child = $this->makeChild(['classroom' => 'Infant', 'birth_date' => '2026-02-10']);
        $teacher = User::factory()->create(['role' => 'teacher', 'classroom' => 'Toddler']);

        // Not "refused" — not found. Whose children a teacher may sign in is
        // not a rule about the request, it is a rule about the roster.
        $this->actingAs($teacher)
            ->postJson(route('attendance.signin'), [
                'child_id' => $child->id,
                'attendance_date' => self::WEDNESDAY,
                'session' => 'FULL',
            ])
            ->assertNotFound();

        $this->assertSame(0, Attendance::count());
    }

    /**
     * A closed day still takes one, deliberately. Shutting the centre clears
     * the ticks; it does not make a child who turned up anyway impossible to
     * record, and DSS bills against the day either way.
     */
    public function test_a_closed_day_is_not_one_of_the_rules(): void
    {
        $child = $this->makeChild();
        \App\Models\ClosureDay::create(['closed_on' => self::WEDNESDAY, 'reason' => 'Snow']);

        $this->signIn($child)->assertOk();

        $this->assertSame(1, Attendance::count());
    }

    public function test_the_sheet_shows_whichever_rule_refused(): void
    {
        $this->makeChild();

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::WEDNESDAY]))
            ->assertOk()
            ->getContent();

        // Reading only errors.attendance_date meant every session and roster
        // rule fell back to "unable to sign in" — which is exactly the wording
        // that helps least, on exactly the errors whose wording helps most.
        $this->assertStringContainsString('Object.values(problem.errors ?? {}).flat()[0]', $html);
        // Sign in, take off, and move the hour: three requests, one way of
        // reading whichever rule refused.
        $this->assertSame(3, substr_count($html, 'Object.values(problem.errors ?? {}).flat()[0]'));
    }

    private function signIn(Child $child, array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson(route('attendance.signin'), $overrides + [
            'child_id' => $child->id,
            'attendance_date' => self::WEDNESDAY,
            'session' => 'FULL',
        ]);
    }

    private function makeChild(array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
            'birth_date' => '2024-02-10',
        ]);
    }
}
