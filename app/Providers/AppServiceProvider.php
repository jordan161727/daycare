<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The signed-out screens — the door kiosk and the staff time clock —
         * are the only routes in this app that answer without a login, so they
         * are rate limited. What they are protected against is somebody walking
         * a keyspace, and only the two endpoints that check a secret can be
         * walked. Those get a tight budget; everything else on those screens
         * gets a loose one.
         *
         * They used to share a single thirty-a-minute bucket keyed on the IP,
         * and that bucket counted page loads and button presses too. Every
         * tablet in a centre leaves by one address, so at shift change a dozen
         * staff punching in — a page load, an identify and a punch each — spent
         * the budget in under a minute and the screens started answering 429 to
         * everyone, families included. The clock worked in the morning and not
         * at eight fifty, which reads as a flaky device rather than a limit.
         *
         * Keyed on the address rather than the session: a script would simply
         * not send the cookie back.
         */

        // Where a secret is checked, and so where guessing happens: a PIN at
        // the door, a PIN or a card at the clock. The path is in the key so the
        // two screens cannot exhaust each other — a busy door must not be able
        // to stop staff clocking in. Thirty a minute is generous for a parent
        // with three children and nowhere near enough to walk a keyspace.
        RateLimiter::for('kiosk-secret', fn (Request $request) => Limit::perMinute(30)
            ->by($request->ip().'|'.$request->path()));

        // Loading the screen, and pressing a button the screen already offered.
        // Neither can be guessed at: a punch carries a ticket that only a card
        // or a PIN just accepted at this device can have produced, and a lock
        // ends a session that was already open. Still capped, so a loop cannot
        // sit on the endpoint, but capped well above what a room full of
        // tablets does at shift change.
        RateLimiter::for('kiosk', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));
    }
}
