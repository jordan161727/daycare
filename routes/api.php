<?php

use App\Http\Controllers\Api\ParentAppController;
use App\Http\Controllers\Api\ParentDayController;
use Illuminate\Support\Facades\Route;

/*
 * The parent app (Jordan, 6 Nov 2026) — documented endpoint by endpoint in
 * docs/parent-app-api.md. Everything is under /api/parent and answers JSON.
 *
 * Signing in is two calls and a texted code, held to a few tries a minute
 * (the "parent-login-start" and "-verify" limiters in AppServiceProvider). Everything after is a
 * Sanctum bearer token carrying the "parent" ability, checked by the
 * "parent" middleware, and limited per parent.
 */
Route::prefix('parent')->name('api.parent.')->group(function () {
    // "Am I reaching the server, and am I signed in?" — open, and limited like the rest.
    Route::get('/ping', [ParentAppController::class, 'ping'])->middleware('throttle:parent-api')->name('ping');
    Route::post('/login/start', [ParentAppController::class, 'start'])->middleware('throttle:parent-login-start')->name('login.start');
    Route::post('/login/verify', [ParentAppController::class, 'verify'])->middleware('throttle:parent-login-verify')->name('login.verify');

    Route::middleware(['auth:sanctum', 'parent', 'throttle:parent-api'])->group(function () {
        Route::post('/logout', [ParentAppController::class, 'logout'])->name('logout');
        Route::get('/me', [ParentAppController::class, 'me'])->name('me');

        Route::get('/threads', [ParentAppController::class, 'threads'])->name('threads');
        Route::get('/threads/{thread}', [ParentAppController::class, 'thread'])->whereNumber('thread')->name('thread');
        Route::post('/threads/{thread}/messages', [ParentAppController::class, 'send'])->whereNumber('thread')->name('send');
        Route::post('/threads/{thread}/read', [ParentAppController::class, 'read'])->whereNumber('thread')->name('read');
        // By id, not by LAN as the staff screens address a child.
        Route::post('/children/{child:id}/threads', [ParentAppController::class, 'open'])->whereNumber('child')->name('open');

        Route::post('/uploads', [ParentAppController::class, 'upload'])->name('upload');

        Route::post('/devices', [ParentAppController::class, 'registerDevice'])->name('devices.store');
        Route::delete('/devices', [ParentAppController::class, 'forgetDevice'])->name('devices.destroy');

        // Version 2 (9 Nov 2026): the day, photos, calendar, absences, pickup,
        // notification settings — docs/parent-app-api.md §4.12–4.17. {child} is
        // the id from /me, checked against the parent's own children (404 if not).
        Route::get('/children/{child}/daily', [ParentDayController::class, 'daily'])->whereNumber('child')->name('daily');
        Route::get('/children/{child}/photos', [ParentDayController::class, 'photos'])->whereNumber('child')->name('photos');
        Route::get('/events', [ParentDayController::class, 'events'])->name('events');
        Route::post('/absences', [ParentDayController::class, 'absence'])->name('absences.store');
        Route::get('/children/{child}/pickup', [ParentDayController::class, 'pickup'])->whereNumber('child')->name('pickup');
        Route::put('/children/{child}/pickup/today', [ParentDayController::class, 'pickupToday'])->whereNumber('child')->name('pickup.today');
        Route::post('/children/{child}/pickup/one-time', [ParentDayController::class, 'pickupOneTime'])->whereNumber('child')->name('pickup.one-time');
        Route::get('/notification-preferences', [ParentDayController::class, 'prefs'])->name('prefs.show');
        Route::put('/notification-preferences', [ParentDayController::class, 'updatePrefs'])->name('prefs.update');
    });
});
