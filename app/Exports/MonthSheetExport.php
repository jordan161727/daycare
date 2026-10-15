<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The month as the paper form, as a workbook.
 *
 * The form the centre keeps is one sheet per room — "Month: September, Year:
 * 2026, Room: Preschool 3" across the top, four lines a child, a column a
 * day, "Total kids per day" along the foot. So the workbook is one worksheet
 * per room, each laid out exactly so, and nothing else: no summary sheet, no
 * legend sheet, because the person opening this has the paper form in their
 * other hand and is checking the two against each other.
 *
 * Built from the rows MonthSheetController::month() builds for the page, so
 * the file and the screen cannot describe different months.
 */
class MonthSheetExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  Collection<int, array>  $rows  a child each, with byDay
     * @param  Collection<int, \Illuminate\Support\Carbon>  $days  every day of the month
     * @param  array<string, int>  $perDay  children in, by date
     */
    public function __construct(
        private Collection $rows,
        private Collection $days,
        private array $perDay,
        private int $month,
        private int $year,
        private ?string $room = null,
        private ?Collection $comments = null,
        // The door's rows, when the workbook carries both records.
        private ?Collection $doorRows = null,
    ) {}

    public function sheets(): array
    {
        $rooms = $this->rows->groupBy('room')->sortKeys();

        if ($this->room !== null) {
            $rooms = $rooms->only([$this->room]);
        }

        // A month with nobody on the roll still opens as a workbook with one
        // empty room sheet rather than as a file Excel refuses.
        // With the door's rows as well, each record is its own run of room
        // sheets, the title saying which — "Infant · Register", "Infant · Door".
        $both = $this->doorRows !== null;
        $sheets = $this->roomSheets($rooms, $both ? ' · Register' : '');

        if ($both) {
            $doorRooms = $this->doorRows->groupBy('room')->sortKeys();
            if ($this->room !== null) {
                $doorRooms = $doorRooms->only([$this->room]);
            }
            $sheets = array_merge($sheets, $this->roomSheets($doorRooms, ' · Door'));
        }

        // The back of the paper sheet — the comments — as a last sheet, when
        // the month is exported with them.
        if ($this->comments !== null) {
            $sheets[] = new MonthCommentsSheet($this->comments);
        }

        return $sheets;
    }

    /** A sheet per room, or one empty sheet rather than a file Excel refuses. */
    private function roomSheets(Collection $rooms, string $suffix): array
    {
        if ($rooms->isEmpty()) {
            return [new MonthRoomSheet($this->room ?? 'Roll', collect(), $this->days, $this->month, $this->year, $suffix)];
        }

        return $rooms->map(fn (Collection $children, string $room) => new MonthRoomSheet(
            $room,
            $children->values(),
            $this->days,
            $this->month,
            $this->year,
            $suffix,
        ))->values()->all();
    }
}
