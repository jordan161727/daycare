<?php

namespace Tests\Feature;

use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\PaidHours;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Part 1 of the staff attendance brief: the paid-hours rule, corrections,
 * late grace, the week's total, today's statuses, time-off conflicts, who
 * sees pay, and the initials on the badges.
 */
class StaffAttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    /** A Friday after the rule's start date (Nov 1, 2026). */
    private const TODAY = '2026-11-06';

    /** The Tuesday before it. */
    private const DAY = '2026-11-03';

    private User $director;

    private User $office;

    private User $lana;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('daycare.timesheet.clock.enabled', true);
        config()->set('daycare.timesheet.clock.auto_break', ['after_minutes' => 360, 'max_minutes' => 30, 'from' => '2026-11-01']);
        config()->set('daycare.timesheet.clock.late_grace', 5);

        $this->travelTo(Carbon::parse(self::TODAY.' 10:00:00'));

        $this->director = User::factory()->create(['role' => 'admin', 'job_role' => 'Director', 'name' => 'Ray Kader', 'must_change_password' => false]);
        $this->office = User::factory()->create(['role' => 'admin', 'job_role' => 'Administrator', 'name' => 'Office Admin', 'must_change_password' => false]);
        $this->lana = User::factory()->create([
            'role' => 'teacher', 'name' => 'Lana', 'classroom' => 'Transition', 'classrooms' => ['Transition'],
            'employment' => 'FT', 'job_role' => 'Lead Teacher', 'pay_rate' => 21.5, 'direct_deposit' => true, 'must_change_password' => false,
        ]);
    }

    /* ---- 1. the break rule ---- */

    public function test_the_break_rule_takes_only_what_is_worked_past_six_hours_up_to_thirty(): void
    {
        $cases = [
            [5 * 60 + 59, 5 * 60 + 59],
            [6 * 60, 6 * 60],
            [6 * 60 + 4, 6 * 60],
            [6 * 60 + 20, 6 * 60],
            [6 * 60 + 30, 6 * 60],
            [9 * 60 + 4, 8 * 60 + 34],
        ];

        foreach ($cases as [$worked, $paid]) {
            $this->assertSame($paid, PaidHours::paid($worked, self::DAY), "worked {$worked} min");
        }
    }

    public function test_the_threshold_and_maximum_come_from_config(): void
    {
        config()->set('daycare.timesheet.clock.auto_break.after_minutes', 300);
        config()->set('daycare.timesheet.clock.auto_break.max_minutes', 45);

        $this->assertSame(5 * 60, PaidHours::paid(5 * 60, self::DAY));
        $this->assertSame(5 * 60, PaidHours::paid(5 * 60 + 40, self::DAY));
        $this->assertSame(8 * 60 + 15, PaidHours::paid(9 * 60, self::DAY));
    }

    public function test_a_day_on_the_clock_pays_by_the_rule(): void
    {
        $this->punch('09:00', TimePunch::IN);
        $this->punch('15:04', TimePunch::OUT);

        $day = app(TimeClock::class)->day($this->lana->id, self::DAY);

        $this->assertSame(6 * 60, $day['worked']);
        $this->assertSame(4, $day['auto_break']);
        $this->assertSame(4, $day['unpaid_break']);
    }

    public function test_a_punched_lunch_is_the_break_and_the_rule_stays_out_of_it(): void
    {
        $this->punch('08:00', TimePunch::IN);
        $this->punch('12:00', TimePunch::LUNCH_START);
        $this->punch('12:20', TimePunch::LUNCH_END);
        $this->punch('17:04', TimePunch::OUT);

        $day = app(TimeClock::class)->day($this->lana->id, self::DAY);

        $this->assertSame(0, $day['auto_break']);
        $this->assertSame(20, $day['unpaid_break']);
        $this->assertSame(8 * 60 + 44, $day['worked']);
    }

    public function test_days_before_the_start_date_are_paid_as_they_were(): void
    {
        $this->punch('09:00', TimePunch::IN, '2026-10-27');
        $this->punch('15:04', TimePunch::OUT, '2026-10-27');

        $day = app(TimeClock::class)->day($this->lana->id, '2026-10-27');

        $this->assertSame(6 * 60 + 4, $day['worked']);
        $this->assertSame(0, $day['auto_break']);
    }

    /* ---- 2 & 3. corrections ---- */

    public function test_a_corrected_noon_to_six_day_has_no_break_and_pays_six_hours(): void
    {
        $in = $this->punch('12:10', TimePunch::IN);
        $out = $this->punch('18:30', TimePunch::OUT);

        $changes = [['op' => 'move', 'id' => $in->id, 'at' => '12:00'], ['op' => 'move', 'id' => $out->id, 'at' => '18:00']];

        // What the panel shows while it is being typed…
        $preview = $this->actingAs($this->director)
            ->postJson(route('timesheets.panel.preview', ['user' => $this->lana, 'date' => self::DAY]), ['changes' => $changes])
            ->assertOk()->json();

        $this->assertSame(0, $preview['auto_break']);
        $this->assertSame(6 * 60, $preview['worked']);
        $this->assertSame('6h 00m', $preview['paid_label']);

        // …is what the save pays.
        $this->actingAs($this->director)
            ->postJson(route('timesheets.panel.save', ['user' => $this->lana, 'date' => self::DAY]), ['reason' => 'wrong_time', 'changes' => $changes])
            ->assertOk();

        $day = app(TimeClock::class)->day($this->lana->id, self::DAY);
        $this->assertSame(0, $day['unpaid_break']);
        $this->assertSame(6 * 60, $day['worked']);
    }

    public function test_the_panel_defaults_the_break_from_the_rule(): void
    {
        $this->punch('08:00', TimePunch::IN);
        $this->punch('14:20', TimePunch::OUT);

        $json = $this->actingAs($this->director)
            ->getJson(route('timesheets.panel', ['user' => $this->lana, 'date' => self::DAY]))
            ->assertOk()->json();

        $this->assertSame(20, $json['break_rule']['auto_break']);
        $this->assertSame(360, $json['break_rule']['after']);
        $this->assertSame(30, $json['break_rule']['max']);
        $this->assertTrue($json['break_rule']['applies']);
    }

    public function test_a_correction_without_a_reason_is_refused_by_the_server(): void
    {
        $in = $this->punch('09:20', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $this->actingAs($this->director)
            ->postJson(route('timesheets.panel.save', ['user' => $this->lana, 'date' => self::DAY]), ['changes' => [['op' => 'move', 'id' => $in->id, 'at' => '09:00']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('09:20', app(TimeClock::class)->punches($this->lana->id, self::DAY)->first()->punched_at->format('H:i'));
    }

    public function test_a_correction_keeps_who_when_the_original_and_the_new_value_in_the_change_log(): void
    {
        $in = $this->punch('09:20', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $this->actingAs($this->director)
            ->postJson(route('timesheets.panel.save', ['user' => $this->lana, 'date' => self::DAY]), [
                'reason' => 'forgot_in', 'changes' => [['op' => 'move', 'id' => $in->id, 'at' => '09:00']],
            ])->assertOk();

        $original = TimePunch::find($in->id);
        $this->assertNotNull($original->voided_at);
        $this->assertSame($this->director->id, $original->voided_by);

        $log = $this->actingAs($this->director)
            ->get(route('staff.timesheets', ['from' => '2026-11-02', 'to' => '2026-11-06']))
            ->assertOk()->viewData('log');
        $line = collect($log)->first();

        // Who, the original time, the new one, and why — in the week's log.
        $this->assertSame('Ray Kader', $line['who']);
        $this->assertMatchesRegularExpression('/9:20 am\s*→\s*9:00 am/iu', $line['what']);
        $this->assertStringContainsString(TimePunch::REASONS['forgot_in'], $line['reason']);
    }

    /* ---- 4. late ---- */

    public function test_late_is_more_than_the_configured_grace_after_the_shift_starts(): void
    {
        $this->shift(self::DAY, 9 * 60, 15 * 60);
        $this->punch('09:08', TimePunch::IN);
        $this->punch('15:00', TimePunch::OUT);

        $rows = fn () => $this->actingAs($this->director)
            ->get(route('staff.timesheets', ['from' => '2026-11-02', 'to' => '2026-11-06']))
            ->viewData('rows')->firstWhere('id', $this->lana->id);

        $this->assertSame('late', $rows()['days'][self::DAY]['flag']);

        config()->set('daycare.timesheet.clock.late_grace', 10);
        $this->assertNull($rows()['days'][self::DAY]['flag']);
        $this->assertSame(10, PaidHours::lateGrace());
    }

    public function test_the_grace_period_is_shown_on_the_history_legend(): void
    {
        config()->set('daycare.timesheet.clock.late_grace', 7);

        $this->actingAs($this->director)
            ->get(route('staff.timesheets', ['from' => '2026-11-02', 'to' => '2026-11-06']))
            ->assertSee('late is more than 7 minutes after the scheduled start', false);
    }

    /* ---- 5. the week ---- */

    public function test_the_payroll_week_runs_monday_to_sunday_and_flags_overtime_past_forty(): void
    {
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
            $this->day($date, '07:00', '17:30');   // 10h30 worked, 10h00 paid
        }
        $this->day('2026-11-08', '09:00', '11:00'); // Sunday, still this week

        $week = app(PaidHours::class)->week($this->lana->id, '2026-11-04');

        $this->assertSame('2026-11-02', $week['from']);
        $this->assertSame('2026-11-08', $week['to']);
        $this->assertSame(42 * 60, $week['minutes']);
        $this->assertTrue($week['overtime']);
        $this->assertSame(120, $week['over']);

        $row = $this->actingAs($this->director)
            ->get(route('staff.timesheets', ['from' => '2026-11-02', 'to' => '2026-11-06']))
            ->viewData('rows')->firstWhere('id', $this->lana->id);

        $this->assertTrue($row['overtime']);
        $this->assertSame('42h 00m', $row['week_total']);
    }

    public function test_forty_hours_exactly_is_not_overtime_and_the_limit_is_configurable(): void
    {
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
            $this->day($date, '07:00', '17:30');
        }

        $this->assertFalse(app(PaidHours::class)->week($this->lana->id, self::DAY)['overtime']);

        config()->set('daycare.timesheet.overtime_after', 38);
        $this->assertTrue(app(PaidHours::class)->week($this->lana->id, self::DAY)['overtime']);
    }

    /* ---- 6. today's statuses ---- */

    public function test_everyone_on_today_s_schedule_is_in_exactly_one_status_and_the_counts_add_up(): void
    {
        $today = self::TODAY;
        $in = $this->teacher('Inez');
        $done = $this->teacher('Dana');
        $late = $this->teacher('Lola');
        $due = $this->teacher('Dua');
        $split = $this->teacher('Sami');
        $off = $this->teacher('Ofra');

        $this->shift($today, 7 * 60, 15 * 60, $in);
        $this->shift($today, 6 * 60, 9 * 60, $done);
        $this->shift($today, 8 * 60, 16 * 60, $late);
        $this->shift($today, 13 * 60, 18 * 60, $due);
        $this->shift($today, 6 * 60, 8 * 60, $split);
        $this->shift($today, 15 * 60, 18 * 60, $split);
        StaffTimeOff::create(['user_id' => $off->id, 'date' => $today, 'kind' => 'all', 'reason' => 'sick', 'paid' => true]);
        $this->shift($today, 7 * 60, 15 * 60, $off);

        $this->punch('07:00', TimePunch::IN, $today, $in);
        $this->punch('06:00', TimePunch::IN, $today, $done);
        $this->punch('09:00', TimePunch::OUT, $today, $done);
        $this->punch('06:00', TimePunch::IN, $today, $split);
        $this->punch('08:00', TimePunch::OUT, $today, $split);

        $live = $this->actingAs($this->director)->get(route('staff.timesheets'))->viewData('live');
        $keys = collect($live['rows'])->pluck('key', 'name');

        $this->assertSame('in', $keys['Inez']);
        $this->assertSame('done', $keys['Dana']);
        $this->assertSame('late', $keys['Lola']);
        $this->assertSame('due', $keys['Dua']);
        $this->assertSame('due', $keys['Sami'], 'clocked out with a later shift is due back, not done');
        $this->assertSame('off', $keys['Ofra']);

        $c = $live['counts'];
        $this->assertSame($c['all'], $c['in'] + $c['done'] + $c['late'] + $c['due'] + $c['off']);
        $this->assertSame(count($live['rows']), $c['all']);
    }

    /* ---- 8. time off conflicts ---- */

    public function test_a_saved_time_off_entry_carries_its_conflict_built_from_the_data(): void
    {
        $date = '2026-11-10';
        $this->shift($date, 9 * 60, 18 * 60);
        StaffTimeOff::create(['user_id' => $this->lana->id, 'date' => $date, 'kind' => 'until', 'from_minute' => 15 * 60, 'reason' => 'appointment', 'paid' => false]);

        $html = $this->actingAs($this->director)->get(route('staff.show', [$this->lana, 'tab' => 'off']))->assertOk()->getContent();

        $this->assertStringContainsString('Lana’s only available 3:00–6:00 PM. Transition needs cover 9:00 AM–3:00 PM.', $html);
    }

    public function test_the_conflict_text_follows_the_shift_not_a_fixed_sentence(): void
    {
        $entry = new StaffTimeOff(['kind' => 'leave', 'to_minute' => 11 * 60, 'date' => '2026-11-10']);

        $impact = $entry->impactOn(['start' => 8 * 60, 'end' => 16 * 60, 'room' => 'Infant'], 'Evana');

        $this->assertSame('Conflict', $impact['tag']);
        $this->assertSame('Evana’s only available 8:00–11:00 AM. Infant needs cover 11:00 AM–4:00 PM.', $impact['text']);
    }

    /* ---- 9. pay ---- */

    public function test_only_the_director_is_sent_pay(): void
    {
        $this->actingAs($this->director)->get(route('staff.show', $this->lana))
            ->assertSee('value="21.50"', false);

        $html = $this->actingAs($this->office)->get(route('staff.show', $this->lana))->assertOk()->getContent();

        $this->assertStringNotContainsString('21.50', $html);
        $this->assertStringNotContainsString('name="pay_rate"', $html);
        $this->assertStringNotContainsString('name="direct_deposit"', $html);
        $this->assertStringContainsString('Only Ray Kader can view pay.', $html);

        $row = $this->actingAs($this->office)->get(route('staff.timesheets', ['view' => 'hist']))->viewData('rows')->firstWhere('id', $this->lana->id);
        $this->assertNull($row['rate']);
    }

    public function test_an_admin_save_cannot_change_pay(): void
    {
        $this->actingAs($this->office)->put(route('staff.update', $this->lana), [
            'name' => 'Lana', 'email' => $this->lana->email, 'pay_rate' => 99, 'direct_deposit' => 0,
        ])->assertRedirect();

        $this->lana->refresh();
        $this->assertSame('21.50', (string) $this->lana->pay_rate);
        $this->assertTrue($this->lana->direct_deposit);
    }

    /* ---- 10. initials ---- */

    public function test_no_two_people_share_initials(): void
    {
        $laquora = $this->teacher('LaQuora');
        $other = $this->teacher('Lana Ali');

        $this->assertSame('LA', $this->lana->fresh()->initials);
        $this->assertSame('LQ', $laquora->fresh()->initials);
        $this->assertNotSame('LA', $other->fresh()->initials);

        $all = User::whereIn('role', ['admin', 'teacher'])->get()->map->initials;
        $this->assertSame($all->count(), $all->unique()->count());
    }

    /* ---- helpers ---- */

    private function teacher(string $name): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => $name, 'employment' => 'FT', 'classroom' => 'Toddler', 'must_change_password' => false]);
    }

    private function shift(string $date, int $start, int $end, ?User $who = null): StaffShift
    {
        return StaffShift::create([
            'week_start' => Carbon::parse($date)->startOfWeek()->toDateString(), 'user_id' => ($who ?? $this->lana)->id, 'shift_date' => $date,
            'day' => strtoupper(Carbon::parse($date)->format('D')), 'starts_at' => $start, 'ends_at' => $end,
            'classroom' => ($who ?? $this->lana)->classroom, 'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    private function day(string $date, string $in, string $out): void
    {
        $this->punch($in, TimePunch::IN, $date);
        $this->punch($out, TimePunch::OUT, $date);
    }

    private function punch(string $time, string $type, string $date = self::DAY, ?User $who = null): TimePunch
    {
        return app(TimeClock::class)->punch(
            user: $who ?? $this->lana,
            type: $type,
            at: Carbon::parse($date.' '.$time, config('app.timezone')),
        );
    }
}
