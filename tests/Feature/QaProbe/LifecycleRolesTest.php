<?php

namespace Tests\Feature\QaProbe;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Http\Controllers\ChildController;
use App\Services\DashboardSnapshot;
use App\Services\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * QA probe — Children module: status lifecycle (area H) and roles/security
 * (area J). Each test asserts what the client's test plan / HANDOFF spec
 * EXPECTS; a failing test is a finding. Only TEST-… records are used.
 *
 * Run: php artisan test tests/Feature/QaProbe/LifecycleRolesTest.php
 */
class LifecycleRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $office;

    private User $teacher;

    private int $lan = 90001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00'));
        Setting::forget();
        Storage::fake('local');

        $this->director = User::factory()->create(['name' => 'TEST-Ray', 'email' => 'test-ray@example.test', 'role' => 'admin', 'job_role' => 'Director', 'must_change_password' => false]);
        $this->office = User::factory()->create(['name' => 'TEST-Office', 'email' => 'test-office@example.test', 'role' => 'admin', 'job_role' => 'Office', 'must_change_password' => false]);
        $this->teacher = User::factory()->create(['name' => 'TEST-Teach', 'email' => 'test-teach@example.test', 'role' => 'teacher', 'classroom' => 'Toddler', 'must_change_password' => false]);
    }

    /* ======================= H: lifecycle ======================= */

    public function test_h1_start_today_makes_active_and_appears_on_check_in(): void
    {
        $c = $this->child(['status' => 'Pending', 'first_name' => 'TEST-Pam']);
        $this->assertStringNotContainsString('TEST-Pam', $this->actingAs($this->director)->get(route('check-in.index'))->getContent(), 'pending not on check-in');

        $this->actingAs($this->director)->post(route('children.start', $c), ['first_day' => '2026-10-29'])->assertRedirect(route('children.show', $c));
        $this->assertSame('Active', $c->fresh()->status);
        $this->assertSame('started', $c->statusChanges()->first()->event);
        $this->assertStringContainsString('TEST-Pam', $this->actingAs($this->director)->get(route('check-in.index'))->getContent());
    }

    public function test_h2_start_in_future_waits_then_sync_starts_on_the_day(): void
    {
        $c = $this->child(['status' => 'Pending', 'first_name' => 'TEST-Fut']);
        $this->actingAs($this->director)->post(route('children.start', $c), ['first_day' => '2026-11-02'])->assertRedirect();
        $this->assertSame('Pending', $c->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-01 00:10:00'));
        $this->artisan('children:sync-enrollments')->expectsOutputToContain('0 started')->assertSuccessful();
        $this->assertSame('Pending', $c->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-02 00:10:00'));
        $this->artisan('children:sync-enrollments')->expectsOutputToContain('1 started')->assertSuccessful();
        $this->assertSame('Active', $c->fresh()->status);
        $this->assertSame(1, $c->statusChanges()->where('event', 'started')->count());
    }

    public function test_h2b_start_requires_a_valid_first_day(): void
    {
        $c = $this->child(['status' => 'Pending']);
        $this->actingAs($this->director)->post(route('children.start', $c), [])->assertSessionHasErrors('first_day');
        $this->actingAs($this->director)->post(route('children.start', $c), ['first_day' => 'not-a-date'])->assertSessionHasErrors('first_day');
        $this->assertSame('Pending', $c->fresh()->status);
    }

    public function test_h3_cancel_requires_reason_and_keep_pending_posts_nothing(): void
    {
        $c = $this->child(['status' => 'Pending']);
        $this->actingAs($this->director)->post(route('children.cancel', $c), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->director)->post(route('children.cancel', $c), ['reason' => 'Made up'])->assertSessionHasErrors('reason');
        $this->assertSame('Pending', $c->fresh()->status);

        // "Keep pending" must be a plain button inside the cancel panel, not a submit.
        $html = $this->actingAs($this->director)->get(route('children.show', $c))->getContent();
        $this->assertStringContainsString('<button type="button" class="btn" @click="panel = null">Keep pending</button>', $html);

        $this->actingAs($this->director)->post(route('children.cancel', $c), ['reason' => 'Family changed plans'])->assertRedirect();
        $this->assertSame('Inactive', $c->fresh()->status);
        $this->assertSame('cancelled', $c->statusChanges()->first()->event);
    }

    /** Client plan: last day, reason, could-come-back and note are all required. */
    public function test_h4_withdraw_requires_every_field(): void
    {
        $c = $this->child();
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['reason' => 'Family choice', 'could_return' => 'Yes', 'note' => 'x'])->assertSessionHasErrors('last_day');
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'could_return' => 'Yes', 'note' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => 'zzz', 'reason' => 'Family choice'])->assertSessionHasErrors('last_day');
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'reason' => 'Family choice', 'could_return' => 'Maybe'])->assertSessionHasErrors('could_return');

        // Missing could_return (client plan: required):
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'reason' => 'Family choice', 'note' => 'x'])
            ->assertSessionHasErrors('could_return');
    }

    public function test_h4c_withdraw_requires_a_note(): void
    {
        $c = $this->child();
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'reason' => 'Family choice', 'could_return' => 'Yes'])
            ->assertSessionHasErrors('note');
    }

    public function test_h4b_withdraw_last_day_before_first_day_is_refused(): void
    {
        $c = $this->child(['enrolled_on' => '2026-09-01']);
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-08-01', 'reason' => 'Family choice', 'could_return' => 'No', 'note' => 'x'])
            ->assertSessionHasErrors('last_day');
    }

    public function test_h5_after_last_day_child_is_off_every_roster_but_kept_for_billing(): void
    {
        config()->set('daycare.dss.users', 'test-ray@example.test');
        $c = $this->child(['first_name' => 'TEST-Gone', 'last_name' => 'TEST-Leaver', 'enrolled_on' => '2026-09-01', 'dss_cin' => 'ZZ00000Z', 'dss_case_no' => 'S9999999Z', 'schedule_days' => [1, 2, 3, 4, 5]]);
        $mom = $this->guardian($c, 'TEST-Mom-Gone', '7165550101');
        foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] as $d) {
            $this->sign($c, $d);
        }

        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'reason' => 'Moved out of the area', 'could_return' => 'No', 'note' => 'TEST note'])->assertRedirect();
        $c->refresh();
        $this->assertSame('Inactive', $c->status);

        // Off: check-in, register this week, ratio roll, room group text recipients, family search.
        $this->assertStringNotContainsString('TEST-Gone', $this->actingAs($this->director)->get(route('check-in.index'))->getContent(), 'check-in');
        $this->assertStringNotContainsString('TEST-Leaver', $this->actingAs($this->director)->get(route('attendance.index', ['date' => '2026-10-29']))->getContent(), 'register this week');
        $this->assertSame(0, app(DashboardSnapshot::class)->for($this->director)['enrolled'], 'ratio roll');
        $rows = $this->actingAs($this->director)->getJson(route('messages.recipients', ['to' => 'room', 'room' => 'Toddler']))->assertOk()->json('rows');
        $this->assertSame([], $rows, 'group text recipients');
        $this->assertCount(0, app(Messaging::class)->childrenIn('Toddler'));
        $this->postJson(route('check-in.store'), ['child_id' => $c->id, 'session' => 'FULL', 'health_code' => 0])->assertStatus(422);

        // Kept: last week on the register, DSS grid + export, month sheet, weekly report.
        $this->assertStringContainsString('TEST-Leaver', $this->actingAs($this->director)->get(route('attendance.index', ['date' => '2026-10-21']))->getContent(), 'register last week');
        $csv = $this->actingAs($this->director)->get(route('dss.export', ['month' => '2026-10']))->assertOk()->getContent();
        $this->assertStringContainsString('10/23/2026', $csv, 'DSS export keeps the last day');
        $this->assertStringContainsString('TEST-Leaver', $csv);
        $row = collect($this->actingAs($this->director)->getJson(route('dss.grid', ['month' => '2026-10']))->json('rows'))->firstWhere('id', $c->id);
        $this->assertNotNull($row, 'DSS grid row');
        $this->assertStringContainsString('TEST note', $row['wd']);

    }

    /** Month sheet for the month a child left in: control child must show, leaver must too. */
    public function test_h5b_month_sheet_keeps_a_child_who_left_this_month(): void
    {
        [$stay, $c] = $this->leaverAndControl();

        $html = $this->actingAs($this->director)->get(route('attendance.month-sheet', ['month' => 10, 'year' => 2026]))->assertOk()->getContent();
        $this->assertStringContainsString('TEST-Control', $html, 'control');
        $this->assertStringContainsString('TEST-Leaver', $html, 'October month sheet drops the child who left Oct 23');
    }

    public function test_h5c_weekly_report_keeps_a_child_for_the_week_they_left(): void
    {
        [$stay, $c] = $this->leaverAndControl();

        $html = $this->actingAs($this->director)->get(route('reports.index', ['date' => '2026-10-21']))->assertOk()->getContent();
        $this->assertStringContainsString('TEST-Control', $html, 'control');
        $this->assertStringContainsString('TEST-Leaver', $html, 'weekly report for Oct 19-23 drops the child who attended all five days');
    }

    private function leaverAndControl(): array
    {
        $stay = $this->child(['first_name' => 'TEST-Ctrl', 'last_name' => 'TEST-Control']);
        $c = $this->child(['first_name' => 'TEST-Gone', 'last_name' => 'TEST-Leaver', 'enrolled_on' => '2026-09-01']);
        foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] as $d) {
            $this->sign($c, $d);
            $this->sign($stay, $d);
        }
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-23', 'reason' => 'Moved out of the area', 'could_return' => 'No', 'note' => 'n'])->assertRedirect();

        return [$stay, $c];
    }

    public function test_h6_future_last_day_keeps_them_until_then(): void
    {
        $c = $this->child(['first_name' => 'TEST-Soon']);
        $this->guardian($c, 'TEST-Mom-Soon', '7165550102');
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-10-30', 'reason' => 'Changed provider', 'could_return' => 'Not sure', 'note' => 'n'])->assertRedirect();
        $this->assertSame('Active', $c->fresh()->status);
        $this->assertStringContainsString('TEST-Soon', $this->actingAs($this->director)->get(route('check-in.index'))->getContent());
        $this->assertCount(1, app(Messaging::class)->childrenIn('Toddler'));

        $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
        $this->artisan('children:sync-enrollments')->assertSuccessful();
        $this->assertSame('Active', $c->fresh()->status, 'still here on the last day');

        $this->travelTo(Carbon::parse('2026-10-31 00:10:00'));
        $this->artisan('children:sync-enrollments')->expectsOutputToContain('1 withdrawn')->assertSuccessful();
        $this->assertSame('Inactive', $c->fresh()->status);
        $this->assertCount(0, app(Messaging::class)->childrenIn('Toddler'));
    }

    public function test_h7_reenroll_keeps_lan_and_family_and_history_shows_both_periods(): void
    {
        $c = $this->child(['first_name' => 'TEST-Back', 'enrolled_on' => '2026-09-01']);
        $mom = $this->guardian($c, 'TEST-Mom-Back', '7165550103');
        $lan = $c->lan;
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-09-15', 'reason' => 'Family choice', 'could_return' => 'Yes', 'note' => 'n'])->assertRedirect();
        $this->actingAs($this->director)->post(route('children.reenroll', $c))->assertRedirect();
        $c->refresh();

        $this->assertSame('Active', $c->status);
        $this->assertSame($lan, $c->lan);
        $this->assertTrue($c->people()->whereKey($mom->id)->exists(), 'family link kept');
        $events = $c->statusChanges()->pluck('event')->all();
        $this->assertContains('withdrawn', $events);
        $this->assertContains('reenrolled', $events);
        $this->actingAs($this->director)->get(route('children.show', $c))->assertOk()->assertSee('Withdrawn · Family choice')->assertSee('Re-enrolled');
    }

    public function test_h7b_reenroll_keeps_the_gap_out_of_the_enrolled_days(): void
    {
        $c = $this->child(['first_name' => 'TEST-Gap', 'enrolled_on' => '2026-09-01']);
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-09-15', 'reason' => 'Family choice', 'could_return' => 'Yes', 'note' => 'n'])->assertRedirect();
        $this->actingAs($this->director)->post(route('children.reenroll', $c))->assertRedirect();
        $c->refresh();
        $this->assertSame('2026-09-01', $c->enrolled_on?->toDateString(), 'first day unchanged by re-enroll');
        $this->assertNull($c->withdrawn_on, 'last day of the first period wiped');

        config()->set('daycare.dss.users', 'test-ray@example.test');
        $c->update(['schedule_days' => [1, 2, 3, 4, 5], 'dss_cin' => 'ZZ11111Z']);
        $row = collect($this->actingAs($this->director)->getJson(route('dss.grid', ['month' => '2026-09']))->json('rows'))->firstWhere('id', $c->id);
        $this->assertSame('gone', $row['cells']['2026-09-22']['st'] ?? null, 'DSS September grid, Tue Sep 22 (in the gap) reads: '.json_encode($row['cells']['2026-09-22'] ?? null).' wd='.json_encode($row['wd'] ?? null));

        // Both periods: the gap (Sep 16 – Oct 28) must not read as enrolled.
        $this->assertFalse($c->isEnrolledOn('2026-09-22'), 'gap between periods reads as enrolled — first period\'s last day was wiped on re-enroll');
    }

    public function test_h8_archive_restore_tabs_and_counts(): void
    {
        Setting::put(ChildController::SHOW_ARCHIVED_SETTING, '1');
        $active = $this->child(['first_name' => 'TEST-Stay']);
        $c = $this->child(['first_name' => 'TEST-Arch', 'status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);

        $json = $this->actingAs($this->director)->postJson(route('children.archive', $c))->assertOk()->json();
        $this->assertSame('Archived', $c->fresh()->status);
        $this->assertSame(['all' => 1, 'byStatus' => ['Active' => 1, 'Pending' => 0, 'Inactive' => 0], 'archived' => 1], $json['counts']);

        $this->actingAs($this->director)->get(route('children.index', ['status' => 'Inactive']))->assertOk()->assertDontSee('TEST-Arch');
        $this->actingAs($this->director)->get(route('children.index'))->assertOk()->assertDontSee('TEST-Arch');
        $this->actingAs($this->director)->get(route('children.index', ['status' => 'Archived']))->assertOk()->assertSee('TEST-Arch');

        $json = $this->actingAs($this->director)->postJson(route('children.unarchive', $c))->assertOk()->json();
        $this->assertSame('Inactive', $c->fresh()->status);
        $this->assertSame(1, $json['counts']['byStatus']['Inactive']);
        $this->assertSame(0, $json['counts']['archived']);
    }

    public function test_h8b_archived_tab_is_404_while_the_setting_is_off(): void
    {
        $c = $this->child(['first_name' => 'TEST-Arch2', 'status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);
        $c->archive($this->director);
        $this->actingAs($this->director)->get(route('children.index', ['status' => 'Archived']))->assertNotFound();
    }

    /** "Archived records are read-only until restored" — every write endpoint. */
    public function test_h9_archived_record_is_read_only_on_every_write_endpoint(): void
    {
        $c = $this->child(['first_name' => 'TEST-RO', 'status' => 'Inactive', 'withdrawn_on' => '2026-09-30', 'alerts' => [['type' => 'allergy', 'text' => 'TEST peanuts']]]);
        $p = $this->guardian($c, 'TEST-Mom-RO', '7165550104');
        $c->archive($this->director);
        $this->actingAs($this->director);

        $accepted = [];
        $try = function (string $label, $response) use (&$accepted) {
            $code = $response->getStatusCode();
            if ($code < 400 && ! session()->has('errors')) {
                $accepted[] = $label.' → '.$code;
            }
            session()->forget('errors');
        };

        $try('PUT update (edit form)', $this->put(route('children.update', $c), ['first_name' => 'TEST-RO2', 'last_name' => $c->last_name, 'status' => 'Archived']));
        $try('PUT overview', $this->put(route('children.overview', $c), ['nickname' => 'TEST-nick']));
        $try('PUT enrollment-note', $this->put(route('children.enrollment-note', $c), ['enrollment_note' => 'TEST']));
        $try('PUT health', $this->put(route('children.health', $c), ['doctor' => 'TEST-Dr']));
        $try('POST alert', $this->post(route('children.alerts.store', $c), ['type' => 'allergy', 'text' => 'TEST eggs']));
        $try('DELETE alert 0', $this->delete(route('children.alerts.destroy', [$c, 0])));
        $try('POST note', $this->post(route('children.notes.store', $c), ['body' => 'TEST note']));
        $try('POST document', $this->post(route('children.documents.store', $c), ['kind' => array_key_first(Child::DOCUMENT_KINDS), 'file' => UploadedFile::fake()->create('t.pdf', 5, 'application/pdf')]));
        $try('POST people flags', $this->post(route('children.people.flags', [$c, $p]), ['can_pickup' => 1, 'restriction' => '']));
        $try('POST room', $this->post(route('children.room', $c), ['room' => 'Infant']));
        $try('POST people link (new person)', $this->postJson(route('people.store'), ['child_id' => $c->id, 'name' => 'TEST-New', 'cell' => '7165550199', 'can_pickup' => true]));

        $this->assertSame([], $accepted, "Archived record accepted writes:\n".implode("\n", $accepted));
    }

    /** An archived child whose archive came straight from Active has no last day — can the door still take them? */
    public function test_h9b_archived_child_cannot_be_signed_in_anywhere(): void
    {
        $c = $this->child(['first_name' => 'TEST-ArchIn']);
        $c->archive($this->director);   // as the direct POST in h10 allows

        $this->actingAs($this->director);
        $checkIn = $this->postJson(route('check-in.store'), ['child_id' => $c->id, 'session' => 'FULL', 'health_code' => 0])->getStatusCode();
        $register = $this->postJson(route('attendance.signin'), ['child_id' => $c->id, 'attendance_date' => '2026-10-29', 'session' => 'FULL'])->getStatusCode();

        $this->assertSame(0, Attendance::where('child_id', $c->id)->count() + \App\Models\DoorAttendance::where('child_id', $c->id)->count(),
            "archived child signed in: check-in HTTP $checkIn, register HTTP $register");
    }

    public function test_h9c_pending_child_cannot_be_checked_in_by_direct_post(): void
    {
        $c = $this->child(['first_name' => 'TEST-PendIn', 'status' => 'Pending']);
        $code = $this->actingAs($this->director)->postJson(route('check-in.store'), ['child_id' => $c->id, 'session' => 'FULL', 'health_code' => 0])->getStatusCode();
        $this->assertSame(0, \App\Models\DoorAttendance::where('child_id', $c->id)->count(), "pending child checked in, HTTP $code");
    }

    public function test_h9d_kiosk_cannot_sign_in_an_archived_child(): void
    {
        config(['daycare.kiosk.enabled' => true]);
        $c = $this->child(['first_name' => 'TEST-KioskArch']);
        $p = Person::create(['name' => 'TEST-KioskMom']);
        $p->setPin('481902');
        $p->forceFill(['phone_last4' => '4417'])->save();
        ChildPerson::create(['child_id' => $c->id, 'person_id' => $p->id, 'relationship' => 'Mother', 'can_pickup' => true]);
        $c->archive($this->director);

        $this->postJson(route('kiosk.unlock'), ['pin' => '481902'])->assertOk();
        $status = $this->postJson(route('kiosk.punch'), ['child_id' => $c->id, 'direction' => 'in'])->json('status');
        $this->assertNotSame('ok', $status, 'kiosk signed in an archived child');
        $this->assertSame(0, Attendance::where('child_id', $c->id)->count());
    }

    public function test_h10_invalid_transitions_by_direct_post(): void
    {
        $pending = $this->child(['status' => 'Pending']);
        $active = $this->child();
        $archived = $this->child(['status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);
        $archived->archive($this->director);
        $this->actingAs($this->director);

        $this->post(route('children.withdraw', $pending), ['last_day' => '2026-10-23', 'reason' => 'Other', 'could_return' => 'No', 'note' => 'x'])->assertStatus(422);
        $this->post(route('children.start', $archived), ['first_day' => '2026-10-29'])->assertStatus(422);
        $this->post(route('children.reenroll', $active))->assertStatus(422);
        $this->post(route('children.reenroll', $archived))->assertStatus(422);
        $this->post(route('children.cancel', $active), ['reason' => 'Other'])->assertStatus(422);

        $this->post(route('children.archive', $active))->assertStatus(422);
        $this->assertSame('Active', $active->fresh()->status, 'Active child archived by direct POST');
    }

    public function test_h10b_archive_a_pending_child_by_direct_post_is_refused(): void
    {
        $pending = $this->child(['status' => 'Pending']);
        $this->actingAs($this->director)->post(route('children.archive', $pending))->assertStatus(422);
        $this->assertSame('Pending', $pending->fresh()->status);
    }

    /** Stale tab: restore pressed on a child someone already restored and re-enrolled. */
    public function test_h10c_stale_restore_does_not_report_a_false_status(): void
    {
        $c = $this->child(['first_name' => 'TEST-Stale']);
        $res = $this->actingAs($this->director)->postJson(route('children.unarchive', $c));
        $this->assertSame('Active', $c->fresh()->status);
        $this->assertNotSame(200, $res->getStatusCode(), 'stale Restore answered 200: '.$res->json('message'));
    }

    /** Stale tab: a second Withdraw on a child already leaving silently overwrites the first. */
    public function test_h10d_second_withdraw_from_stale_tab_is_refused(): void
    {
        $c = $this->child();
        $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-11-06', 'reason' => 'Moved out of the area', 'could_return' => 'No', 'note' => 'first'])->assertRedirect();
        $second = $this->actingAs($this->director)->post(route('children.withdraw', $c), ['last_day' => '2026-12-18', 'reason' => 'Family choice', 'could_return' => 'Yes', 'note' => 'second']);
        $this->assertSame(422, $second->getStatusCode(), 'second withdraw accepted; last day now '.$c->fresh()->withdrawn_on?->toDateString());
    }

    /** The edit form's status radio is a second door round the lifecycle. */
    public function test_h11_edit_form_cannot_change_status_outside_the_lifecycle(): void
    {
        $archived = $this->child(['first_name' => 'TEST-EditArch', 'status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);
        $archived->archive($this->director);
        $pending = $this->child(['first_name' => 'TEST-EditPend', 'status' => 'Pending']);

        $this->actingAs($this->director)->put(route('children.update', $archived), ['first_name' => 'TEST-EditArch', 'last_name' => $archived->last_name, 'status' => 'Active']);
        $this->actingAs($this->director)->put(route('children.update', $pending), ['first_name' => 'TEST-EditPend', 'last_name' => $pending->last_name, 'status' => 'Active']);

        $found = [];
        if ($archived->fresh()->status !== 'Archived') {
            $found[] = 'Archived → '.$archived->fresh()->status.' via edit form; history: '.$archived->statusChanges()->get()->map(fn ($l) => $l->event.':'.$l->from_status.'>'.$l->to_status)->implode(' | ');
        }
        if ($pending->fresh()->status !== 'Pending') {
            $found[] = 'Pending → '.$pending->fresh()->status.' via edit form, enrolled_on='.($pending->fresh()->enrolled_on?->toDateString() ?? 'null').'; history: '.$pending->statusChanges()->get()->map(fn ($l) => $l->event.':'.$l->from_status.'>'.$l->to_status)->implode(' | ');
        }
        $this->assertSame([], $found, implode("\n", $found));
    }

    public function test_h12_no_route_deletes_a_child(): void
    {
        $deleting = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true) && str_contains($r->uri(), 'children'))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame(['children/{child}/alerts/{index}'], $deleting, 'only the alert delete is expected');
        $this->assertFalse(Route::has('children.destroy'));
    }

    /** Two tabs on one record: an alert deleted by index from a stale page removes the wrong allergy. */
    public function test_h13_stale_alert_delete_cannot_remove_the_wrong_alert(): void
    {
        $c = $this->child(['alerts' => [['type' => 'allergy', 'text' => 'TEST bees'], ['type' => 'allergy', 'text' => 'TEST peanuts - EpiPen']]]);
        // Tab A removes "bees" (index 0). Tab B, opened before, still shows bees at 0 and also presses delete on it.
        $this->actingAs($this->director)->delete(route('children.alerts.destroy', [$c, 0]))->assertRedirect();
        $this->actingAs($this->director)->delete(route('children.alerts.destroy', [$c, 0]));

        $texts = collect($c->fresh()->alerts)->pluck('text')->all();
        $this->assertContains('TEST peanuts - EpiPen', $texts, 'the peanut allergy was deleted by a stale tab');
    }

    /* ======================= J: roles ======================= */

    public function test_j1_teacher_cannot_open_dss(): void
    {
        config()->set('daycare.dss.users', '');
        $this->actingAs($this->teacher)->get(route('dss.index'))->assertForbidden();
        $this->actingAs($this->teacher)->get(route('dss.export', ['month' => '2026-10']))->assertForbidden();
    }

    /** "Only visible to Ray K": with DAYCARE_DSS_USERS unset (as in the project .env) an office admin gets in. */
    public function test_j2_dss_is_for_the_authorised_director_only(): void
    {
        config()->set('daycare.dss.users', '');
        $this->actingAs($this->director)->get(route('dss.index'))->assertOk();
        $this->actingAs($this->office)->get(route('dss.index'))->assertForbidden();
    }

    public function test_j2b_dss_with_the_named_user_set_blocks_other_admins(): void
    {
        config()->set('daycare.dss.users', 'test-ray@example.test');
        $this->actingAs($this->director)->get(route('dss.index'))->assertOk();
        $this->actingAs($this->office)->get(route('dss.index'))->assertForbidden();
        $this->actingAs($this->office)->get(route('dss.export', ['month' => '2026-10']))->assertForbidden();
    }

    public function test_j3_teacher_cannot_run_any_lifecycle_step(): void
    {
        $active = $this->child();
        $pending = $this->child(['status' => 'Pending']);
        $gone = $this->child(['status' => 'Inactive', 'withdrawn_on' => '2026-09-30']);
        $this->actingAs($this->teacher);

        $this->post(route('children.start', $pending), ['first_day' => '2026-10-29'])->assertForbidden();
        $this->post(route('children.cancel', $pending), ['reason' => 'Other'])->assertForbidden();
        $this->post(route('children.withdraw', $active), ['last_day' => '2026-10-23', 'reason' => 'Other'])->assertForbidden();
        $this->post(route('children.reenroll', $gone))->assertForbidden();
        $this->post(route('children.archive', $gone))->assertForbidden();
        $this->post(route('children.unarchive', $gone))->assertForbidden();
        $this->post(route('children.room', $active), ['room' => 'Infant'])->assertForbidden();
        $this->get(route('children.create'))->assertForbidden();
    }

    /** The edit form lets a teacher do what the lifecycle routes refuse. */
    public function test_j3b_teacher_cannot_withdraw_or_move_rooms_through_the_edit_form(): void
    {
        $c = $this->child(['first_name' => 'TEST-TEdit']);
        $this->actingAs($this->teacher)->put(route('children.update', $c), [
            'first_name' => 'TEST-TEdit', 'last_name' => $c->last_name, 'status' => 'Inactive', 'withdrawn_on' => '2026-10-20',
        ]);
        $found = [];
        $c->refresh();
        if ($c->status !== 'Active') {
            $found[] = 'teacher set status '.$c->status.', withdrawn_on='.$c->withdrawn_on?->toDateString().', reason='.var_export($c->withdrawal_reason, true).'; history: '.$c->statusChanges()->get()->map(fn ($l) => $l->event.':'.$l->from_status.'>'.$l->to_status)->implode(' | ');
        }

        $d = $this->child(['first_name' => 'TEST-TMove']);
        $this->actingAs($this->teacher)->put(route('children.update', $d), [
            'first_name' => 'TEST-TMove', 'last_name' => $d->last_name, 'status' => 'Active', 'classroom_override' => 'Infant',
        ]);
        if ($d->fresh()->classroom !== 'Toddler') {
            $found[] = 'teacher moved child to '.$d->fresh()->classroom.' via edit form; teacher can now open it? '.$this->actingAs($this->teacher)->get(route('children.show', $d))->getStatusCode();
        }

        $e = $this->child(['first_name' => 'TEST-TDss', 'dss_case_no' => 'S1111111Z']);
        $this->actingAs($this->teacher)->put(route('children.update', $e), [
            'first_name' => 'TEST-TDss', 'last_name' => $e->last_name, 'status' => 'Active', 'classroom_override' => 'Toddler', 'dss_case_no' => 'S2222222Z', 'enrolled_on' => '2026-01-01',
        ]);
        if ($e->fresh()->dss_case_no !== 'S1111111Z') {
            $found[] = 'teacher changed DSS case no to '.$e->fresh()->dss_case_no.' and enrolled_on to '.$e->fresh()->enrolled_on?->toDateString();
        }
        $this->assertSame([], $found, implode("\n", $found));
    }

    public function test_j4_teacher_sees_only_their_rooms(): void
    {
        $mine = $this->child(['first_name' => 'TEST-Mine']);
        $other = $this->child(['first_name' => 'TEST-Other', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);
        $otherMom = $this->guardian($other, 'TEST-OtherMom', '7165550111');
        $other->update(['photo_path' => 'children/test-other.jpg']);
        Storage::disk('local')->put('children/test-other.jpg', 'jpg');
        $doc = $other->documents()->create(['kind' => array_key_first(Child::DOCUMENT_KINDS), 'name' => 'TEST doc', 'path' => 'children/documents/t.pdf', 'signed_on' => '2026-10-01', 'uploaded_by' => $this->director->id]);
        Storage::disk('local')->put('children/documents/t.pdf', 'pdf');

        $this->actingAs($this->teacher);
        $this->get(route('children.index'))->assertOk()->assertSee('TEST-Mine')->assertDontSee('TEST-Other');
        $this->get(route('children.show', $other))->assertForbidden();
        $this->get(route('children.edit', $other))->assertForbidden();
        $this->put(route('children.health', $other), ['doctor' => 'x'])->assertForbidden();
        $this->get(route('children.documents.show', [$other, $doc]))->assertForbidden();
        $this->get(route('children.people', $other))->assertForbidden();
        $this->getJson(route('people.show', $otherMom))->assertForbidden();
        $this->postJson(route('attendance.week.cell'), ['child_id' => $other->id, 'date' => '2026-10-28', 'session' => 'FULL', 'state' => 'pres'])->assertNotFound();
        $this->getJson(route('messages.recipients', ['to' => 'room', 'room' => 'Infant']))->assertForbidden();
        $this->getJson(route('messages.recipients', ['to' => 'family', 'child_id' => $other->id]))->assertNotFound();
    }

    public function test_j4b_teacher_people_search_does_not_reveal_other_rooms_families(): void
    {
        $other = $this->child(['first_name' => 'TEST-OtherKid', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);
        $this->guardian($other, 'TEST-SecretMom', '7165550112');

        $json = $this->actingAs($this->teacher)->getJson(route('people.search', ['q' => 'TEST-Secret']))->assertOk()->json('people');
        $this->assertSame([], $json, 'teacher search returned: '.json_encode($json));
    }

    public function test_j4c_teacher_photo_of_child_in_another_room(): void
    {
        $other = $this->child(['first_name' => 'TEST-PhotoKid', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);
        $other->update(['photo_path' => 'children/test-photo.jpg']);
        Storage::disk('local')->put('children/test-photo.jpg', 'jpg');
        // Spec §4: teacher reads basic info for own room only. Code comment says any staff (door screen).
        $this->actingAs($this->teacher)->get(route('children.photo', $other))->assertForbidden();
    }

    public function test_j4d_teacher_export_is_own_room_and_basic_info_only(): void
    {
        Excel::fake();
        $this->child(['first_name' => 'TEST-ExMine', 'dss_case_no' => 'S0000001Z', 'custody_note' => 'TEST custody']);
        $this->child(['first_name' => 'TEST-ExOther', 'classroom' => 'Infant', 'classroom_override' => 'Infant']);

        $this->actingAs($this->teacher)->get(route('children.export'))->assertOk();
        Excel::assertDownloaded('children-2026-10-29.xlsx', function ($export) {
            $names = $export->collection()->pluck('first_name')->all();
            $this->assertSame(['TEST-ExMine'], $names, 'other room in teacher export');
            $headings = $export->sheets()[0]->headings();
            $sensitive = array_values(array_intersect($headings, ['DSS case no', 'DSS CIN', 'Health', 'Custody note', 'Withdrawal reason']));
            $peopleHeadings = $export->sheets()[1]->headings();
            $this->assertSame([], array_merge($sensitive, array_intersect($peopleHeadings, ['SSN (last 4)', 'Driver licence'])), 'teacher export carries office-only columns');

            return true;
        });
    }

    public function test_j5_teacher_reports_are_owner_only(): void
    {
        $this->actingAs($this->teacher)->get(route('reports.index'))->assertForbidden();
    }

    public function test_j5b_office_admin_settings_are_owner_only(): void
    {
        $this->actingAs($this->office)->get(route('settings.children'))->assertForbidden();
    }

    /** No parent role exists; an account with any other role must see nothing. */
    public function test_j6_non_staff_account_sees_no_children(): void
    {
        $this->child(['first_name' => 'TEST-Floor', 'alerts' => [['type' => 'allergy', 'text' => 'TEST shellfish']]]);
        $parent = User::factory()->create(['email' => 'test-parent@example.test', 'role' => 'parent', 'must_change_password' => false]);

        $this->actingAs($parent);
        $leaks = [];
        foreach (['check-in.index' => [], 'attendance.index' => [], 'attendance.month-sheet' => [], 'children.index' => [], 'comments.index' => [], 'dashboard' => []] as $name => $params) {
            $res = $this->get(route($name, $params));
            if ($res->getStatusCode() === 200 && str_contains($res->getContent(), 'TEST-Floor')) {
                $leaks[] = $name;
            }
        }
        $this->assertSame([], $leaks, 'role=parent sees TEST-Floor on: '.implode(', ', $leaks));
    }

    public function test_j6b_non_staff_account_cannot_check_a_child_in(): void
    {
        $c = $this->child(['first_name' => 'TEST-ParIn']);
        $parent = User::factory()->create(['email' => 'test-parent2@example.test', 'role' => 'parent', 'must_change_password' => false]);
        $code = $this->actingAs($parent)->postJson(route('check-in.store'), ['child_id' => $c->id, 'session' => 'FULL', 'health_code' => 0])->getStatusCode();
        $this->assertSame(403, $code);
    }

    public function test_j7_signed_out_and_logged_out_reach_nothing(): void
    {
        $c = $this->child();
        $this->get(route('children.show', $c))->assertRedirect(route('login'));
        $this->get(route('children.export'))->assertRedirect(route('login'));
        $this->get(route('children.photo', $c))->assertRedirect(route('login'));

        $this->actingAs($this->director)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $this->get(route('children.index'))->assertRedirect(route('login'));
    }

    public function test_j7b_kiosk_window_closes_after_90_seconds_and_on_lock(): void
    {
        config(['daycare.kiosk.enabled' => true]);
        $c = $this->child(['first_name' => 'TEST-KioskKid']);
        $p = Person::create(['name' => 'TEST-KioskDad']);
        $p->setPin('551177');
        $p->forceFill(['phone_last4' => '4418'])->save();
        ChildPerson::create(['child_id' => $c->id, 'person_id' => $p->id, 'relationship' => 'Father', 'can_pickup' => true]);

        $this->postJson(route('kiosk.unlock'), ['pin' => '551177'])->assertOk()->assertJson(['status' => 'ok']);
        $this->postJson(route('kiosk.lock'))->assertOk();
        $this->postJson(route('kiosk.punch'), ['child_id' => $c->id, 'direction' => 'in'])->assertJson(['status' => 'expired']);

        $this->postJson(route('kiosk.unlock'), ['pin' => '551177'])->assertOk();
        $this->travel(91)->seconds();
        $this->postJson(route('kiosk.punch'), ['child_id' => $c->id, 'direction' => 'in'])->assertJson(['status' => 'expired']);
        $this->assertSame(0, Attendance::where('child_id', $c->id)->count());
    }

    public function test_j8_error_bodies_do_not_leak_child_data(): void
    {
        $c = $this->child(['first_name' => 'TEST-Err', 'alerts' => [['type' => 'allergy', 'text' => 'TEST sesame']]]);
        $other = $this->child(['first_name' => 'TEST-Err2', 'classroom' => 'Infant', 'classroom_override' => 'Infant', 'alerts' => [['type' => 'allergy', 'text' => 'TEST kiwi']]]);

        $nf = $this->actingAs($this->director)->get('/children/99999999');
        $nf->assertNotFound();
        $this->assertStringNotContainsString('vendor/laravel', $nf->getContent());

        $forbidden = $this->actingAs($this->teacher)->get(route('children.show', $other));
        $forbidden->assertForbidden();
        $this->assertStringNotContainsString('TEST-Err2', $forbidden->getContent());
        $this->assertStringNotContainsString('TEST kiwi', $forbidden->getContent());

        $json = $this->actingAs($this->teacher)->putJson(route('children.health', $other), ['doctor' => 'x']);
        $this->assertStringNotContainsString('TEST kiwi', $json->getContent());
    }

    /** Spec §4: teacher "read basic info for own room only". Can a teacher lift a court-order restriction or drop an allergy? */
    public function test_j9_teacher_cannot_lift_a_restriction_or_remove_an_allergy(): void
    {
        $c = $this->child(['first_name' => 'TEST-Restr', 'alerts' => [['type' => 'allergy', 'text' => 'TEST peanuts']]]);
        $dad = Person::create(['name' => 'TEST-Dad-Barred', 'cell' => '7165550120']);
        ChildPerson::create(['child_id' => $c->id, 'person_id' => $dad->id, 'relationship' => 'Father', 'can_pickup' => true, 'restriction' => 'TEST court order']);

        $this->actingAs($this->teacher)->post(route('children.people.flags', [$c, $dad]), ['relationship' => 'Father', 'can_pickup' => 1, 'restriction' => '']);
        $this->actingAs($this->teacher)->delete(route('children.alerts.destroy', [$c, 0]));

        $found = [];
        if (ChildPerson::where('child_id', $c->id)->where('person_id', $dad->id)->value('restriction') === null) {
            $found[] = 'teacher lifted the court-order restriction; mayCollect now '.var_export($dad->mayCollect($c), true);
        }
        if (($c->fresh()->alerts ?? []) === []) {
            $found[] = 'teacher removed the only allergy alert';
        }
        $this->assertSame([], $found, implode("\n", $found));
    }

    /* ======================= helpers ======================= */

    private function child(array $attributes = []): Child
    {
        $lan = (string) $this->lan++;

        return Child::create($attributes + [
            'lan' => $lan,
            'status' => 'Active',
            'first_name' => 'TEST-Kid'.$lan,
            'last_name' => 'TEST-Fam'.$lan,
            'classroom' => 'Toddler',
            'classroom_override' => 'Toddler',
            'birth_date' => '2024-04-05',
            'dob' => '2024-04-05',
        ]);
    }

    private function guardian(Child $child, string $name, string $cell): Person
    {
        $p = Person::create(['name' => $name, 'cell' => $cell]);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $p->id, 'relationship' => 'Mother', 'is_guardian' => true, 'can_pickup' => true, 'gets_texts' => true]);

        return $p;
    }

    private function sign(Child $child, string $date): void
    {
        Attendance::create([
            'child_id' => $child->id, 'attendance_date' => $date, 'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' 08:00', config('app.timezone')),
            'signed_out_at' => Carbon::parse($date.' 16:30', config('app.timezone')),
        ]);
    }
}
