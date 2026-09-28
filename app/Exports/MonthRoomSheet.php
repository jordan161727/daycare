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
    ) {}

    public function title(): string
    {
        // A sheet name may not carry the characters a room name can, and is
        // capped at 31 — Excel rejects the file outright rather than trimming.
        return mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $this->room), 0, 31);
    }

    public function array(): array
    {
        $monthName = Carbon::create($this->year, $this->month, 1)->format('F');

        $rows = [
            ['Month: '.$monthName, 'Year: '.$this->year, 'Room: '.$this->room],
            ['Student', 'Line', ...$this->days->map(fn (Carbon $day) => $day->day)->all()],
            ['', '', ...$this->days->map(fn (Carbon $day) => strtoupper($day->format('D')))->all()],
        ];

        foreach ($this->children as $child) {
            foreach (self::LINES as $index => [$label, $key, $isCode]) {
                // Name on the first line, hours on the second, as the form.
                $head = match ($index) {
                    0 => $child['name'],
                    1 => $child['hours'] ?? '',
                    default => '',
                };

                $rows[] = [$head, $label, ...$this->days->map(function (Carbon $day) use ($child, $key, $isCode) {
                    $value = $child['byDay'][$day->toDateString()][$key] ?? null;

                    // Nought is a reading; null is no reading. Keep the difference.
                    return $isCode ? ($value === null ? '' : (string) $value) : ($value ?? '');
                })->all()];
            }
        }

        // The two totals the form carries, along the foot.
        $perDay = $this->days->map(fn (Carbon $day) => $this->children
            ->filter(fn (array $child) => ($child['byDay'][$day->toDateString()]['in'] ?? null) !== null)
            ->count());

        $rows[] = ['Total kids per day', '', ...$perDay->all()];
        $rows[] = ['Total kids for month: '.$perDay->sum()];

        return $rows;
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 24, 'B' => 8];

        // A day is a narrow column — a time is six characters — and a month
        // of them has to fit a landscape page the way the form does.
        foreach (range(0, $this->days->count() - 1) as $offset) {
            $widths[self::column($offset + 3)] = 7;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = self::column($this->days->count() + 2);
        $childRows = $this->children->count() * count(self::LINES);
        $footer = 4 + $childRows;

        // The name and line columns stay put while the month scrolls by.
        $sheet->freezePane('C4');

        // Ruled both ways, as the paper is: an eye running down a column has
        // to be able to tell which row it is on.
        $sheet->getStyle('A2:'.$last.($footer))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('C2:'.$last.($footer))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B4:B'.($footer - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // A rule under every child's block, so four lines read as one child.
        foreach (range(0, max(0, $this->children->count() - 1)) as $index) {
            $bottom = 3 + ($index + 1) * count(self::LINES);
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
