<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\KioskEvent;
use App\Models\Setting;
use App\Models\StaffDevice;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\DashboardSnapshot;
use App\Services\StaffPresence;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who's where (HANDOFF §5.3.1), the ratios reading from it, and the
 * closing-time safety net (§5.3.2).
 *
 * The RULES:
 *  - Ratios count only people checked into a room.
 *  - Not clocked in says why: late only past five minutes, time off never late.
 *  - At closing, whoever is still on the clock is flagged and taken out of
 *    their room; no clock-out punch is invented.
 */
class StaffPresenceBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:30:00', config('app.timezone')));
        config(['daycare.kiosk.enabled' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_the_board_says_who_is_where_and_why_the_rest_are_not(): void
    {
        $presence = app(StaffPresence::class);
        Setting::put('rooms.PreK.ratio', 7);
        $this->childrenIn('PreK', 8);

        // Each arrival at its own time, because "since" is read off the
        // check-in and the check-in is stamped with now().
        $lana = $this->teacher('Lana Ortiz', 'PreK');
        $this->travelTo(now()->setTime(7, 12));
        $this->clockIn($lana, '07:12');
        $presence->checkIn($lana, 'PreK', StaffRoomCheckin::VIA_DOOR);

        $evana = $this->teacher('Evana Roy', 'Infant');
        $this->travelTo(now()->setTime(7, 28));
        $this->clockIn($evana, '07:28');
        $presence->checkIn($evana, 'Infant', StaffRoomCheckin::VIA_DOOR);

        $denise = $this->teacher('Denise Park', 'UPK-4');
        $this->travelTo(now()->setTime(7, 57));
        $this->clockIn($denise, '07:57');
        $presence->checkIn($denise, null, StaffRoomCheckin::VIA_DOOR);

        $this->travelTo(now()->setTime(10, 15));
        app(TimeClock::class)->punch($evana, TimePunch::BREAK_START, now());
        $presence->leave($evana, StaffRoomCheckin::ENDED_BREAK);
        $this->travelTo(now()->setTime(10, 30));

        $late = $this->teacher('Frenci Ahmed', 'UPK-4');
        $this->shift($late, 9 * 60);
        $due = $this->teacher('LaQuora Hill', 'PreK');
        $this->shift($due, 10 * 60 + 45);
        $off = $this->teacher('Zameera Khan', 'Transition');
        $this->shift($off, 15 * 60);
        StaffTimeOff::create(['user_id' => $off->id, 'date' => '2026-10-15', 'kind' => 'until', 'from_minute' => 15 * 60, 'reason' => 'appointment', 'paid' => false, 'created_by' => $this->admin->id]);
        $allDay = $this->teacher('Tammy Lee', 'Toddler');
        StaffTimeOff::create(['user_id' => $allDay->id, 'date' => '2026-10-15', 'kind' => 'all', 'reason' => 'sick', 'paid' => true, 'created_by' => $this->admin->id]);
        $gone = $this->teacher('Mary Jo Bell', 'Toddler');
        $this->clockIn($gone, '06:55');
        $this->travelTo(now()->setTime(10, 2));
        app(TimeClock::class)->punch($gone, TimePunch::OUT, now());
        $this->travelTo(now()->setTime(10, 30));
        $unscheduled = $this->teacher('Chandler Ross', 'Transition');

        $board = $presence->board();

        $prek = collect($board['rooms'])->firstWhere('room', 'PreK');
        $this->assertSame(['1 teacher · need 2 · 8 kids', 'bad'], [$prek['label'], $prek['tone']]);
        $this->assertSame([['name' => 'Lana Ortiz', 'since' => '7:12 AM']], $prek['people']);
        $this->assertSame('no children', collect($board['rooms'])->firstWhere('room', 'Infant')['label']);

        $this->assertSame([['name' => 'Evana Roy', 'since' => '10:15 AM', 'last' => 'Infant']], $board['on_break']);
        $this->assertSame([['name' => 'Denise Park', 'since' => 'since 7:57 AM']], $board['office']);

        $notes = collect($board['not_in'])->mapWithKeys(fn ($p) => [$p['name'] => [$p['note'], $p['tone']]])->all();
        $this->assertSame(['Late · due 9:00 AM', 'bad'], $notes['Frenci Ahmed']);
        $this->assertSame(['Due 10:45 AM', 'mut'], $notes['LaQuora Hill']);
        // RULE: time off is never late.
        $this->assertSame(['Time off until 3:00 PM', 'info'], $notes['Zameera Khan']);
        $this->assertSame(['Off today', 'info'], $notes['Tammy Lee']);
        $this->assertSame(['Clocked out 10:02 AM', 'mut'], $notes['Mary Jo Bell']);
        $this->assertSame(['Not scheduled', 'mut'], $notes['Chandler Ross']);

        $this->actingAs($this->admin)->get(route('staff.presence'))
            ->assertOk()
            ->assertSee('Who’s where right now')
            ->assertSee('1 teacher · need 2 · 8 kids')
            ->assertSee('Time off until 3:00 PM')
            ->assertSee('Late · due 9:00 AM');
    }

    public function test_the_dashboard_ratios_count_only_people_checked_into_the_room(): void
    {
        Setting::put('rooms.Toddler.ratio', 6);
        $this->childrenIn('Toddler', 10);
        $presence = app(StaffPresence::class);

        // Rostered for Toddler, on the clock — but checked into PreK.
        $elsewhere = $this->teacher('Priya Raman', 'Toddler');
        $this->shift($elsewhere, 7 * 60, 'Toddler');
        $this->clockIn($elsewhere, '07:00');
        $presence->checkIn($elsewhere, 'PreK', StaffRoomCheckin::VIA_TABLET);

        // In the office: on the clock, in nobody's ratio.
        $office = $this->teacher('Denise Park', 'Toddler');
        $this->clockIn($office, '07:00');
        $presence->checkIn($office, null, StaffRoomCheckin::VIA_DOOR);

        // Actually in Toddler.
        $here = $this->teacher('Sultana Begum', 'Infant');
        $this->clockIn($here, '07:00');
        $presence->checkIn($here, 'Toddler', StaffRoomCheckin::VIA_DOOR);

        // Never placed at all — the app's own clock. Read off the roster as before.
        $legacy = $this->teacher('Noor Haddad', 'Toddler');
        $this->clockIn($legacy, '07:00');

        $toddler = collect(app(DashboardSnapshot::class)->for($this->admin)['ratios'])->firstWhere('room', 'Toddler');

        // Ten toddlers at 1:6 need two. Priya (checked into PreK) and Denise
        // (office) count nowhere; Sultana (checked in here) and Noor (never
        // placed, so read off her home room) do.
        $this->assertSame(['covered', 10, 2, 2], [$toddler['tone'], $toddler['children'], $toddler['staff'], $toddler['needed']]);
        $this->assertSame('Covered', $toddler['status']);
    }

    public function test_at_closing_whoever_is_still_on_the_clock_is_flagged_and_taken_out_of_the_room(): void
    {
        $presence = app(StaffPresence::class);
        $lana = $this->teacher('Lana Ortiz', 'PreK');
        $this->clockIn($lana, '07:12');
        $presence->checkIn($lana, 'PreK', StaffRoomCheckin::VIA_DOOR);

        $gone = $this->teacher('Mary Jo Bell', 'Toddler');
        $this->clockIn($gone, '06:55');
        app(TimeClock::class)->punch($gone, TimePunch::OUT, now());

        $this->travelTo(now()->setTime(18, 15));
        $this->artisan('staff:close-day')->expectsOutputToContain('1 still on the clock at closing')->assertSuccessful();

        $this->assertDatabaseHas('staff_room_checkins', ['user_id' => $lana->id, 'classroom' => 'PreK', 'ended_reason' => StaffRoomCheckin::ENDED_CLOSING]);
        $this->assertDatabaseHas('kiosk_events', ['kind' => KioskEvent::FLAG, 'user_id' => $lana->id, 'text' => 'Lana Ortiz still on the clock at closing — office to fix the day']);
        $this->assertSame(1, KioskEvent::flags()->count(), 'Somebody who clocked out is not flagged.');

        // RULE: no clock-out punch is invented; the director fixes the day.
        $this->assertSame(0, TimePunch::where('user_id', $lana->id)->where('type', TimePunch::OUT)->count());
        $this->assertSame(TimeClock::WORKING, app(TimeClock::class)->state($lana, '2026-10-15'));
        $this->assertSame(0, StaffRoomCheckin::open()->count());
    }

    public function test_a_flag_can_be_seen_to_and_stays_in_the_feed(): void
    {
        $lana = $this->teacher('Lana Ortiz', 'PreK');
        $flag = app(StaffPresence::class)->flag($lana, 'Ratio: PreK short after Lana Ortiz left', 'PreK');

        $this->actingAs($this->admin)->post(route('staff.presence.resolve', $flag))->assertRedirect(route('staff.presence'));

        $this->assertNotNull($flag->fresh()->resolved_at);
        $this->assertSame($this->admin->id, $flag->fresh()->resolved_by);
        $this->assertSame(0, KioskEvent::open()->count());
        $this->assertSame(1, KioskEvent::count());
    }

    public function test_the_board_is_the_director_s(): void
    {
        $teacher = $this->teacher('Lana Ortiz', 'PreK');

        $this->get(route('staff.presence'))->assertRedirect(route('login'));
        $this->actingAs($teacher)->get(route('staff.presence'))->assertForbidden();
    }

    /**
     * Ray (28 Oct 2026): "a link to the kiosk above the login details, as in
     * my draft". It sits above the user card, opens Who's where, and the
     * board links on to the clock and to pairing. A teacher, who cannot open
     * the board, is not offered it.
     */
    public function test_the_kiosks_link_sits_above_the_login_details_for_a_director(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $link = strpos($html, 'data-kiosks-link');
        $this->assertNotFalse($link);
        $this->assertLessThan(strpos($html, 'title="My profile"'), $link, 'the Kiosks link comes before the login details');
        $this->assertStringContainsString('href="'.route('staff.presence').'" data-kiosks-link', $html);

        $board = $this->actingAs($this->admin)->get(route('staff.presence'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('clock.kiosk').'" target="_blank"', $board);
        // No "Pair a kiosk" any more (29 Oct 2026): screens pair themselves.
        $this->assertStringNotContainsString('data-pair-kiosk', $board);

        $teacher = $this->teacher('Lana Ortiz', 'PreK');
        $this->assertStringNotContainsString('data-kiosks-link', $this->actingAs($teacher)->get(route('dashboard'))->getContent());
    }

    /* ---- helpers ---- */

    private function teacher(string $name, string $room): User
    {
        return User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $room]);
    }

    private function clockIn(User $user, string $time): void
    {
        app(TimeClock::class)->punch($user, TimePunch::IN, Carbon::parse('2026-10-15 '.$time, config('app.timezone')));
    }

    private function shift(User $user, int $startsAt, string $room = 'PreK'): void
    {
        StaffShift::create(['week_start' => '2026-10-12', 'user_id' => $user->id, 'shift_date' => '2026-10-15', 'day' => 4, 'starts_at' => $startsAt, 'ends_at' => $startsAt + 8 * 60, 'classroom' => $room, 'role' => 'STAFF']);
    }

    private function childrenIn(string $room, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $child = Child::create(['lan' => $room.$i, 'first_name' => "Kid$i", 'last_name' => $room, 'status' => 'Active', 'classroom' => $room]);
            Attendance::create(['child_id' => $child->id, 'attendance_date' => '2026-10-15', 'session' => 'FULL', 'signed_in_at' => Carbon::parse('2026-10-15 07:40', config('app.timezone'))->addMinutes($i)]);
        }
    }
}
