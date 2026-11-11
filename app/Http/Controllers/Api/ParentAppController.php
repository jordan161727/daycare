<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\MessageThread;
use App\Models\ParentDevice;
use App\Models\Person;
use App\Services\Messaging;
use App\Services\ParentPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The parent app's API — every endpoint in docs/parent-app-api.md. Only
 * translates: ParentPortal holds the rules about who sees and sends what.
 *
 * A conversation the parent may not read answers 404, not 403, so the API
 * never says that a conversation exists.
 */
class ParentAppController extends Controller
{
    public function __construct(private ParentPortal $portal) {}

    /**
     * Is the app (or Postman) talking to the server, and signed in as whom?
     * Open to anyone: it says only whether the token presented is a live
     * parent token, and whose — nothing about anybody else.
     */
    public function ping(): JsonResponse
    {
        $user = auth('sanctum')->user();
        $parent = $user instanceof Person && $user->tokenCan('parent') ? $user : null;

        return response()->json([
            'connected' => true,
            'api' => 'parent',
            'centre' => config('daycare.company.name'),
            'server_time' => now()->toIso8601String(),
            'signed_in' => (bool) $parent,
            'parent' => $parent ? ['id' => $parent->id, 'name' => $parent->name] : null,
        ]);
    }

    /* ---- signing in ---- */

    /** Text a code. The same answer whether or not the number is on file. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $code = $this->portal->sendCode($data['phone'], $request->ip());

        return response()->json(array_filter([
            'sent' => true,
            'expires_in' => ParentPortal::CODE_MINUTES * 60,
            'message' => 'If that number is on file, a code is on its way.',
            // The parent-app sandbox only (scripts/parent-app-sandbox.ps1):
            // with no phone to text, the code comes back in the answer. Needs
            // the setting AND a local environment, so a live server never does.
            'debug_code' => config('daycare.parent_app.show_codes') && app()->environment('local') ? $code : null,
        ], fn ($value) => $value !== null));
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $person = $this->portal->verifyCode($data['phone'], $data['code']);

        if (! $person) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Ask for a new one.']);
        }

        return response()->json([
            'token' => $this->portal->issueToken($person, (string) ($data['device_name'] ?? '')),
            'token_type' => 'Bearer',
            'expires_in_days' => (int) config('daycare.parent_app.token_days', 90),
            'parent' => $this->profile($person),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['signed_out' => true]);
    }

    /* ---- who ---- */

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    /** The parent, their children, and the centre's lines — never the whole Person (it carries an SSN). */
    private function profile(Person $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'cell' => $person->cell,
            'email' => $person->email,
            'children' => $this->portal->children($person)->map(fn (Child $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'first_name' => $c->first_name,
                'classroom' => $c->classroom,
                'status' => $c->status,
            ])->values()->all(),
            'centre' => [
                'name' => config('daycare.company.name'),
                'classroom_line' => config('daycare.messages.classroom_line'),
                'office_line' => config('daycare.messages.office_line'),
            ],
        ];
    }

    /* ---- conversations ---- */

    public function threads(Request $request): JsonResponse
    {
        return response()->json(['threads' => $this->portal->inbox($request->user())]);
    }

    public function thread(Request $request, MessageThread $thread): JsonResponse
    {
        $person = $this->allowed($request, $thread);
        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'thread' => $this->portal->threadJson($thread, $person),
            'messages' => $this->portal->messages($person, $thread, isset($data['after_id']) ? (int) $data['after_id'] : null, isset($data['before_id']) ? (int) $data['before_id'] : null, (int) ($data['limit'] ?? 50)),
        ]);
    }

    /** Start (or find) the Room or Office conversation about one child. */
    public function open(Request $request, Child $child): JsonResponse
    {
        /** @var Person $person */
        $person = $request->user();
        abort_unless($this->portal->children($person)->contains('id', $child->id), 404);

        $data = $request->validate(['kind' => ['required', Rule::in([MessageThread::ROOM, MessageThread::OFFICE])]]);
        $thread = $this->portal->openThread($child, $data['kind']);

        return response()->json(['thread' => $this->portal->threadJson($thread, $person)]);
    }

    public function send(Request $request, MessageThread $thread): JsonResponse
    {
        $person = $this->allowed($request, $thread);
        abort_if($thread->isAnnouncement(), 422, 'Announcements are one-way. Start an Office conversation instead.');

        $prefix = $this->uploadFolder($person).'/';
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:1600', 'required_without:media'],
            'media' => ['nullable', 'array', 'max:4', 'required_without:body'],
            'media.*.path' => ['required', 'string', 'starts_with:'.$prefix],
            'media.*.kind' => ['required', Rule::in(['photo', 'video'])],
        ]);

        $media = collect($data['media'] ?? [])
            ->filter(fn ($m) => Storage::disk('local')->exists($m['path']))
            ->map(fn ($m) => ['kind' => $m['kind'], 'path' => $m['path']])
            ->values()->all();

        $message = $this->portal->send($person, $thread, $data['body'] ?? null, $media);

        return response()->json(['message' => $this->portal->messageJson($message, $thread, $person)], 201);
    }

    public function read(Request $request, MessageThread $thread): JsonResponse
    {
        $person = $this->allowed($request, $thread);
        $data = $request->validate(['last_message_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['last_read_id' => $this->portal->markRead($person, $thread, $data['last_message_id'] ?? null)]);
    }

    /** A photo or video, kept until it is sent with a message. */
    public function upload(Request $request, Messaging $messaging): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,video/mp4,video/quicktime,video/webm'],
        ]);

        $file = $data['file'];
        $kind = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'photo';
        $path = $file->store($this->uploadFolder($request->user()), 'local');

        return response()->json(['path' => $path, 'kind' => $kind, 'url' => $messaging->mediaUrl($path)], 201);
    }

    /* ---- push ---- */

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
        ]);

        // A token belongs to one phone; if another parent signed in on it
        // before, it is theirs no longer.
        ParentDevice::updateOrCreate(
            ['push_token' => $data['push_token']],
            ['person_id' => $request->user()->id, 'platform' => $data['platform'] ?? null, 'last_seen_at' => now()],
        );

        return response()->json(['registered' => true], 201);
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['push_token' => ['required', 'string', 'max:255']]);
        ParentDevice::where('push_token', $data['push_token'])->where('person_id', $request->user()->id)->delete();

        return response()->json(['forgotten' => true]);
    }

    /* ---- helpers ---- */

    private function allowed(Request $request, MessageThread $thread): Person
    {
        /** @var Person $person */
        $person = $request->user();
        abort_unless($this->portal->canSee($person, $thread), 404);

        return $person;
    }

    /** Under messages/media, where the signed media route serves from. */
    private function uploadFolder(Person $person): string
    {
        return 'messages/media/parent-'.$person->id;
    }
}
