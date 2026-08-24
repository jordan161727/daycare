<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins an account still on its emailed password to the change form.
 *
 * The account is real and the login succeeded — this is not an authorisation
 * check. It exists so a password that was typed by the director and sent over
 * email stops being the thing that guards a payroll screen.
 */
class RequirePasswordChange
{
    /** Routes that would otherwise be unreachable, so the redirect can resolve. */
    private const ALLOWED = [
        'password.change',
        'password.change.update',
        'logout',
        'csrf.token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->mustChangePassword() || in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        // Long-lived screens poll in the background. Answering those with a
        // redirect to an HTML form gives a confusing parse error in the
        // console, so they get told plainly instead.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Please choose a new password before continuing.'], 403);
        }

        // This redirect is an extra hop the sender did not know about, and a
        // flash only survives one. Without reflashing, a teacher's first
        // sign-in of all — the one that lands here — silently swallows the
        // "clocked in at 7:02" they most need to see.
        $request->session()->reflash();

        return redirect()->route('password.change');
    }
}
