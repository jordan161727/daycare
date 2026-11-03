<?php

namespace App\Services\Mail;

/**
 * Something that sends one email and says loudly when it could not.
 *
 * An interface so a test can stand a fake in its place
 * ($this->app->instance(Mailer::class, ...)) without touching the network.
 * The real one is MailtrapMailer, bound in AppServiceProvider.
 *
 * Separate from Laravel's Mail facade on purpose: that one still carries the
 * new-account welcome over SMTP. This carries the mail where a failure has to
 * stop the change that caused it — see ForgotPasswordController.
 */
interface Mailer
{
    /**
     * @param  list<array{cid: string, path: string, type: string}>  $inline  Images the HTML shows as src="cid:…".
     *
     * @throws MailSendError when the provider refused it, or did not answer.
     */
    public function sendEmail(
        string $to,
        ?string $toName,
        string $subject,
        string $html,
        string $text,
        string $category,
        array $inline = [],
    ): void;
}
