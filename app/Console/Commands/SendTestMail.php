<?php

namespace App\Console\Commands;

use App\Mail\TeacherWelcomeMail;
use App\Models\User;
use App\Services\TemporaryPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Proves the mail settings before a real teacher depends on them.
 *
 * Sends the actual welcome email rather than a generic "test" body, because
 * the things that go wrong on shared hosting — a From address the provider
 * refuses, a link built from the wrong APP_URL — only show up in the real one.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {email : Where to send it}';

    protected $description = 'Send yourself the teacher welcome email to check the mail settings';

    public function handle(): int
    {
        $address = $this->argument('email');

        $this->line('  mailer: '.config('mail.default'));
        $this->line('  host:   '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  from:   '.config('mail.from.address'));
        $this->line('  login:  '.route('login'));
        $this->newLine();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log", so nothing will be sent — look in storage/logs/laravel.log instead.');
        }

        // Unsaved on purpose: this must not leave a stray account behind.
        $teacher = new User(['name' => 'Test Teacher', 'email' => $address]);

        try {
            Mail::to($address)->send(new TeacherWelcomeMail($teacher, TemporaryPassword::generate()));
        } catch (\Throwable $e) {
            $this->error('Could not send: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sent to '.$address.'. If it is not there in a minute, check the spam folder.');

        return self::SUCCESS;
    }
}
