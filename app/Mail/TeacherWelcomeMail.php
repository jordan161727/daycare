<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "your account is open" email, carrying the one-time password.
 *
 * The password travels in the body rather than behind a link because staff
 * read this on a phone in a classroom, and a reset link that expires before
 * they get a break is worse than a password they can retype.
 */
class TeacherWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $teacher,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.config('app.name').' account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.teacher-welcome',
            with: [
                'name' => $this->teacher->firstName(),
                'email' => $this->teacher->email,
                'password' => $this->temporaryPassword,
                'loginUrl' => route('login'),
            ],
        );
    }
}
