<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\HealthAudit;
use App\Models\SymptomCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The health check taken at the door.
 *
 * What these pin down is the part that is a statement about a child rather
 * than a convenience: who may write one, what a code is allowed to say, and
 * that nothing changes without the record saying who changed it. A sheet that
 * quietly forgot a fever would be worse than one that never held it.
 */
class HealthScreeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so there are days behind and ahead inside the week.
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrator']);
        $this->teacher = User::factory()->create(['role' => 'teacher', 'name' => 'Rachel Kim', 'classrooms' => ['PreK']]);

        $this->child = Child::create([
            'lan' => '10064',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'classroom' => 'PreK',
            'status' => 'Active',
            // Old enough for PreK: the room a child lands in is derived from
            // their birthday, and the sheet refuses a cell outside enrolment.
            'dob' => Carbon::parse('2026-09-23')->subYears(4)->toDateString(),
        ]);
    }

    public function test_the_centre_list_ships_with_the_twelve_codes(): void
    {
        $this->assertSame(12, SymptomCode::count());
        $this->assertSame('Normal', SymptomCode::find(0)->label);
        $this->assertSame('Vomiting', SymptomCode::find(10)->label);

        // The one whose meaning lives entirely in the words beside it.
        $this->assertTrue(SymptomCode::find(11)->requires_note);
        $this->assertFalse(SymptomCode::find(4)->requires_note);
    }

    public function test_the_picker_is_offered_the_active_codes(): void
    {
        $this->actingAs($this->teacher)
            ->getJson(route('health.codes'))
            ->assertOk()
            ->assertJsonCount(12, 'codes')
            ->assertJsonPath('codes.0.code', 0)
            ->assertJsonPath('codes.0.sick', false)
            ->assertJsonPath('codes.4.sick', true);
    }

    public function test_a_retired_code_leaves_the_picker_but_not_the_history(): void
    {
        // The reason a code is retired is usually that it was being misread,
        // so it must stop being offered — and the months of records that used
        // it still have to read correctly.
        $attendance = $this->attendance('2026-09-23', ['health_in_code' => 6]);

        SymptomCode::find(6)->update(['active' => false]);

        $this->actingAs($this->teacher)
            ->getJson(route('health.codes'))
            ->assertOk()
            ->assertJsonCount(11, 'codes');

        $this->assertSame(6, $attendance->fresh()->health_in_code);
    }

    public function test_a_code_can_be_recorded_on_todays_arrival(): void
    {
        $attendance = $this->attendance('2026-09-23');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4])
            ->assertOk()
            ->assertJsonPath('code', 4)
            ->assertJsonPath('sick', true);

        $this->assertSame(4, $attendance->fresh()->health_in_code);
    }

    public function test_code_eleven_will_not_save_without_words(): void
    {
        // "Other" with no note is not a record of anything.
        $attendance = $this->attendance('2026-09-23');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 11])
            ->assertStatus(422)
            ->assertJsonValidationErrors('health_note');

        $this->assertNull($attendance->fresh()->health_in_code);

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), [
                'direction' => 'in', 'code' => 11, 'note' => 'Sore left ear, parent aware',
            ])
            ->assertOk();

        $this->assertSame('Sore left ear, parent aware', $attendance->fresh()->health_in_note);
    }

    public function test_a_code_the_centre_does_not_use_is_refused(): void
    {
        $attendance = $this->attendance('2026-09-23');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('health_code');
    }

    public function test_a_leaving_check_needs_a_child_who_has_left(): void
    {
        // A code recorded against a child still in the room is a note about a
        // moment that has not happened.
        $attendance = $this->attendance('2026-09-23');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'out', 'code' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('health_code');

        $attendance->update(['signed_out_at' => now()]);

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'out', 'code' => 10])
            ->assertOk();

        $this->assertSame(10, $attendance->fresh()->health_out_code);
    }

    public function test_a_teacher_cannot_write_a_code_onto_a_day_already_gone(): void
    {
        // By then nobody remembers the child, and a code typed from memory is
        // a statement about a child's health that nobody witnessed.
        $attendance = $this->attendance('2026-09-21');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4])
            ->assertForbidden();

        $this->assertNull($attendance->fresh()->health_in_code);
    }

    public function test_an_administrator_can_correct_a_day_already_gone(): void
    {
        $attendance = $this->attendance('2026-09-21');

        $this->actingAs($this->admin)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4])
            ->assertOk();

        $this->assertSame(4, $attendance->fresh()->health_in_code);
    }

    public function test_every_change_is_written_down(): void
    {
        $attendance = $this->attendance('2026-09-23');

        $this->actingAs($this->teacher)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 0]);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => 4]);

        $audits = HealthAudit::where('attendance_id', $attendance->id)->orderBy('id')->get();

        $this->assertCount(2, $audits);

        $this->assertNull($audits[0]->old_code);
        $this->assertSame(0, $audits[0]->new_code);
        $this->assertSame($this->teacher->id, $audits[0]->user_id);

        // The correction says what it replaced, which is the whole point.
        $this->assertSame(0, $audits[1]->old_code);
        $this->assertSame(4, $audits[1]->new_code);
        $this->assertSame($this->admin->id, $audits[1]->user_id);
    }

    public function test_clearing_a_code_is_a_change_like_any_other(): void
    {
        // A record that quietly forgot a fever is worse than one that never
        // held it.
        $attendance = $this->attendance('2026-09-23', ['health_in_code' => 4, 'health_in_note' => 'Sent home']);

        $this->actingAs($this->admin)
            ->postJson(route('attendance.health', $attendance), ['direction' => 'in', 'code' => null])
            ->assertOk();

        $attendance->refresh();

        $this->assertNull($attendance->health_in_code);
        // The words explained a symptom that is no longer recorded.
        $this->assertNull($attendance->health_in_note);

        $audit = HealthAudit::where('attendance_id', $attendance->id)->latest('id')->firstOrFail();

        $this->assertSame(4, $audit->old_code);
        $this->assertNull($audit->new_code);
    }

    public function test_normal_is_the_only_code_that_is_not_sick(): void
    {
        // The header count, the chip colour and the sheet all have to agree
        // about what "sick" means.
        $this->assertFalse(SymptomCode::isSick(0));
        $this->assertFalse(SymptomCode::isSick(null));

        foreach (range(1, 11) as $code) {
            $this->assertTrue(SymptomCode::isSick($code), "code {$code} should read as sick");
        }
    }

    public function test_a_child_who_arrived_well_and_left_unwell_counts_as_sick(): void
    {
        // A count that only read the morning would say the day was clear.
        $attendance = $this->attendance('2026-09-23', [
            'health_in_code' => 0,
            'signed_out_at' => now(),
            'health_out_code' => 4,
        ]);

        $this->assertTrue($attendance->isSick());
    }

    public function test_a_check_in_can_carry_its_code_in_one_press(): void
    {
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
            'health_code' => 6,
        ])->assertOk()->assertJsonPath('health_in_code', 6);

        $attendance = Attendance::firstOrFail();

        $this->assertSame(6, $attendance->health_in_code);
        $this->assertDatabaseHas('health_audits', [
            'attendance_id' => $attendance->id,
            'direction' => 'in',
            'new_code' => 6,
        ]);
    }

    public function test_signing_in_without_a_code_records_no_code_rather_than_normal(): void
    {
        // The door kiosk has no member of staff present to judge a symptom.
        // "Not recorded" is true; 0 would be a claim that somebody looked.
        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
        ])->assertOk();

        $this->assertNull(Attendance::firstOrFail()->health_in_code);
    }

    public function test_tapping_an_existing_cell_does_not_wipe_this_mornings_code(): void
    {
        $attendance = $this->attendance('2026-09-23', ['health_in_code' => 4]);

        $this->actingAs($this->admin)->postJson(route('attendance.signin'), [
            'child_id' => $this->child->id,
            'attendance_date' => '2026-09-23',
            'session' => 'FULL',
        ])->assertOk();

        $this->assertSame(4, $attendance->fresh()->health_in_code);
    }

    private function attendance(string $date, array $extra = []): Attendance
    {
        return Attendance::create(array_merge([
            'child_id' => $this->child->id,
            'attendance_date' => $date,
            'session' => 'FULL',
            'signed_in_at' => Carbon::parse($date.' 08:12'),
        ], $extra));
    }
}
