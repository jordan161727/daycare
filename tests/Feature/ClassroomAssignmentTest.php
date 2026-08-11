<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassroomAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-27';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand inside an editable week: rooms can only be overridden in a week
        // that has not ended.
        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /* ---- F1: the room follows the date of birth ---- */

    public function test_each_band_starts_on_the_day_the_child_reaches_it(): void
    {
        $today = Carbon::parse(self::MONDAY);

        // The boundary age belongs to the higher band, so the day before and the
        // day of a boundary must land in different rooms.
        $boundaries = [
            ['dob' => $today->copy()->subWeeks(6), 'room' => 'Infant'],
            ['dob' => $today->copy()->subWeeks(6)->addDay(), 'room' => null],
            ['dob' => $today->copy()->subMonths(18), 'room' => 'Transition'],
            ['dob' => $today->copy()->subMonths(18)->addDay(), 'room' => 'Infant'],
            ['dob' => $today->copy()->subYears(2), 'room' => 'Toddler'],
            ['dob' => $today->copy()->subYears(2)->addDay(), 'room' => 'Transition'],
            ['dob' => $today->copy()->subYears(3), 'room' => 'PreK'],
            ['dob' => $today->copy()->subYears(3)->addDay(), 'room' => 'Toddler'],
            ['dob' => $today->copy()->subYears(4), 'room' => 'UPK-4'],
            ['dob' => $today->copy()->subYears(4)->addDay(), 'room' => 'PreK'],
            ['dob' => $today->copy()->subYears(5), 'room' => 'School Age'],
            ['dob' => $today->copy()->subYears(5)->addDay(), 'room' => 'UPK-4'],
        ];

        foreach ($boundaries as $case) {
            $this->assertSame(
                $case['room'],
                ClassroomAssignment::automaticFor($case['dob']),
                'A child born '.$case['dob']->toDateString().' belongs in '.($case['room'] ?? 'no room'),
            );
        }
    }

    public function test_a_month_end_birthday_moves_room_on_the_month_end(): void
    {
        // Born on the 31st, 18 months later is the end of February. Rolling the
        // missing day forward instead would leave this child in Infant three
        // days longer than one born a day earlier.
        $this->assertSame('Infant', ClassroomAssignment::automaticFor(Carbon::parse('2025-08-31'), Carbon::parse('2027-02-27')));
        $this->assertSame('Transition', ClassroomAssignment::automaticFor(Carbon::parse('2025-08-31'), Carbon::parse('2027-02-28')));

        // A leap-day child turns two at the end of February, not in March.
        $this->assertSame('Transition', ClassroomAssignment::automaticFor(Carbon::parse('2024-02-29'), Carbon::parse('2026-02-27')));
        $this->assertSame('Toddler', ClassroomAssignment::automaticFor(Carbon::parse('2024-02-29'), Carbon::parse('2026-02-28')));
    }

    public function test_a_younger_child_is_never_in_a_higher_band(): void
    {
        $asOf = Carbon::parse('2026-08-11');
        $previous = null;

        // Every birth date across two years, including both month ends and a
        // leap day. A child born a day later can only be in the same band or a
        // lower one — never overtake the child born before them.
        for ($day = Carbon::parse('2020-01-01'); $day->lte('2021-12-31'); $day->addDay()) {
            $room = ClassroomAssignment::automaticFor($day->copy(), $asOf);
            $rank = $room === null ? -1 : ClassroomAssignment::rankOf($room);

            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous, $rank, 'a child born '.$day->toDateString().' overtook one born a day earlier');
            }

            $previous = $rank;
        }
    }

    public function test_a_child_outside_every_band_gets_no_room(): void
    {
        $today = Carbon::parse(self::MONDAY);

        // Too young for Infant, and aged out of School Age. Both are nearly
        // always a mistyped date, so neither is rounded into the nearest room.
        $this->assertNull(ClassroomAssignment::automaticFor($today->copy()->subWeeks(3)));
        $this->assertNull(ClassroomAssignment::automaticFor($today->copy()->subYears(12)));
        $this->assertSame('School Age', ClassroomAssignment::automaticFor($today->copy()->subYears(12)->addDay()));
    }

    public function test_a_child_is_filed_in_the_room_their_age_gives_them(): void
    {
        $child = $this->makeChild('Lovelace', 'Ada', '2024-01-15');

        $this->assertSame('Toddler', $child->classroom);
    }

    public function test_a_birthday_moves_the_child_without_anyone_touching_the_record(): void
    {
        // Turns 2 on the Wednesday of this week.
        $child = $this->makeChild('Hopper', 'Grace', '2024-07-29');

        $this->assertSame('Transition', $child->classroom);

        $this->travelTo(Carbon::parse('2026-07-29 09:00:00'));
        ClassroomAssignment::syncAll();

        $this->assertSame('Toddler', $child->fresh()->classroom);
    }

    /* ---- F2: the override, and when it starts ---- */

    public function test_the_director_can_move_a_child_up_early(): void
    {
        // 17½ months — Infant by the rule, ready for Transition in practice.
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $this->assertSame('Infant', $child->classroom);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'Transition',
        ])->assertOk()->assertJson([
            'classroom' => 'Transition',
            'automatic_classroom' => 'Infant',
            'classroom_override' => 'Transition',
            'classroom_override_from' => self::MONDAY,
            'override_stale' => false,
        ]);

        $this->assertSame('Transition', $child->fresh()->classroom);
    }

    public function test_an_override_does_nothing_before_the_date_it_starts(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'Transition',
            'effective_from' => '2026-07-30',
        ])->assertOk();

        $child->refresh();

        // Dated for Thursday: Wednesday is still an Infant day, and the stored
        // room only moves when the date arrives.
        $this->assertSame('Infant', $child->classroomOn(Carbon::parse('2026-07-29')));
        $this->assertSame('Transition', $child->classroomOn(Carbon::parse('2026-07-30')));
        $this->assertSame('Infant', $child->classroom);

        $this->travelTo(Carbon::parse('2026-07-30 09:00:00'));
        ClassroomAssignment::syncAll();

        $this->assertSame('Transition', $child->fresh()->classroom);
    }

    public function test_an_override_cannot_start_in_a_week_that_has_ended(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        // A finished week is the record of the ratio that had to be staffed, so
        // an override cannot reach back and rewrite its head counts.
        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'Transition',
            'effective_from' => '2026-07-15',
        ])->assertStatus(422);

        $this->assertNull($child->fresh()->classroom_override);
    }

    public function test_a_teacher_cannot_move_a_child_between_rooms(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $teacher = User::factory()->create(['role' => 'teacher', 'classrooms' => ['Infant']]);

        $this->actingAs($teacher)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'Transition',
        ])->assertForbidden();

        $this->assertNull($child->fresh()->classroom_override);
    }

    public function test_a_room_outside_the_bands_is_refused(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'Preschool',
        ])->assertStatus(422);
    }

    /* ---- the same override, set from the child's record ---- */

    public function test_the_record_takes_an_override_without_making_you_date_it(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        // Picking a room and leaving the date alone is the common case. It must
        // not come back as "the date is required" — blank means today, which is
        // what the field says and what the schedule page does.
        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->recordFor($child, ['classroom_override' => 'Transition']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('children.index'));

        $child->refresh();

        $this->assertSame('Transition', $child->classroom_override);
        $this->assertSame(self::MONDAY, $child->classroom_override_from->toDateString());
        $this->assertSame('Transition', $child->classroom);
    }

    public function test_the_record_can_date_an_override_for_later(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->recordFor($child, [
                'classroom_override' => 'Transition',
                'classroom_override_from' => '2026-07-30',
            ]))
            ->assertSessionHasNoErrors();

        $child->refresh();

        $this->assertSame('2026-07-30', $child->classroom_override_from->toDateString());
        $this->assertSame('Infant', $child->classroom, 'the room only moves when the date arrives');
    }

    public function test_clearing_the_room_on_the_record_clears_the_date_with_it(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->recordFor($child, [
                'classroom_override' => '',
                'classroom_override_from' => self::MONDAY,
            ]))
            ->assertSessionHasNoErrors();

        $child->refresh();

        $this->assertNull($child->classroom_override);
        $this->assertNull($child->classroom_override_from, 'a start date with no room to start is left over');
        $this->assertSame('Infant', $child->classroom);
    }

    public function test_the_record_shows_the_date_of_birth_it_holds(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $html = $this->actingAs($this->admin)->get(route('children.edit', $child))->assertOk()->getContent();

        // A Carbon echoed straight out gives "2025-02-11 00:00:00", which
        // <input type="date"> refuses — the field goes blank and saving the form
        // then wipes the date, taking the child's room with it.
        $this->assertMatchesRegularExpression('/name="birth_date"[^>]*value="2025-02-11"/', $html);
    }

    public function test_the_record_shows_a_date_of_birth_held_in_the_older_column(): void
    {
        // Every record predating the enrolment form keeps its date in `dob`.
        // The field reads `birth_date`, so those records opened blank — and a
        // blank date field saved is a date thrown away.
        $child = Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'dob' => '2025-02-11',
        ]);

        $this->actingAs($this->admin)
            ->get(route('children.edit', $child))
            ->assertOk()
            ->assertSee('value="2025-02-11"', false);

        // Saving fills both columns, so whichever one a reader looks at now
        // agrees with the other.
        $this->actingAs($this->admin)
            ->put(route('children.update', $child), [
                'lan' => $child->lan,
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'status' => $child->status,
                'birth_date' => '2025-03-11',
            ])
            ->assertSessionHasNoErrors();

        $child->refresh();

        $this->assertSame('2025-03-11', $child->birth_date->toDateString());
        $this->assertSame('2025-03-11', $child->dob->toDateString());
        $this->assertSame('Infant', $child->classroom);
    }

    public function test_saving_an_unchanged_record_keeps_the_room(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');

        $this->assertSame('Infant', $child->classroom);

        // Open the record, change nothing that matters, save. The date of birth
        // has to survive the round trip or the child falls out of every room.
        $this->actingAs($this->admin)
            ->put(route('children.update', $child), $this->recordFor($child, ['first_name' => 'Kathryn']))
            ->assertSessionHasNoErrors();

        $child->refresh();

        $this->assertSame('2025-02-11', $child->birth_date->toDateString());
        $this->assertSame('Infant', $child->classroom);
    }

    public function test_a_document_import_shows_the_room_the_extracted_birth_date_gives(): void
    {
        Storage::fake();
        Storage::put('child-imports/probe.pdf', 'a scanned enrolment form');

        // There is no child record yet on a document import — the date of birth
        // came out of the PDF, and the room has to follow it all the same.
        $this->actingAs($this->admin)
            ->withSession(['child_imports.probe' => [
                'fields' => ['first_name' => 'Katherine', 'last_name' => 'Johnson', 'birth_date' => '2022-02-11'],
                'path' => 'child-imports/probe.pdf',
                'mime' => 'application/pdf',
                'name' => 'enrolment.pdf',
            ]])
            ->get(route('children.document-import.review', 'probe'))
            ->assertOk()
            ->assertSee('Automatic: UPK-4')
            ->assertSee('value="2022-02-11"', false);
    }

    /* ---- F4: it holds until it is cleared ---- */

    public function test_an_override_survives_a_birthday(): void
    {
        // Turns 18 months on the Wednesday, which would move her to Transition
        // anyway — but she has been placed in Toddler, and that must hold.
        $child = $this->makeChild('Hamilton', 'Margaret', '2025-01-29');
        $child->forceFill(['classroom_override' => 'Toddler', 'classroom_override_from' => self::MONDAY])->save();

        $this->assertSame('Toddler', $child->fresh()->classroom);

        $this->travelTo(Carbon::parse('2026-07-29 09:00:00'));
        ClassroomAssignment::syncAll();

        $this->assertSame('Toddler', $child->fresh()->classroom);
    }

    public function test_opening_a_week_never_reassigns_an_overridden_child(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        app(WeekSchedule::class)->open(self::MONDAY);
        $this->actingAs($this->admin)->get(route('attendance.index', ['date' => self::MONDAY]))->assertOk();

        $this->assertSame('Transition', $child->fresh()->classroom);
    }

    public function test_clearing_an_override_hands_the_child_back_to_their_age(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => null,
        ])->assertOk()->assertJson(['classroom' => 'Infant', 'classroom_override' => null]);

        $child->refresh();

        $this->assertNull($child->classroom_override);
        $this->assertNull($child->classroom_override_from);
        $this->assertSame('Infant', $child->classroom);
    }

    /* ---- F3: an override that has stopped doing anything is flagged ---- */

    public function test_an_override_the_age_has_caught_up_with_is_flagged_but_kept(): void
    {
        // Moved into Transition early, at 17½ months.
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        $this->assertFalse($child->fresh()->classroomOverrideIsStale());

        // Two years on she is a Toddler by the rule, and the override is now
        // holding her back rather than moving her forward.
        $this->travelTo(Carbon::parse('2028-07-27 09:00:00'));
        $child->refresh();

        $this->assertTrue($child->classroomOverrideIsStale());
        $this->assertSame('Transition', $child->classroomOn());
    }

    /* ---- F5: head counts read the overridden room ---- */

    public function test_the_report_counts_an_overridden_child_in_their_new_room(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $this->makeChild('Easley', 'Annie', '2025-02-11');

        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        $response = $this->actingAs($this->admin)
            ->get(route('reports.index', ['date' => self::MONDAY]))
            ->assertOk();

        // Katherine is printed under Transition; Annie is the only Infant left.
        $rooms = $response->viewData('blocks')->keyBy('room');

        $this->assertSame([$child->id], $rooms['Transition']['children']->pluck('id')->all());
        $this->assertCount(1, $rooms['Infant']['children']);
    }

    public function test_teacher_visibility_follows_the_override(): void
    {
        $child = $this->makeChild('Johnson', 'Katherine', '2025-02-11');
        $child->forceFill(['classroom_override' => 'Transition', 'classroom_override_from' => self::MONDAY])->save();

        $infantTeacher = User::factory()->create(['role' => 'teacher', 'classrooms' => ['Infant']]);
        $transitionTeacher = User::factory()->create(['role' => 'teacher', 'classrooms' => ['Transition']]);

        // The room she is in is the room whose teacher can sign her in.
        $this->assertSame(0, Child::visibleTo($infantTeacher)->count());
        $this->assertSame(1, Child::visibleTo($transitionTeacher)->count());
    }

    public function test_moving_into_school_age_splits_the_day_into_am_and_pm(): void
    {
        // Four, so UPK-4 by the rule, and signing in once for the whole day.
        $child = $this->makeChild('Wilson', 'Bea', '2022-01-10');
        app(WeekSchedule::class)->open(self::MONDAY);

        $this->assertSame(5, ScheduleSlot::where('child_id', $child->id)->count());

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'School Age',
        ])->assertOk()->assertJson(['sessions' => ['AM', 'PM']]);

        $sessions = ScheduleSlot::where('child_id', $child->id)->pluck('session')->unique()->sort()->values();

        $this->assertSame(['AM', 'PM'], $sessions->all());
        $this->assertSame(10, ScheduleSlot::where('child_id', $child->id)->count());
    }

    public function test_a_day_already_ticked_stays_ticked_when_the_day_splits(): void
    {
        $child = $this->makeChild('Wilson', 'Bea', '2022-01-10');
        app(WeekSchedule::class)->open(self::MONDAY);

        ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', '2026-07-28')
            ->update(['is_scheduled' => true]);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'School Age',
        ])->assertOk();

        // Splitting Tuesday in two is not the director changing their mind about
        // whether the child comes on Tuesday.
        $this->assertSame(2, ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', '2026-07-28')
            ->where('is_scheduled', true)
            ->count());

        $this->assertSame(0, ScheduleSlot::where('child_id', $child->id)
            ->where('slot_date', '2026-07-27')
            ->where('is_scheduled', true)
            ->count());
    }

    public function test_a_day_already_signed_in_keeps_its_box_when_the_day_splits(): void
    {
        $child = $this->makeChild('Wilson', 'Bea', '2022-01-10');
        app(WeekSchedule::class)->open(self::MONDAY);

        Attendance::create([
            'child_id' => $child->id,
            'attendance_date' => self::MONDAY,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse(self::MONDAY.' 08:30:00'),
        ]);

        $this->actingAs($this->admin)->postJson(route('attendance.schedule.classroom'), [
            'child_id' => $child->id,
            'classroom' => 'School Age',
        ])->assertOk();

        // A child who was here was here, and DSS bills it — the box that carries
        // the sign-in is never taken away.
        $this->assertDatabaseHas('schedule_slots', [
            'child_id' => $child->id,
            'slot_date' => self::MONDAY,
            'session' => 'FULL',
        ]);
    }

    /* ---- the room a child is in is never changed behind the director's back ---- */

    public function test_a_room_set_by_hand_on_a_child_with_no_date_of_birth_is_kept(): void
    {
        $child = Child::create([
            'lan' => '2001',
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => 'Toddler',
        ]);

        // Nothing to work the room out from, so what was set by hand stands —
        // recorded as the override it is, rather than quietly dropped.
        $this->assertSame('Toddler', $child->classroom);
        $this->assertSame('Toddler', $child->classroom_override);
    }

    /** The child's record as the edit form posts it, with the fields under test on top. */
    private function recordFor(Child $child, array $overrides): array
    {
        return array_merge([
            'lan' => $child->lan,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'status' => $child->status,
            'birth_date' => $child->birth_date->toDateString(),
        ], $overrides);
    }

    private function makeChild(string $last, string $first, string $birthDate): Child
    {
        return Child::create([
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
        ]);
    }
}
