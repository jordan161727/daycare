<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
    ],

    // Texts to families (Phase 9). Used when daycare.messages.driver is twilio.
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
    ],

    // Pushes to the parent app go through Expo (ParentPush). The access token
    // is optional unless "enhanced push security" is turned on in Expo.
    'expo' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    // Email over Mailtrap's HTTP API (App\Services\Mail\MailtrapMailer).
    // "sandbox" lands every email in a Mailtrap test inbox; "live" delivers.
    'mailtrap' => [
        'token' => env('MAILTRAP_API_TOKEN'),
        'inbox_id' => env('MAILTRAP_INBOX_ID'),
        'mode' => env('MAIL_MODE', 'sandbox'),
        'from' => env('MAIL_FROM', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('MAIL_FROM_NAME', 'AngelNest'),
    ],

];
