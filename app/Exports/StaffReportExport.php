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
 * Any staff report, as a spreadsheet.
 *
 * One exporter for all of them, because the controller builds every report to
 * the same shape — columns and rows — and a file per report would be sixteen
 * classes that differ only in their headings.
 *
 * The rows are written exactly as the screen shows them, hours included: this
 * is the file handed to whoever asked for the report, and they are reading the
 * same period they were shown. Where a report carries a decimal hours column
 * it is already in the rows, because that is the number that gets multiplied.
 */
class StaffReportExport implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * @param  array<int, array{label: string, align: string}>  $columns
     * @param  Collection<int, array<int, mixed>>  $rows
     */
    public function __construct(
        private array $columns,
        private Collection $rows,
        private string $report,
        private string $label,
    ) {}

    public function title(): string
    {
        // A sheet name may not carry the characters a report label can, and is
        // capped at 31 — Excel rejects the file outright rather than trimming.
        return mb_substr(str_replace(['—', ':', '/', '\\', '?', '*', '[', ']'], '-', $this->report), 0, 31);
    }

    public function headings(): array
    {
        return array_column($this->columns, 'label');
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }

    /** The period the file covers, for its name. */
    public function label(): string
    {
        return $this->label;
    }
}
