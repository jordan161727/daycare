<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\MessageThread;
use App\Services\ClassroomAssignment;
use App\Services\Messaging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Messages — Phase 9 of docs/work.md, as Messages.dc.html draws it.
 *
 * The list down the left, the open conversation or the new message on the
 * right. A teacher sees their rooms' Room conversations and the
 * announcements; the office sees everything. Sending, replying, the
 * recipients table and ✨ Polish all answer as JSON so the page never
 * reloads mid-thought; a parent's reply arrives at inbound(), from the SMS
 * provider, with no session at all.
 */
class MessageController extends Controller
{
    public function __construct(private Messaging $messaging) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Opened from a child's record: a new message to that family, with
        // the family already chosen. Otherwise the thread asked for, or the
        // newest one.
        $child = $request->filled('child') ? Child::visibleTo($user)->find($request->integer('child')) : null;
        $open = $request->filled('thread') ? MessageThread::visibleTo($user)->find($request->integer('thread')) : null;
        $open ??= $child ? null : MessageThread::visibleTo($user)->whereNotNull('last_message_at')->orderByDesc('last_message_at')->first();

        // Read before the list is built, so the list and the badge agree.
        if ($open) {
            $open->readBy($user);
        }

        $threads = $this->messaging->listFor($user);

        return view('messages.index', [
            'threads' => $threads,
            'current' => $open ? $this->messaging->payload($open, $user) : null,
            'startWith' => $child ? $this->family($child) : null,
            'isAdmin' => $user->isAdmin(),
            'rooms' => $user->isAdmin() ? ClassroomAssignment::rooms() : $user->assignedClassrooms(),
            'lines' => ['classroom' => config('daycare.messages.classroom_line'), 'office' => config('daycare.messages.office_line')],
            'counts' => [
                'all' => count(array_filter($threads, fn ($t) => $t['unread'])),
                'room' => count(array_filter($threads, fn ($t) => $t['unread'] && $t['kind'] === 'room')),
                'office' => count(array_filter($threads, fn ($t) => $t['unread'] && $t['kind'] === 'office')),
                'ann' => count(array_filter($threads, fn ($t) => $t['unread'] && $t['kind'] === 'ann')),
            ],
        ]);
    }

    /** One conversation, as data, marked read. */
    public function show(Request $request, MessageThread $thread)
    {
        abort_unless(MessageThread::visibleTo($request->user())->whereKey($thread->id)->exists(), 404);
        $thread->readBy($request->user());

        return response()->json($this->messaging->payload($thread, $request->user()));
    }

    /** A reply in an open conversation. */
    public function reply(Request $request, MessageThread $thread)
    {
        abort_unless(MessageThread::visibleTo($request->user())->whereKey($thread->id)->exists(), 404);

        if ($thread->isAnnouncement() || ! $thread->child) {
            return response()->json(['message' => 'This conversation cannot be replied to.'], 422);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:1600', 'required_without:media'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*.path' => ['required', 'string', 'starts_with:messages/media/'],
            'media.*.kind' => ['required', Rule::in(['photo', 'video'])],
        ]);

        $this->messaging->sendToFamily($thread->child, $thread->kind, $data['body'] ?? null, $this->attachments($data["media"] ?? []), $request->user());

        return response()->json($this->messaging->payload($thread->fresh(), $request->user()));
    }

    /** A new message: to a family, a whole room, or everyone. */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'to' => ['required', Rule::in($user->isAdmin() ? ['family', 'room', 'everyone'] : ['family', 'room'])],
            'child_id' => ['required_if:to,family', 'nullable', 'integer'],
            'room' => ['required_if:to,room', 'nullable', Rule::in($user->isAdmin() ? ClassroomAssignment::rooms() : $user->assignedClassrooms())],
            'kind' => ['nullable', Rule::in($user->isAdmin() ? ['room', 'office'] : ['room'])],
            'body' => ['nullable', 'string', 'max:1600', 'required_without:media'],
            'media' => ['nullable', 'array', 'max:6'],
            'media.*.path' => ['required', 'string', 'starts_with:messages/media/'],
            'media.*.kind' => ['required', Rule::in(['photo', 'video'])],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['integer'],
        ]);

        $media = $this->attachments($data["media"] ?? []);
        $body = $data['body'] ?? null;

        if ($data['to'] === 'family') {
            $child = Child::visibleTo($user)->find($data['child_id']);
            if (! $child) {
                return response()->json(['message' => 'That child is not one you can message.'], 422);
            }

            $message = $this->messaging->sendToFamily($child, $data['kind'] ?? MessageThread::ROOM, $body, $media, $user);

            return response()->json([
                'thread_id' => $message->thread_id,
                'summary' => $message->delivered.' delivered'.(count($message->skipped ?? []) ? ' · '.count($message->skipped).' skipped' : ''),
            ]);
        }

        if ($data['to'] === 'room') {
            $result = $this->messaging->sendToRoom($data['room'], $body, $media, $user, array_map('intval', $data['exclude'] ?? []));

            return response()->json(['thread_id' => $result['thread_id'], 'summary' => $result['families'].' families · '.$result['delivered'].' delivered · '.$result['skipped'].' skipped']);
        }

        $result = $this->messaging->announce($body, $media, $user);

        return response()->json(['thread_id' => $result['thread_id'], 'summary' => $result['families'].' families · '.$result['delivered'].' delivered · '.$result['skipped'].' skipped']);
    }

    /** Who a message would reach, before it is sent. */
    public function recipients(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'to' => ['required', Rule::in(['family', 'room', 'everyone'])],
            'child_id' => ['nullable', 'integer'],
            'room' => ['nullable', 'string'],
        ]);

        if ($data['to'] === 'family') {
            $child = Child::visibleTo($user)->find($data['child_id'] ?? 0);

            return response()->json($child ? $this->family($child) : ['message' => 'Not found'], $child ? 200 : 404);
        }

        if ($data['to'] === 'room') {
            abort_unless($user->canAccessClassroom($data['room'] ?? null), 403);

            return response()->json(['rows' => $this->messaging->roomRows($data['room'])]);
        }

        abort_unless($user->isAdmin(), 403);

        return response()->json(['rows' => $this->messaging->everyoneRows()]);
    }

    /** The family picker: children by name, with who would get the text. */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));

        $children = Child::visibleTo($request->user())
            ->where('status', 'Active')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")
                ->orWhereHas('personLinks.person', fn ($p) => $p->where('name', 'like', "%{$q}%"))))
            ->orderBy('first_name')->orderBy('last_name')
            ->limit(12)
            ->get();

        return response()->json(['children' => $children->map(fn (Child $c) => $this->family($c))->values()]);
    }

    private function family(Child $child): array
    {
        $r = $this->messaging->recipientsFor($child);

        return [
            'child_id' => $child->id,
            'child' => $child->displayName(),
            'room' => $child->classroom,
            'gets' => collect($r['gets'])->map(fn ($g) => $g['name'].' ('.$g['rel'].')')->implode(' and '),
            'skipped' => collect($r['skipped'])->map(fn ($s) => $s['name'].': '.$s['why'])->implode(' · '),
            'phones' => count($r['gets']),
            'no_photo' => ! $child->photoConsentOnFile(),
        ];
    }

    /** ✨ Polish: the same message with spelling, grammar and tone put right. */
    public function polish(Request $request)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:1600']]);

        return response()->json(['text' => $this->messaging->polish($data['text'])]);
    }

    /** A photo or video for a message, kept until it is sent. */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,video/mp4,video/quicktime,video/webm'],
        ]);

        $file = $data['file'];
        $kind = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'photo';
        $path = $file->store('messages/media', 'local');

        return response()->json([
            'path' => $path,
            'kind' => $kind,
            'name' => $file->getClientOriginalName(),
            'size' => round($file->getSize() / 1048576, 1).' MB',
            'url' => $this->messaging->mediaUrl($path),
        ]);
    }

    /** A photo or video, by its signed link — what a parent's phone opens. */
    public function media(Request $request)
    {
        abort_unless($request->hasValidSignature(), 403);
        $path = (string) $request->query('path');
        abort_unless(str_starts_with($path, 'messages/media/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * A parent's text, from the SMS provider.
     *
     * Twilio posts From, To, Body and MediaUrlN. With the log driver nothing
     * ever arrives here, but the route exists so switching drivers is a
     * matter of pointing the provider at it.
     */
    public function inbound(Request $request)
    {
        if (config('daycare.messages.driver') === 'twilio' && filled(config('services.twilio.token'))) {
            abort_unless($this->twilioSigned($request), 403);
        }

        $media = [];
        for ($i = 0; $i < (int) $request->input('NumMedia', 0); $i++) {
            if ($url = $request->input('MediaUrl'.$i)) {
                $media[] = $url;
            }
        }

        $this->messaging->inbound((string) $request->input('From'), (string) $request->input('To'), $request->input('Body'), $media);

        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    /** Twilio's request signature: HMAC-SHA1 of the URL and the sorted params. */
    private function twilioSigned(Request $request): bool
    {
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, (string) config('services.twilio.token'), true));

        return hash_equals($expected, (string) $request->header('X-Twilio-Signature'));
    }

    /** Only what was uploaded through upload(), and only the fields the record keeps. */
    private function attachments(array $items): array
    {
        return array_values(array_map(fn ($m) => [
            'kind' => $m['kind'],
            'path' => $m['path'],
            'name' => $m['name'] ?? basename($m['path']),
            'size' => $m['size'] ?? null,
            'duration' => $m['duration'] ?? null,
        ], array_filter($items, fn ($m) => Storage::disk('local')->exists($m['path']))));
    }
}
