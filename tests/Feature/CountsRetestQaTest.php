<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\Setting;
use App\Models\User;
use App\Services\CentreSettings;
use App\Services\DashboardSnapshot;
use App\Services\FloorPlan;
use App\Services\RoomCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesChildrenReadyForActive;
use Tests\TestCase;

/**
 * QA retest, 16 Nov 2026.
 *
 *  1/3  Director Attendance's room chips count the children on the roll
 *       that day (RoomCounts::isEnrolled) — "All 0/118" sat beside "117
 *       enrolled" because the chips counted every row of the week.
 *  1/2  The Children list's Active tab counts the status; when that is more
 *       than are enrolled today (Pharaoh: Active, last day Nov 6) it says so.
 *  4    UPK-4 and School Age share one room of 20: places are checked for
 *       the space, once, while each class keeps its ratio.
 *  5    An edit that makes a record match another child is warned about
 *       too; an edit that leaves the name alone is not.
 *
 * The day: Monday 16 Nov 2026, 10 in the morning.
 */
class CountsRetestQaTest extends TestCase
{
    use MakesChildrenReadyForActive;
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private User $admin;

    private int $lan = 8100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:00:00', config('app.timezone')));
    }

    /* ---- 1 and 3: the register's chips and its summary agree ---- */

    public function test_the_register_room_chips_count_only_children_on_the_roll(): void
    {
        $this->child('Pia', 'PreK', '2023-03-01');
        $left = $this->child('Lef', 'PreK', '2023-03-01', ['enrolled_on' => '2026-04-20', 'withdrawn_on' => '2026-11-06', 'withdrawal_reason' => 'Changed provider']);

        $page = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertSame(1, $page->viewData('dayCounts')['enrolled']);

        // The child whose last day was before this week is not a row at all
        // (Child::onRollDuring) — "Showing all 118" under "117 enrolled".
        $this->assertFalse(collect($page->viewData('children'))->contains('id', $left->id));
        $this->assertFalse(RoomCounts::isEnrolled($left, self::MONDAY));

        // The chips and the footer count by the header's rule, not every row.
        $html = $page->getContent();
        $this->assertStringContainsString("x-text=\"centreIn + '/' + centreCount\"", $html);
        $this->assertStringNotContainsString("centreIn + '/' + 2", $html);
        $this->assertStringContainsString('roomCount(room) { return this.childrenData.filter(child => child.classroom === room && this.counted(child)).length; }', $html);
        $this->assertStringContainsString('x-text="footerText" data-register-footer', $html);
        $this->assertStringNotContainsString('Showing all <span', $html);

        // Anybody who attended that week is still a row, whatever the dates.
        \App\Models\Attendance::create(['child_id' => $left->id, 'attendance_date' => self::MONDAY, 'session' => 'FULL', 'signed_in_at' => now()]);
        $this->assertTrue(collect($this->actingAs($this->admin)->get(route('attendance.index'))->viewData('children'))->contains('id', $left->id));
    }

    /* ---- 1: one rule on the Children page too ---- */

    public function test_the_children_tabs_count_by_the_same_rule_as_every_other_page(): void
    {
        $this->child('Pia', 'PreK', '2023-03-01');
        $left = $this->child('Lef', 'PreK', '2023-03-01', ['enrolled_on' => '2026-04-20', 'withdrawn_on' => '2026-11-06', 'withdrawal_reason' => 'Changed provider']);
        $starts = $this->child('Sta', 'PreK', '2023-03-01', ['enrolled_on' => '2026-11-20']);
        $this->child('Old', 'PreK', '2023-03-01', ['status' => 'Inactive', 'withdrawn_on' => '2026-06-01']);

        $page = $this->actingAs($this->admin)->get(route('children.index'))->assertOk();

        // Active is enrolled today: the dashboard's number, and the room chips with it.
        $dashboard = app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true);
        $this->assertSame(1, $dashboard['enrolled']);
        $this->assertSame(['Active' => 1, 'Pending' => 1, 'Inactive' => 2], $page->viewData('statusCounts')->all());
        $this->assertSame(1, $page->viewData('roomCounts')['PreK']);
        $this->assertSame(['Pia'], $page->viewData('children')->pluck('first_name')->all());

        // Past the last day: with the leavers, flagged, until the nightly sync moves him.
        $withdrawn = $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Inactive']))->assertOk()
            ->assertSee('Left Nov 6 — status not updated');
        $this->assertTrue($withdrawn->viewData('children')->contains('id', $left->id));

        // A first day still to come: with Pending.
        $pending = $this->actingAs($this->admin)->get(route('children.index', ['status' => 'Pending']))->assertOk();
        $this->assertTrue($pending->viewData('children')->contains('id', $starts->id));

        // The register agrees.
        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertSame(1, $register->viewData('dayCounts')['enrolled']);
        $this->assertSame(1, RoomCounts::byRoom(self::MONDAY)['PreK']['enrolled']);
    }

    /* ---- 4: a shared room is checked once, for the space ---- */

    public function test_upk4_and_school_age_are_one_space_by_default(): void
    {
        $centre = app(CentreSettings::class);

        $this->assertSame('UPK-4', $centre->sharesWith('School Age'));
        $this->assertNull($centre->sharesWith('UPK-4'));
        $this->assertSame(['UPK-4', 'School Age'], $centre->space('School Age'));
        $this->assertSame(['UPK-4', 'School Age'], $centre->space('UPK-4'));
        $this->assertSame(['PreK'], $centre->space('PreK'));
        $this->assertSame(20, $centre->spaceCapacity('School Age'));
        $this->assertSame('UPK-4 + School Age (shared room)', $centre->spaceLabel('School Age'));

        // A saved setting wins over config — and can take the sharing away.
        Setting::put('rooms.School Age.shares_with', 'none');
        $this->assertNull($centre->sharesWith('School Age'));
        $this->assertSame(['School Age'], $centre->space('School Age'));
    }

    public function test_a_shared_room_is_warned_about_once_with_the_combined_figures(): void
    {
        // 12 in UPK-4 and 12 in School Age: neither over 20 alone, 24 together.
        foreach (range(1, 12) as $i) {
            $this->child('Up'.$i, 'UPK-4', '2022-01-01');
            $this->child('Sa'.$i, 'School Age', '2019-01-01');
        }
        $expected = 'UPK-4 + School Age (shared room): Over capacity: 24 enrolled and 24 expected today for 20 places';

        $warnings = RoomCounts::capacityWarnings(RoomCounts::byRoom(self::MONDAY), app(CentreSettings::class));
        $this->assertSame($expected, $warnings['UPK-4']);
        $this->assertSame($expected, $warnings['School Age']);
        $this->assertNull($warnings['PreK']);

        // Dashboard: said once, on the first room of the space; ratios kept per class.
        $ratios = collect(app(DashboardSnapshot::class)->for($this->admin, wholeBuilding: true)['ratios'])->keyBy('room');
        $this->assertSame($expected, $ratios['UPK-4']['capacityWarning']);
        $this->assertNull($ratios['School Age']['capacityWarning']);
        $this->assertSame([10, 15], [$ratios['UPK-4']['ratio'], $ratios['School Age']['ratio']]);
        $dashboard = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($dashboard, 'UPK-4 + School Age (shared room)'));

        // Floor plan: both panels carry the space's warning and its places free.
        $rooms = collect(app(FloorPlan::class)->for($this->admin)['rooms'])->keyBy('room');
        $this->assertSame($expected, $rooms['UPK-4']['capacityWarning']);
        $this->assertSame($expected, $rooms['School Age']['capacityWarning']);
        $this->assertSame(['School Age'], $rooms['UPK-4']['sharedWith']);
        $this->assertSame(20 - $rooms['UPK-4']['in'] - $rooms['School Age']['in'], $rooms['School Age']['placesFree']);
        $this->assertSame(['label' => 'UPK-4 + School Age (shared room)', 'in' => 0, 'capacity' => 20, 'first' => true], $rooms['UPK-4']['space']);
        $this->assertFalse($rooms['School Age']['space']['first']);
        $this->assertSame([10, 15], [$rooms['UPK-4']['ratio'], $rooms['School Age']['ratio']]);

        // The cards: one capacity line and one ⚠ for the space, not "cap 20 · ⚠ Over-booked" twice.
        $plan = $this->actingAs($this->admin)->get(route('floor-plan.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($plan, 'shared room 0 of 20'));
        $this->assertStringContainsString('see UPK-4 for the shared room', $plan);
        $this->assertSame(1, substr_count($plan, 'data-room-overbooked'));
        $this->assertStringContainsString('· shares with School Age', $plan);

        // Settings: one line for the space, and the shared room noted.
        $centre = app(CentreSettings::class);
        $this->assertSame(1, collect($centre->warnings())->filter(fn ($w) => str_contains($w, '(shared room)'))->count());
        $this->assertSame('UPK-4', collect($centre->rooms())->firstWhere('room', 'School Age')['shares_with']);

        // Director Attendance: the chip of each room in the space wears it.
        $register = $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk();
        $this->assertSame($expected, $register->viewData('roomWarnings')['School Age']);
    }

    public function test_a_shared_room_under_its_places_is_not_warned_although_each_alone_would_fit(): void
    {
        foreach (range(1, 9) as $i) {
            $this->child('Up'.$i, 'UPK-4', '2022-01-01');
            $this->child('Sa'.$i, 'School Age', '2019-01-01');
        }

        $warnings = RoomCounts::capacityWarnings(RoomCounts::byRoom(self::MONDAY), app(CentreSettings::class));
        $this->assertNull($warnings['UPK-4']);
        $this->assertNull($warnings['School Age']);
    }

    /* ---- 5: an edit that makes a duplicate ---- */

    public function test_an_edit_that_makes_a_record_match_another_child_is_warned_about(): void
    {
        $jace = Child::create(['lan' => '10102', 'first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '2020-01-07', 'dob' => '2020-01-07']);
        $other = Child::create(['lan' => '10200', 'first_name' => 'Jake', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '2020-01-07', 'dob' => '2020-01-07']);

        $form = fn (array $over) => $over + ['first_name' => 'Jake', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '01/07/2020'];

        // Renamed to " jace " — the same child as LAN 10102: held.
        $this->actingAs($this->admin)->put(route('children.update', $other), $form(['first_name' => ' jace ']))
            ->assertSessionHasErrors('duplicate')
            ->assertSessionHas('duplicate_of', $jace->id);
        $this->assertSame('Jake', $other->fresh()->first_name);

        // Save anyway: saved.
        $this->actingAs($this->admin)->put(route('children.update', $other), $form(['first_name' => 'Jace', 'confirm_duplicate' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Jace', $other->fresh()->first_name);

        // Now both are Jace Miller: an edit that leaves the name and birthday
        // alone is not warned on every save, and a record never matches itself.
        $this->actingAs($this->admin)->put(route('children.update', $jace), ['first_name' => 'Jace', 'last_name' => 'Miller', 'status' => 'Pending', 'birth_date' => '01/07/2020', 'nickname' => 'J'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Child::findDuplicate('Jace', 'Miller', '2020-01-07', $jace->id)->is($other));        $this->assertTrue(Child::findDuplicate('Jace', 'Miller', '2020-01-07', $other->id)->is($jace));
    }

    private function child(string $first, string $room, string $dob, array $extra = []): Child
    {
        return Child::create($extra + [
            'lan' => (string) $this->lan++,
            'first_name' => $first,
            'last_name' => 'TEST-'.$first,
            'status' => 'Active',
            'classroom' => $room,
            'classroom_override' => $room,
            'dob' => $dob,
            'drop_off_time' => '08:00',
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }
}
