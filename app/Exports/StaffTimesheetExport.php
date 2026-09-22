<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The week grid, as a spreadsheet.
 *
 * A row per person per day rather than the grid's shape, which puts a person
 * on a row and a day in a column. The screen is laid out that way because it
 * is scanned — a director's eye runs along a row looking for a coloured dot —
 * and a spreadsheet is not scanned, it is sorted, filtered and totalled. In
 * the grid's shape a month is sixty-odd columns and the only thing you can do
 * with it is read it; like this, hours by person is a pivot table and "every
 * late arrival in September" is one filter.
 *
 * Days nobody worked are left out. A row of dashes per person per weekend is
 * noise in a file whose whole purpose is to be filtered.
 *
 * The pay rate is on it, so this is payroll-sensitive and the page it comes
 * from is director-only.
 */
class StaffTimesheetExport implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * What a dot on the grid means, said in words.
     *
     * The file is read away from the screen, often by somebody who never saw
     * it, so a colour is no use — and "OK" is worth stating rather than
     * leaving blank, because a blank reads as unchecked.
     */
    private const STATUS = [
        'on_time' => 'On time',
        'late' => 'Late',
        'missing_out' => 'Missing time out',
    ];

    /** @param  Collection<int, array<string, mixed>>  $rows  The grid's own rows. */
    public function __construct(private Collection $rows, private string $label) {}

    public function title(): string
    {
        return 'Timesheet';
    }

    public function headings(): array
    {
        return [
            'Staff ID', 'Name', 'Role', 'Rate', 'Date', 'Day',
            'Time in', 'Time out', 'Status', 'Week total (h)',
        ];
    }

    public function collection(): Collection
    {
        $out = collect();

        foreach ($this->rows as $row) {
            foreach ($row['days'] as $date => $day) {
                if ($day['empty']) {
                    continue;
                }

                $out->push([
                    $row['staff_id'],
                    $row['name'],
                    $row['role'],
                    $row['rate'],
                    $date,
                    \Illuminate\Support\Carbon::parse($date)->format('D'),
                    $day['in'],
                    // The grid shows an amber dash here. In a file the words
                    // are in the status column, so this stays honestly empty.
                    $day['out'],
                    self::STATUS[$day['status']] ?? '',
                    $row['hours'],
                ]);
            }
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:J1');

        return [1 => ['font' => ['bold' => true]]];
    }

    /** The range the file covers, for its name. */
    public function label(): string
    {
        return $this->label;
    }
}
