<?php

namespace App\Services\Mail;

use RuntimeException;

/** The Mailtrap settings in .env are missing or do not make sense. */
class MailConfigError extends RuntimeException {}
