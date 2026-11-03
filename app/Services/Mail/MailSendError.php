<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * The provider did not take the email: a non-2xx answer, or no answer at all.
 *
 * Carries the HTTP status (null when the request never got one — a timeout or
 * a refused connection) and the provider's own words, never the API token.
 */
class MailSendError extends RuntimeException
{
    public function __construct(public readonly ?int $status, string $message)
    {
        parent::__construct($message);
    }
}
