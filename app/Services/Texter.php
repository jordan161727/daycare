<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The thing that actually sends a text.
 *
 * Two drivers. "log" is the default until the centre has an SMS account: the
 * text is written to the log and reported as delivered, so the whole of
 * Messages can be used and read without a number leaving the building.
 * "twilio" posts to Twilio's REST API with the account in config/services.
 *
 * Kept to one method on purpose: Messaging decides who, what and from which
 * line; this only carries it.
 */
class Texter
{
    /**
     * @param  list<string>  $mediaUrls  Public URLs of photos to attach (MMS).
     * @return bool Whether the provider took it.
     */
    public function send(string $from, string $to, string $body, array $mediaUrls = []): bool
    {
        $driver = config('daycare.messages.driver', 'log');

        if ($driver === 'twilio') {
            return $this->twilio($from, $to, $body, $mediaUrls);
        }

        Log::info('[sms] '.$from.' → '.$to.': '.$body.($mediaUrls ? ' ['.count($mediaUrls).' media]' : ''));

        return true;
    }

    private function twilio(string $from, string $to, string $body, array $mediaUrls): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if (blank($sid) || blank($token)) {
            Log::warning('[sms] Twilio is the driver but TWILIO_SID / TWILIO_TOKEN are not set; text to '.$to.' not sent.');

            return false;
        }

        $payload = ['From' => $from, 'To' => $to, 'Body' => $body];

        foreach ($mediaUrls as $url) {
            $payload['MediaUrl'][] = $url;
        }

        try {
            return Http::withBasicAuth($sid, $token)
                ->asForm()
                ->timeout(20)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload)
                ->successful();
        } catch (\Throwable $e) {
            Log::warning('[sms] Twilio refused a text to '.$to.': '.$e->getMessage());

            return false;
        }
    }

    /** "+17165550143" — what a provider wants; digits are what the record keeps. */
    public static function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return '+'.$digits;
    }
}
