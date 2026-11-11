<?php

namespace App\Services;

use App\Models\ParentDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The thing that actually sends a push to the parent app — Texter's twin.
 *
 * Two drivers, as Texter has. "log" is the default: the push is written to
 * the log, so the app can be built and tried without an Expo account. "expo"
 * posts to Expo's push service, which carries it to Apple and Google; the
 * tokens are the ExponentPushToken[...] strings the app registers.
 *
 * Kept to one job: ParentPortal and Messaging decide who and what.
 */
class ParentPush
{
    /**
     * @param  list<int>  $personIds
     * @param  array<string, mixed>  $data  Handed to the app with the push (thread_id, message_id).
     * @return int How many phones it went to.
     */
    public function send(array $personIds, string $title, string $body, array $data = []): int
    {
        $tokens = ParentDevice::whereIn('person_id', array_unique($personIds))->pluck('push_token')->all();

        if ($tokens === []) {
            return 0;
        }

        if (config('daycare.parent_app.push_driver', 'log') !== 'expo') {
            Log::info('[push] '.count($tokens).' phone(s): '.$title.' — '.$body);

            return count($tokens);
        }

        $sent = 0;

        // Expo takes up to a hundred to a request.
        foreach (array_chunk($tokens, 100) as $chunk) {
            try {
                $response = Http::timeout(15)
                    ->withToken((string) config('services.expo.access_token'))
                    ->acceptJson()
                    ->post('https://exp.host/--/api/v2/push/send', array_map(fn ($token) => [
                        'to' => $token,
                        'title' => $title,
                        'body' => $body,
                        'data' => $data,
                        'sound' => 'default',
                    ], $chunk));

                foreach ((array) data_get($response->json(), 'data', []) as $i => $ticket) {
                    if (($ticket['status'] ?? null) === 'ok') {
                        $sent++;
                    } elseif (data_get($ticket, 'details.error') === 'DeviceNotRegistered') {
                        // The app was deleted or the token replaced; stop sending to it.
                        ParentDevice::where('push_token', $chunk[$i] ?? '')->delete();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[push] Expo did not take the push: '.$e->getMessage());
            }
        }

        return $sent;
    }
}
