<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side protections, on every response (QA #44, 12 Nov 2026).
 *
 * Framing is SAMEORIGIN, not DENY: the app frames its own pages — the payroll
 * preview, the enrollment-form pages on document review, the room tablet on
 * Staff › Kiosk and on the presence board — and DENY would blank all four.
 * Another site still cannot frame any of it, which is what the header is for.
 *
 * The Content-Security-Policy goes out report-only. The pages lean on inline
 * <script> blocks and Alpine, which evaluates its attribute expressions, so
 * an enforced policy would have to allow 'unsafe-inline' and 'unsafe-eval'
 * anyway. Report-only lets the browser console show what an enforced policy
 * would block, so it can be tightened and switched to enforcing with
 * DAYCARE_CSP_ENFORCE=true without guessing.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        if ($request->isSecure()) {
            // A year; not includeSubDomains — the rest of the domain is not ours to commit.
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

        $csp = $this->policy();
        $h->set(config('daycare.security.csp_enforce') ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $csp);

        return $response;
    }

    private function policy(): string
    {
        // While `npm run dev` runs, the scripts, styles and hot reload come from the Vite server.
        // Schemes rather than the address: Vite's is often http://[::1]:5173,
        // and a policy cannot name an IPv6 address at all. Only ever on a
        // machine running `npm run dev`; a server serving built files never
        // has the hot file.
        $dev = Vite::isRunningHot() ? ' http: ws:' : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$dev,
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net".$dev,
            "font-src 'self' data: https://fonts.gstatic.com https://fonts.bunny.net",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "connect-src 'self'".$dev,
            // The assistance page's explainer video.
            "frame-src 'self' https://www.youtube-nocookie.com",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}
