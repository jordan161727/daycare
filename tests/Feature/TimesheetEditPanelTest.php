<?php

namespace Tests\Feature;

use App\Http\Controllers\StaffTimesheetController;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\TimesheetEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The edit panel on the Timesheets grid.
 *
 * Click an amber or red dot, fix the day, say why once, save once. The panel
 * is a different door onto the same audit trail as the day screen: every
 * change is still a void and a replacement, each stamped with who and why.
 * What these pin is the door — that one save applies a batch together, that a
 * batch which would leave the day unable to add up is refused whole, and that
 * the grid can be repainted from the answer without a reload.
 */
class TimesheetEditPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-08-16';

    /** A Thursday; the 18th is a Tuesday already gone. */
    private const TODAY = '2026-08-20';

    private const DAY = '2026-08-18';

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('daycare.timesheet.clock.enabled', true);

        $this->travelTo(Carbon::parse(self::TODAY.' 09:00:00'));

        $this->admin = User::create([
            'name' => 'Director', 'email' => 'director@example.com',
            'password' => 'password', 'role' => 'admin',
        ]);

        $this->teacher = User::create([
            'name' => 'Amaan Bin Alam', 'email' => 'amaan@example.com',
            'password' => 'password', 'role' => 'teacher',
            'employment' => 'FT', 'classroom' => 'Toddler', 'pay_rate' => 20,
        ]);
    }

    // ---------------- opening the day ----------------

    public function test_the_panel_hands_back_the_day_as_data(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('12:00', TimePunch::LUNCH_START);
        $this->punch('12:30', TimePunch::LUNCH_END);

        StaffShift::create([
            'week_start' => '2026-08-17', 'user_id' => $this->teacher->id, 'shift_date' => self::DAY,
            'day' => 'Tue', 'starts_at' => 7 * 60, 'ends_at' => 16 * 60 + 30, 'classroom' => 'Toddler', 'role' => StaffShift::ROLE_STAFF,
        ]);

        $json = $this->actingAs($this->admin)
            ->getJson(route('timesheets.panel', ['user' => $this->teacher, 'date' => self::DAY]))
            ->assertOk()
            ->json();

        $this->assertSame('Amaan Bin Alam', $json['staff']['name']);
        $this->assertSame(self::DAY, $json['date']);
        $this->assertFalse($json['locked']);

        // The live punches, in order, with the time the way a field wants it.
        $this->assertSame(['IN', 'LUNCH_START', 'LUNCH_END'], array_column($json['punches'], 'type'));
        $this->assertSame('07:00', $json['punches'][0]['at']);

        // Still on the clock on a day already gone: the missing clock-out.
        $this->assertTrue($json['totals']['open']);
        $this->assertSame('working', $json['state']);

        // The one-click fix the panel offers for it.
        $this->assertSame('16:30', $json['shift']['ends_at']);
        $this->assertSame(9 * 60 + 30, $json['totals']['scheduled']);

        // The fixed reasons, and the cell the grid will repaint from.
        $this->assertSame(TimePunch::REASONS, $json['reasons']);
        $this->assertSame(StaffTimesheetController::MISSING_OUT, $json['cell']['status']);
    }

    // ---------------- one save, many changes ----------------

    public function test_filling_the_missing_clock_out_pays_the_day(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $json = $this->save([
            ['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:30'],
        ], 'forgot')->assertOk()->json();

        $this->assertTrue($json['saved']);
        $this->assertSame(9 * 60 + 30, TimesheetEntry::first()->workedMinutes());

        $added = TimePunch::where('type', TimePunch::OUT)->sole();
        $this->assertSame(TimePunch::SOURCE_SUPERVISOR, $added->source);
        $this->assertSame($this->admin->id, $added->recorded_by);
        $this->assertSame('Forgot to punch', $added->reason);

        // And the grid is told the day is fine now — as a corrected day, not
        // as one the clock got right on its own.
        $this->assertSame(StaffTimesheetController::EDITED, $json['cell']['status']);
        $this->assertSame('4:30 PM', $json['cell']['out']);
        $this->assertSame(9.5, $json['hours']);
    }

    public function test_moving_a_punch_voids_the_original_and_writes_a_replacement(): void
    {
        $in = $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $this->save([
            ['op' => 'move', 'id' => $in->id, 'at' => '06:45'],
        ], 'wrong_time')->assertOk();

        $in->refresh();
        $this->assertTrue($in->isVoided());
        $this->assertSame($this->admin->id, $in->voided_by);
        $this->assertSame('Punched wrong time', $in->void_reason);

        $replacement = TimePunch::where('corrects_id', $in->id)->sole();
        $this->assertSame('6:45 am', $replacement->time());
        $this->assertSame(TimePunch::IN, $replacement->type);

        // The record grew by one and lost nothing.
        $this->assertSame(3, TimePunch::count());
        $this->assertSame(8 * 60 + 15, TimesheetEntry::first()->workedMinutes());
    }

    public function test_a_break_is_added_as_a_pair_and_removed_as_a_pair(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $this->save([
            ['op' => 'add', 'type' => TimePunch::BREAK_START, 'at' => '10:00'],
            ['op' => 'add', 'type' => TimePunch::BREAK_END, 'at' => '10:10'],
        ], 'forgot')->assertOk();

        $start = TimePunch::where('type', TimePunch::BREAK_START)->sole();
        $end = TimePunch::where('type', TimePunch::BREAK_END)->sole();

        // A ten-minute rest break is paid, so the day is still eight hours.
        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());

        $this->save([
            ['op' => 'remove', 'id' => $start->id],
            ['op' => 'remove', 'id' => $end->id],
        ], 'wrong_time')->assertOk();

        $this->assertTrue($start->fresh()->isVoided());
        $this->assertTrue($end->fresh()->isVoided());
        $this->assertSame(8 * 60, TimesheetEntry::first()->workedMinutes());
    }

    public function test_several_changes_land_together_under_one_reason(): void
    {
        $in = $this->punch('07:00', TimePunch::IN);

        $this->save([
            ['op' => 'move', 'id' => $in->id, 'at' => '07:05'],
            ['op' => 'add', 'type' => TimePunch::LUNCH_START, 'at' => '12:00'],
            ['op' => 'add', 'type' => TimePunch::LUNCH_END, 'at' => '12:30'],
            ['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00'],
        ], 'device', 'Kiosk was frozen all morning')->assertOk();

        $reasons = TimePunch::where('source', TimePunch::SOURCE_SUPERVISOR)->pluck('reason')->unique()->all();

        $this->assertSame(['Kiosk / device issue: Kiosk was frozen all morning'], $reasons);
        // 7:05 → 16:00, less a 30-minute unpaid lunch.
        $this->assertSame(8 * 60 + 25, TimesheetEntry::first()->workedMinutes());
    }

    public function test_a_break_nobody_came_back_off_is_fixed_by_typing_its_end(): void
    {
        /*
         * The awkward one. A break started, never ended, and a clock-out
         * pressed anyway — which the clock refuses, because "out" while on a
         * break is not a state it has. The day pays nothing and shows amber.
         *
         * The fix is not a new pair and not a moved clock-out: it is the one
         * punch that is missing, typed into the gap it left. The panel draws
         * that gap as an empty "Back from break" row with a one-click usual
         * length; the server takes the single addition and the day adds up.
         */
        $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::BREAK_START);
        $this->punch('16:00', TimePunch::OUT);

        $before = $this->actingAs($this->admin)->getJson($this->url())->assertOk()->json();

        $this->assertTrue($before['totals']['broken']);
        $this->assertSame(StaffTimesheetController::MISSING_OUT, $before['cell']['status']);
        $this->assertSame(0, TimesheetEntry::first()?->workedMinutes() ?? 0);

        $after = $this->save([
            ['op' => 'add', 'type' => TimePunch::BREAK_END, 'at' => '15:15'],
        ], 'forgot')->assertOk()->json();

        $this->assertFalse($after['totals']['broken']);
        $this->assertSame(StaffTimesheetController::EDITED, $after['cell']['status']);

        // Nine hours, with the fifteen-minute break paid up to the cap.
        $cap = (int) config('daycare.timesheet.clock.paid_break_cap');
        $this->assertSame(9 * 60 - 15 + min(15, $cap), TimesheetEntry::first()->workedMinutes());

        // And the panel's own script knows how to draw that gap.
        $page = $this->actingAs($this->admin)->get(route('staff.timesheets'))->assertOk()->getContent();
        $this->assertStringContainsString("rows.splice(index + 1, 0, {key: row.pair + 'end'", $page);
        $this->assertStringContainsString('afterStart(row)', $page);
    }

    // ---------------- what is refused ----------------

    public function test_a_day_that_would_not_add_up_is_refused_whole(): void
    {
        $this->punch('07:00', TimePunch::IN);
        $this->punch('12:00', TimePunch::LUNCH_START);
        $this->punch('12:30', TimePunch::LUNCH_END);

        // A clock-out before lunch ended: the day can no longer be walked.
        $response = $this->save([
            ['op' => 'add', 'type' => TimePunch::OUT, 'at' => '12:15'],
        ], 'forgot');

        $response->assertStatus(422);
        $this->assertStringContainsString('out of order', $response->json('message'));

        // Nothing was written. Not the clock-out, and not a half of anything.
        $this->assertSame(3, TimePunch::count());
        $this->assertSame(0, TimePunch::where('source', TimePunch::SOURCE_SUPERVISOR)->count());
    }

    public function test_clock_in_can_be_moved_but_not_removed(): void
    {
        $in = $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $this->save([['op' => 'remove', 'id' => $in->id]], 'forgot')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Clock-in can be moved but not removed.']);

        $this->assertFalse($in->fresh()->isVoided());
    }

    public function test_a_reason_is_required_and_other_needs_a_note(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $change = [['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00']];

        $this->actingAs($this->admin)
            ->postJson($this->url(), ['changes' => $change])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->admin)
            ->postJson($this->url(), ['changes' => $change, 'reason' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->actingAs($this->admin)
            ->postJson($this->url(), ['changes' => $change, 'reason' => 'other', 'note' => 'Sent home early, agreed with director'])
            ->assertOk();

        $this->assertSame('Other: Sent home early, agreed with director', TimePunch::where('type', TimePunch::OUT)->sole()->reason);
    }

    public function test_a_stale_panel_is_told_the_day_moved(): void
    {
        $in = $this->punch('07:00', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        // Somebody else already replaced the clock-in.
        app(TimeClock::class)->void($in, $this->admin, 'Punched wrong time');

        $this->save([['op' => 'move', 'id' => $in->id, 'at' => '06:50']], 'wrong_time')
            ->assertStatus(409);
    }

    public function test_an_approved_period_cannot_be_changed_from_the_panel(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $period = TimesheetPeriod::forDate(self::DAY);
        $period->forceFill(['status' => TimesheetPeriod::STATUS_APPROVED])->save();

        $this->save([['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00']], 'forgot')
            ->assertStatus(422);

        $this->assertTrue(
            $this->actingAs($this->admin)->getJson($this->url())->json('locked')
        );
    }

    public function test_a_teacher_cannot_reach_the_panel(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $response = $this->actingAs($this->teacher)->getJson($this->url());

        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    // ---------------- the grid afterwards ----------------

    public function test_a_corrected_day_is_a_hollow_ring_on_the_grid(): void
    {
        $this->punch('07:00', TimePunch::IN);

        $this->save([['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00']], 'forgot')->assertOk();

        $html = $this->actingAs($this->admin)
            ->get(route('staff.timesheets', ['from' => '2026-08-17', 'to' => '2026-08-21']))
            ->assertOk()
            ->getContent();

        // Fine now, and visibly not fine on its own: a ring, not a dot.
        $this->assertStringContainsString('title="Edited', $html);
        $this->assertStringNotContainsString('title="Missing time out', $html);
    }

    public function test_a_corrected_day_that_is_still_late_stays_red(): void
    {
        StaffShift::create([
            'week_start' => '2026-08-17', 'user_id' => $this->teacher->id, 'shift_date' => self::DAY,
            'day' => 'Tue', 'starts_at' => 7 * 60, 'ends_at' => 16 * 60, 'classroom' => 'Toddler', 'role' => StaffShift::ROLE_STAFF,
        ]);

        $this->punch('07:40', TimePunch::IN);

        // The ring says "fine now", and a late day is not.
        $json = $this->save([['op' => 'add', 'type' => TimePunch::OUT, 'at' => '16:00']], 'forgot')->assertOk()->json();

        $this->assertSame(StaffTimesheetController::LATE, $json['cell']['status']);
    }

    // ---------------- helpers ----------------

    private function punch(string $time, string $type, string $date = self::DAY): TimePunch
    {
        return app(TimeClock::class)->punch(
            user: $this->teacher,
            type: $type,
            at: Carbon::parse($date.' '.$time, config('app.timezone')),
        );
    }

    private function url(): string
    {
        return route('timesheets.panel', ['user' => $this->teacher, 'date' => self::DAY, 'from' => '2026-08-17', 'to' => '2026-08-21']);
    }

    private function save(array $changes, string $reason, ?string $note = null)
    {
        return $this->actingAs($this->admin)->postJson($this->url(), array_filter([
            'changes' => $changes,
            'reason' => $reason,
            'note' => $note,
        ], fn ($value) => $value !== null));
    }
}
