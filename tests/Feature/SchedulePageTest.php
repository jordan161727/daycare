<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\ScheduleSlot;
use App\Models\StaffRule;
use App\Models\StaffScheduleWeek;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\User;
use App\Services\StaffSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PlacesChildrenInRooms;
use Tests\TestCase;

/**
 * The Schedule page — Phase 7: draft and publish, and editing By room.
 *
 * What these pin is the promise the page makes before a change is saved:
 * that it says what the change does to both rooms, that a Must rule blocks
 * it outright, and that a Prefer rule only ever gets a mention.
 */
class SchedulePageTest extends TestCase
{
    use PlacesChildrenInRooms, RefreshDatabase;

    private const WEEK = '2026-07-27';   // Mon 27 Jul – Fri 31 Jul

    private User $admin;

    private User $maria;

    private User $emily;

    private User $hannah;

    private User $olivia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::WEEK.' 09:00:00'));

        $this->admin = User::create(['name' => 'Director', 'email' => 'director@example.com', 'password' => 'password', 'role' => 'admin']);
        $this->maria = $this->teacher('Maria Santos', 'FT', 'Infant');
        $this->emily = $this->teacher('Emily Carter', 'FT', 'Toddler');
        $this->hannah = $this->teacher('Hannah Brooks', 'FT', 'PreK');
        $this->olivia = $this->teacher('Olivia Turner', 'PT', 'Toddler');

        // Hannah is never in Infant: a Must rule.
        $this->hannah->staffRules()->create(['rule_type' => 'ROOM_FORBIDDEN', 'priority' => 'HARD', 'value_text' => 'Infant']);

        // Eight infants need two staff; Maria alone is one short all day.
        $this->bookChildren('Infant', 8);
        $this->bookChildren('Toddler', 6);

        $this->shift($this->maria, 'Infant', 7 * 60 + 30, 15 * 60 + 30);
        $this->shift($this->emily, 'Toddler', 8 * 60, 16 * 60);
        $this->shift($this->hannah, 'PreK', 8 * 60, 16 * 60);
    }

    // ---------------- draft and publish ----------------

    public function test_a_generated_week_is_a_draft_until_it_is_published(): void
    {
        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);

        // The director sees the draft and is told staff cannot.
        $html = $this->actingAs($this->admin)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk()->getContent();
        $this->assertStringContainsString('Draft', $html);
        $this->assertStringContainsString("staff can't see it until you publish", $html);

        // A teacher sees nothing of it.
        $html = $this->actingAs($this->emily)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk()->getContent();
        $this->assertStringContainsString("isn't published yet", $html);
        $this->assertStringNotContainsString('data-teacher-row', $html);

        // Published: the same page, now with the week on it.
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::WEEK])->assertRedirect();

        $week = StaffScheduleWeek::firstWhere('week_start', self::WEEK);
        $this->assertNotNull($week->published_at);
        $this->assertSame($this->admin->id, $week->published_by);

        $html = $this->actingAs($this->emily)->get(route('staff-schedule.index', ['week' => self::WEEK]))->assertOk()->getContent();
        $this->assertStringContainsString('data-teacher-row', $html);
        $this->assertStringContainsString('Published', $html);
    }

    public function test_regenerating_a_published_week_makes_it_a_draft_again(): void
    {
        app(StaffSchedule::class)->generate(self::WEEK, $this->admin);
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::WEEK]);

        $this->actingAs($this->admin)->post(route('staff-schedule.generate'), ['week' => self::WEEK])->assertRedirect();

        $this->assertNull(StaffScheduleWeek::firstWhere('week_start', self::WEEK)->published_at);
    }

    // ---------------- previewing a change ----------------

    public function test_a_move_is_previewed_with_its_effect_on_both_rooms(): void
    {
        $shift = StaffShift::where('user_id', $this->emily->id)->first();

        $json = $this->preview(['kind' => 'move', 'id' => $shift->id, 'to' => 'Infant'])->assertOk()->json();

        $this->assertSame('Move Emily Carter from Toddler to Infant', $json['title']);
        $this->assertFalse($json['blocked']);

        // The whole shift, or from a time in the afternoon.
        $this->assertSame('whole', $json['opt']);
        $this->assertContains('From 12p', array_column($json['opts'], 'label'));

        $tags = array_column($json['impact'], 'tag');
        $texts = implode(' ', array_column($json['impact'], 'text'));

        // Infant's gap closes while Emily is there; Toddler is left with nobody.
        $this->assertContains('Fixed', $tags);
        $this->assertContains('Short', $tags);
        $this->assertStringContainsString('Fills the Infant gap', $texts);
        $this->assertStringContainsString('Toddler drops below ratio', $texts);
    }

    public function test_a_move_into_a_forbidden_room_is_blocked_by_the_must_rule(): void
    {
        $shift = StaffShift::where('user_id', $this->hannah->id)->first();

        $json = $this->preview(['kind' => 'move', 'id' => $shift->id, 'to' => 'Infant'])->assertOk()->json();

        $this->assertTrue($json['blocked']);
        $this->assertSame('Must', $json['impact'][0]['tag']);
        $this->assertStringContainsString('Hannah Brooks is never assigned to Infant', $json['impact'][0]['text']);

        // And it cannot be saved, whatever the card said.
        $this->apply(['kind' => 'move', 'id' => $shift->id, 'to' => 'Infant'])->assertStatus(422);
        $this->assertSame('PreK', $shift->fresh()->classroom);
    }

    public function test_a_preference_is_reported_but_never_blocks(): void
    {
        $this->emily->staffRules()->create(['rule_type' => 'ROOM_PREFERENCE', 'priority' => 'SOFT', 'value_text' => 'Toddler']);
        $shift = StaffShift::where('user_id', $this->emily->id)->first();

        $json = $this->preview(['kind' => 'move', 'id' => $shift->id, 'to' => 'Infant'])->assertOk()->json();

        $this->assertFalse($json['blocked']);
        $prefer = collect($json['impact'])->firstWhere('tag', 'Prefer');
        $this->assertNotNull($prefer);
        $this->assertStringContainsString('Emily Carter prefers Toddler', $prefer['text']);
    }

    // ---------------- applying a change ----------------

    public function test_a_drop_from_the_tray_adds_a_shift_that_covers_the_gap(): void
    {
        $json = $this->preview(['kind' => 'add', 'user_id' => $this->olivia->id, 'to' => 'Infant'])->assertOk()->json();

        $this->assertSame('fit', $json['opt']);
        $this->assertStringContainsString('covers the gap', $json['opts'][0]['label']);
        $this->assertStringContainsString('Fills the Infant gap', implode(' ', array_column($json['impact'], 'text')));

        $day = $this->apply(['kind' => 'add', 'user_id' => $this->olivia->id, 'to' => 'Infant', 'opt' => 'fit'])->assertOk()->json();

        $added = StaffShift::where('user_id', $this->olivia->id)->where('day', 'MON')->first();
        $this->assertNotNull($added);
        $this->assertSame('Infant', $added->classroom);
        $this->assertSame(StaffShift::ROLE_FLOAT, $added->role);

        // The day comes back repainted — Infant now has two on the floor
        // while Olivia is there (the late afternoon stays short, which the
        // strip still says) — and the week counts the edit.
        $infant = collect($day['rooms'])->firstWhere('key', 'Infant');
        $this->assertCount(2, $infant['lanes']);
        $this->assertTrue(collect($infant['cov'])->contains(fn ($band) => $band['have'] === 2 && $band['need'] === 2), 'no band of the strip shows the gap filled');
        $this->assertSame(1, StaffScheduleWeek::firstWhere('week_start', self::WEEK)->edits);
    }

    public function test_a_split_move_keeps_the_morning_and_moves_the_afternoon(): void
    {
        $shift = StaffShift::where('user_id', $this->emily->id)->first();

        $this->apply(['kind' => 'move', 'id' => $shift->id, 'to' => 'Infant', 'opt' => (string) (12 * 60)])->assertOk();

        $mine = StaffShift::where('user_id', $this->emily->id)->orderBy('starts_at')->get();
        $this->assertCount(2, $mine);
        $this->assertSame(['Toddler', 8 * 60, 12 * 60], [$mine[0]->classroom, $mine[0]->starts_at, $mine[0]->ends_at]);
        $this->assertSame(['Infant', 12 * 60, 16 * 60, StaffShift::ROLE_FLOAT], [$mine[1]->classroom, $mine[1]->starts_at, $mine[1]->ends_at, $mine[1]->role]);
    }

    public function test_who_could_cover_lists_only_people_the_rules_allow(): void
    {
        $json = $this->actingAs($this->admin)->getJson(route('staff-schedule.day', ['week' => self::WEEK, 'day' => 'MON']))->assertOk()->json();

        $infant = collect($json['cover'])->firstWhere('room', 'Infant');
        $this->assertNotNull($infant, 'Infant is short and should ask who could cover');

        $names = array_column($infant['who'], 'name');
        $this->assertContains('Olivia Turner', $names);
        // Never in Infant, and already in Toddler then.
        $this->assertNotContains('Hannah Brooks', $names);
        $this->assertNotContains('Emily Carter', $names);
    }

    // ---------------- time off from a bar ----------------

    public function test_time_off_from_a_bar_clashes_and_the_shift_can_be_trimmed_around_it(): void
    {
        $shift = StaffShift::where('user_id', $this->maria->id)->first();

        $day = $this->actingAs($this->admin)->postJson(route('staff-schedule.time-off'), [
            'week' => self::WEEK, 'day' => 'MON', 'user_id' => $this->maria->id, 'kind' => 'until', 'minute' => 12 * 60, 'reason' => 'appointment',
        ])->assertOk()->json();

        // Written where the Staff page keeps it, in the director's name.
        $entry = StaffTimeOff::sole();
        $this->assertSame(['until', 12 * 60, 'appointment', $this->admin->id], [$entry->kind, $entry->from_minute, $entry->reason, $entry->created_by]);

        // The day says so, and offers the fix.
        $this->assertCount(1, $day['conf']);
        $this->assertStringContainsString('Maria Santos is out until 12:00 PM', $day['conf'][0]['title']);
        $this->assertSame('Keep 12p–3:30p', $day['conf'][0]['fixLabel']);
        $this->assertSame([12 * 60, 15 * 60 + 30], $day['conf'][0]['keep']);

        $after = $this->apply(['kind' => 'trim', 'id' => $shift->id, 'from' => 12 * 60, 'to' => 15 * 60 + 30])->assertOk()->json();

        $this->assertSame([12 * 60, 15 * 60 + 30], [$shift->fresh()->starts_at, $shift->fresh()->ends_at]);
        $this->assertSame([], $after['conf']);
    }

    // ---------------- who may ----------------

    public function test_a_teacher_can_read_but_not_edit(): void
    {
        $this->actingAs($this->admin)->post(route('staff-schedule.publish'), ['week' => self::WEEK]);
        $shift = StaffShift::where('user_id', $this->emily->id)->first();

        $this->actingAs($this->emily)->get(route('staff-schedule.index', ['week' => self::WEEK, 'view' => 'room']))->assertOk();

        $response = $this->actingAs($this->emily)->postJson(route('staff-schedule.apply'), ['week' => self::WEEK, 'day' => 'MON', 'kind' => 'move', 'id' => $shift->id, 'to' => 'Infant']);
        $this->assertContains($response->getStatusCode(), [302, 403]);
        $this->assertSame('Toddler', $shift->fresh()->classroom);
    }

    public function test_the_sheet_for_the_wall_lists_everyone_with_plain_times(): void
    {
        $html = $this->actingAs($this->admin)->get(route('staff-schedule.print', ['week' => self::WEEK]))->assertOk()->getContent();

        $this->assertStringContainsString('Staff schedule', $html);
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringContainsString('7:30a – 3:30p', $html);
        $this->assertStringContainsString('Infant', $html);
    }

    // ---------------- helpers ----------------

    private function preview(array $change)
    {
        return $this->actingAs($this->admin)->postJson(route('staff-schedule.preview'), ['week' => self::WEEK, 'day' => 'MON'] + $change);
    }

    private function apply(array $change)
    {
        return $this->actingAs($this->admin)->postJson(route('staff-schedule.apply'), ['week' => self::WEEK, 'day' => 'MON'] + $change);
    }

    private function teacher(string $name, string $employment, string $room): User
    {
        return User::create([
            'name' => $name,
            'email' => str($name)->slug().'@example.com',
            'password' => 'password',
            'role' => 'teacher',
            'employment' => $employment,
            'title' => $room,
            'classroom' => $room,
        ]);
    }

    private function shift(User $person, string $room, int $from, int $to, string $day = 'MON'): StaffShift
    {
        return StaffShift::create([
            'week_start' => self::WEEK, 'user_id' => $person->id, 'shift_date' => self::WEEK, 'day' => $day,
            'starts_at' => $from, 'ends_at' => $to, 'classroom' => $room, 'role' => StaffShift::ROLE_STAFF,
        ]);
    }

    /** Book children into a room for every day of the week. */
    private function bookChildren(string $room, int $count): void
    {
        $dates = collect(range(0, 4))->map(fn ($offset) => Carbon::parse(self::WEEK)->addDays($offset)->toDateString());

        for ($i = 0; $i < $count; $i++) {
            $child = Child::create([
                'lan' => (string) (1000 + Child::count() + 1),
                'first_name' => 'Child',
                'last_name' => $room.$i,
                'dob' => $this->dobForRoom($room),
                'status' => 'Active',
            ]);

            foreach ($dates as $date) {
                ScheduleSlot::create(['week_start' => self::WEEK, 'child_id' => $child->id, 'slot_date' => $date, 'session' => 'FULL', 'is_scheduled' => true]);
            }
        }
    }
}
