<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A dead CSRF token here means a page was left open, not an attack.
        // Send people back to the form they were filling in, with their answers
        // and a plain explanation, instead of Laravel's bare "Page Expired".
        //
        // Matched on the status, not on TokenMismatchException: the framework
        // has already turned it into a 419 HttpException by the time render
        // callbacks run, so a typed callback would never fire.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $message = 'Your session timed out because the page sat open for a while. Please try again.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 419);
            }

            return redirect()->back()
                ->withInput(collect($request->input())->except(['_token', 'password', 'password_confirmation'])->all())
                ->with('error', $message);
        });
    })->create();
