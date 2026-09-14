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
         * The door kiosk is the one route in this app that answers a signed-out
         * request, so it gets a limiter of its own.
         *
         * Two layers, and they stop different attacks. The lockout on the
         * guardian row stops somebody working through one family's PIN; this
         * stops them working through every six-digit number, because a PIN that
         * matches nobody has no row to count against.
         *
         * Keyed on the address rather than the session: a script would simply
         * not send the cookie back. Thirty a minute is generous for a parent
         * with three children and nowhere near enough to walk a keyspace.
         */
        RateLimiter::for('kiosk', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
