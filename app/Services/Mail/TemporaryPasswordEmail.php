<?php

namespace App\Services\Mail;

/**
 * The "here is a new temporary password" email, filled in from
 * resources/mail/temp-password-email.html and .txt (see MailTemplate).
 */
class TemporaryPasswordEmail
{
    public const SUBJECT = 'Your temporary AngelNest password';

    public const CATEGORY = 'temp-password';

    public const PLACEHOLDERS = ['first_name', 'director_name', 'center_name', 'temp_password', 'expires_at', 'signin_url'];

    /**
     * @param  array<string, string>  $values  One per PLACEHOLDERS entry.
     * @return array{subject: string, html: string, text: string, category: string}
     */
    public static function render(array $values): array
    {
        return ['subject' => self::SUBJECT, 'category' => self::CATEGORY]
            + MailTemplate::fill('temp-password-email', self::PLACEHOLDERS, $values);
    }
}
