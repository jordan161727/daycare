<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page is read fresh from the server, by the browser and by whatever
 * cache the host puts in front of PHP.
 *
 * On the live server a director corrected a child — a new photograph, a new
 * name — and the roster and the attendance board went on showing the old
 * one. A private window showed the correction at once, which is the mark of
 * a cache: the pages were being kept somewhere and handed back without
 * asking. The host runs LiteSpeed, whose server cache can keep a page
 * regardless of what the application said about it unless it is told, in its
 * own header, not to. Nginx's fastcgi cache has an equivalent. Both are set
 * here on every response, which costs nothing when there is no such cache.
 *
 * The browser is told no-store as well. Laravel's default is no-cache, which
 * means "ask first", and that is enough for a page fetched over the network —
 * but the back button restores a page from the back/forward cache without
 * asking anybody, and a roll of children is not something to show as it was
 * when the tab was last looked at. no-store keeps it out of that cache too.
 *
 * A controller that has already said how long its response may be kept — the
 * photograph with its stamped address, the exports with their no-store — is
 * left alone: it knew what it was doing.
 */
class FreshPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Symfony fills Cache-Control in with "no-cache, private" when nothing
        // was set, so the header being present says nothing. A max-age or a
        // no-store does: somebody chose it.
        $chosen = $response->headers->hasCacheControlDirective('max-age')
            || $response->headers->hasCacheControlDirective('no-store')
            || $response->headers->hasCacheControlDirective('s-maxage');

        if (! $chosen) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        // The host's own cache, in the header it reads. The LiteSpeed one is
        // the one that bit; the Nginx one is there for the day the host changes.
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Expires', '0');

        return $response;
    }
}
