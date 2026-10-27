<?php

namespace App\Services;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Texts to families — Phase 9 of docs/work.md, as Messages.dc.html draws it.
 *
 * Three kinds of conversation. Room is about the child's day and is read by
 * the child's teachers and the office; it goes out from the Classroom line.
 * Office is tuition, DSS, enrolment — anything private — read by the office
 * only, from the Office line. An announcement goes to every family at once,
 * one-way, from the Office line; a reply to it lands as an Office
 * conversation.
 *
 * Who gets a text is never decided here. It is the "Texts" tick on each
 * person on the child's record, less anyone who replied STOP, anyone under
 * a court restriction, and anyone with no mobile number — and every one of
 * those is shown as skipped, with the reason, before the send.
 */
class Messaging
{
    public function __construct(private Texter $texter) {}

    /* ---------------------------------------------------------------
     * Who gets a text
     * --------------------------------------------------------------- */

    /**
     * One child's contacts, sorted into who gets the text and who is skipped.
     *
     * @return array{gets: list<array{person_id: int, name: string, rel: string, phone: string}>, skipped: list<array{name: string, rel: string, why: string}>}
     */
    public function recipientsFor(Child $child): array
    {
        $gets = [];
        $skipped = [];

        foreach ($child->personLinks()->with('person')->get() as $link) {
            $person = $link->person;

            if (! $person) {
                continue;
            }

            $rel = $link->relationship ?: 'Contact';
            $why = match (true) {
                ! $link->gets_texts => 'Texts not ticked',
                filled($link->restriction) => 'restricted — '.Str::limit($link->restriction, 40),
                $person->texts_stopped_at !== null => 'replied STOP',
                blank($person->cell) => 'no mobile number',
                default => null,
            };

            if ($why) {
                $skipped[] = ['name' => $person->name, 'rel' => $rel, 'why' => $why];
            } else {
                $gets[] = ['person_id' => $person->id, 'name' => $person->name, 'rel' => $rel, 'phone' => $person->cell];
            }
        }

        return ['gets' => $gets, 'skipped' => $skipped];
    }

    /** The children a text to a room would go about: active, in that room. */
    public function childrenIn(string $room): Collection
    {
        return Child::where('status', 'Active')->where('classroom', $room)->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * The recipients table for a room: one row per child, who gets it, who
     * is skipped, and whether a photo may be sent with them in it.
     */
    public function roomRows(string $room): array
    {
        return $this->childrenIn($room)->map(function (Child $child) {
            $r = $this->recipientsFor($child);

            return [
                'child_id' => $child->id,
                'child' => $child->displayName(),
                'gets' => $r['gets'] === [] ? 'Nobody — no one can get texts' : collect($r['gets'])->map(fn ($g) => $g['name'].' ('.$g['rel'].')')->implode(', '),
                'skip' => collect($r['skipped'])->map(fn ($s) => $s['name'].': '.$s['why'])->implode(' · '),
                'phones' => count($r['gets']),
                'skipped' => count($r['skipped']),
                'no_photo' => ! $child->photoConsentOnFile(),
            ];
        })->values()->all();
    }

    /** One row per room, for an announcement: families, phones, skipped. */
    public function everyoneRows(): array
    {
        $rows = [];

        foreach (ClassroomAssignment::rooms() as $room) {
            $children = $this->roomRows($room);
            $families = count(array_filter($children, fn ($c) => $c['phones'] > 0));

            $rows[] = [
                'room' => $room,
                'children' => count($children),
                'families' => $families,
                'phones' => array_sum(array_column($children, 'phones')),
                'skipped' => array_sum(array_column($children, 'skipped')),
            ];
        }

        return $rows;
    }

    /* ---------------------------------------------------------------
     * Threads
     * --------------------------------------------------------------- */

    /** The one conversation of this kind about this child. */
    public function threadFor(Child $child, string $kind, ?User $by = null): MessageThread
    {
        return MessageThread::firstOrCreate(
            ['kind' => $kind, 'child_id' => $child->id],
            ['classroom' => $child->classroom, 'created_by' => $by?->id],
        );
    }

    /** The list down the left, newest first, with what this person has not read. */
    public function listFor(User $user): array
    {
        $threads = MessageThread::visibleTo($user)
            ->with(['child', 'latest' => fn ($q) => $q->limit(1), 'readers' => fn ($q) => $q->where('users.id', $user->id)])
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->get();

        return $threads->map(fn (MessageThread $t) => $this->row($t, $user))->values()->all();
    }

    private function row(MessageThread $t, User $user): array
    {
        $last = $t->latest->first();
        $seen = (int) ($t->readers->first()?->pivot?->last_message_id ?? 0);
        $unread = $last && $last->direction === Message::IN && $last->id > $seen;
        $head = $this->heading($t);

        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'kindLabel' => $t->kind === MessageThread::ROOM ? 'Room · '.$t->classroom : MessageThread::KINDS[$t->kind],
            'name' => $head['name'],
            'ini' => $head['ini'],
            'sub' => $head['sub'],
            'when' => $this->when($t->last_message_at),
            'unread' => $unread,
            'last' => $last ? ($last->body ?: (($last->media ?? []) !== [] ? '📷 Picture message' : '')) : '',
            'search' => strtolower($head['name'].' '.$head['sub']),
        ];
    }

    /** "Daniel Ortiz" / "Ava Cruz · Toddler · Daniel Ortiz, Rosa Cruz". */
    private function heading(MessageThread $t): array
    {
        if ($t->isAnnouncement()) {
            return ['name' => $t->title ?: 'Announcement', 'ini' => 'ALL', 'sub' => 'All families'];
        }

        $child = $t->child;

        if (! $child) {
            return ['name' => $t->title ?: 'Family', 'ini' => '?', 'sub' => MessageThread::KINDS[$t->kind]];
        }

        $r = $this->recipientsFor($child);
        $names = collect($r['gets'])->pluck('name');

        if ($names->isEmpty()) {
            $names = $child->personLinks()->with('person')->get()->map(fn ($l) => $l->person?->name)->filter();
        }

        $first = $names->first() ?: $child->displayName();
        $ini = collect(explode(' ', $first))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');

        return [
            'name' => $first,
            'ini' => strtoupper($ini),
            'sub' => $child->displayName().' · '.($child->classroom ?: 'No room').($names->count() > 1 ? ' · '.$names->implode(', ') : ''),
        ];
    }

    private function when($at): string
    {
        if (! $at) {
            return '';
        }
        if ($at->isToday()) {
            return $at->format('g:i A');
        }
        if ($at->isYesterday()) {
            return 'Yesterday';
        }

        return $at->format('M j');
    }

    /** Everything the open conversation shows. */
    public function payload(MessageThread $t, User $user): array
    {
        $t->loadMissing('child');
        $head = $this->heading($t);
        $kind = $t->kind;
        $room = $t->classroom ?: $t->child?->classroom;

        $msgs = $t->messages()->with(['sender', 'person'])->get()->map(function (Message $m) use ($room, $kind) {
            $who = $m->isOut()
                ? ($m->sender?->name ?? 'Office').' · '.($kind === MessageThread::ROOM ? $room : MessageThread::KINDS[$kind])
                : ($m->person?->name ?? 'Parent');
            $meta = $who.' · '.$this->stamp($m->sent_at ?? $m->created_at);

            if ($m->isOut() && $kind === MessageThread::ANNOUNCEMENT) {
                $meta .= ' · '.$m->delivered.' delivered'.(count($m->skipped ?? []) ? ' · '.count($m->skipped).' skipped' : '');
            } elseif ($m->isOut() && ($m->media ?? []) !== []) {
                $meta .= ' · picture message';
            }

            return [
                'id' => $m->id,
                'out' => $m->isOut(),
                'text' => $m->body ?? '',
                'meta' => $meta,
                'media' => collect($m->media ?? [])->map(fn ($x) => [
                    'kind' => $x['kind'] ?? 'photo',
                    'label' => ($x['kind'] ?? 'photo') === 'video' ? 'Video' : 'Photo',
                    'dur' => $x['duration'] ?? '',
                    'url' => isset($x['path']) ? $this->mediaUrl($x['path']) : ($x['url'] ?? null),
                ])->values()->all(),
            ];
        })->values()->all();

        $first = Str::before($head['name'], ' ');
        $line = $kind === MessageThread::ROOM ? config('daycare.messages.classroom_line') : config('daycare.messages.office_line');

        return [
            'id' => $t->id,
            'kind' => $kind,
            'kindLabel' => $kind === MessageThread::ROOM ? 'Room · '.$room : MessageThread::KINDS[$kind],
            'name' => $head['name'],
            'first' => $first,
            'sub' => $head['sub'],
            'see' => match ($kind) {
                MessageThread::ROOM => 'Seen by '.$room.' teachers and the director',
                MessageThread::OFFICE => 'Office only — teachers can’t see this conversation',
                default => 'Sent to everyone · one-way · replies go to Office',
            },
            'msgs' => $msgs,
            'canReply' => $kind !== MessageThread::ANNOUNCEMENT,
            'noReplyTxt' => 'Announcements are one-way. Any replies show up as Office conversations.',
            'line' => $kind === MessageThread::OFFICE
                ? 'Sent from the Office line '.$line.' · signed with your name'
                : 'Sent from the Classroom line '.$line.' · signed “'.Str::before($user->name, ' ').' · '.$room.'”',
        ];
    }

    private function stamp($at): string
    {
        if (! $at) {
            return '';
        }
        if ($at->isToday()) {
            return 'Today '.$at->format('g:i A');
        }
        if ($at->isYesterday()) {
            return 'Yesterday '.$at->format('g:i A');
        }

        return $at->format('M j, g:i A');
    }

    /* ---------------------------------------------------------------
     * Sending
     * --------------------------------------------------------------- */

    /** One family, about one child. */
    public function sendToFamily(Child $child, string $kind, ?string $body, array $media, User $by): Message
    {
        $thread = $this->threadFor($child, $kind, $by);
        $r = $this->recipientsFor($child);

        return $this->deliver($thread, $body, $media, $by, $r['gets'], $r['skipped'], null);
    }

    /**
     * Every family in a room, as one text each in their own conversation.
     *
     * @param  list<int>  $exclude  Children the director unticked.
     * @return array{families: int, delivered: int, skipped: int, thread_id: ?int}
     */
    public function sendToRoom(string $room, ?string $body, array $media, User $by, array $exclude = []): array
    {
        $key = (string) Str::uuid();
        $families = 0;
        $delivered = 0;
        $skipped = 0;
        $threadId = null;

        foreach ($this->childrenIn($room) as $child) {
            if (in_array($child->id, $exclude, true)) {
                continue;
            }

            $r = $this->recipientsFor($child);

            if ($r['gets'] === []) {
                continue;
            }

            $families++;
            $message = $this->deliver($this->threadFor($child, MessageThread::ROOM, $by), $body, $media, $by, $r['gets'], $r['skipped'], $key);
            $delivered += $message->delivered;
            $skipped += count($message->skipped ?? []);
            $threadId ??= $message->thread_id;
        }

        return ['families' => $families, 'delivered' => $delivered, 'skipped' => $skipped, 'thread_id' => $threadId];
    }

    /**
     * Everyone, once. A parent with children in two rooms gets one text.
     *
     * @return array{families: int, delivered: int, skipped: int, thread_id: int}
     */
    public function announce(?string $body, array $media, User $by): array
    {
        $gets = [];
        $skipped = [];
        $families = 0;

        foreach (Child::where('status', 'Active')->get() as $child) {
            $r = $this->recipientsFor($child);
            if ($r['gets'] !== []) {
                $families++;
            }
            foreach ($r['gets'] as $g) {
                $gets[$g['person_id']] = $g;
            }
            foreach ($r['skipped'] as $s) {
                $skipped[$s['name'].'|'.$s['why']] = $s;
            }
        }

        $thread = MessageThread::create([
            'kind' => MessageThread::ANNOUNCEMENT,
            'title' => Str::limit(trim((string) $body) ?: 'Announcement', 48),
            'created_by' => $by->id,
        ]);

        $message = $this->deliver($thread, $body, $media, $by, array_values($gets), array_values($skipped), null);

        return ['families' => $families, 'delivered' => $message->delivered, 'skipped' => count($message->skipped ?? []), 'thread_id' => $thread->id];
    }

    /**
     * Write the message and carry it to every phone on the list.
     *
     * Signed the way the mock-up signs it — a room text with the teacher's
     * first name and the room, an office text with the sender's name — so
     * a parent knows who is talking without a number telling them.
     */
    private function deliver(MessageThread $thread, ?string $body, array $media, User $by, array $gets, array $skipped, ?string $key): Message
    {
        $kind = $thread->kind;
        $line = $kind === MessageThread::ROOM ? config('daycare.messages.classroom_line') : config('daycare.messages.office_line');
        $room = $thread->classroom ?: $thread->child?->classroom;

        $message = $thread->messages()->create([
            'direction' => Message::OUT,
            'body' => filled($body) ? trim($body) : null,
            'user_id' => $by->id,
            'media' => $media ?: null,
            'broadcast_key' => $key,
            'skipped' => $skipped ?: null,
            'sent_at' => now(),
        ]);

        $text = trim((string) $body);
        $signature = $kind === MessageThread::ROOM
            ? '— '.Str::before($by->name, ' ').' · '.$room
            : '— '.$by->name.' · '.config('daycare.company.name');

        $photos = [];
        $links = [];
        foreach ($media as $item) {
            if (($item['kind'] ?? 'photo') === 'video') {
                $links[] = 'Video: '.$this->mediaUrl($item['path'], true);
            } else {
                $photos[] = $this->mediaUrl($item['path'], true);
            }
        }

        $full = implode("\n", array_filter([$text, ...$links, $signature]));

        $delivered = 0;
        foreach ($gets as $g) {
            if ($this->texter->send(Texter::e164($line) ?? $line, Texter::e164($g['phone']), $full, $photos)) {
                $delivered++;
            }
        }

        $message->update(['delivered' => $delivered]);
        $thread->forceFill(['last_message_at' => now()])->save();
        $thread->readBy($by);

        return $message;
    }

    /** A link to a photo or video a parent can open, for a while. */
    public function mediaUrl(string $path, bool $absolute = true): string
    {
        return URL::temporarySignedRoute('messages.media', now()->addDays((int) config('daycare.messages.video_link_days', 30)), ['path' => $path], true);
    }

    /* ---------------------------------------------------------------
     * A parent's reply
     * --------------------------------------------------------------- */

    /**
     * A text that arrived on one of the lines.
     *
     * The number says who; the line says which kind of conversation. STOP is
     * honoured on the spot. Everything else lands in the family's newest
     * conversation of that kind, or opens one.
     */
    public function inbound(string $from, string $to, ?string $body, array $mediaUrls = []): ?Message
    {
        $digits = substr(Person::digits($from), -10);

        if (strlen($digits) < 7) {
            return null;
        }

        $person = Person::whereNotNull('cell')->get()->first(fn (Person $p) => substr(Person::digits($p->cell), -10) === $digits);

        if (! $person) {
            Log::info('[sms] reply from an unknown number '.$from.' ignored.');

            return null;
        }

        $word = strtoupper(trim((string) $body));

        if (in_array($word, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            $person->forceFill(['texts_stopped_at' => now()])->save();

            return null;
        }

        if (in_array($word, ['START', 'YES', 'UNSTOP'], true)) {
            $person->forceFill(['texts_stopped_at' => null])->save();

            return null;
        }

        $office = substr(Person::digits($to), -10) === substr(Person::digits((string) config('daycare.messages.office_line')), -10);
        $kind = $office ? MessageThread::OFFICE : MessageThread::ROOM;

        $children = ChildPerson::where('person_id', $person->id)->with('child')->get()
            ->map(fn ($l) => $l->child)->filter(fn ($c) => $c && $c->status === 'Active')->values();

        $thread = MessageThread::where('kind', $kind)->whereIn('child_id', $children->pluck('id'))->orderByDesc('last_message_at')->first();

        if (! $thread) {
            $child = $children->first();
            $thread = $child
                ? $this->threadFor($child, $kind)
                : MessageThread::create(['kind' => MessageThread::OFFICE, 'title' => $person->name]);
        }

        $message = $thread->messages()->create([
            'direction' => Message::IN,
            'body' => filled($body) ? trim($body) : null,
            'person_id' => $person->id,
            'media' => $mediaUrls ? array_map(fn ($u) => ['kind' => 'photo', 'url' => $u], $mediaUrls) : null,
            'sent_at' => now(),
        ]);

        $thread->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    /* ---------------------------------------------------------------
     * ✨ Polish
     * --------------------------------------------------------------- */

    /**
     * The same message, with the spelling, grammar and tone put right and
     * the meaning left alone. Asks the model when there is a key, and does
     * the small fixes itself when there is not — so the button always does
     * something.
     */
    public function polish(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (filled(config('services.gemini.key'))) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent', [
                        'contents' => [['parts' => [['text' => "Rewrite this text message from a daycare to a parent. Fix spelling and grammar and make the tone warm and friendly. Keep every fact and the meaning exactly the same, add nothing, keep it about the same length, and return only the rewritten message as plain text.\n\n".$text]]]],
                    ])->throw();

                $out = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text'));

                if ($out !== '') {
                    return $out;
                }
            } catch (\Throwable $e) {
                Log::warning('[messages] polish fell back to the local fixes: '.$e->getMessage());
            }
        }

        return $this->tidy($text);
    }

    /** The small fixes, done here: common misspellings, "i", "u", a capital and a full stop. */
    private function tidy(string $text): string
    {
        $fix = [
            'tomorow' => 'tomorrow', 'tommorow' => 'tomorrow', 'becuase' => 'because', 'recieve' => 'receive', 'wich' => 'which',
            'alot' => 'a lot', 'untill' => 'until', 'definately' => 'definitely', 'thier' => 'their', 'freind' => 'friend',
            'sory' => 'sorry', 'plz' => 'please', 'pls' => 'please', 'thx' => 'thanks', 'u' => 'you', 'ur' => 'your', 'i' => 'I',
            'dont' => "don't", 'cant' => "can't", 'wont' => "won't", 'didnt' => "didn't", 'im' => "I'm", 'ive' => "I've",
        ];

        $out = preg_replace_callback('/[A-Za-z\']+/u', function ($m) use ($fix) {
            $word = $m[0];
            $lower = strtolower($word);

            if (! isset($fix[$lower])) {
                return $word;
            }

            $fixed = $fix[$lower];

            return ctype_upper($word[0]) && $lower !== 'i' ? ucfirst($fixed) : $fixed;
        }, $text);

        $out = preg_replace('/\s+/', ' ', $out);
        $out = preg_replace_callback('/(^|[.!?]\s+)([a-z])/', fn ($m) => $m[1].strtoupper($m[2]), $out);

        if (! preg_match('/[.!?…]$/u', $out)) {
            $out .= '.';
        }

        return trim($out);
    }
}
