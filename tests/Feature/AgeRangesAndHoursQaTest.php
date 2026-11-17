<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\RoomSchedule;
use App\Models\User;
use App\Services\CentreSettings;
use App\Services\ClassroomAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Second QA brief, Nov 2026.
 *
 *  4  Child hours past when the room or the centre is open: said on the
 *     record and the form for as long as they stand, and listed on Rooms &
 *     hours. Saved, not refused.
 *  6  Age against room: one rule (ClassroomAssignment::ageFits), ranges
 *     min inclusive / max exclusive in months that join end to end, labels
 *     worked out from the same numbers, and the ⚠ shown even when the room
 *     was set by hand.
 *
 * The day: Tuesday 17 Nov 2026.
 */
class AgeRangesAndHoursQaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $lan = 8100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo(Carbon::parse('2026-11-17 09:00:00', config('app.timezone')));
    }

    /* ---- 6: age ranges ---- */

    public function test_the_ranges_join_end_to_end_with_no_gap(): void
    {
        $bands = ClassroomAssignment::BANDS;

        $this->assertSame(6, $bands[0]['min_weeks']);
        foreach (array_slice($bands, 1) as $i => $band) {
            $this->assertSame($bands[$i]['max_months'], $band['min_months'], "{$bands[$i]['room']} ends where {$band['room']} starts");
        }
        $this->assertSame(ClassroomAssignment::AGES_OUT_AT_YEARS * 12, end($bands)['max_months']);
    }

    public function test_min_is_inclusive_and_max_exclusive(): void
    {
        $today = Carbon::today();
        $born = fn (int $months, int $days = 0) => $today->copy()->subMonthsNoOverflow($months)->subDays($days);

        // The Infant / Transition seam: no gap between "18 mo" and "19 mo".
        $this->assertTrue(ClassroomAssignment::ageFits('Infant', $born(18, 20)));
        $this->assertFalse(ClassroomAssignment::ageFits('Infant', $born(19)));
        $this->assertTrue(ClassroomAssignment::ageFits('Transition', $born(19)));

        // 4y 0m is out of PreK and into UPK-4 on the birthday.
        $this->assertTrue(ClassroomAssignment::ageFits('PreK', $born(47, 25)));
        $this->assertFalse(ClassroomAssignment::ageFits('PreK', $born(48)));
        $this->assertTrue(ClassroomAssignment::ageFits('UPK-4', $born(48)));

        // 2y 10m is under PreK's minimum.
        $this->assertFalse(ClassroomAssignment::ageFits('PreK', $born(34)));

        // 5y 9m fits UPK-4, which runs to the sixth birthday.
        $this->assertTrue(ClassroomAssignment::ageFits('UPK-4', $born(69)));
        $this->assertFalse(ClassroomAssignment::ageFits('UPK-4', $born(72)));

        // Under six weeks, and twelve: no room.
        $this->assertFalse(ClassroomAssignment::ageFits('Infant', $today->copy()->subWeeks(5)));
        $this->assertFalse(ClassroomAssignment::ageFits('School Age', $born(144)));
        $this->assertFalse(ClassroomAssignment::ageFits('Nowhere', $born(30)));
    }

    public function test_the_automatic_room_is_the_one_room_the_rule_says_yes_to(): void
    {
        $today = Carbon::today();

        // Every fortnight from birth to past twelve: exactly one room fits, and it is the automatic one.
        for ($days = 0; $days <= 365 * 13; $days += 14) {
            $dob = $today->copy()->subDays($days);
            $fits = array_values(array_filter(ClassroomAssignment::rooms(), fn ($room) => ClassroomAssignment::ageFits($room, $dob)));

            $this->assertLessThanOrEqual(1, count($fits), "born {$dob->toDateString()}");
            $this->assertSame($fits[0] ?? null, ClassroomAssignment::automaticFor($dob), "born {$dob->toDateString()}");
        }
    }

    public function test_the_labels_say_the_rule(): void
    {
        $this->assertSame(
            ['6 wk–under 19 mo', '19–under 24 mo', '24–under 36 mo', '3–under 4 yrs', '4–under 6 yrs', '6–under 12 yrs'],
            array_map(fn ($room) => ClassroomAssignment::ageLabel($room), ClassroomAssignment::rooms()),
        );

        foreach (ClassroomAssignment::rooms() as $room) {
            $this->assertSame(ClassroomAssignment::ageLabel($room), app(CentreSettings::class)->ages($room));
        }

        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()
            ->assertSee('3–under 4 yrs')->assertSee('4–under 6 yrs');
    }

    public function test_a_room_set_by_hand_below_the_age_range_is_still_warned(): void
    {
        // 2y 10m, moved up to PreK by hand (LAN 10024 in the brief).
        $child = $this->child('Toby', '2024-01-10');
        $child->forceFill(['classroom_override' => 'PreK'])->save();
        $child = $child->fresh();

        $this->assertSame('PreK', $child->classroom);
        $fit = $child->roomFit();
        $this->assertSame('mismatch', $fit['state']);
        $this->assertTrue($fit['by_hand']);
        $this->assertSame('2y 10m — PreK is for 3–under 4 yrs; by age, Toddler (room set by hand).', $fit['text']);

        // The record says it beside the room, and the register draws ⚠ and ✎ together.
        $this->actingAs($this->admin)->get(route('children.show', $child))->assertOk()
            ->assertSee('data-age-warning', false)->assertSee($fit['text']);
        $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()
            ->assertSee("child.classroom_override ? '⚠✎' : '⚠'", false);

        // Nothing moved: the room is still the one set by hand.
        ClassroomAssignment::syncAll();
        $this->assertSame('PreK', $child->fresh()->classroom);
    }

    public function test_four_years_in_prek_is_warned_and_five_years_nine_in_upk4_is_not(): void
    {
        // 4y 0m left in PreK (LAN 10025 in the brief): the rule says UPK-4, and now the label agrees.
        $four = $this->child('Ada', '2022-11-10');
        Child::whereKey($four->id)->update(['classroom' => 'PreK']);
        $fit = $four->fresh()->roomFit();
        $this->assertSame('mismatch', $fit['state']);
        $this->assertStringContainsString('PreK is for 3–under 4 yrs', $fit['text']);

        $older = $this->child('Ben', '2021-02-10');
        $this->assertSame('5y 9m', $older->fresh()->ageInWords());
        $this->assertSame('UPK-4', $older->fresh()->classroom);
        $this->assertSame('ok', $older->fresh()->roomFit()['state']);

        $this->actingAs($this->admin)->get(route('children.show', $older))->assertOk()->assertDontSee('data-age-warning', false);
    }

    /* ---- 4: hours outside when the centre is open ---- */

    public function test_hours_past_closing_are_said_on_the_record_the_form_and_rooms_and_hours(): void
    {
        $late = $this->child('Late', '2020-01-07', ['drop_off_time' => '07:00', 'pick_up_time' => '20:00']);
        $fine = $this->child('Fine', '2020-01-07', ['drop_off_time' => '08:00', 'pick_up_time' => '17:00']);
        $expected = 'Hours 7:00 AM – 8:00 PM fall outside when the center is open (7:00 AM – 6:00 PM). Check the times.';

        // Not only on the save that made it: every time the record is opened.
        $this->actingAs($this->admin)->get(route('children.show', $late))->assertOk()
            ->assertSee('data-hours-outside', false)->assertSee($expected);
        $this->actingAs($this->admin)->get(route('children.show', $fine))->assertOk()
            ->assertDontSee('data-hours-outside', false);

        // The form, beside the times, with the centre's own closing hour.
        $this->actingAs($this->admin)->get(route('children.edit', $late))->assertOk()
            ->assertSee('data-hours-outside', false)->assertSee($expected)
            ->assertSee('The center closes at 6:00 PM.');

        // Rooms & hours lists who, by LAN, so they can be fixed.
        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()
            ->assertSee('data-outside-hours-child="'.$late->lan.'"', false)
            ->assertDontSee('data-outside-hours-child="'.$fine->lan.'"', false)
            ->assertSee('1 child has hours outside when the center is open');

        // Nothing about the child's hours was changed.
        $this->assertSame('20:00', substr((string) $late->fresh()->pick_up_time, 0, 5));
    }

    public function test_a_room_set_to_close_after_the_centre_does_not_excuse_late_hours(): void
    {
        RoomSchedule::create(['room' => 'School Age', 'opens_at' => '07:00', 'closes_at' => '20:00']);

        $this->assertSame(
            'Hours 7:00 AM – 8:00 PM fall outside when the center is open (7:00 AM – 6:00 PM). Check the times.',
            Child::hoursOutsideOpening('07:00', '20:00', 'School Age'),
        );

        // A room that keeps shorter hours than the centre is the one named.
        RoomSchedule::create(['room' => 'Infant', 'opens_at' => '07:30', 'closes_at' => '17:30']);
        $this->assertSame(
            'Hours 7:00 AM – 5:00 PM fall outside when Infant is open (7:30 AM – 5:30 PM). Check the times.',
            Child::hoursOutsideOpening('07:00', '17:00', 'Infant'),
        );
        $this->assertNull(Child::hoursOutsideOpening('08:00', '17:00', 'Infant'));
    }

    private function child(string $first, string $dob, array $extra = []): Child
    {
        return Child::create($extra + [
            'lan' => (string) $this->lan++,
            'first_name' => $first,
            'last_name' => 'TEST-'.$first,
            'status' => 'Active',
            'dob' => $dob,
            'schedule_days' => [1, 2, 3, 4, 5],
        ]);
    }
}
