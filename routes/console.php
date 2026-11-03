<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Proves the Mailtrap settings (MAILTRAP_API_TOKEN, MAILTRAP_INBOX_ID,
// MAIL_MODE) through the same mailer the temporary-password email uses. In
// sandbox mode it lands in the Mailtrap test inbox, not the address given.
Artisan::command('send-mail {email : Who it is addressed to}', function () {
    $config = config('services.mailtrap');

    $this->line('  mode:  '.($config['mode'] ?: 'sandbox'));
    $this->line('  inbox: '.($config['inbox_id'] ?: '(not set)'));
    $this->line('  from:  '.$config['from'].' ('.$config['from_name'].')');
    $this->line('  token: '.(filled($config['token']) ? 'set' : '(not set)'));
    $this->newLine();

    try {
        app(\App\Services\Mail\Mailer::class)->sendEmail(
            to: $this->argument('email'),
            toName: null,
            subject: 'You are awesome!',
            html: '<p>Congrats for sending a test email with Mailtrap from AngelNest!</p>',
            text: 'Congrats for sending a test email with Mailtrap from AngelNest!',
            category: 'Integration Test',
        );
    } catch (\App\Services\Mail\MailConfigError|\App\Services\Mail\MailSendError $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $this->info(($config['mode'] ?: 'sandbox') === 'live'
        ? 'Sent. Check Mailtrap → Email Logs.'
        : 'Sent. Check Mailtrap → Sandboxes → your inbox.');

    return 0;
})->purpose('Send a test email through the Mailtrap HTTP API');

// A room follows an age, so it can only change at a date boundary — a birthday.
// Once a night, before anyone opens the sheet, is enough.
Schedule::command('classrooms:sync')->dailyAt('00:05');

// A first day agreed for Monday starts the child on Monday; a last day on
// Friday keeps them on the check-in screen until Friday is over.
Schedule::command('children:sync-enrollments')->dailyAt('00:10');

// The closing-time safety net (HANDOFF §5.3.2): anyone still on the clock
// when the centre closes is flagged for the office and taken out of their
// room. A quarter past closing rather than on the hour, so the person who
// clocks out at 6:00 sharp is not flagged by a job that ran at 6:00 sharp.
Schedule::command('staff:close-day')->dailyAt(rescue(function () {
    $closes = (string) app(\App\Services\CentreSettings::class)->get('centre_closes');

    return \Illuminate\Support\Carbon::createFromFormat('H:i', substr($closes, 0, 5))->addMinutes(15)->format('H:i');
}, '18:15', false));

// The UPK-4 sheet to the school district (Phase 8.2). Runs every day at the
// hour the office chose and decides for itself whether today is the day.
Schedule::command('reports:upk-email')->dailyAt(rescue(fn () => (string) \App\Models\Setting::get('upk_email.time', '07:00'), '07:00', false));
