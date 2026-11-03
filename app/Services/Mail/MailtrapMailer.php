<?php

namespace App\Services\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends through Mailtrap's HTTP API — not SMTP, which shared hosting tends to
 * block or throttle without saying so.
 *
 * Two modes. "sandbox" posts to a Mailtrap test inbox: every email lands there
 * and none reaches a real person, which is what a staging copy full of real
 * staff addresses needs. "live" posts to the sending API and delivers.
 *
 * The settings are checked when this is built, not when the app boots: a
 * missing token should stop a password email, not take every page of the site
 * down with it.
 *
 * Logged: the category, the address masked, the status and the time. Never
 * the body — the one email this exists for has a password in it — and never
 * the token.
 */
class MailtrapMailer implements Mailer
{
    public const SANDBOX_URL = 'https://sandbox.api.mailtrap.io/api/send/';

    public const LIVE_URL = 'https://send.api.mailtrap.io/api/send';

    private const TIMEOUT_SECONDS = 10;

    private string $token;

    private string $url;

    private string $fromEmail;

    private string $fromName;

    /** @param  array{token?: ?string, inbox_id?: ?string, mode?: ?string, from?: ?string, from_name?: ?string}  $config */
    public function __construct(array $config)
    {
        $mode = blank($config['mode'] ?? null) ? 'sandbox' : strtolower(trim($config['mode']));

        if (! in_array($mode, ['sandbox', 'live'], true)) {
            throw new MailConfigError("MAIL_MODE must be \"sandbox\" or \"live\", not \"{$mode}\".");
        }

        if (blank($config['token'] ?? null)) {
            throw new MailConfigError('MAILTRAP_API_TOKEN is not set, so no email can be sent. Add it to .env.');
        }

        if ($mode === 'sandbox' && blank($config['inbox_id'] ?? null)) {
            throw new MailConfigError('MAIL_MODE is sandbox but MAILTRAP_INBOX_ID is not set. Add the test inbox id to .env, or set MAIL_MODE=live.');
        }

        if (blank($config['from'] ?? null)) {
            throw new MailConfigError('MAIL_FROM is not set: Mailtrap needs a From address.');
        }

        $this->token = $config['token'];
        $this->url = $mode === 'sandbox' ? self::SANDBOX_URL.rawurlencode(trim($config['inbox_id'])) : self::LIVE_URL;
        $this->fromEmail = $config['from'];
        $this->fromName = (string) ($config['from_name'] ?? '');
    }

    public function sendEmail(
        string $to,
        ?string $toName,
        string $subject,
        string $html,
        string $text,
        string $category,
        array $inline = [],
    ): void {
        $recipient = ['email' => $to];

        if (filled($toName)) {
            $recipient['name'] = $toName;
        }

        $payload = [
            'from' => ['email' => $this->fromEmail, 'name' => $this->fromName],
            'to' => [$recipient],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'category' => $category,
        ];

        // Inline, not linked: a picture on our own server is one the mail
        // client blocks by default and one a local copy cannot serve at all.
        foreach ($inline as $image) {
            $payload['attachments'][] = [
                'content' => base64_encode(file_get_contents($image['path'])),
                'filename' => basename($image['path']),
                'type' => $image['type'],
                'disposition' => 'inline',
                'content_id' => $image['cid'],
            ];
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($this->url, $payload);
        } catch (ConnectionException $e) {
            $this->log('warning', 'not sent', $category, $to, null);

            throw new MailSendError(null, $this->redact('Mailtrap did not answer: '.$e->getMessage()));
        }

        if (! $response->successful()) {
            $this->log('warning', 'refused', $category, $to, $response->status());

            throw new MailSendError($response->status(), $this->redact('Mailtrap refused the email ('.$response->status().'): '.$this->reason($response->json(), $response->body())));
        }

        $this->log('info', 'sent', $category, $to, $response->status());
    }

    /** "b***@email.com" — enough to tell two staff apart in a log, not enough to mail them. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    /** Mailtrap answers { "errors": ["..."] }, occasionally { "error": "..." }. */
    private function reason(mixed $json, string $body): string
    {
        if (is_array($json)) {
            $errors = $json['errors'] ?? $json['error'] ?? $json['message'] ?? null;

            if (is_array($errors)) {
                return implode('; ', array_map(fn ($e) => is_scalar($e) ? (string) $e : json_encode($e), $errors));
            }

            if (is_string($errors)) {
                return $errors;
            }
        }

        return mb_substr(trim($body), 0, 300) ?: 'no reason given';
    }

    /** Belt and braces: nothing Mailtrap says back should carry the token, but if it ever echoes it, not into our logs. */
    private function redact(string $message): string
    {
        return str_replace($this->token, '[token]', $message);
    }

    private function log(string $level, string $what, string $category, string $to, ?int $status): void
    {
        Log::{$level}('[mail] '.$category.' '.$what, [
            'category' => $category,
            'to' => self::mask($to),
            'status' => $status,
            'at' => now()->toIso8601String(),
        ]);
    }
}
