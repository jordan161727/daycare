<?php

namespace App\Services;

use App\Mail\UpkSheetMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The monthly email of the UPK-4 sheet to the school district.
 *
 * Settings live in the settings table under upk_email.*: whether it is on,
 * who it goes to, which day and hour, whether the spreadsheet rides along,
 * and whether a month with sign-ins still missing is held for the
 * director's OK first. The command runs every day at the chosen hour and
 * sends the month that just ended on the chosen day; Send now sends it
 * from the page regardless.
 */
class UpkEmail
{
    public const TIMES = ['06:00' => '6:00 AM', '07:00' => '7:00 AM', '09:00' => '9:00 AM'];

    public function __construct(private Reports $reports, private UpkSheetPdf $pdf) {}

    /** @return array{on: bool, to: list<string>, when: string, time: string, csv: bool, hold: bool, log: list<array>} */
    public function settings(): array
    {
        return [
            'on' => (bool) Setting::get('upk_email.on', false),
            'to' => array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('upk_email.to', ''))))),
            'when' => Setting::get('upk_email.when', 'first') === 'weekday' ? 'weekday' : 'first',
            'time' => array_key_exists((string) Setting::get('upk_email.time', '07:00'), self::TIMES) ? (string) Setting::get('upk_email.time', '07:00') : '07:00',
            'csv' => (bool) Setting::get('upk_email.csv', true),
            'hold' => (bool) Setting::get('upk_email.hold', true),
            'log' => json_decode((string) Setting::get('upk_email.log', '[]'), true) ?: [],
        ];
    }

    public function save(array $settings): void
    {
        Setting::put('upk_email.on', $settings['on'] ? '1' : '0');
        Setting::put('upk_email.to', implode(',', $settings['to']));
        Setting::put('upk_email.when', $settings['when']);
        Setting::put('upk_email.time', $settings['time']);
        Setting::put('upk_email.csv', $settings['csv'] ? '1' : '0');
        Setting::put('upk_email.hold', $settings['hold'] ? '1' : '0');
    }

    /** The next send, for the line under the title. */
    public function nextSend(): Carbon
    {
        $first = today()->copy()->addMonthNoOverflow()->startOfMonth();

        if ($this->settings()['when'] === 'weekday') {
            while ($first->dayOfWeekIso > 5) {
                $first->addDay();
            }
        }

        return $first;
    }

    /** Is today the day the month just ended goes out? */
    public function isSendDay(Carbon $today): bool
    {
        $when = $this->settings()['when'];

        if ($when === 'first') {
            return $today->day === 1;
        }

        $first = $today->copy()->startOfMonth();
        while ($first->dayOfWeekIso > 5) {
            $first->addDay();
        }

        return $today->isSameDay($first);
    }

    /** The subject, body and attachment names for one month, as the page previews them. */
    public function draft(int $year, int $month): array
    {
        $sheet = $this->reports->upkSheet($year, $month);
        $label = $sheet['label'];

        return [
            'sheet' => $sheet,
            'subject' => 'UPK-4 attendance — '.$label.' — Little Angels',
            'body' => "Hello,\n\nAttached is the UPK-4 attendance sheet for {$label} ({$sheet['children']} children, {$sheet['schoolDays']} school days).\n\nThank you,\n".config('daycare.company.name'),
            'pdfName' => $sheet['file'].'.pdf',
            'csvName' => $sheet['file'].'.csv',
            'missing' => $sheet['missing'],
        ];
    }

    /**
     * Send one month to a list of addresses.
     *
     * @return int How many it went to.
     */
    public function send(int $year, int $month, array $to, bool $withCsv): int
    {
        $to = array_values(array_unique(array_filter($to)));

        if ($to === []) {
            return 0;
        }

        $draft = $this->draft($year, $month);
        $pdf = $this->pdf->render($draft['sheet']);
        $csv = $withCsv ? Reports::csv($draft['sheet']['csv']) : null;

        Mail::to($to)->send(new UpkSheetMail($draft['subject'], $draft['body'], $pdf, $draft['pdfName'], $csv, $withCsv ? $draft['csvName'] : null));

        return count($to);
    }

    /**
     * The scheduled run: on the send day, the month that just ended goes
     * out — or, when it still has sign-ins missing and the director asked
     * to be told first, a note goes to the directors instead and the month
     * waits for Send now.
     */
    public function runScheduled(Carbon $today): ?string
    {
        $s = $this->settings();

        if (! $s['on'] || ! $this->isSendDay($today)) {
            return null;
        }

        $last = $today->copy()->subMonthNoOverflow();
        $label = $last->format('F');
        $draft = $this->draft($last->year, $last->month);

        if ($draft['sheet']['children'] === 0 || $draft['sheet']['schoolDays'] === 0) {
            $this->log($label.' · no UPK-4 days', 'Skipped', '#8a90a0');

            return 'skipped';
        }

        if ($s['hold'] && $draft['missing'] > 0) {
            $directors = User::where('role', 'admin')->whereNotNull('email')->pluck('email')->all();
            $note = "The UPK-4 sheet for {$label} is ready but {$draft['missing']} sign-in".($draft['missing'] === 1 ? ' is' : 's are')." still missing. It has not been sent. Open Reports → UPK-4 attendance sheet, put the days right, and press Send now.";
            Mail::to($directors)->send(new UpkSheetMail('Check: UPK-4 attendance for '.$label.' — '.$draft['missing'].' sign-ins missing', $note, $this->pdf->render($draft['sheet']), $draft['pdfName'], null, null));
            $this->log('Check: '.$draft['missing'].' sign-ins missing — waiting for your OK', 'Held', '#b45309');

            return 'held';
        }

        $n = $this->send($last->year, $last->month, $s['to'], $s['csv']);
        $this->log($label.' · '.$n.' '.($n === 1 ? 'person' : 'people'), $n ? 'Delivered' : 'Nobody to send to', $n ? '#15803d' : '#be123c');

        return 'sent';
    }

    /** One line in the Sent list, newest first, ten kept. */
    public function log(string $what, string $status, string $colour): void
    {
        $log = $this->settings()['log'];
        array_unshift($log, ['when' => now()->format('M j, g:i A'), 'what' => $what, 'st' => $status, 'col' => $colour]);
        Setting::put('upk_email.log', json_encode(array_slice($log, 0, 10)));
    }
}
