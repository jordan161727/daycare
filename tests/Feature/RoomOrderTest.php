<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Services\ClassroomAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rooms are listed in the order the centre says them — Infant, Transition,
 * Toddler, PreK, UPK-4, School Age — on every screen that lists them.
 *
 * Alphabetical order put PreK before Toddler and UPK-4 last, and the order
 * of first appearance changed with whichever child happened to sort first.
 * Neither is an order a teacher would recognise.
 */
class RoomOrderTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED = ['Infant', 'Transition', 'Toddler', 'PreK', 'UPK-4', 'School Age'];

    public function test_any_list_of_rooms_is_put_youngest_first(): void
    {
        $this->assertSame(
            self::EXPECTED,
            ClassroomAssignment::inOrder(['UPK-4', 'PreK', 'School Age', 'Toddler', 'Infant', 'Transition'])->all()
        );

        // A room the bands do not know goes after them, and blanks are dropped.
        $this->assertSame(
            ['Infant', 'School Age', 'Annex', 'Gym'],
            ClassroomAssignment::inOrder(['Gym', null, 'School Age', '', 'Annex', 'Infant', 'Infant'])->all()
        );
    }

    public function test_the_roll_sorted_by_classroom_runs_youngest_first(): void
    {
        $this->children();

        $html = $this->actingAs($this->admin())
            ->get(route('children.index', ['sort' => 'classroom', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertSame(self::EXPECTED, $this->roomsInOrderOfAppearance($html));
    }

    public function test_the_register_chips_run_youngest_first(): void
    {
        $this->children();

        $html = $this->actingAs($this->admin())->get(route('attendance.index'))->assertOk()->getContent();

        preg_match_all('/@click="room=&quot;([^&]+)&quot;"|@click="room=\'([^\']+)\'"/', $html, $matches);
        $chips = array_values(array_filter(array_map(fn ($a, $b) => $a ?: $b, $matches[1], $matches[2])));

        $this->assertSame(self::EXPECTED, $chips);
    }

    public function test_the_report_dropdown_runs_youngest_first(): void
    {
        $this->children();

        $html = $this->actingAs($this->admin())->get(route('reports.index'))->assertOk()->getContent();

        preg_match('/<select id="report-classroom".*?<\/select>/s', $html, $select);
        preg_match_all('/<option value="([^"]+)"/', $select[0] ?? '', $options);

        $this->assertSame(self::EXPECTED, $options[1]);
    }

    public function test_teacher_attendance_chips_run_youngest_first(): void
    {
        // Both lists on the page: the chips the server draws for the sheet,
        // and the order the browser is handed for the ones it draws itself.
        $this->children();

        $html = $this->actingAs($this->admin())->get(route('check-in.index'))->assertOk()->getContent();

        preg_match_all('/@click="room = &quot;([^&]+)&quot;"|@click="room = \'([^\']+)\'"/', $html, $matches);
        $chips = array_values(array_filter(array_map(fn ($a, $b) => $a ?: $b, $matches[1], $matches[2])));

        $this->assertSame(self::EXPECTED, $chips);
        // @js writes an array as JSON.parse('...'), so read it back the same way.
        $this->assertSame(1, preg_match("/roomOrder: (?:JSON\.parse\('(.*?)'\)|(\[.*?\])),/", $html, $order));
        $this->assertSame(self::EXPECTED, json_decode(json_decode('"'.($order[1] ?: $order[2]).'"'), true));
    }

    /** One child in every room, given in an order that is neither alphabetical nor the right one. */
    private function children(): void
    {
        foreach (['UPK-4', 'Infant', 'School Age', 'Toddler', 'PreK', 'Transition'] as $number => $room) {
            Child::create([
                'lan' => (string) (5000 + $number), 'status' => 'Active',
                'first_name' => 'Child', 'last_name' => chr(ord('A') + $number),
                'classroom' => $room, 'classroom_override' => $room,
                'birth_date' => '2022-01-01', 'schedule_days' => [1, 2, 3, 4, 5],
            ]);
        }
    }

    /** @return array<int, string> */
    private function roomsInOrderOfAppearance(string $html): array
    {
        preg_match_all('/<x-room-icon[^>]*>|<\/span>\s*(Infant|Transition|Toddler|PreK|UPK-4|School Age)<\/td>/', $html, $m);
        $rooms = array_values(array_filter($m[1]));

        if ($rooms === []) {
            preg_match_all('/\b(Infant|Transition|Toddler|PreK|UPK-4|School Age)<\/td>/', $html, $m);
            $rooms = $m[1];
        }

        return array_values(array_unique($rooms));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
