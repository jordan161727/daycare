<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceComment;
use App\Models\ChildPerson;
use App\Models\HealthAudit;
use App\Models\ParentNotificationPref;
use App\Models\SymptomCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells a child's parents, by push to the app, what staff noted on the
 * register (17 Nov 2026): the day's comment, and a drop-off or pick-up health
 * check that found something. The same people the app shows that child to
 * (ParentPortal: Texts ticked, no restriction, child Active or Pending), less
 * anyone who switched "Daily report" notifications off.
 *
 * A push that fails is logged and forgotten: a teacher saving a note must
 * never be stopped by a parent's phone.
 */
class ParentAlerts
{
    public function __construct(private ParentPush $push) {}

    /** A comment was written, or its words changed. */
    public function comment(AttendanceComment $comment, bool $edited = false): void
    {
        $this->safely(function () use ($comment, $edited) {
            $child = $comment->child()->first();
            if (! $child) {
                return;
            }
            $title = $child->first_name.' · '.($child->classroom ?: 'Little Angels');
            $body = ($edited ? 'Note updated: ' : 'Note from the room: ').Str::limit((string) $comment->body, 140);

            $this->send($comment->child_id, $title, $body, $this->dayOf($comment->date));
        });
    }

    /** A health check was recorded. Only a symptom (anything but Normal) is worth a push. */
    public function health(Attendance $attendance, string $direction, ?int $code, ?string $note): void
    {
        if (! SymptomCode::isSick($code)) {
            return;
        }

        $this->safely(function () use ($attendance, $direction, $code, $note) {
            $child = $attendance->child()->first();
            if (! $child) {
                return;
            }
            $label = SymptomCode::find($code)?->label ?? 'Symptom '.$code;
            $when = $direction === HealthAudit::OUT ? 'at pick-up' : 'at drop-off';
            $body = 'Health check '.$when.': '.$label.($note ? ' — '.Str::limit($note, 100) : '');

            $this->send($attendance->child_id, $child->first_name.' · Health', $body, $this->dayOf($attendance->attendance_date));
        });
    }

    /** The parents the app shows this child to, who want daily-report pushes. @return list<int> */
    public function familyOf(int $childId): array
    {
        $ids = ChildPerson::where('child_id', $childId)
            ->where('gets_texts', true)
            ->where(fn ($w) => $w->whereNull('restriction')->orWhere('restriction', ''))
            ->whereHas('child', fn ($c) => $c->whereIn('status', ParentPortal::CHILD_STATUSES))
            ->pluck('person_id')->unique()->values()->all();

        return ParentNotificationPref::wanting($ids, 'daily');
    }

    private function send(int $childId, string $title, string $body, string $date): void
    {
        // data.kind tells the app to open the Daily report on that day, not a conversation.
        $this->push->send($this->familyOf($childId), $title, $body, ['kind' => 'daily', 'child_id' => $childId, 'date' => $date]);
    }

    private function dayOf($date): string
    {
        return $date ? Carbon::parse($date)->toDateString() : now(config('app.timezone'))->toDateString();
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('[parent-alerts] push not sent: '.$e->getMessage());
        }
    }
}
