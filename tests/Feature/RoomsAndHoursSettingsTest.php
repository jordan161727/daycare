<?php

namespace Tests\Feature;

use App\Models\RoomSchedule;
use App\Models\Setting;
use App\Models\User;
use App\Services\CentreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings → Rooms & hours — Phase 1.2 of docs/work.md: the rooms table,
 * the centre's hours and shift limits, the New York group check, the
 * conflict warnings, and the one reader everything else goes through.
 */
class RoomsAndHoursSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        foreach (['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'] as $room) {
            RoomSchedule::create(['room' => $room, 'opens_at' => '07:00', 'closes_at' => '18:00']);
        }
    }

    public function test_the_page_lists_every_room_with_its_figures_and_the_centres_day(): void
    {
        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()
            ->assertSee('Rooms &amp; hours', false)
            ->assertSeeInOrder(['Room', 'Ages', 'Staff : children', 'Capacity', 'NY max group', 'Room open hours', 'Check'])
            ->assertSeeInOrder(['data-room-row="Infant"', 'data-room-row="Transition"', 'data-room-row="Toddler"', 'data-room-row="PreK"', 'data-room-row="UPK-4"', 'data-room-row="School Age"'], false)
            ->assertSee('6 wk–18 mo')
            ->assertSee('Centre opens')->assertSee('Centre closes')->assertSee('Earliest start for everyone else')->assertSee('Early drop-off room')
            ->assertSee('School Age leaves for school')->assertSee('School Age back from school')->assertSee('Longest shift (hours)')->assertSee('Most hours in a week')
            ->assertSee('Only staff who may open the building can start before the earliest start')
            ->assertSee('>Save<', false);

        // The tab is on the settings menu, and a teacher is kept out.
        $this->actingAs($this->admin)->get(route('settings.children'))->assertSee(route('settings.rooms'), false);
        $this->actingAs(User::factory()->create(['role' => 'teacher']))->get(route('settings.rooms'))->assertForbidden();
    }

    public function test_saving_writes_the_figures_and_the_services_read_them(): void
    {
        $this->actingAs($this->admin)->put(route('settings.rooms.update'), $this->payload([
            'rooms' => [['room' => 'Toddler', 'ratio' => 5, 'capacity' => 10, 'opens_at' => '07:30', 'closes_at' => '17:30']],
            'centre' => ['school_age_returns' => '15:30', 'max_week_hours' => 38],
        ]))->assertRedirect(route('settings.rooms'))->assertSessionHas('status', 'Rooms & hours saved.');

        $centre = app(CentreSettings::class);
        $this->assertSame(5, $centre->ratio('Toddler'), 'the saved ratio wins over config');
        $this->assertSame(6, (int) config('daycare.ratios.Toddler'), 'config is untouched');
        $this->assertSame(10, $centre->capacity('Toddler'));
        $this->assertSame('15:30', $centre->get('school_age_returns'));
        $this->assertSame(38, $centre->get('max_week_hours'));
        $this->assertSame('07:30', substr(RoomSchedule::where('room', 'Toddler')->sole()->opens_at, 0, 5));
        $this->assertSame('5', Setting::get('rooms.Toddler.ratio'));
    }

    public function test_a_capacity_above_new_yorks_group_cap_is_flagged(): void
    {
        $this->actingAs($this->admin)->put(route('settings.rooms.update'), $this->payload([
            'rooms' => [['room' => 'PreK', 'ratio' => 7, 'capacity' => 22, 'opens_at' => '07:00', 'closes_at' => '18:00']],
        ]))->assertRedirect(route('settings.rooms'))
            ->assertSessionHas('status', fn (string $m) => str_starts_with($m, 'Rooms & hours saved — '));

        $this->actingAs($this->admin)->get(route('settings.rooms'))->assertOk()
            ->assertSee("Above NY&#039;s group of 18 — runs as two groups", false)
            ->assertSee('data-room-check="warn"', false)
            ->assertSee('data-settings-warnings', false)
            ->assertSee("PreK: Above NY&#039;s group of 18 — runs as two groups.", false);
    }

    public function test_a_saved_value_that_conflicts_is_said(): void
    {
        $this->actingAs($this->admin)->put(route('settings.rooms.update'), $this->payload([
            'rooms' => [['room' => 'Infant', 'ratio' => 4, 'capacity' => 8, 'opens_at' => '07:00', 'closes_at' => '18:30']],
            'centre' => ['centre_closes' => '18:00', 'school_age_leaves' => '15:30', 'school_age_returns' => '15:00', 'earliest_start' => '06:30'],
        ]))->assertRedirect(route('settings.rooms'));

        $warnings = app(CentreSettings::class)->warnings();
        $this->assertContains('Infant: Closes after the centre does.', $warnings);
        $this->assertContains('School Age comes back before it leaves — check the two times.', $warnings);
        $this->assertContains('The earliest start for everyone else is before the centre opens — only staff who may open the building can start then.', $warnings);

        // A close before an open is refused outright, not warned about.
        $this->actingAs($this->admin)->from(route('settings.rooms'))->put(route('settings.rooms.update'), $this->payload([
            'centre' => ['centre_opens' => '09:00', 'centre_closes' => '08:00'],
        ]))->assertRedirect(route('settings.rooms'))->assertSessionHasErrors('centre.centre_closes');
    }

    /** A full, valid form, with the given rows and values laid over the defaults. */
    private function payload(array $over = []): array
    {
        $rooms = collect(['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'])
            ->map(fn ($room) => ['room' => $room, 'ratio' => (int) config("daycare.ratios.$room"), 'capacity' => (int) config("daycare.capacity.$room"), 'opens_at' => '07:00', 'closes_at' => '18:00'])
            ->keyBy('room');
        foreach ($over['rooms'] ?? [] as $row) {
            $rooms[$row['room']] = $row;
        }
        $centre = collect(CentreSettings::KEYS)->map(fn ($meta) => $meta['default'])->all();
        $centre['school_age_returns'] = '15:00';

        return ['rooms' => $rooms->values()->all(), 'centre' => array_merge($centre, $over['centre'] ?? [])];
    }
}
