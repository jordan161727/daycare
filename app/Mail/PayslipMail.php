<?php

namespace App\Mail;

use App\Models\PayrollSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * One employee's payslip, split out of the combined payroll PDF.
 *
 * The subject and body are written by the director on the payroll screen and
 * passed in already filled — placeholder substitution happens once, in
 * fill(), so what they previewed is exactly what is sent.
 */
class PayslipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PayrollSlip $slip,
        public string $subjectLine,
        public string $bodyText,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.payslip',
            with: ['body' => $this->bodyText],
        );
    }

    public function attachments(): array
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', $this->slip->displayName());

        return [
            Attachment::fromData(fn () => $this->pdf, "payslip_{$name}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Substitute the placeholders the payroll screen documents.
     *
     * Unknown placeholders are left alone rather than blanked — a typo showing
     * through as "{frist_name}" in the preview gets fixed, where a silent empty
     * string ships.
     */
    public static function fill(string $template, PayrollSlip $slip, ?string $periodLabel): string
    {
        $period = $periodLabel ?: self::describePeriod($slip);

        return strtr($template, [
            '{first_name}' => $slip->firstName(),
            '{name}' => $slip->displayName(),
            '{period}' => $period,
            '{period_dash}' => $period ? ' - '.$period : '',
            '{period_for}' => $period ? ' for '.$period : '',
            '{period_start}' => self::day($slip->period_start),
            '{period_end}' => self::day($slip->period_end),
            '{check_date}' => self::day($slip->check_date),
        ]);
    }

    /** "Jul 9 - Jul 22, 2026", when the slip carried its own dates. */
    private static function describePeriod(PayrollSlip $slip): string
    {
        if (! $slip->period_start || ! $slip->period_end) {
            return '';
        }

        return $slip->period_start->format('M j').' - '.$slip->period_end->format('M j, Y');
    }

    private static function day(?Carbon $date): string
    {
        return $date?->format('M j, Y') ?? '';
    }
}
