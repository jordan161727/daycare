<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

/**
 * The UPK-4 attendance sheet as the school district's one-page PDF.
 *
 * Drawn to the same layout as the paper form: the key, the month, the
 * school site and the grade across the top; twenty-five numbered rows;
 * five weeks of Monday-to-Friday columns with a tick or an A; the absences
 * totalled; a note; and the head count per day along the bottom.
 */
class UpkSheetPdf
{
    /** @param  array  $sheet  Reports::upkSheet() */
    public function render(array $sheet): string
    {
        $pdf = new Fpdi('L', 'mm', 'Letter');
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Attendance Sheet', 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(8, 17);
        $pdf->Cell(26, 10, "A=Absent\n", 1, 0, 'L');
        $pdf->SetXY(8, 17);
        $pdf->MultiCell(26, 5, "A=Absent\n/ = Present", 1, 'L');
        $pdf->SetXY(44, 20);
        $pdf->Cell(14, 6, 'Month:', 0, 0);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetTextColor(31, 42, 122);
        $pdf->Cell(50, 6, $sheet['month'].' '.$sheet['year'], 'B', 0);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(130, 20);
        $pdf->Cell(22, 6, 'School Site:', 0, 0);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetTextColor(31, 42, 122);
        $pdf->Cell(60, 6, 'Little Angels', 'B', 0);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetXY(228, 20);
        $pdf->Cell(13, 6, 'Grade:', 0, 0);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->SetTextColor(31, 42, 122);
        $pdf->Cell(20, 6, $sheet['grade'] ?? 'UPK4', 'B', 0);
        $pdf->SetTextColor(0);

        // The table: 263 mm across. Name 50, 25 day columns of 6.6, total 14, notes 34.
        $x0 = 8;
        $y = 31;
        $nameW = 50;
        $dayW = 6.6;
        $totW = 14;
        $noteW = 263 - $nameW - 25 * $dayW - $totW;
        $rowH = 5.4;

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetXY($x0, $y);
        $pdf->Cell($nameW, $rowH * 2, 'Last Name, First Name', 1, 0, 'L');
        foreach ($sheet['cols'] as $col) {
            $pdf->Cell($dayW, $rowH, $col['n'], 1, 0, 'C');
        }
        $pdf->Cell($totW, $rowH * 2, 'Total Abs', 1, 0, 'C');
        $pdf->Cell($noteW, $rowH * 2, 'Notes', 1, 1, 'C');
        $pdf->SetXY($x0 + $nameW, $y + $rowH);
        foreach ($sheet['cols'] as $col) {
            $pdf->Cell($dayW, $rowH, $col['dow'], 1, 0, 'C');
        }
        $y += $rowH * 2;

        foreach ($sheet['rows'] as $row) {
            $pdf->SetXY($x0, $y);
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->SetTextColor(0);
            $pdf->Cell(7, $rowH, (string) $row['no'], 'LTB', 0, 'R');
            $pdf->SetTextColor(31, 42, 122);
            $pdf->Cell($nameW - 7, $rowH, $this->latin($row['name']), 'RTB', 0, 'L');
            $pdf->SetFont('Helvetica', 'B', 8);
            foreach ($row['cells'] as $cell) {
                $pdf->Cell($dayW, $rowH, $cell['v'] === '✓' ? '/' : $cell['v'], 1, 0, 'C');
            }
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->Cell($totW, $rowH, $row['tot'], 1, 0, 'C');
            $pdf->SetFont('Helvetica', '', 6);
            $pdf->Cell($noteW, $rowH, $this->latin(mb_strimwidth($row['note'], 0, 34, '…')), 1, 1, 'L');
            $y += $rowH;
        }

        $pdf->SetXY($x0, $y);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->Cell($nameW, $rowH, 'Grand Total', 1, 0, 'L');
        foreach ($sheet['grand'] as $g) {
            $pdf->Cell($dayW, $rowH, $g['v'], 1, 0, 'C');
        }
        $pdf->Cell($totW, $rowH, '', 1, 0);
        $pdf->Cell($noteW, $rowH, '', 1, 1);

        return $pdf->Output('S');
    }

    /** FPDF's core fonts are Latin-1; anything else becomes the nearest it has. */
    private function latin(string $text): string
    {
        return (string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', str_replace(['…', '’', '–'], ['...', "'", '-'], $text));
    }
}
