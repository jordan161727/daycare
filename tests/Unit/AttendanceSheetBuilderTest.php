<?php

namespace Tests\Unit;

use App\Models\Child;
use App\Models\ClosureDay;
use App\Models\ScheduleSlot;
use App\Services\AttendanceSheet;
use App\Services\WeekSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The week laid out for paper.
 *
 * Asked of the service directly rather than through the printed page: what goes
 * in which column, in what order, and what is counted — the arithmetic and the
 * shape, with no markup in the way.
 */
class AttendanceSheetBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-14';

    private AttendanceSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));
        $this->sheet = new AttendanceSheet;
    }

    public function test_the_week_is_the_five_days_the_centre_opens(): void
    {
        $built = $this->build();

        $this->assertSame(self::MONDAY, $built['weekStart']);
        $this->assertCount(5, $built['dates']);
        $this->assertSame('2026-09-18', $built['dates']->last()->toDateString());
    }

    public function test_rooms_come_out_youngest_first(): void
    {
        // Created in a jumbled order on purpose: the sheet's order is the
        // centre's, not the order records happened to be entered in.
        $this->makeChild('PreK', '2023-03-03');
        $this->makeChild('Infant', '2026-02-10');
        $this->makeChild('School Age', '2019-03-02');
        $this->makeChild('Toddler', '2024-02-10');
        $this->open();
        $this->tickAll();

        $this->assertSame(
            ['Infant', 'Toddler', 'PreK', 'School Age'],
            $this->roomNames($this->build())
        );
    }

    public function test_children_are_listed_by_surname_within_a_room(): void
    {
        $this->makeChild('Toddler', '2024-02-10', ['last_name' => 'Zamora', 'first_name' => 'Ada']);
        $this->makeChild('Toddler', '2024-02-10', ['last_name' => 'Alvarez', 'first_name' => 'Mia']);
        $this->makeChild('Toddler', '2024-02-10', ['last_name' => 'Alvarez', 'first_name' => 'Ben']);
        $this->open();
        $this->tickAll();

        // Surname, then first name — the order the paper file is in.
        $this->assertSame(
            ['Alvarez, Ben', 'Alvarez, Mia', 'Zamora, Ada'],
            array_column($this->firstRoom($this->build())['rows'], 'name')
        );
    }

    public function test_a_child_not_enrolled_that_week_is_not_a_row_at_all(): void
    {
        $this->makeChild('Toddler', '2024-02-10', ['last_name' => 'Here']);
        $this->makeChild('Toddler', '2024-02-10', ['last_name' => 'Later', 'enrolled_on' => '2026-10-05']);
        $this->open();
        $this->tickAll();

        // No slot means no place at the centre that week — as against a child
        // on the roll with nothing ticked, who is a row of grey boxes.
        $this->assertSame(['Here, Ada'], array_column($this->firstRoom($this->build())['rows'], 'name'));
    }

    public function test_a_whole_day_room_gets_one_box_a_day(): void
    {
        $child = $this->makeChild('Toddler', '2024-02-10');
        $this->open();
        $this->tick($child, ['2026-09-14', '2026-09-16']);

        $room = $this->firstRoom($this->build());

        $this->assertFalse($room['split']);
        $this->assertSame(['FULL' => true], $room['rows'][0]['cells']['2026-09-14']);
        $this->assertSame(['FULL' => false], $room['rows'][0]['cells']['2026-09-15']);

        // Counted per day, and the week's ticks totalled for the room.
        $this->assertSame(1, $room['counts']['2026-09-14']['FULL']);
        $this->assertSame(0, $room['counts']['2026-09-15']['FULL']);
        $this->assertSame(2, $room['ticks']);
    }

    public function test_school_age_gets_a_morning_and_an_afternoon(): void
    {
        $child = $this->makeChild('School Age', '2019-03-02');
        $this->open();
        ScheduleSlot::where('child_id', $child->id)->where('session', 'AM')->update(['is_scheduled' => true]);

        $room = $this->firstRoom($this->build());

        $this->assertTrue($room['split']);
        $this->assertSame(['AM' => true, 'PM' => false], $room['rows'][0]['cells']['2026-09-14']);

        // Both halves counted separately: one number would be wrong for
        // whichever half of the day somebody was checking.
        $this->assertSame(1, $room['counts']['2026-09-14']['AM']);
        $this->assertSame(0, $room['counts']['2026-09-14']['PM']);

        // A child expected for either half is one child present that day.
        $this->assertSame(1, $room['present']['2026-09-14']);
    }

    public function test_a_closed_day_has_no_cells_and_is_not_counted(): void
    {
        $child = $this->makeChild('Toddler', '2024-02-10');
        ClosureDay::create(['closed_on' => self::MONDAY, 'reason' => 'Labour Day']);
        $this->open();
        $this->tick($child, ['2026-09-15']);

        $built = $this->build();
        $room = $this->firstRoom($built);

        // Null, not an empty box: there is nothing to fill in on a day the
        // centre was shut, and the cell says so rather than looking unticked.
        $this->assertNull($room['rows'][0]['cells'][self::MONDAY]);
        $this->assertSame('Labour Day', $built['closed'][self::MONDAY]);
        $this->assertSame(0, $built['dayTotals'][self::MONDAY]);
        $this->assertSame(1, $built['dayTotals']['2026-09-15']);
    }

    public function test_a_room_with_nothing_ticked_moves_off_the_grid(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $this->makeChild('Infant', '2026-02-10');
        $this->makeChild('Infant', '2026-03-03');
        $this->open();
        $this->tick($toddler, ['2026-09-14']);

        $built = $this->build();

        // A block of grey the size of a room is a column spent on nothing, so
        // it is accounted for by name at the foot of the page instead.
        $this->assertSame(['Toddler'], $this->roomNames($built));
        $this->assertSame([['room' => 'Infant', 'count' => 2]], $built['unscheduledRooms']);
    }

    public function test_the_page_always_has_three_columns_to_lay_out(): void
    {
        $child = $this->makeChild('Toddler', '2024-02-10');
        $this->open();
        $this->tick($child, ['2026-09-14']);

        $built = $this->build();

        // Three, even for one room: the page is laid out against three columns
        // and an empty one keeps the others their width.
        $this->assertCount(AttendanceSheet::COLUMNS, $built['columns']);
        $this->assertSame([], $built['columns'][2]['rooms']);
    }

    public function test_the_column_holding_a_split_room_is_flagged_wide(): void
    {
        $schoolAge = $this->makeChild('School Age', '2019-03-02');
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $this->open();
        $this->tick($toddler, ['2026-09-14']);
        ScheduleSlot::where('child_id', $schoolAge->id)->update(['is_scheduled' => true]);

        $built = $this->build();

        // Two boxes a day need the name column narrower, and the view reads the
        // flag rather than working it out again.
        foreach ($built['columns'] as $column) {
            $holdsSplit = collect($column['rooms'])->contains(fn ($room) => $room['split']);

            $this->assertSame($holdsSplit, $column['wide']);
        }
    }

    public function test_rooms_are_dealt_across_the_columns_rather_than_piled_in_one(): void
    {
        // Six rooms of equal size: a greedy fill would put them all in the
        // first column and leave two empty.
        foreach ([['Infant', '2026-02-10'], ['Transition', '2025-02-10'], ['Toddler', '2024-02-10'],
                  ['PreK', '2023-03-03'], ['UPK-4', '2022-02-10'], ['School Age', '2019-03-02']] as [$room, $dob]) {
            $child = $this->makeChild($room, $dob);
            $this->open();
            ScheduleSlot::where('child_id', $child->id)->update(['is_scheduled' => true]);
        }

        $built = $this->build();

        foreach ($built['columns'] as $index => $column) {
            $this->assertNotEmpty($column['rooms'], 'column '.$index.' was left empty');
        }

        // And no room is split across two of them: a room is read as one block.
        $this->assertSame(6, collect($built['columns'])->sum(fn ($column) => count($column['rooms'])));
    }

    public function test_the_centre_total_is_the_rooms_that_are_actually_on_the_page(): void
    {
        $toddler = $this->makeChild('Toddler', '2024-02-10');
        $infant = $this->makeChild('Infant', '2026-02-10');
        $this->open();
        $this->tick($toddler, ['2026-09-14', '2026-09-15']);
        $this->tick($infant, ['2026-09-14']);

        $built = $this->build();

        $this->assertSame(2, $built['dayTotals']['2026-09-14']);
        $this->assertSame(1, $built['dayTotals']['2026-09-15']);
        $this->assertSame(0, $built['dayTotals']['2026-09-16']);
    }

    /* ------------------------------------------------------------- helpers */

    private function build(): array
    {
        return $this->sheet->build(self::MONDAY, Child::where('status', 'Active')->get());
    }

    /** @return array<int, string> the room names on the grid, in page order */
    private function roomNames(array $built): array
    {
        return collect($built['columns'])
            ->flatMap(fn ($column) => array_column($column['rooms'], 'name'))
            ->all();
    }

    private function firstRoom(array $built): array
    {
        return collect($built['columns'])->flatMap(fn ($column) => $column['rooms'])->first();
    }

    private function open(): void
    {
        app(WeekSchedule::class)->open(self::MONDAY);
    }

    /** Everybody, every day — for the tests about shape rather than ticks. */
    private function tickAll(): void
    {
        ScheduleSlot::where('week_start', self::MONDAY)->update(['is_scheduled' => true]);
    }

    private function tick(Child $child, array $dates): void
    {
        ScheduleSlot::where('child_id', $child->id)
            ->whereIn('slot_date', $dates)
            ->update(['is_scheduled' => true]);
    }

    private function makeChild(string $room, string $birthDate, array $attributes = []): Child
    {
        return Child::create($attributes + [
            'lan' => (string) (1000 + Child::count() + 1),
            'status' => 'Active',
            'first_name' => 'Ada',
            'last_name' => 'Child'.Child::count(),
            'classroom' => $room,
            'birth_date' => $birthDate,
        ]);
    }
}
