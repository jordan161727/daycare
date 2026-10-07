<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * A staff member's photograph, streamed from the public disk.
 *
 * It used to be a plain link into public/storage, which is a symlink the
 * host has to allow: on the staging server it answered 403 and every face
 * in the app went blank. Served through a route instead, the file comes out
 * through PHP like everything else and needs no link, no FollowSymLinks and
 * no storage:link step on each new server. It is behind the login, like the
 * pages the faces appear on.
 */
class AvatarController extends Controller
{
    public function show(User $user)
    {
        abort_if(blank($user->avatar_path) || ! Storage::disk('public')->exists($user->avatar_path), 404);

        return Storage::disk('public')->response($user->avatar_path, null, [
            // The address carries a stamp of the file (see User::avatar_url),
            // so a replaced photograph is fetched from a new address and the
            // old one may sit in the browser's cache for a day.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
