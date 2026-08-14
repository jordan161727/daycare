<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use setasign\Fpdi\Fpdi;

/**
 * A stand-in for the combined PDF a payroll provider exports.
 *
 * Testing the mailer needs a multi-page document with real legal names on it,
 * and the real thing is every employee's pay — not a file to keep copies of on
 * a developer's machine. This builds an equivalent from the staff already in
 * the database.
 */
class MakeDemoPayroll extends Command
{
    protected $signature = 'payroll:demo
        {--out= : Where to write the PDF (default: storage/app/demo-payroll.pdf)}
        {--period-start=2026-07-09}
        {--period-end=2026-07-22}
        {--check-date=2026-07-26}
        {--clean : Skip the deliberately awkward pages}';

    protected $description = 'Build a fake combined payroll PDF from the seeded staff, for testing the payslip mailer';

    public function handle(): int
    {
        $staff = User::teachers()->whereNotNull('legal_name')->get();

        if ($staff->isEmpty()) {
            $this->error('No staff with a legal name on file. Run: php artisan db:seed --class=StaffSeeder');

            return self::FAILURE;
        }

        $pdf = new Fpdi;
        $pages = 0;

        foreach ($staff as $person) {
            $this->page($pdf, $person->legal_name, $person->pay_rate);
            $pages++;

            // Maria gets a second page so the "consecutive pages become one
            // payslip" grouping has something to group.
            if (! $this->option('clean') && $person->legal_name === 'Maria G. Santos') {
                $this->page($pdf, $person->legal_name, $person->pay_rate, continuation: true);
                $pages++;
            }
        }

        if (! $this->option('clean')) {
            // Somebody who left. The matcher should leave this unassigned rather
            // than attach it to whoever is closest.
            $this->page($pdf, 'Jonathan P. Whitfield', 15.00);
            $pages++;
        }

        $out = $this->option('out') ?: storage_path('app/demo-payroll.pdf');
        file_put_contents($out, $pdf->Output('S'));

        $this->info("Wrote {$pages} pages for {$staff->count()} staff to {$out}");

        if (! $this->option('clean')) {
            $this->line('Includes one two-page payslip and one page naming somebody not on staff.');
        }

        return self::SUCCESS;
    }

    private function page(Fpdi $pdf, string $name, ?string $rate, bool $continuation = false): void
    {
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 12, 'Little Angels Day Care', 0, 1);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 8, 'Statement of Earnings'.($continuation ? ' (continued)' : ''), 0, 1);
        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell(0, 9, 'Employee: '.$name, 0, 1);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(0, 8, sprintf(
            'Pay Period: %s - %s',
            $this->usDate($this->option('period-start')),
            $this->usDate($this->option('period-end'))
        ), 0, 1);
        $pdf->Cell(0, 8, 'Check Date: '.$this->usDate($this->option('check-date')), 0, 1);
        $pdf->Ln(6);

        $hours = $continuation ? 0 : 80;
        $gross = $hours * (float) ($rate ?? 15);

        foreach ([
            ['Regular hours', $continuation ? '—' : number_format($hours, 2)],
            ['Rate', '$'.number_format((float) ($rate ?? 15), 2)],
            ['Gross pay', '$'.number_format($gross, 2)],
            ['Taxes withheld', '$'.number_format($gross * 0.22, 2)],
            ['Net pay', '$'.number_format($gross * 0.78, 2)],
        ] as [$label, $value]) {
            $pdf->Cell(70, 8, $label, 0, 0);
            $pdf->Cell(0, 8, $value, 0, 1);
        }

        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->Cell(0, 6, 'Sample document generated for testing. Not a real payslip.', 0, 1);
    }

    private function usDate(string $iso): string
    {
        return \Illuminate\Support\Carbon::parse($iso)->format('m/d/Y');
    }
}
