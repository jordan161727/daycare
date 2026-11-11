<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildAttendancePunch;
use App\Models\ChildPerson;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\ParentAbsenceReport;
use App\Models\ParentPickupPlan;
use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Version 2 of the parent app (9 Nov 2026): a child's day, their photos, the
 * calendar, absences and pickup. Like ParentPortal, every rule is here and the
 * controller only translates. See docs/parent-app-api.md §4.12–4.17.
 *
 * It reads what the centre already records and invents nothing: the day is
 * the register (attendances and the door's punches), the photos are the ones
 * staff sent in the parent's conversations, the calendar is the closure days.
 * Meals, naps and teachers' notes are not recorded anywhere yet, so the daily
 * report says so (totals null) rather than showing zeros.
 *
 * What a parent tells the centre goes into a conversation staff already read,
 * so nobody has to learn a new screen: an absence in the Room conversation, a
 * pickup in the Office one.
 */
class ParentDay
{
    public const PHOTO_DAYS_PER_PAGE = 7;

    public function __construct(private ParentPortal $portal) {}

    /** One of this parent's children, or null — callers answer 404. */
    public function childOf(Person $person, int $childId): ?Child
    {
        return $this->portal->children($person)->firstWhere('id', $childId);
    }

    /* ---------------------------------------------------------------
     * The day: GET /children/{id}/daily?date=
     * --------------------------------------------------------------- */

    public function daily(Child $child, string $date): array
    {
        $punches = ChildAttendancePunch::where('child_id', $child->id)
            ->where('service_date', $date)
            ->with('person')
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $rows = Attendance::where('child_id', $child->id)
            ->where('attendance_date', $date)
            ->orderBy('signed_in_at')
            ->get();

        $entries = $punches->isNotEmpty()
            ? $punches->map(fn (ChildAttendancePunch $p) => $this->punchEntry($p))
            : $rows->flatMap(fn (Attendance $a) => array_filter([
                $a->signed_in_at ? ['id' => $a->id * 10 + 1, 'time' => $this->iso($a->signed_in_at), 'type' => 'checkin', 'title' => 'Checked in', 'detail' => null] : null,
                $a->signed_out_at ? ['id' => $a->id * 10 + 2, 'time' => $this->iso($a->signed_out_at), 'type' => 'checkout', 'title' => 'Checked out', 'detail' => null] : null,
            ]));

        return [
            'date' => $date,
            'status' => $this->status($child, $date, $rows, $punches),
            // Not recorded at the centre yet: null, not a row of zeros.
            'totals' => null,
            'entries' => $entries->sortBy('time')->values()->all(),
            'note' => null,
        ];
    }

    private function punchEntry(ChildAttendancePunch $p): array
    {
        $in = $p->direction === ChildAttendancePunch::IN;
        $who = $p->person?->name;

        return [
            'id' => $p->id,
            'time' => $this->iso($p->occurred_at),
            'type' => $in ? 'checkin' : 'checkout',
            'title' => $in ? 'Checked in' : 'Checked out',
            'detail' => $who ? ($in ? 'Dropped off by ' : 'Picked up by ').$who : null,
        ];
    }

    /** Where the child is: in, gone home, away, or not here yet. */
    private function status(Child $child, string $date, Collection $rows, Collection $punches): array
    {
        $in = $rows->whereNotNull('signed_in_at')->sortBy('signed_in_at')->first();
        $out = $rows->whereNotNull('signed_out_at')->sortByDesc('signed_out_at')->first();
        $still = $rows->first(fn (Attendance $a) => $a->isSignedIn());
        $whoIn = $punches->firstWhere('direction', ChildAttendancePunch::IN)?->person?->name;
        $whoOut = $punches->where('direction', ChildAttendancePunch::OUT)->last()?->person?->name;

        if ($in && ! $still && $out) {
            return ['state' => 'checked_out', 'time' => $this->iso($out->signed_out_at), 'by' => $whoOut];
        }
        if ($in) {
            return ['state' => 'checked_in', 'time' => $this->iso($in->signed_in_at), 'by' => $whoIn];
        }

        $report = ParentAbsenceReport::where('child_id', $child->id)->where('absent_on', $date)->with('person')->first();
        if ($report) {
            return ['state' => 'absent', 'time' => $this->iso($report->created_at), 'by' => $report->person?->name];
        }
        if (DssDayMark::where('child_id', $child->id)->where('marked_on', $date)->where('mark', DssDayMark::ABSENT)->exists()) {
            return ['state' => 'absent', 'time' => null, 'by' => null];
        }

        return ['state' => 'not_arrived', 'time' => null, 'by' => null];
    }

    /* ---------------------------------------------------------------
     * Photos: GET /children/{id}/photos?page=
     * --------------------------------------------------------------- */

    /**
     * Every photo staff sent in a conversation this parent can read about this
     * child (its Room and Office conversations, and the announcements), by day,
     * newest first.
     */
    public function photos(Person $person, Child $child, int $page): array
    {
        $threadIds = $this->portal->threads($person)
            ->where(fn ($q) => $q->where('child_id', $child->id)->orWhere('kind', MessageThread::ANNOUNCEMENT))
            ->pluck('id');

        $messages = Message::whereIn('thread_id', $threadIds)
            ->where('direction', Message::OUT)
            ->whereNotNull('media')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        $byDay = [];
        foreach ($messages as $m) {
            $photos = collect($m->media ?? [])->filter(fn ($x) => ($x['kind'] ?? 'photo') === 'photo' && isset($x['path']))->values();
            if ($photos->isEmpty()) {
                continue;
            }
            $at = Carbon::parse($m->sent_at ?? $m->created_at)->timezone(config('app.timezone'));
            $day = $at->toDateString();
            $byDay[$day]['captions'][] = $m->body ? Str::limit($m->body, 60) : null;
            foreach ($photos as $i => $x) {
                $url = app(Messaging::class)->mediaUrl($x['path']);
                $byDay[$day]['photos'][] = ['id' => $m->id * 10 + $i, 'thumb_url' => $url, 'full_url' => $url, 'taken_at' => $at->toIso8601String()];
            }
        }

        $days = array_keys($byDay);
        rsort($days);
        $slice = array_slice($days, ($page - 1) * self::PHOTO_DAYS_PER_PAGE, self::PHOTO_DAYS_PER_PAGE);

        return [
            'groups' => array_map(fn (string $day) => [
                'date' => $day,
                'label' => $this->dayLabel($day),
                'caption' => collect($byDay[$day]['captions'])->filter()->unique()->first(),
                'photos' => $byDay[$day]['photos'],
            ], $slice),
            'next_page' => count($days) > $page * self::PHOTO_DAYS_PER_PAGE ? $page + 1 : null,
        ];
    }

    private function dayLabel(string $day): string
    {
        $d = Carbon::parse($day, config('app.timezone'));

        return match (true) {
            $d->isToday() => 'TODAY',
            $d->isYesterday() => 'YESTERDAY',
            default => strtoupper($d->format('D, M j')),
        };
    }

    /* ---------------------------------------------------------------
     * Calendar: GET /events?month=YYYY-MM
     * --------------------------------------------------------------- */

    /** The days the centre is closed. There is no events table yet; when there is, its rows join these. */
    public function events(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', config('app.timezone'))->startOfDay();

        $events = ClosureDay::betweenDates($start, $start->copy()->endOfMonth())
            ->orderBy('closed_on')
            ->get()
            ->map(fn (ClosureDay $d) => [
                'id' => $d->id,
                'date' => $d->closed_on->toDateString(),
                'kind' => 'closed',
                'title' => $d->label(),
                'detail' => null,
            ])->values()->all();

        return ['month' => $month, 'events' => $events];
    }

    /* ---------------------------------------------------------------
     * Absence: POST /absences
     * --------------------------------------------------------------- */

    /**
     * Record the days and tell the room, in the Room conversation, as a
     * message from the parent — the teachers see it where they already look.
     *
     * @param  list<string>  $dates
     * @param  list<string>  $symptoms
     */
    public function reportAbsence(Person $person, Child $child, array $dates, string $reason, array $symptoms, ?string $note): array
    {
        sort($dates);
        $symptoms = $reason === 'sick' ? array_values(array_unique($symptoms)) : [];

        return DB::transaction(function () use ($person, $child, $dates, $reason, $symptoms, $note) {
            $thread = $this->portal->openThread($child, MessageThread::ROOM);
            $message = $this->portal->send($person, $thread, $this->absenceText($child, $dates, $reason, $symptoms, $note), []);

            $reports = collect($dates)->map(fn (string $date) => ParentAbsenceReport::updateOrCreate(
                ['child_id' => $child->id, 'absent_on' => $date],
                ['person_id' => $person->id, 'reason' => $reason, 'symptoms' => $symptoms ?: null, 'note' => $note, 'message_id' => $message->id],
            ));

            return $reports->map(fn (ParentAbsenceReport $r) => [
                'id' => $r->id,
                'child_id' => $r->child_id,
                'date' => $r->absent_on->toDateString(),
                'reason' => $r->reason,
            ])->values()->all();
        });
    }

    private function absenceText(Child $child, array $dates, string $reason, array $symptoms, ?string $note): string
    {
        $days = collect($dates)->map(fn ($d) => Carbon::parse($d)->format('D, M j'))->join(', ', ' and ');
        $text = 'Absence: '.$child->first_name.' will be away '.$days.'. Reason: '.ucfirst($reason);
        if ($symptoms !== []) {
            $text .= ' ('.implode(', ', array_map(fn ($s) => $s === 'stomach' ? 'upset stomach' : $s, $symptoms)).')';
        }
        $text .= '.';
        if (filled($note)) {
            $text .= ' Note: '.trim($note);
        }

        return $text;
    }

    /* ---------------------------------------------------------------
     * Pickup: GET /children/{id}/pickup, PUT …/today, POST …/one-time
     * --------------------------------------------------------------- */

    /**
     * The pick-up list as the door reads it, and who the parent said is coming
     * today. The door PIN is never sent: it is stored only as a hash, and each
     * adult has their own.
     */
    public function pickup(Child $child): array
    {
        $people = $this->pickupList($child)->map(fn (ChildPerson $l) => [
            'id' => $l->person_id,
            'name' => $l->person?->name ?? 'Unknown',
            'relation' => $l->relationship ?: 'Family',
            'is_parent' => (bool) $l->is_guardian,
        ])->values();

        $plan = ParentPickupPlan::where('child_id', $child->id)->where('pickup_on', $this->today())->first();
        if ($plan?->isOneTime()) {
            $people->push(['id' => 0, 'name' => $plan->name, 'relation' => $plan->relation ?: 'One time', 'is_parent' => false, 'one_time' => true]);
        }

        return [
            'code' => null,
            'expires_at' => null,
            'code_hint' => 'Use your own door PIN at the front-desk kiosk. Ask the office if you need one.',
            'people' => $people->all(),
            'today_person_id' => $plan ? ($plan->person_id ?? 0) : null,
        ];
    }

    /** @return Collection<int, ChildPerson> guardians first, then by name */
    public function pickupList(Child $child): Collection
    {
        return ChildPerson::where('child_id', $child->id)
            ->allowedToCollect()
            ->with('person')
            ->get()
            ->filter(fn (ChildPerson $l) => $l->allowsPickup() && $l->person)
            ->sortBy(fn (ChildPerson $l) => [$l->is_guardian ? 0 : 1, $l->person->name])
            ->values();
    }

    /** Somebody on the list is collecting today. Null when they are not on it. */
    public function chooseToday(Person $by, Child $child, int $personId): ?array
    {
        $link = $this->pickupList($child)->firstWhere('person_id', $personId);
        if (! $link) {
            return null;
        }

        $relation = $link->relationship ?: 'Family';
        $this->savePlan($by, $child, $link->person_id, $link->person->name, $relation, null,
            'Pickup today: '.$link->person->name.' ('.$relation.') will collect '.$child->first_name.'.');

        return $this->pickup($child);
    }

    /** Somebody not on the list. The office checks them; they are never added to it. */
    public function oneTimeToday(Person $by, Child $child, string $name, string $relation, string $phone): array
    {
        $this->savePlan($by, $child, null, $name, $relation, $phone,
            'Pickup today: '.$name.' ('.$relation.', '.$phone.') will collect '.$child->first_name.'. They are not on the pick-up list — please check photo ID.');

        return $this->pickup($child);
    }

    private function savePlan(Person $by, Child $child, ?int $personId, string $name, ?string $relation, ?string $phone, string $text): void
    {
        DB::transaction(function () use ($by, $child, $personId, $name, $relation, $phone, $text) {
            $thread = $this->portal->openThread($child, MessageThread::OFFICE);
            $message = $this->portal->send($by, $thread, $text, []);

            ParentPickupPlan::updateOrCreate(
                ['child_id' => $child->id, 'pickup_on' => $this->today()],
                ['person_id' => $personId, 'name' => $name, 'relation' => $relation, 'phone' => $phone, 'set_by' => $by->id, 'message_id' => $message->id],
            );
        });
    }

    /* ---------------------------------------------------------------
     * Helpers
     * --------------------------------------------------------------- */

    public function today(): string
    {
        return now(config('app.timezone'))->toDateString();
    }

    private function iso($at): ?string
    {
        return $at ? Carbon::parse($at)->timezone(config('app.timezone'))->toIso8601String() : null;
    }
}
