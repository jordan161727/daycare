<?php

namespace App\Services\Mail;

/**
 * The "reset your password" email from Forgot password? on the sign-in page,
 * filled in from resources/mail/reset-password-email.html and .txt.
 *
 * A link, not a new password: anybody can type a staff address into that
 * form, and if it issued a password it would also switch off the one they
 * have. A link changes nothing until the account holder follows it.
 */
class PasswordResetEmail
{
    public const SUBJECT = 'Reset your AngelNest password';

    public const CATEGORY = 'password-reset';

    public const PLACEHOLDERS = ['first_name', 'center_name', 'reset_url', 'expires_minutes'];

    /**
     * @param  array<string, string>  $values  One per PLACEHOLDERS entry.
     * @return array{subject: string, html: string, text: string, category: string}
     */
    public static function render(array $values): array
    {
        return ['subject' => self::SUBJECT, 'category' => self::CATEGORY]
            + MailTemplate::fill('reset-password-email', self::PLACEHOLDERS, $values);
    }
}
