<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A child who has left is still on the weeks they were here.
 *
 * The sheet asked for status = Active and nothing else, so the day a child was
 * marked Inactive they vanished from every week in the system, the eleven
 * months they attended included. A register is a record as much as a
 * worksheet, and a record that cannot be looked back at is not one.
 */
class InactiveOnTheRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const THIS_WEEK = '2026-09-14';   // Mon 14 – Fri 18
    private const LAST_TERM = '2026-06-01';   // Mon 1 – Fri 5

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse(self::THIS_WEEK.' 09:00:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    /** The week they attended still lists them, months after they left. */
    public function test_a_leaver_appears_on_the_week_they_signed_in(): void
    {
        $gone = $this->child('Gone', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-05']);

        Attendance::create([
            'child_id' => $gone->id,
            'attendance_date' => '2026-06-03',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-06-03 08:10'),
        ]);

        app(WeekSchedule::class)->open(self::LAST_TERM);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::LAST_TERM]))
            ->assertOk()
            ->assertSee('Gone');
    }

    /** And is gone from this week, because they are. */
    public function test_a_leaver_is_not_on_a_week_after_they_left(): void
    {
        $this->child('Gone', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-05']);
        $this->child('Here');

        app(WeekSchedule::class)->open(self::THIS_WEEK);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::THIS_WEEK]))
            ->assertOk()
            ->assertSee('Here')
            ->assertDontSee('Gone');
    }

    /**
     * The weeks between the first day and the last, with nothing signed in.
     *
     * A child can be on the roll for a week they never turned up in — a fortnight
     * of illness before they left — and those weeks are part of the record too.
     */
    public function test_a_leaver_appears_on_a_week_inside_their_enrolment(): void
    {
        $this->child('Gone', [
            'status' => 'Inactive',
            'enrolled_on' => '2026-01-05',
            'withdrawn_on' => '2026-06-05',
        ]);

        app(WeekSchedule::class)->open(self::LAST_TERM);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::LAST_TERM]))
            ->assertOk()
            ->assertSee('Gone');
    }

    /**
     * Inactive with no leaving date is placed by what they did, and nothing else.
     *
     * "Not with us" and no date is not a statement about any particular week, so
     * the only weeks it can be shown on are the ones they signed in.
     */
    public function test_an_inactive_child_with_no_leaving_date_is_placed_by_their_attendance(): void
    {
        $vague = $this->child('Vague', ['status' => 'Inactive']);

        app(WeekSchedule::class)->open(self::THIS_WEEK);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::THIS_WEEK]))
            ->assertOk()
            ->assertDontSee('Vague');

        Attendance::create([
            'child_id' => $vague->id,
            'attendance_date' => self::THIS_WEEK,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(self::THIS_WEEK.' 08:10'),
        ]);

        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::THIS_WEEK]))
            ->assertOk()
            ->assertSee('Vague');
    }

    /**
     * The word under the name, and only where it is news.
     *
     * Sixty rows reading "Active" would hide the one that does not, so the
     * status is drawn only when it differs — which is how a reader looking at a
     * week gone by learns why a child stopped appearing.
     */
    public function test_the_status_is_shown_under_the_name_only_when_it_is_not_active(): void
    {
        // Somebody who stayed, so the week has a roll and a grid to draw.
        $this->child('Here');
        $gone = $this->child('Gone', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-05']);

        Attendance::create([
            'child_id' => $gone->id,
            'attendance_date' => '2026-06-03',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-06-03 08:10'),
        ]);

        app(WeekSchedule::class)->open(self::LAST_TERM);

        $html = $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::LAST_TERM]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-show="child.status !== \'Active\'"', $html);
        $this->assertStringContainsString('x-text="child.status"', $html);

        // And the status itself reaches the browser to be drawn from.
        $this->assertSame(1, preg_match("/childrenData: JSON\.parse\('(.*?)'\)/", $html, $matches));
        $rows = json_decode(json_decode('"'.$matches[1].'"'), associative: true);

        $this->assertSame('Inactive', collect($rows)->firstWhere('first_name', 'Gone')['status']);
    }

    /** The counts under the toolbar are counted off the rows, not off a second query. */
    public function test_the_roll_count_matches_the_rows_on_the_page(): void
    {
        $gone = $this->child('Gone', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-05']);
        $this->child('Here');

        Attendance::create([
            'child_id' => $gone->id,
            'attendance_date' => '2026-06-03',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-06-03 08:10'),
        ]);

        app(WeekSchedule::class)->open(self::LAST_TERM);

        // Both of them: one active, one who was here that week.
        $this->actingAs($this->admin)
            ->get(route('attendance.index', ['date' => self::LAST_TERM]))
            ->assertOk()
            ->assertSee('All <span class="ml-0.5 opacity-60 tabular-nums"', false)
            ->assertSee("+ '/' + 2", false);
    }

    /** The printed week carries them too, or the paper disagrees with the screen. */
    public function test_the_printed_week_lists_the_leaver(): void
    {
        $here = $this->child('Here');
        $gone = $this->child('Gone', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-05']);

        Attendance::create([
            'child_id' => $gone->id,
            'attendance_date' => '2026-06-03',
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse('2026-06-03 08:10'),
        ]);

        app(WeekSchedule::class)->open(self::LAST_TERM);
        ScheduleSlot::where('child_id', $here->id)->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)
            ->get(route('attendance.print', ['date' => self::LAST_TERM]))
            ->assertOk()
            ->assertSee('Test, Gone');
    }

    private function child(string $first, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (10000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => 'Test',
            'classroom' => 'Toddler',
            'birth_date' => '2023-10-01',
        ]);
    }
}
