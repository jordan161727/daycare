<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use App\Models\ScheduleSlot;
use App\Models\Setting;
use App\Models\StaffShift;
use App\Models\TimePunch;
use App\Models\User;
use App\Services\FloorPlan;
use App\Services\KioskAttendance;
use App\Services\TimeClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QA spec for the Floor plan screen (pasted 29 Oct 2026). The baseline is the
 * spec's table at 10:00 AM on a Thursday; each test is one case from A–J.
 * A failing assertion here is a finding, reported rather than fixed.
 */
class FloorPlanQaSpecTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-10-15';

    private const WEEK = '2026-10-12';

    private string $tz;

    private User $director;

    /** @var array<string, User> */
    private array $t = [];

    /** @var array<string, list<Child>> */
    private array $kids = [];

    private int $lan = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone');
        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00', $this->tz));
        $this->director = User::factory()->create(['role' => 'admin', 'name' => 'Ray Kader', 'job_role' => 'Director', 'must_change_password' => false]);

        // Room setup from the spec's table.
        foreach (['UPK-4' => [8, 20], 'School Age' => [10, 20], 'Infant' => [4, 8], 'Transition' => [5, 10], 'Toddler' => [5, 10], 'PreK' => [7, 22]] as $room => [$ratio, $cap]) {
            Setting::put("rooms.$room.ratio", $ratio);
            Setting::put("rooms.$room.capacity", $cap);
        }

        // Children in.
        foreach (['UPK-4' => 17, 'Infant' => 6, 'Transition' => 3, 'Toddler' => 10, 'PreK' => 7] as $room => $n) {
            foreach (range(1, $n) as $i) {
                $this->checkedIn($room, '07:30');
            }
        }
        // School Age: six out this morning, booked back at 3:00.
        foreach (range(1, 6) as $i) {
            $c = $this->child('School Age');
            $this->tick($c, 'AM');
            $this->tick($c, 'PM', '15:00');
            Attendance::create(['child_id' => $c->id, 'attendance_date' => self::DAY, 'session' => 'AM', 'signed_in_at' => $this->at('07:10'), 'signed_out_at' => $this->at('08:20')]);
        }
        // Expected 14: booked for later this morning, spread so no room passes its capacity.
        foreach (['UPK-4' => 3, 'Transition' => 4, 'PreK' => 7] as $room => $n) {
            foreach (range(1, $n) as $i) {
                $this->tick($this->child($room), 'FULL', '10:45');
            }
        }
        // Toddler: an eleventh enrolled child, not booked today.
        $this->child('Toddler');

        // Staff.
        $this->teacher('Denise', 'UPK-4', '07:00', [7, 15]);
        $this->teacher('Frenci', 'UPK-4', '07:05', [7, 15]);
        $this->teacher('Beverly', 'UPK-4', '07:30', [7.5, 15.5]);
        $this->teacher('Amanda', 'School Age', null, [14.5, 18]);
        $this->teacher('Evana', 'Infant', '06:58', [7, 15]);
        $this->teacher('Farhana', 'Infant', '07:15', [7, 15]);
        $this->teacher('Zameera', 'Transition', '07:20', [7, 15]);
        $this->teacher('Mary Jo', 'Toddler', '07:29', [7.5, 15.5]);
        $this->teacher('Sultana', 'Toddler', null, [9.5, 17.5]);
        $this->teacher('Lana', 'PreK', '07:40', [7.5, 15.5]);
        $this->teacher('LaQuora', 'PreK', '08:00', [8, 16]);
        $this->teacher('Pat', 'PreK', null, [12, 18]);
    }

    /* ================= A. Summary cards ================= */

    public function test_A1_cards_match_the_baseline(): void
    {
        $c = $this->plan()['counters'];
        $this->assertSame(['in' => 43, 'returning' => 6, 'out' => 0, 'expected' => 14, 'staff' => 9, 'short' => 1], $c);

        $this->page()->assertSeeInOrder(['Checked in', '43', 'Checked out · returning', '6', 'Checked out', '0', 'Expected', '14', 'Staff on the floor', '9', 'Rooms below ratio', '1']);
    }

    public function test_A2_checked_in_is_the_sum_of_the_rooms(): void
    {
        $p = $this->plan();
        $this->assertSame($p['counters']['in'], collect($p['rooms'])->sum('in'));
    }

    public function test_A3_staff_on_the_floor_counts_only_clocked_in_and_in_a_room(): void
    {
        // A tenth teacher, clocked in, with no room and no shift.
        $this->teacher('Nora', null, '08:30', null);

        $p = $this->plan();
        $inRooms = collect($p['rooms'])->sum('staffIn');
        $this->assertSame(9, $inRooms);
        $this->assertSame($inRooms, $p['counters']['staff'], "Staff on the floor says {$p['counters']['staff']}, rooms hold {$inRooms}");
    }

    public function test_A4_rooms_below_ratio_equals_short_badges(): void
    {
        $p = $this->plan();
        $this->assertSame(collect($p['rooms'])->where('tone', 'short')->count(), $p['counters']['short']);
        $this->assertSame(1, substr_count($this->page()->getContent(), 'data-room-tone="short"'));
    }

    public function test_A5_live_updated_time_moves_after_a_check_in(): void
    {
        $this->page()->assertSee('Live · updated 10:00 AM');
        $this->travel(3)->minutes();
        $this->checkedIn('Infant', '10:03');
        $this->page()->assertSee('Live · updated 10:03 AM');
    }

    /* ================= B. Ratio math ================= */

    public function test_B1_B2_B3_needed_is_rounded_up(): void
    {
        $r = $this->rooms();
        $this->assertSame(2, $r['Toddler']['needed'], 'Toddler 10 @1:5');
        $this->assertSame(3, $r['UPK-4']['needed'], 'UPK-4 17 @1:8');
        $this->assertSame(2, $r['Infant']['needed'], 'Infant 6 @1:4');
        $this->assertSame(1, $r['Transition']['needed'], 'Transition 3 @1:5');
        $this->assertSame(1, $r['PreK']['needed'], 'PreK 7 @1:7');
    }

    public function test_B4_infant_seven_and_eight_need_two(): void
    {
        $this->checkedIn('Infant', '09:50');
        $this->assertSame([7, 2], [$this->rooms()['Infant']['in'], $this->rooms()['Infant']['needed']]);
        $this->checkedIn('Infant', '09:55');
        $this->assertSame([8, 2], [$this->rooms()['Infant']['in'], $this->rooms()['Infant']['needed']]);
    }

    public function test_B4_a_ninth_infant_is_blocked_or_flagged(): void
    {
        $this->checkedIn('Infant', '09:50');
        $this->checkedIn('Infant', '09:55');

        // At the kiosk, as a parent would.
        $ninth = $this->child('Infant');
        $this->tick($ninth);
        $result = app(KioskAttendance::class)->punch($ninth, 'in', $this->guardian($ninth));

        $infant = $this->rooms()['Infant'];
        $blocked = ! Attendance::where('child_id', $ninth->id)->exists();
        $words = strtolower($infant['status'].' '.json_encode($infant['tip']).' '.$this->roomHtml($this->page()->getContent(), 'Infant'));
        $flagged = str_contains($words, 'over capacity') || str_contains($words, 'over cap') || str_contains($words, 'full') || $infant['tone'] === 'over';

        $this->assertTrue($blocked || $flagged, "9 infants in a room of 8: kiosk said '{$result['status']}', room shows {$infant['in']} in · cap {$infant['capacity']}, status '{$infant['status']}', free {$infant['free']}");
    }

    public function test_B5_an_empty_room_needs_nobody_and_is_not_short(): void
    {
        // School Age has 0 in this morning; Transition emptied too.
        Attendance::whereIn('child_id', collect($this->kids['Transition'])->pluck('id'))->update(['signed_out_at' => $this->at('09:59')]);
        $r = $this->rooms();
        $this->assertSame([0, 0], [$r['Transition']['in'], $r['Transition']['needed']]);
        $this->assertNotSame('short', $r['Transition']['tone']);
        $this->assertNotSame('short', $r['School Age']['tone']);
    }

    public function test_B6_one_child_needs_one(): void
    {
        $ids = collect($this->kids['Transition'])->pluck('id');
        Attendance::whereIn('child_id', $ids->slice(1))->update(['signed_out_at' => $this->at('09:59')]);
        $this->assertSame([1, 1], [$this->rooms()['Transition']['in'], $this->rooms()['Transition']['needed']]);
    }

    /* ================= C. Room statuses ================= */

    public function test_C1_every_room_has_its_baseline_badge(): void
    {
        $status = collect($this->rooms())->map(fn ($r) => $r['status'])->sortKeys()->all();
        $this->assertEquals(collect(['UPK-4' => 'Covered', 'School Age' => 'Away', 'Infant' => 'Covered', 'Transition' => 'Covered', 'Toddler' => 'Short 1', 'PreK' => '1 spare'])->sortKeys()->all(), $status);
    }

    public function test_C2_toddler_is_red_short_one_one_staff_need_two(): void
    {
        $html = $this->page()->getContent();
        $toddler = $this->roomHtml($html, 'Toddler');
        $this->assertMatchesRegularExpression('/class="room short"[^>]*data-room="Toddler"/s', $html);
        $this->assertStringContainsString('class="pill bad">Short 1<', $toddler);
        $this->assertStringContainsString('1 staff · need 2', $toddler);
    }

    public function test_C3_prek_spare_and_school_age_away_with_return_time(): void
    {
        $html = $this->page()->getContent();
        $this->assertStringContainsString('>1 spare<', $this->roomHtml($html, 'PreK'));
        $sa = $this->roomHtml($html, 'School Age');
        $this->assertStringContainsString('>Away<', $sa);
        $this->assertStringContainsString('6 checked out · returning at 3:00 PM', $sa);
    }

    public function test_C4_seat_dots_match_in_booked_and_capacity(): void
    {
        $html = $this->page()->getContent();
        $out = [];
        foreach ($this->rooms() as $name => $r) {
            $room = $this->roomHtml($html, $name);
            $dots = [substr_count($room, 'seat s-in'), substr_count($room, 'seat s-come'), substr_count($room, 'seat s-free')];
            $out[$name] = $dots;
            $this->assertSame([$r['in'], $r['booked'], $r['capacity'] - $r['in'] - $r['booked']], $dots, "$name dots");
        }
        // UPK 17+3 of 20, School Age 0 of 20, Infant 6 of 8, Transition 3+4 of 10, Toddler 10 of 10, PreK 7+7 of 22.
        $this->assertEquals(['UPK-4' => [17, 3, 0], 'School Age' => [0, 0, 20], 'Infant' => [6, 0, 2], 'Transition' => [3, 4, 3], 'Toddler' => [10, 0, 0], 'PreK' => [7, 7, 8]], $out);
    }

    public function test_C5_staff_chip_colours(): void
    {
        $html = $this->page()->getContent();
        $chip = fn (string $room, string $name) => preg_match('/<span class="dot ([a-z-]+)"><\/span>'.preg_quote($name, '/').'</', $this->roomHtml($html, $room), $m) ? $m[1] : null;

        $this->assertSame('d-in', $chip('Toddler', 'Mary'));
        $this->assertSame('d-late', $chip('Toddler', 'Sultana'), 'Sultana red after 9:30');
        $this->assertSame('d-due', $chip('PreK', 'Pat'));
        $this->assertSame('d-due', $chip('School Age', 'Amanda'));
        $this->assertSame('d-in', $chip('PreK', 'LaQuora'));
    }

    public function test_C5_sultana_turns_red_on_the_minute_with_no_grace(): void
    {
        // Observation for the report: late here has no grace period.
        $this->travelTo(Carbon::parse(self::DAY.' 09:30:00', $this->tz));
        $s = collect($this->rooms()['Toddler']['staff'])->firstWhere('name', 'Sultana');
        $this->assertSame('late', $s['status'], 'at 9:30 exactly');
    }

    /* ================= D. Live updates ================= */

    public function test_D1_a_kiosk_check_in_moves_the_room_and_the_card(): void
    {
        $child = $this->child('Transition');
        $this->tick($child);
        $result = app(KioskAttendance::class)->punch($child, 'in', $this->guardian($child));

        $p = $this->plan();
        $this->assertSame([4, 44], [collect($p['rooms'])->firstWhere('room', 'Transition')['in'], $p['counters']['in']], 'kiosk said '.$result['status']);
    }

    public function test_D2_a_school_age_child_out_for_school_is_returning(): void
    {
        $c = $this->child('School Age');
        $this->tick($c, 'AM');
        $this->tick($c, 'PM', '15:00');
        $g = $this->guardian($c);
        app(KioskAttendance::class)->punch($c, 'in', $g);
        $this->assertSame(44, $this->plan()['counters']['in']);
        app(KioskAttendance::class)->punch($c, 'out', $g);
        $this->assertSame([43, 7], [$this->plan()['counters']['in'], $this->plan()['counters']['returning']]);
    }

    public function test_D3_a_child_out_for_the_day_is_checked_out(): void
    {
        $c = $this->kids['PreK'][0];
        app(KioskAttendance::class)->punch($c, 'out', $this->guardian($c));
        $p = $this->plan();
        $this->assertSame([42, 1], [$p['counters']['in'], $p['counters']['out']]);
    }

    public function test_D4_a_teacher_clocking_out_turns_a_covered_room_short(): void
    {
        $this->punch($this->t['Denise'], '10:00', TimePunch::OUT);
        $p = $this->plan();
        $this->assertSame('Short 1', collect($p['rooms'])->firstWhere('room', 'UPK-4')['status']);
        $this->assertSame(2, $p['counters']['short']);
    }

    public function test_D5_sultana_clocking_in_covers_toddler_and_clears_the_suggestion(): void
    {
        $this->punch($this->t['Sultana'], '10:00', TimePunch::IN);
        $p = $this->plan();
        $toddler = collect($p['rooms'])->firstWhere('room', 'Toddler');
        $this->assertSame(['Covered', 0], [$toddler['status'], $p['counters']['short']]);
        $this->assertNull($toddler['tip']);
        $this->page()->assertDontSee('Move LaQuora');
    }

    /* ================= E. Room panel ================= */

    public function test_E1_E2_E3_toddler_panel_figures(): void
    {
        $r = $this->rooms()['Toddler'];
        $this->assertSame(['in' => 10, 'booked' => 0, 'returning' => 0, 'out' => 0], ['in' => $r['in'], 'booked' => $r['booked'], 'returning' => $r['returning'], 'out' => $r['out']]);
        $this->assertSame(['needed' => 2, 'staffIn' => 1, 'free' => 0, 'capacity' => 10, 'enrolled' => 11], ['needed' => $r['needed'], 'staffIn' => $r['staffIn'], 'free' => $r['free'], 'capacity' => $r['capacity'], 'enrolled' => $r['enrolled']]);

        $staff = collect($r['staff'])->keyBy('name');
        $this->assertSame(['in', '7:29 AM'], [$staff['Mary']['status'], $staff['Mary']['in']]);
        $this->assertSame(['late', '9:30'], [$staff['Sultana']['status'], $staff['Sultana']['due']]);

        // The panel builds its lines in the browser from these; the templates say:
        $html = $this->page()->getContent();
        $this->assertStringContainsString("room.needed + ' (' + room.in + ' ÷ ' + room.ratio + ', rounded up)'", $html);
        $this->assertStringContainsString("'Due ' + s.due + ' · not in'", $html);
        $this->assertStringContainsString("(s.in ? 'In ' + s.in : 'In')", $html);
    }

    public function test_E1_teacher_list_names_mary_jo_in_full(): void
    {
        $names = collect($this->rooms()['Toddler']['staff'])->pluck('name')->all();
        $this->assertContains('Mary Jo', $names, 'names shown: '.implode(', ', $names));
    }

    public function test_E4_the_explanation_names_the_short_room_the_late_and_the_spare_teacher(): void
    {
        $tip = $this->rooms()['Toddler']['tip']['text'];
        $this->assertSame("10 toddlers need 2 staff at 1:5. Sultana was due 9:30 and hasn't clocked in. PreK has 7 children and 2 staff, so LaQuora is free to cover.", $tip);
    }

    public function test_E5_every_room_panel_has_its_own_figures(): void
    {
        $r = $this->rooms();
        $this->assertSame([17, 3, 3, 0, 20], [$r['UPK-4']['in'], $r['UPK-4']['booked'], $r['UPK-4']['staffIn'], $r['UPK-4']['free'], $r['UPK-4']['capacity']]);
        $this->assertSame([0, 6, 0, 0], [$r['School Age']['in'], $r['School Age']['returning'], $r['School Age']['staffIn'], $r['School Age']['needed']]);
        $this->assertSame([6, 2, 2, 2], [$r['Infant']['in'], $r['Infant']['needed'], $r['Infant']['staffIn'], $r['Infant']['free']]);
        $this->assertSame([3, 4, 1, 3], [$r['Transition']['in'], $r['Transition']['booked'], $r['Transition']['staffIn'], $r['Transition']['free']]);
        $this->assertSame([7, 7, 2, 8], [$r['PreK']['in'], $r['PreK']['booked'], $r['PreK']['staffIn'], $r['PreK']['free']]);
    }

    /* ================= F. Suggested move ================= */

    public function test_F1_the_button_reads_as_specified(): void
    {
        $this->assertSame('Move LaQuora to Toddler until Sultana arrives', $this->rooms()['Toddler']['tip']['action']);
        $this->page()->assertSee('data-room-move', false);
    }

    public function test_F2_the_source_room_is_still_covered_after_the_move(): void
    {
        $prek = $this->rooms()['PreK'];
        $this->assertGreaterThanOrEqual($prek['needed'], $prek['staffIn'] - 1);
    }

    public function test_F3_no_spare_room_means_no_move_only_the_alert(): void
    {
        $this->punch($this->t['LaQuora'], '10:00', TimePunch::OUT);
        $tip = $this->rooms()['Toddler']['tip'];
        $this->assertNull($tip['action']);
        $this->assertStringContainsString('call a substitute', $tip['text']);
    }

    public function test_F4_two_short_rooms_one_spare_teacher_is_offered_once(): void
    {
        $this->punch($this->t['Denise'], '10:00', TimePunch::OUT); // UPK-4 short 1
        $r = $this->rooms();
        $offers = collect($r)->filter(fn ($room) => ($room['tip']['move']['user_id'] ?? null) === $this->t['LaQuora']->id)->keys()->all();
        $this->assertCount(1, $offers, 'LaQuora is offered to: '.implode(', ', $offers));
    }

    public function test_F4_the_bigger_shortfall_is_served_first(): void
    {
        // UPK-4 short 2 (two out), Toddler short 1: the one spare should go where it is needed most.
        $this->punch($this->t['Denise'], '10:00', TimePunch::OUT);
        $this->punch($this->t['Frenci'], '10:00', TimePunch::OUT);
        $r = $this->rooms();
        $this->assertSame('Short 2', $r['UPK-4']['status']);
        $this->assertNotNull($r['UPK-4']['tip']['move'] ?? null, 'UPK-4 (short 2) gets no offer; Toddler (short 1) gets: '.($r['Toddler']['tip']['action'] ?? 'none'));
    }

    public function test_F5_infant_is_never_a_source_that_would_break_one_to_four(): void
    {
        // Infant at 6 with two staff has none to spare; with a third it has one.
        $this->punch($this->t['LaQuora'], '10:00', TimePunch::OUT);
        $this->assertNull($this->rooms()['Toddler']['tip']['action']);

        $third = $this->teacher('Ines', 'Infant', '08:10', [8, 16]);
        $tip = $this->rooms()['Toddler']['tip'];
        $this->assertSame('Move Ines to Toddler until Sultana arrives', $tip['action'], 'a third infant teacher is spare');
        $after = ceil(6 / 4);
        $this->assertGreaterThanOrEqual($after, 2, 'Infant keeps 2 for 6');
    }

    /* ================= G. Approve the move ================= */

    public function test_G1_G2_G3_the_move_covers_toddler_and_keeps_the_staff_count(): void
    {
        $this->move();

        $p = $this->plan();
        $r = collect($p['rooms'])->keyBy('room');
        $laq = collect($r['Toddler']['staff'])->firstWhere('name', 'LaQuora');
        $this->assertNotNull($laq, 'LaQuora in Toddler');
        $this->assertTrue($laq['moved']);
        $this->assertSame(['Covered', 'Covered'], [$r['Toddler']['status'], $r['PreK']['status']]);
        $this->assertSame([0, 9], [$p['counters']['short'], $p['counters']['staff']]);

        $html = $this->page()->getContent();
        $this->assertMatchesRegularExpression('/<span class="dot d-moved"><\/span>LaQuora/', $this->roomHtml($html, 'Toddler'));
    }

    public function test_G4_sultana_clocking_in_sends_laquora_back_to_prek(): void
    {
        $this->move();
        $this->travel(20)->minutes();
        app(TimeClock::class)->punch(user: $this->t['Sultana'], type: TimePunch::IN, at: now());

        $r = $this->rooms();
        $this->assertNotNull(collect($r['PreK']['staff'])->firstWhere('name', 'LaQuora'), 'LaQuora back in PreK');
        $this->assertNull(collect($r['Toddler']['staff'])->firstWhere('name', 'LaQuora'));
        $this->assertSame(['Covered', '1 spare'], [$r['Toddler']['status'], $r['PreK']['status']]);
    }

    public function test_G5_the_move_records_who_approved_it_and_when(): void
    {
        $this->move();
        $float = StaffShift::where('user_id', $this->t['LaQuora']->id)->where('classroom', 'Toddler')->first();
        $this->assertNotNull($float);
        $this->assertSame($this->t['Sultana']->id, $float->covers_user_id);
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('staff_shifts');
        $who = array_values(array_intersect($columns, ['created_by', 'moved_by', 'approved_by', 'actor_id']));
        $this->assertNotEmpty($who, 'staff_shifts keeps no approver; columns: '.implode(', ', $columns));
    }

    /* ================= H. Register ================= */

    public function test_H1_open_toddler_on_the_register_lists_the_same_children(): void
    {
        $this->page()->assertSee("'Open ' + room.room + ' on the register →'", false);
        $html = $this->actingAs($this->director)->get(route('attendance.index').'?toddler')->assertOk()->getContent();
        foreach (collect($this->kids['Toddler'])->take(10) as $child) {
            $this->assertStringContainsString($child->first_name, $html);
        }
        $this->assertMatchesRegularExpression("/room:\s*'Toddler'/", $html, 'the register opens on Toddler');
    }

    /* ================= I. Edge cases ================= */

    public function test_I1_upk_and_school_age_are_counted_per_group_not_combined(): void
    {
        $p = app(FloorPlan::class)->for($this->director, 'afternoon');
        $r = collect($p['rooms'])->keyBy('room');
        // Observation: two separate checks, each at its own ratio.
        $this->assertSame(6, $r['School Age']['in']);
        $this->assertSame((int) ceil(6 / 10), $r['School Age']['needed']);
        $this->assertSame((int) ceil($r['UPK-4']['in'] / 8), $r['UPK-4']['needed']);
    }

    public function test_I2_a_teacher_in_with_no_room_is_in_no_room(): void
    {
        $this->teacher('Nora', null, '08:30', null);
        $this->assertSame(9, collect($this->rooms())->sum('staffIn'));
    }

    public function test_I3_a_teacher_is_never_in_two_rooms(): void
    {
        $this->move();
        $names = collect($this->rooms())->flatMap(fn ($r) => collect($r['staff'])->pluck('id'))->all();
        $this->assertSame(count($names), count(array_unique($names)));
    }

    public function test_I4_two_approvals_of_the_same_move_make_one_move(): void
    {
        $this->move();
        $other = User::factory()->create(['role' => 'admin', 'name' => 'Second Director', 'must_change_password' => false]);
        $this->actingAs($other)->post(route('floor-plan.move'), ['user_id' => $this->t['LaQuora']->id, 'to' => 'Toddler', 'for' => $this->t['Sultana']->id])->assertRedirect();
        $this->assertSame(1, StaffShift::where('user_id', $this->t['LaQuora']->id)->where('classroom', 'Toddler')->count());
    }

    public function test_I5_the_live_label_can_say_it_is_offline(): void
    {
        $html = $this->page()->getContent();
        $this->assertTrue(str_contains($html, 'offline') || str_contains($html, 'navigator.onLine') || str_contains($html, 'stale'), 'The Live label is static text; the page reloads itself every 60s and has no offline or stale state');
    }

    public function test_I6_the_next_day_starts_from_zero(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 07:00:00', $this->tz));
        $c = $this->plan()['counters'];
        $this->assertSame([0, 0, 0], [$c['in'], $c['returning'], $c['out']]);
    }

    /* ================= J. Access ================= */

    public function test_J1_a_teacher_sees_the_plan_but_not_the_move_button(): void
    {
        $mary = $this->t['Mary Jo'];
        $html = $this->actingAs($mary)->get(route('floor-plan.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-room-move', $html);
        $this->assertStringContainsString("'Ask the director to '", $html);
        $this->actingAs($mary)->post(route('floor-plan.move'), ['user_id' => $this->t['LaQuora']->id, 'to' => 'Toddler'])->assertForbidden();
    }

    public function test_J1_a_teacher_sees_the_same_counts_as_the_director(): void
    {
        $teacher = app(FloorPlan::class)->for($this->t['Mary Jo'])['counters'];
        $this->assertSame($this->plan()['counters'], $teacher, 'a teacher sees only their own rooms’ children');
    }

    public function test_J3_the_legend_uses_the_same_classes_as_the_rooms(): void
    {
        $html = $this->page()->getContent();
        foreach (['seat s-in', 'seat s-come', 'seat s-free', 'dot d-in', 'dot d-late', 'dot d-moved', 'dot d-due'] as $class) {
            $this->assertStringContainsString('<span class="'.$class.'"></span>', $html, $class.' in the legend');
        }
    }

    /* ================= helpers ================= */

    private function plan(): array
    {
        return app(FloorPlan::class)->for($this->director);
    }

    private function rooms(): array
    {
        return collect($this->plan()['rooms'])->keyBy('room')->all();
    }

    private function page()
    {
        return $this->actingAs($this->director)->get(route('floor-plan.index'))->assertOk();
    }

    private function roomHtml(string $html, string $room): string
    {
        $start = strpos($html, 'data-room="'.$room.'"');
        $this->assertNotFalse($start, "no $room on the map");

        return substr($html, $start, strpos($html, '</button>', $start) - $start);
    }

    private function move(): void
    {
        $this->actingAs($this->director)->post(route('floor-plan.move'), [
            'user_id' => $this->t['LaQuora']->id, 'to' => 'Toddler', 'for' => $this->t['Sultana']->id,
        ])->assertRedirect();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse(self::DAY.' '.$time, $this->tz);
    }

    private function child(string $room): Child
    {
        $n = ++$this->lan;
        $c = Child::create(['lan' => (string) $n, 'first_name' => 'Kid'.$n, 'last_name' => str_replace(['-', ' '], '', $room), 'status' => 'Active', 'classroom' => $room, 'classroom_override' => $room]);
        $this->kids[$room][] = $c;

        return $c;
    }

    private function checkedIn(string $room, string $time): Child
    {
        $c = $this->child($room);
        $this->tick($c);
        Attendance::create(['child_id' => $c->id, 'attendance_date' => self::DAY, 'session' => 'FULL', 'signed_in_at' => $this->at($time)]);

        return $c;
    }

    private function tick(Child $child, string $session = 'FULL', ?string $planned = null): void
    {
        ScheduleSlot::create(['week_start' => self::WEEK, 'child_id' => $child->id, 'slot_date' => self::DAY, 'session' => $session, 'is_scheduled' => true, 'planned_time' => $planned]);
    }

    private function guardian(Child $child): Person
    {
        $p = Person::create(['name' => 'Parent of '.$child->first_name, 'cell' => '555-'.$child->lan]);
        ChildPerson::create(['child_id' => $child->id, 'person_id' => $p->id, 'relationship' => 'Mother', 'is_guardian' => true, 'can_pickup' => true]);

        return $p;
    }

    private function teacher(string $name, ?string $home, ?string $clockIn, ?array $shift): User
    {
        $u = User::factory()->create(['role' => 'teacher', 'name' => $name, 'classroom' => $home, 'must_change_password' => false]);
        if ($shift) {
            StaffShift::create(['week_start' => self::WEEK, 'user_id' => $u->id, 'shift_date' => self::DAY, 'day' => 'THU', 'starts_at' => (int) ($shift[0] * 60), 'ends_at' => (int) ($shift[1] * 60), 'classroom' => $home, 'role' => StaffShift::ROLE_STAFF]);
        }
        if ($clockIn) {
            $this->punch($u, $clockIn, TimePunch::IN);
        }

        return $this->t[$name] = $u;
    }

    private function punch(User $u, string $time, string $type): void
    {
        TimePunch::create(['user_id' => $u->id, 'work_date' => self::DAY, 'punched_at' => $this->at($time), 'type' => $type, 'source' => 'kiosk']);
    }
}
