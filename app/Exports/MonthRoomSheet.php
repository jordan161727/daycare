<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One room's month, laid out as the paper form.
 *
 *   Month: September    Year: 2026    Room: Preschool 3
 *   Student      Line    1   2   3   4   5 …
 *                        TU  WE  TH  FR  SA …
 *   Adkins, Maeve  In    7:45a  8:10a …
 *   8:00–5:00      health   0      0  …
 *                  Out   5:15p  5:20p …
 *                  health   0      0  …
 *   …
 *   Total kids per day   6   5   6 …
 *   Total kids for month: 118
 *
 * Four lines a child, exactly as the form: the arrival, the check taken
 * then, the departure, the check taken then. The name sits on the first of
 * the four and the contracted hours on the second, which is where the eye
 * finds them on the paper, and it means no merged cells — a merged block is
 * the thing that breaks the moment somebody sorts or filters the sheet.
 *
 * Times are the app's own short clock ("7:45a") so the file reads like the
 * screen. A code is a number or nothing; nought is printed, because a
 * child who was checked and well is a different fact from one nobody
 * checked, and a blank would say the second.
 *
 * A day with more than one entry — the child left and came back, or a room
 * that books a morning and an afternoon — prints every one of them, and
 * every one in its own cell. The day widens: it takes as many columns as
 * the most entries any child in the room had on it. A child with several
 * entries has each arrival headed "1st", "2nd", "3rd" in its own cell, with
 * the day's number and weekday spanning them.
 * Every child keeps four lines; a child with one entry on a wide day
 * fills the whole day with it, the spare columns merged into that one cell
 * rather than left blank beside it as if something were missing. Days
 * nobody came back on stay one column, so an ordinary month looks exactly
 * like the form.
 * The paper only ever had one pair of lines, and what it did with a second
 * arrival was lose it.
 */
class MonthRoomSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    /** The four lines, in the form's order: label, the byDay key, whether it is a code. */
    private const LINES = [
        ['In', 'in', false],
        ['health', 'in_code', true],
        ['Out', 'out', false],
        ['health', 'out_code', true],
    ];

    /**
     * @param  Collection<int, array>  $children  this room's rows, with byDay
     * @param  Collection<int, Carbon>  $days
     */
    public function __construct(
        private string $room,
        private Collection $children,
        private Collection $days,
        private int $month,
        private int $year,
        // " · Register" or " · Door" when the workbook carries both records.
        private string $suffix = '',
    ) {}

    public function title(): string
    {
        // A sheet name may not carry the characters a room name can, and is
        // capped at 31 — Excel rejects the file outright rather than trimming.
        return mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $this->room.$this->suffix), 0, 31);
    }

    /** The header rows above the children: title, day number, weekday. */
    private const HEADER_ROWS = 3;

    public function array(): array
    {
        $monthName = Carbon::create($this->year, $this->month, 1)->format('F');
        $slots = $this->slots();

        // The day number and the weekday sit over the first of a day's
        // columns and span the rest.
        $rows = [
            ['Month: '.$monthName, 'Year: '.$this->year, 'Room: '.$this->room.($this->suffix ? ' ('.trim($this->suffix, ' ·').')' : '')],
            ['Student', 'Line', ...$this->spread($slots, fn (Carbon $day, int $slot) => $slot === 0 ? $day->day : '')],
            ['', '', ...$this->spread($slots, fn (Carbon $day, int $slot) => $slot === 0 ? strtoupper($day->format('D')) : '')],
        ];

        foreach ($this->children as $child) {
            foreach (self::LINES as $index => [$label, $key, $isCode]) {
                // Name on the first line, hours on the second, as the form.
                $head = match ($index) {
                    0 => $child['name'],
                    1 => $child['hours'] ?? '',
                    default => '',
                };

                $rows[] = [$head, $label, ...$this->spread($slots, function (Carbon $day, int $slot) use ($child, $key, $index) {
                    $onDay = $child['byDay'][$day->toDateString()] ?? null;
                    $entries = $onDay['entries'] ?? [];

                    // The entry this column is, where the day has it. A day
                    // with no entries list — rows built before there was
                    // one — still reads its first column off the day.
                    $entry = $entries[$slot] ?? ($slot === 0 ? $onDay : null);
                    $value = self::cell($entry[$key] ?? null);

                    // Which entry it is, on the line above the arrival —
                    // "1st" over 11:59a, "2nd" over 12:00p — and only for a
                    // child who has more than one that day. The rest of the
                    // sheet carries no such label, so a child who came once
                    // reads as on the form.
                    if ($index === 0 && $value !== '' && count($entries) > 1) {
                        $value = self::ordinal($slot + 1)."\n".$value;
                    }

                    return $value;
                })];
            }
        }

        // The two totals the form carries, along the foot: a child counts
        // once a day, however many times the door opened.
        $perDay = $this->days->mapWithKeys(fn (Carbon $day) => [$day->toDateString() => $this->children
            ->filter(fn (array $child) => ($child['byDay'][$day->toDateString()]['in'] ?? null) !== null)
            ->count()]);

        $rows[] = ['Total kids per day', '', ...$this->spread($slots, fn (Carbon $day, int $slot) => $slot === 0 ? $perDay[$day->toDateString()] : '')];
        $rows[] = ['Total kids for month: '.$perDay->sum()];

        return $rows;
    }

    /**
     * A time or a code as the cell carries it.
     *
     * Nought is a reading; null is no reading. Keep the difference: a
     * code of 0 prints as "0", and only no code at all prints as nothing.
     */
    private static function cell(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * How many columns each day takes: the most entries any child in the
     * room had on it, and at least one. Keyed by the day's date.
     *
     * @return array<string, int>
     */
    private function slots(): array
    {
        $slots = [];

        foreach ($this->days as $day) {
            $iso = $day->toDateString();
            $slots[$iso] = max([1, ...$this->children->map(fn (array $child) => count($child['byDay'][$iso]['entries'] ?? []))->all()]);
        }

        return $slots;
    }

    /** One value a column, across every day and each of its columns, in order. */
    private function spread(array $slots, callable $value): array
    {
        $cells = [];

        foreach ($this->days as $day) {
            foreach (range(0, $slots[$day->toDateString()] - 1) as $slot) {
                $cells[] = $value($day, $slot);
            }
        }

        return $cells;
    }

    /** "1st", "2nd", "3rd", "4th" … */
    private static function ordinal(int $n): string
    {
        $suffix = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n.$suffix;
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 24, 'B' => 8];

        // A day is a narrow column — a time is six characters — and a month
        // of them has to fit a landscape page the way the form does.
        foreach (range(0, array_sum($this->slots()) - 1) as $offset) {
            $widths[self::column($offset + 3)] = 7;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $slots = $this->slots();
        $last = self::column(array_sum($slots) + 2);
        $first = self::HEADER_ROWS + 1;
        $childRows = $this->children->count() * count(self::LINES);
        $footer = $first + $childRows;

        // The name and line columns stay put while the month scrolls by.
        $sheet->freezePane('C'.$first);

        // Ruled both ways, as the paper is: an eye running down a column has
        // to be able to tell which row it is on.
        $sheet->getStyle('A2:'.$last.($footer))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('C2:'.$last.($footer))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        // An arrival headed "1st" is two lines in one cell; wrapped, so Excel
        // shows the label over the time rather than one long string.
        $sheet->getStyle('C'.$first.':'.$last.($footer - 1))->getAlignment()->setWrapText(true);
        $sheet->getStyle('B'.$first.':B'.($footer - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // A day of several columns is headed once: its number and weekday
        // span them. The children's rows merge only the day's spare columns —
        // see below — so what a cell says is never split across two.
        $column = 3;
        foreach ($this->days as $day) {
            $width = $slots[$day->toDateString()];
            if ($width > 1) {
                $from = self::column($column);
                $to = self::column($column + $width - 1);
                $sheet->mergeCells($from.'2:'.$to.'2');
                $sheet->mergeCells($from.'3:'.$to.'3');
                // A rule down the day's edges, so its columns read as one day.
                $sheet->getStyle($from.'2:'.$from.$footer)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM);
                $sheet->getStyle($to.'2:'.$to.$footer)->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM);

                // A child with fewer entries than the day is wide gets the
                // rest of the day as one field: their last entry spans the
                // columns after it, so a child who came once on a day
                // somebody else came three times fills the day with one
                // cell rather than one cell and two blanks that read as
                // something missing. The foot's count spans the same way.
                $this->children->each(function (array $child, int $index) use ($sheet, $day, $width, $column, $to) {
                    $used = max(1, count($child['byDay'][$day->toDateString()]['entries'] ?? []));
                    if ($used >= $width) {
                        return;
                    }
                    $from = self::column($column + $used - 1);
                    $top = self::HEADER_ROWS + 1 + $index * count(self::LINES);
                    foreach (range($top, $top + count(self::LINES) - 1) as $row) {
                        $sheet->mergeCells($from.$row.':'.$to.$row);
                    }
                });
                $sheet->mergeCells($from.$footer.':'.$to.$footer);
            }
            $column += $width;
        }

        // A rule under every child's block, so four lines read as one child.
        foreach (range(0, max(0, $this->children->count() - 1)) as $index) {
            $bottom = self::HEADER_ROWS + ($index + 1) * count(self::LINES);
            $sheet->getStyle('A'.$bottom.':'.$last.$bottom)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true]],
            3 => ['font' => ['size' => 8, 'color' => ['rgb' => '64748B']]],
            $footer => ['font' => ['bold' => true]],
            $footer + 1 => ['font' => ['bold' => true]],
        ];
    }

    /** A 1-based column number as a letter: 1 → A, 27 → AA. */
    private static function column(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }
}
