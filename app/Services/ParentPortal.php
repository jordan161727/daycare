<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\ParentLoginCode;
use App\Models\ParentNotificationPref;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The parent app's side of Messages (Jordan, 6 Nov 2026): who may sign in,
 * which conversations they see, and what they may send. The API controller
 * only translates; every rule is here. See docs/parent-app-api.md.
 *
 * Who may use the app is decided the same way as who gets a text: the
 * "Texts" tick on the person's link to a child, no court restriction on that
 * link, and the child Active or Pending. Untick Texts, add a restriction or
 * withdraw the child and the app stops showing that child at the next
 * request — the token stays, the conversations go. Replying STOP stops the
 * texts only; the app is a different way of hearing and is left alone.
 *
 * Inside that, a parent sees the Room and Office conversations about their
 * own children and the announcements sent since they were first linked —
 * the same conversations the office reads, so a word typed in the app and a
 * text sent from the phone land in the same place.
 */
class ParentPortal
{
    /** A child whose family can use the app. */
    public const CHILD_STATUSES = ['Active', 'Pending'];

    public const CODE_MINUTES = 10;

    public const CODE_ATTEMPTS = 5;

    public function __construct(private Texter $texter, private ParentPush $push) {}

    /* ---------------------------------------------------------------
     * Who
     * --------------------------------------------------------------- */

    /** The links that let this person use the app, with their children. */
    public function links(Person $person): Collection
    {
        return ChildPerson::where('person_id', $person->id)
            ->where('gets_texts', true)
            ->where(fn ($q) => $q->whereNull('restriction')->orWhere('restriction', ''))
            ->whereHas('child', fn ($q) => $q->whereIn('status', self::CHILD_STATUSES))
            ->with('child')
            ->get();
    }

    /** @return Collection<int, Child> */
    public function children(Person $person): Collection
    {
        return $this->links($person)->pluck('child')->unique('id')->sortBy('first_name')->values();
    }

    /** The person a mobile number belongs to, if they may use the app. */
    public function personForPhone(string $phone): ?Person
    {
        $digits = substr(Person::digits($phone), -10);

        if (strlen($digits) < 10) {
            return null;
        }

        return Person::whereNotNull('cell')->get()
            ->filter(fn (Person $p) => substr(Person::digits($p->cell), -10) === $digits)
            ->first(fn (Person $p) => $this->links($p)->isNotEmpty());
    }

    /* ---------------------------------------------------------------
     * Signing in: a code by text, then a token
     * --------------------------------------------------------------- */

    /**
     * Text a six-digit code to the number, if it belongs to somebody who may
     * sign in. Says nothing either way, so the endpoint cannot be used to
     * find out which numbers are on file.
     *
     * @return string|null The code, for the local sandbox to show (see
     *                     ParentAppController::start); null when none was sent.
     */
    public function sendCode(string $phone, ?string $ip = null): ?string
    {
        $person = $this->personForPhone($phone);

        if (! $person) {
            Log::info('[parent-app] sign-in code asked for an unknown or not-allowed number.');

            return null;
        }

        // One live code at a time: asking again replaces the last one.
        ParentLoginCode::where('person_id', $person->id)->whereNull('used_at')->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        ParentLoginCode::create([
            'person_id' => $person->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'ip' => $ip,
        ]);

        $line = (string) config('daycare.messages.office_line');
        $this->texter->send(
            Texter::e164($line) ?? $line,
            (string) Texter::e164($person->cell),
            'Your '.config('daycare.parent_app.name', 'AngelNest').' code is '.$code.'. It expires in '.self::CODE_MINUTES.' minutes. If you did not ask for it, ignore this text.',
        );

        return $code;
    }

    /** The person, if the code is the live one for that number. Spends it. */
    public function verifyCode(string $phone, string $code): ?Person
    {
        $person = $this->personForPhone($phone);

        if (! $person) {
            return null;
        }

        $row = ParentLoginCode::where('person_id', $person->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $row || $row->attempts >= self::CODE_ATTEMPTS) {
            return null;
        }

        if (! Hash::check(preg_replace('/\D+/', '', $code) ?? '', $row->code_hash)) {
            $row->increment('attempts');

            return null;
        }

        $row->forceFill(['used_at' => now()])->save();

        return $person;
    }

    /** A token for the app: says it is a parent's, and lasts a while. */
    public function issueToken(Person $person, string $device): string
    {
        return $person->createToken(
            Str::limit($device ?: 'Parent app', 60, ''),
            ['parent'],
            now()->addDays((int) config('daycare.parent_app.token_days', 90)),
        )->plainTextToken;
    }

    /* ---------------------------------------------------------------
     * Which conversations
     * --------------------------------------------------------------- */

    /** Every conversation this parent may read. */
    public function threads(Person $person): Builder
    {
        $links = $this->links($person);
        $childIds = $links->pluck('child_id')->unique()->values()->all();
        // Announcements from the day they were first on a child's record:
        // what was sent to the families before them was not sent to them.
        $since = $links->min('created_at') ?? now();

        return MessageThread::query()->where(function (Builder $q) use ($childIds, $since) {
            $q->where(fn (Builder $own) => $own->whereIn('kind', [MessageThread::ROOM, MessageThread::OFFICE])->whereIn('child_id', $childIds ?: [0]))
                ->orWhere(fn (Builder $all) => $all->where('kind', MessageThread::ANNOUNCEMENT)->where('created_at', '>=', $since));
        });
    }

    public function canSee(Person $person, MessageThread $thread): bool
    {
        return $this->threads($person)->whereKey($thread->id)->exists();
    }

    /** The inbox: newest first, with how many the parent has not read. */
    public function inbox(Person $person): array
    {
        $threads = $this->threads($person)
            ->whereNotNull('last_message_at')
            ->with(['child', 'latest' => fn ($q) => $q->with('sender')->limit(1)])
            ->orderByDesc('last_message_at')
            ->get();

        $unread = $this->unreadCounts($person, $threads->pluck('id')->all());

        return $threads->map(fn (MessageThread $t) => $this->threadJson($t, $person, (int) ($unread[$t->id] ?? 0)))->values()->all();
    }

    /** Messages after the parent's read mark that they did not write themselves. */
    private function unreadCounts(Person $person, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return DB::table('messages as m')
            ->leftJoin('parent_thread_reads as r', fn ($j) => $j->on('r.thread_id', '=', 'm.thread_id')->where('r.person_id', '=', $person->id))
            ->whereIn('m.thread_id', $threadIds)
            ->whereRaw('m.id > coalesce(r.last_message_id, 0)')
            ->where(fn ($q) => $q->where('m.direction', Message::OUT)->orWhere('m.person_id', '!=', $person->id))
            ->groupBy('m.thread_id')
            ->selectRaw('m.thread_id, count(*) as n')
            ->pluck('n', 'thread_id')
            ->all();
    }

    public function threadJson(MessageThread $t, Person $person, int $unread = 0): array
    {
        $t->loadMissing('child');
        $last = $t->relationLoaded('latest') ? $t->latest->first() : $t->latest()->with('sender')->first();

        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'kind_label' => $this->kindLabel($t),
            'title' => $t->isAnnouncement() ? ($t->title ?: 'Announcement') : ($t->child?->displayName() ?? ($t->title ?: 'Family')),
            'child' => $t->child ? ['id' => $t->child->id, 'name' => $t->child->displayName()] : null,
            'can_reply' => ! $t->isAnnouncement(),
            'unread' => $unread,
            'last_message' => $last ? [
                'id' => $last->id,
                'preview' => $last->body ? Str::limit($last->body, 120) : (($last->media ?? []) !== [] ? '📷 Photo' : ''),
                'from_me' => $last->direction === Message::IN && $last->person_id === $person->id,
                'sent_at' => $this->iso($last->sent_at ?? $last->created_at),
            ] : null,
            'last_message_at' => $this->iso($t->last_message_at),
        ];
    }

    private function kindLabel(MessageThread $t): string
    {
        return $t->kind === MessageThread::ROOM
            ? 'Room · '.($t->classroom ?: $t->child?->classroom ?: '—')
            : MessageThread::KINDS[$t->kind];
    }

    /**
     * A page of one conversation, oldest first. With after_id, only what is
     * newer (the app's poll); with before_id, the page before (scrolling up);
     * with neither, the newest page.
     */
    public function messages(Person $person, MessageThread $t, ?int $afterId, ?int $beforeId, int $limit): array
    {
        $q = Message::where('thread_id', $t->id)->with(['sender', 'person']);

        $page = match (true) {
            $afterId !== null => $q->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get(),
            $beforeId !== null => $q->where('id', '<', $beforeId)->orderByDesc('id')->limit($limit)->get()->reverse()->values(),
            default => $q->orderByDesc('id')->limit($limit)->get()->reverse()->values(),
        };

        return $page->map(fn (Message $m) => $this->messageJson($m, $t, $person))->all();
    }

    public function messageJson(Message $m, MessageThread $t, Person $person): array
    {
        $room = $t->classroom ?: $t->child?->classroom;
        $mine = $m->direction === Message::IN && $m->person_id === $person->id;

        return [
            'id' => $m->id,
            'thread_id' => $m->thread_id,
            'from' => $m->isOut()
                ? [
                    'type' => 'centre',
                    'name' => Str::before($m->sender?->name ?? 'Office', ' ').' · '.($t->kind === MessageThread::ROOM ? $room : config('daycare.company.name')),
                    'is_me' => false,
                ]
                : ['type' => 'parent', 'name' => $mine ? 'You' : ($m->person?->name ?? 'Parent'), 'is_me' => $mine],
            'body' => $m->body,
            'media' => collect($m->media ?? [])->map(fn ($x) => [
                'kind' => $x['kind'] ?? 'photo',
                'url' => isset($x['path']) ? app(Messaging::class)->mediaUrl($x['path']) : ($x['url'] ?? null),
            ])->values()->all(),
            'channel' => $m->channel ?? Message::SMS,
            'sent_at' => $this->iso($m->sent_at ?? $m->created_at),
        ];
    }

    /* ---------------------------------------------------------------
     * What a parent sends
     * --------------------------------------------------------------- */

    /** The one Room or Office conversation about this child, opened if new. */
    public function openThread(Child $child, string $kind): MessageThread
    {
        return MessageThread::firstOrCreate(['kind' => $kind, 'child_id' => $child->id], ['classroom' => $child->classroom]);
    }

    /** A message from the app, into the conversation the office reads. */
    public function send(Person $person, MessageThread $t, ?string $body, array $media): Message
    {
        $message = $t->messages()->create([
            'direction' => Message::IN,
            'channel' => Message::APP,
            'body' => filled($body) ? trim($body) : null,
            'person_id' => $person->id,
            'media' => $media ?: null,
            'sent_at' => now(),
        ]);

        $t->forceFill(['last_message_at' => now()])->save();
        $this->markRead($person, $t, $message->id);

        return $message;
    }

    /** How far the parent has read: to the given message, or to the end. */
    public function markRead(Person $person, MessageThread $t, ?int $upTo = null): int
    {
        $last = (int) ($upTo ?? Message::where('thread_id', $t->id)->max('id'));
        $current = (int) DB::table('parent_thread_reads')->where('person_id', $person->id)->where('thread_id', $t->id)->value('last_message_id');

        DB::table('parent_thread_reads')->updateOrInsert(
            ['person_id' => $person->id, 'thread_id' => $t->id],
            ['last_message_id' => max($last, $current), 'read_at' => now()],
        );

        return max($last, $current);
    }

    /* ---------------------------------------------------------------
     * Telling the app something arrived
     * --------------------------------------------------------------- */

    /**
     * Who the app should tell about a message from the centre: the people
     * allowed the app on that child, or for an announcement every one of
     * them. STOP is not a reason to leave somebody out — it stops texts.
     *
     * @return list<int>
     */
    public function audience(MessageThread $t): array
    {
        $q = ChildPerson::where('gets_texts', true)
            ->where(fn ($w) => $w->whereNull('restriction')->orWhere('restriction', ''));

        $q = $t->isAnnouncement()
            ? $q->whereHas('child', fn ($c) => $c->where('status', 'Active'))
            : $q->where('child_id', $t->child_id)->whereHas('child', fn ($c) => $c->whereIn('status', self::CHILD_STATUSES));

        return $q->pluck('person_id')->unique()->values()->all();
    }

    /** A push for a message the centre sent. @return int phones it went to */
    public function pushFor(Message $m, ?array $personIds = null): int
    {
        $t = $m->thread()->with('child')->first();
        $title = match ($t->kind) {
            MessageThread::ANNOUNCEMENT => 'Announcement',
            MessageThread::ROOM => ($t->child?->first_name ?? 'Your child').' · '.($t->classroom ?: $t->child?->classroom),
            default => 'Office · '.config('daycare.company.name'),
        };
        $body = $m->body ? Str::limit($m->body, 140) : '📷 Photo';

        // Version 2: a parent can switch messages or announcements off in the app.
        $to = ParentNotificationPref::wanting($personIds ?? $this->audience($t), $t->isAnnouncement() ? 'announcements' : 'messages');

        return $this->push->send($to, $title, $body, ['thread_id' => $t->id, 'message_id' => $m->id]);
    }

    private function iso($at): ?string
    {
        return $at ? Carbon::parse($at)->toIso8601String() : null;
    }
}
