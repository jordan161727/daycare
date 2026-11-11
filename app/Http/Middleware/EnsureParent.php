<?php

namespace App\Http\Middleware;

use App\Models\Person;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The parent app's routes: a parent's token, and nothing else. A staff
 * account signed in on the same browser is not a parent, and a token made
 * for anything but the app does not carry the "parent" ability.
 */
class EnsureParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof Person && $user->tokenCan('parent'), 403, 'This is for the parent app.');

        return $next($request);
    }
}
