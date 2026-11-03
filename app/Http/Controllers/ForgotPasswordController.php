<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mail\MailConfigError;
use App\Services\Mail\Mailer;
use App\Services\Mail\MailSendError;
use App\Services\Mail\PasswordResetEmail;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as Passwords;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Forgot password? on the sign-in page.
 *
 * Emails a link, not a password. Anybody can type a staff address into the
 * form; a link changes nothing until the account holder follows it, where a
 * new password would also have switched off the one they have.
 *
 * The page answers the same whether or not the address has an account, so it
 * cannot be used to find out who works here. Laravel's own password broker
 * keeps the tokens (password_reset_tokens, 60 minutes, one per account per
 * minute); only the sending is ours, through the Mailtrap mailer.
 */
class ForgotPasswordController extends Controller
{
    public const SENT = 'If that address belongs to an AngelNest account, we’ve emailed it a link to choose a new password. It works for 60 minutes.';

    public function create()
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Before the lookup, so a site with no mail set up says so for every
        // address alike rather than only for the ones that exist.
        try {
            $mailer = app(Mailer::class);
        } catch (MailConfigError $e) {
            report($e);

            return back()->withInput()->with('error', 'Email is not set up on this site yet, so no link can be sent. Ask the director for a temporary password.');
        }

        $user = User::where('email', $data['email'])->first();

        /** @var PasswordBroker $broker */
        $broker = Passwords::broker();

        // A second request within the minute is answered as if sent: the
        // first link is still in the inbox, and a fresh one would void it.
        if ($user !== null && ! $broker->getRepository()->recentlyCreatedToken($user)) {
            $token = $broker->createToken($user);
            $minutes = (int) config('auth.passwords.users.expire', 60);

            $email = PasswordResetEmail::render([
                'first_name' => $user->firstName(),
                'center_name' => Setting::get('company.name', config('daycare.company.name')),
                'reset_url' => route('password.reset', ['token' => $token, 'email' => $user->email]),
                'expires_minutes' => (string) $minutes,
            ]);

            try {
                $mailer->sendEmail(
                    to: $user->email,
                    toName: $user->name,
                    subject: $email['subject'],
                    html: $email['html'],
                    text: $email['text'],
                    category: $email['category'],
                    inline: $email['inline'],
                );
            } catch (MailSendError $e) {
                // Logged, and the token withdrawn, but answered like any
                // other request: a different reply here would say the
                // address has an account.
                report($e);
                $broker->deleteToken($user);
            }
        }

        return back()->with('status', self::SENT);
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $status = Passwords::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'temp_password_expires_at' => null,
                    'password_changed_at' => now(),
                    // Ends "remember me" on every device as well.
                    'remember_token' => Str::random(60),
                ])->save();

                $user->endSessions();

                rescue(fn () => LoginEvent::create([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'outcome' => LoginEvent::PASSWORD_RESET,
                    'method' => 'link',
                    'ip_address' => $request->ip(),
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                ]), null, false);
            },
        );

        if ($status !== Passwords::PASSWORD_RESET) {
            // Wrong address, or a link that is old, used or not ours: the
            // same answer for each, and the way to get a working one.
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'That link has expired or was already used. Ask for a new one below.']);
        }

        return redirect()->route('login')->with('success', 'Your password has been changed. Sign in with your new one.');
    }
}
