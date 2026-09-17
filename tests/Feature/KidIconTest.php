<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Support\Facades\Blade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The child-facing marks: an animal per room, and the day's state as weather.
 */
class KidIconTest extends TestCase
{
    use RefreshDatabase;

    private const ANIMALS = [
        'Infant' => '🐰',
        'Transition' => '🐢',
        'Toddler' => '🦊',
        'PreK' => '🐻',
        'UPK-4' => '🦉',
        'School Age' => '🐘',
    ];

    public function test_every_room_has_an_animal_of_its_own(): void
    {
        // A room added to ClassroomAssignment without one falls back to a star,
        // which is a silent downgrade rather than a failure — so it is checked
        // against the real room list rather than against this test's copy.
        foreach (ClassroomAssignment::rooms() as $room) {
            $this->assertArrayHasKey($room, self::ANIMALS, "the {$room} room has no animal");

            $this->assertStringContainsString(
                self::ANIMALS[$room],
                (string) $this->blade('<x-room-icon room="'.$room.'" />'),
                "the {$room} room drew the wrong animal"
            );
        }

        $this->assertSame(count(self::ANIMALS), count(array_unique(self::ANIMALS)), 'two rooms share an animal');
    }

    public function test_an_unmapped_room_falls_back_rather_than_rendering_nothing(): void
    {
        $this->assertStringContainsString('⭐', (string) $this->blade('<x-room-icon room="Nursery" />'));
    }

    public function test_the_room_animal_appears_where_the_room_is_named(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeChild('Toddler');

        // The three places a room is read: the sheet's filters, the roster, and
        // the page that sets each room's hours.
        $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->assertSee('🦊', false);
        $this->actingAs($admin)->get(route('children.index'))->assertOk()->assertSee('🦊', false);
        $this->actingAs($admin)->get(route('room-schedule.index'))->assertOk()->assertSee('🐢', false);
    }

    public function test_the_weather_speaks_the_sheets_own_state_names(): void
    {
        // Keyed on $boxStates' keys rather than a second vocabulary, so the two
        // cannot drift apart.
        foreach (['present' => '☀️', 'unplanned' => '🌈', 'scheduled' => '☁️', 'closed' => '🌧️'] as $state => $glyph) {
            $this->assertStringContainsString($glyph, (string) $this->blade('<x-day-weather state="'.$state.'" />'));
        }
    }

    public function test_a_day_with_no_news_draws_nothing(): void
    {
        // "Not scheduled" is the commonest cell on a grid sixty rows deep. An
        // icon for it would be noise, and the empty box already says it.
        $this->assertSame('', trim((string) $this->blade('<x-day-weather state="off" />')));
    }

    /**
     * The sign-in box is one line: label, arrival time, nothing else. The
     * weather mark it used to carry made a signed-in box two lines tall, so
     * every row on the sheet was sized by the busiest cell in it.
     */
    public function test_the_sign_in_box_carries_no_weather_mark(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeChild('Toddler');

        $html = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('boxWeather', $html);

        // The cell carries the arrival time and nothing else — displayTime is
        // the whole of what a signed-in box shows, so there is no second mark
        // to read and no second mark to keep in step. Written into the box by
        // cellInner rather than bound on a span of its own.
        $this->assertStringContainsString(
            "this.esc(this.displayTime(child.id, date, session))",
            $html,
            'the cell no longer shows the arrival time'
        );
        $this->assertStringContainsString('return this.sessionTime(childId, date, session);', $html);
    }

    public function test_the_rows_name_the_room_by_its_animal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeChild('Toddler');

        $html = $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->getContent();

        // Handed to the browser from ClassroomAssignment rather than typed into
        // the script — see the next test for why that matters.
        $this->assertStringContainsString('roomAnimals:', $html);
        $this->assertStringContainsString('roomAnimal(child.classroom)', $html);
    }

    public function test_the_animal_map_has_exactly_one_home(): void
    {
        // Two screens need it in two languages: Blade renders the component,
        // and the attendance sheet hands it to Alpine. A second copy would
        // drift the first time a room was renamed — so nothing outside
        // ClassroomAssignment may spell the animals out.
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $body = file_get_contents($file);

            foreach (self::ANIMALS as $room => $glyph) {
                if (str_contains($body, "'{$room}' => '{$glyph}'")) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], array_unique($offenders), 'the animal map is written out a second time');
        $this->assertStringContainsString(
            "'Toddler' => '🦊'",
            file_get_contents(app_path('Services/ClassroomAssignment.php'))
        );
    }

    /** Every Blade template in the app, however deeply nested. */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function makeChild(string $room): Child
    {
        return Child::create([
            'lan' => (string) (2000 + Child::count()),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'classroom' => $room,
            'status' => 'Active',
        ]);
    }
}
