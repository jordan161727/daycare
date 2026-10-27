<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The UPK-4 attendance sheet for a month, to the school district.
 *
 * Plain wording and plain attachments: a PDF of the sheet, and the same
 * rows as a spreadsheet when the office asked for one.
 */
class UpkSheetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public string $pdf,
        public string $pdfName,
        public ?string $csv,
        public ?string $csvName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.payslip', with: ['body' => $this->bodyText]);
    }

    public function attachments(): array
    {
        $files = [Attachment::fromData(fn () => $this->pdf, $this->pdfName)->withMime('application/pdf')];

        if ($this->csv !== null) {
            $files[] = Attachment::fromData(fn () => $this->csv, $this->csvName)->withMime('text/csv');
        }

        return $files;
    }
}
