<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A response the browser must not keep.
 *
 * For the exports. Each one is built fresh on every click, but it goes out
 * as a file response, which stamps itself Cache-Control: public with a
 * Last-Modified date — and a browser may keep such a response and hand it
 * back without asking. From the same address every day, that was a director
 * downloading the roll, correcting a child, downloading again and opening
 * yesterday's file: the record had changed, the page showed it, and the
 * export had not. A private window, with nothing kept, always got the new
 * file.
 *
 * no-store says there is nothing to keep; the other two headers are for
 * proxies and old browsers that only read those. Last-Modified goes, since a
 * date on a file that is never the same twice is a reason to reuse it.
 */
class NeverCached
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->remove('Last-Modified');

        return $response;
    }
}
