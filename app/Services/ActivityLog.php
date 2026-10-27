<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Attendance;
use App\Models\Child;
use App\Models\ChildNote;
use App\Models\ClosureDay;
use App\Models\DssDayMark;
use App\Models\KioskEvent;
use App\Models\LeaveRequest;
use App\Models\LoginEvent;
use App\Models\Message;
use App\Models\ScheduleSlot;
use App\Models\Setting;
use App\Models\StaffRoomCheckin;
use App\Models\StaffShift;
use App\Models\StaffTimeOff;
use App\Models\TimePunch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Everything anyone does, written down as a sentence.
 *
 * Ray (28 Oct 2026): "every time someone does something it is logged —
 * 11:00 AM = Farhana checked Saroya in @ 11:00 AM; 11:05 AM = Patrick
 * modified Evana check in time for Sept 15 from Nothing to 10:00 AM".
 *
 * Hung off the models' own created / updated / deleted events rather than
 * off the controllers, so a check-in from the teacher page, the director's
 * grid, the DSS sheet, the door kiosk or a console command all leave the
 * same line. Each watched model has a describer below that turns the
 * change into the words the office would use; a change the describer has
 * no words for is not logged, and a describer that throws is reported and
 * never stops the save it was describing.
 *
 * The actor is whoever is signed in. The kiosks have nobody signed in, so
 * they are named by the screen: "Front door", "Time clock", "Room tablet".
 * A scheduled command is "System".
 */
class ActivityLog
{
    /** @var list<class-string<Model>> */
    public const WATCHED = [
        Attendance::class, DssDayMark::class, TimePunch::class, StaffRoomCheckin::class,
        Child::class, ChildNote::class, ScheduleSlot::class,
        User::class, StaffShift::class, StaffTimeOff::class, LeaveRequest::class,
        Setting::class, ClosureDay::class, Message::class, LoginEvent::class, KioskEvent::class,
    ];

    /** Columns whose change is never worth a line. */
    private const QUIET = [
        'updated_at', 'created_at', 'remember_token', 'last_seen_at', 'last_login_at',
        'email_verified_at', 'password_changed_at', 'must_change_password', 'age',
        'enrollment_note_at', 'name_format', 'updated_by', 'created_by',
        // Written by Child's own saving hook alongside classroom, which tells it.
        'classroom_override', 'classroom_override_from',
    ];

    /** The log says these changed, never to what. */
    private const SECRET = ['password', 'pin', 'token', 'secret'];

    private static bool $ready = false;

    public static function watch(): void
    {
        foreach (self::WATCHED as $model) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $model::{$event}(fn (Model $m) => self::tell($event, $m));
            }
        }
    }

    public static function tell(string $event, Model $model): void
    {
        // Not before the table exists: the migration that creates it saves
        // nothing, but a migration before it might.
        if (! self::$ready) {
            self::$ready = Schema::hasTable('activity_log');
            if (! self::$ready) {
                return;
            }
        }

        try {
            [$actorId, $actor] = self::actor();
            $text = self::describe($event, $model, $actor, $actorId);
            if ($text === null) {
                return;
            }

            Activity::create([
                'actor_id' => $actorId,
                'actor_name' => $actor,
                'subject_type' => $model->getMorphClass(),
                'subject_id' => $model->getKey(),
                'subject_name' => self::subjectName($model),
                'event' => $event,
                'text' => mb_substr($text, 0, 400),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return array{0: ?int, 1: string} */
    private static function actor(): array
    {
        if ($user = auth()->user()) {
            return [$user->id, $user->name];
        }

        $route = (string) (request()->route()?->getName() ?? '');
        if (str_starts_with($route, 'clock.')) {
            return [null, 'Time clock'];
        }
        if (str_starts_with($route, 'room.')) {
            return [null, 'Room tablet'];
        }
        if (str_contains($route, 'door') || str_contains($route, 'kiosk')) {
            return [null, 'Front door'];
        }

        return [null, app()->runningInConsole() ? 'System' : 'Nobody signed in'];
    }

    private static function describe(string $e, Model $m, string $actor, ?int $actorId): ?string
    {
        return match (true) {
            $m instanceof Attendance => self::attendance($e, $m, $actor),
            $m instanceof DssDayMark => self::dssMark($e, $m, $actor),
            $m instanceof TimePunch => self::punch($e, $m, $actor, $actorId),
            $m instanceof StaffRoomCheckin => self::roomCheckin($e, $m),
            $m instanceof Child => self::child($e, $m, $actor),
            $m instanceof ChildNote => self::childNote($e, $m, $actor),
            $m instanceof ScheduleSlot => self::slot($e, $m, $actor),
            $m instanceof User => self::staff($e, $m, $actor, $actorId),
            $m instanceof StaffShift => self::shift($e, $m, $actor),
            $m instanceof StaffTimeOff => self::timeOff($e, $m, $actor),
            $m instanceof LeaveRequest => self::leave($e, $m, $actor, $actorId),
            $m instanceof Setting => self::setting($e, $m, $actor),
            $m instanceof ClosureDay => self::closure($e, $m, $actor),
            $m instanceof Message => self::message($e, $m, $actor),
            $m instanceof LoginEvent => self::login($e, $m),
            $m instanceof KioskEvent => self::kiosk($e, $m),
            default => null,
        };
    }

    // ---- The describers ---------------------------------------------------

    private static function attendance(string $e, Attendance $a, string $actor): ?string
    {
        $c = self::childName($a->child);
        $day = self::d($a->attendance_date);
        $today = Carbon::parse($a->attendance_date)->isToday();
        $for = $today ? '' : ' for '.$day;
        $sess = $a->session && $a->session !== 'FULL' ? ' ('.$a->session.')' : '';

        if ($e === 'created') {
            if ($a->signed_in_at && $a->signed_out_at) {
                return "$actor entered {$c}'s times{$for}: ".self::t($a->signed_in_at).'–'.self::t($a->signed_out_at).$sess;
            }
            if ($a->signed_in_at) {
                return "$actor checked $c in @ ".self::t($a->signed_in_at).$for.$sess;
            }
            if ($a->signed_out_at) {
                return "$actor checked $c out @ ".self::t($a->signed_out_at).$for.$sess;
            }

            return null;
        }

        if ($e === 'deleted') {
            $was = $a->signed_in_at ? ' (was '.self::t($a->signed_in_at).'–'.self::t($a->signed_out_at).')' : '';

            return "$actor removed {$c}'s attendance for $day$sess$was";
        }

        $changed = array_keys($a->getChanges());
        $in = in_array('signed_in_at', $changed, true);
        $out = in_array('signed_out_at', $changed, true);

        if ($in && $out) {
            return "$actor modified {$c}'s times for $day from ".self::t($a->getOriginal('signed_in_at')).'–'.self::t($a->getOriginal('signed_out_at'))
                .' to '.self::t($a->signed_in_at).'–'.self::t($a->signed_out_at).$sess;
        }
        if ($in) {
            $old = $a->getOriginal('signed_in_at');
            if ($old === null && $today && $a->signed_in_at) {
                return "$actor checked $c in @ ".self::t($a->signed_in_at).$sess;
            }

            return "$actor modified {$c}'s check-in time for $day from ".self::t($old).' to '.self::t($a->signed_in_at).$sess;
        }
        if ($out) {
            $old = $a->getOriginal('signed_out_at');
            if ($old === null && $today && $a->signed_out_at) {
                return "$actor checked $c out @ ".self::t($a->signed_out_at).$sess;
            }

            return "$actor modified {$c}'s check-out time for $day from ".self::t($old).' to '.self::t($a->signed_out_at).$sess;
        }
        if (array_intersect($changed, ['health_in_code', 'health_in_note', 'health_out_code', 'health_out_note'])) {
            return "$actor recorded a health check for $c$for";
        }

        return null;
    }

    private static function dssMark(string $e, DssDayMark $m, string $actor): ?string
    {
        $c = self::childName($m->child);
        $day = self::d($m->marked_on);
        $word = $m->mark === DssDayMark::ABSENT ? 'absent' : 'not scheduled';

        return match ($e) {
            'created' => "$actor marked $c $word for $day",
            'updated' => in_array('mark', array_keys($m->getChanges()), true) ? "$actor marked $c $word for $day" : null,
            'deleted' => "$actor cleared the $word mark on $c for $day",
            default => null,
        };
    }

    private static function punch(string $e, TimePunch $p, string $actor, ?int $actorId): ?string
    {
        $s = self::staffName($p->user);
        $self = $actorId !== null && $actorId === $p->user_id;
        $did = strtolower(TimePunch::LABELS[$p->type] ?? $p->type);   // "clocked in"
        $what = strtolower(TimePunch::ACTIONS[$p->type] ?? $p->type); // "clock in"
        $on = Carbon::parse($p->work_date)->isToday() ? '' : ' on '.self::d($p->work_date);
        $why = $p->reason ? ' — '.(TimePunch::REASONS[$p->reason] ?? $p->reason) : '';

        if ($e === 'created') {
            if ($p->voided_at) {
                return null;
            }

            return $self || $p->source === TimePunch::SOURCE_CLOCK || $p->source === TimePunch::SOURCE_ROOM_TABLET
                ? "$s $did @ ".self::t($p->punched_at).$on.($self ? '' : '')
                : "$actor added a $what for $s @ ".self::t($p->punched_at).$on.$why;
        }
        if ($e === 'deleted') {
            return "$actor removed {$s}'s $what @ ".self::t($p->punched_at).$on;
        }

        $changed = array_keys($p->getChanges());
        if (in_array('voided_at', $changed, true) && $p->voided_at) {
            return "$actor voided {$s}'s $what @ ".self::t($p->punched_at).$on.$why;
        }
        if (in_array('punched_at', $changed, true)) {
            return "$actor modified {$s}'s $what$on from ".self::t($p->getOriginal('punched_at')).' to '.self::t($p->punched_at).$why;
        }

        return null;
    }

    private static function roomCheckin(string $e, StaffRoomCheckin $r): ?string
    {
        $s = self::staffName($r->user);
        $place = $r->classroom ?: 'the office';

        if ($e === 'created') {
            return "$s checked into $place";
        }
        if ($e === 'updated' && in_array('ended_at', array_keys($r->getChanges()), true) && $r->ended_at) {
            return match ($r->ended_reason) {
                StaffRoomCheckin::ENDED_BREAK => "$s left $place for a break",
                StaffRoomCheckin::ENDED_CLOSING => "$s was taken out of $place at closing",
                default => null, // a move is told by the new check-in, a clock-out by the punch
            };
        }

        return null;
    }

    private static function child(string $e, Child $c, string $actor): ?string
    {
        $name = self::childName($c);

        if ($e === 'created') {
            return "$actor added $name".($c->lan ? " (LAN {$c->lan})" : '');
        }
        if ($e === 'deleted') {
            return "$actor deleted {$name}'s record";
        }

        $changes = self::fieldChanges($c, [
            'status' => fn ($v) => Child::STATUS_LABELS[$v] ?? $v,
            'classroom' => null, 'first_name' => null, 'last_name' => null,
            'drop_off_time' => 'agreed drop-off', 'pick_up_time' => 'agreed pick-up', 'schedule_days' => 'schedule days',
            'dss_cin' => 'CIN', 'dss_case_no' => 'DSS case number', 'expected_hours_per_week' => 'authorized hours',
            'enrollment_note' => 'enrollment note', 'withdrawn_on' => 'withdrawal date', 'enrolled_on' => 'enrolled on',
            'birth_date' => 'date of birth', 'dob' => 'date of birth',
            'archived_at' => fn ($v) => $v ? 'archived' : 'unarchived',
        ]);

        return $changes === [] ? null : "$actor changed {$name}'s ".implode(', ', $changes);
    }

    private static function childNote(string $e, ChildNote $n, string $actor): ?string
    {
        $c = self::childName($n->child);
        $kind = strtolower(ChildNote::KINDS[$n->kind] ?? (string) $n->kind);

        return match ($e) {
            'created' => "$actor added a $kind note on $c",
            'updated' => "$actor edited a $kind note on $c",
            'deleted' => "$actor removed a $kind note on $c",
            default => null,
        };
    }

    private static function slot(string $e, ScheduleSlot $s, string $actor): ?string
    {
        $c = self::childName($s->child);
        $day = self::d($s->slot_date);
        $sess = $s->session && $s->session !== 'FULL' ? ' ('.$s->session.')' : '';
        $changed = array_keys($s->getChanges());

        if ($e === 'deleted') {
            return "$actor cleared {$c}'s booking for $day$sess";
        }
        if ($e === 'created' || in_array('is_scheduled', $changed, true)) {
            return $s->is_scheduled ? "$actor booked $c for $day$sess" : "$actor unbooked $c for $day$sess";
        }
        if (in_array('planned_time', $changed, true)) {
            return "$actor set {$c}'s time for $day$sess to ".($s->planned_time ? self::t(Carbon::parse($s->planned_time)) : 'none');
        }

        return null;
    }

    private static function staff(string $e, User $u, string $actor, ?int $actorId): ?string
    {
        $s = $u->name;
        $own = $actorId === $u->id;
        $whose = $own ? 'their own' : "{$s}'s";

        if ($e === 'created') {
            return "$actor added staff member $s".($u->role ? " ($u->role)" : '');
        }
        if ($e === 'deleted') {
            return "$actor removed staff member $s";
        }

        $changes = self::fieldChanges($u, ['role' => null, 'name' => null, 'email' => null, 'phone' => null, 'classroom' => 'room']);

        return $changes === [] ? null : "$actor changed $whose ".implode(', ', $changes);
    }

    private static function shift(string $e, StaffShift $sh, string $actor): ?string
    {
        $s = self::staffName($sh->user);
        $day = self::d($sh->shift_date);
        $span = self::minutes($sh->starts_at).'–'.self::minutes($sh->ends_at);
        $room = $sh->classroom ? " in {$sh->classroom}" : '';

        return match ($e) {
            'created' => "$actor scheduled $s on $day $span$room",
            'updated' => "$actor changed {$s}'s shift on $day to $span$room",
            'deleted' => "$actor removed {$s}'s shift on $day",
            default => null,
        };
    }

    private static function timeOff(string $e, StaffTimeOff $t, string $actor): ?string
    {
        $s = self::staffName($t->user);
        $when = self::d($t->date).($t->until && $t->until->ne($t->date) ? '–'.self::d($t->until) : '');
        $kind = method_exists($t, 'kindLabel') ? $t->kindLabel() : $t->kind;

        return match ($e) {
            'created' => "$actor gave $s time off $when ($kind)".($t->reason ? " — {$t->reason}" : ''),
            'updated' => "$actor changed {$s}'s time off $when ($kind)",
            'deleted' => "$actor removed {$s}'s time off $when",
            default => null,
        };
    }

    private static function leave(string $e, LeaveRequest $l, string $actor, ?int $actorId): ?string
    {
        $s = self::staffName($l->user);
        $own = $actorId === $l->user_id;
        $span = self::d($l->starts_on).($l->ends_on && $l->ends_on->ne($l->starts_on) ? '–'.self::d($l->ends_on) : '');
        $type = $l->leave_type ? strtolower((string) $l->leave_type).' ' : '';

        if ($e === 'created') {
            return $own ? "$s requested {$type}leave $span" : "$actor entered {$type}leave for $s $span";
        }
        if ($e === 'deleted') {
            return "$actor removed {$s}'s leave $span";
        }
        if (in_array('status', array_keys($l->getChanges()), true)) {
            return match ($l->status) {
                LeaveRequest::STATUS_APPROVED => "$actor approved {$s}'s leave $span",
                LeaveRequest::STATUS_DENIED => "$actor declined {$s}'s leave $span",
                LeaveRequest::STATUS_CANCELLED => ($own ? "$s cancelled their" : "$actor cancelled {$s}'s")." leave $span",
                default => null,
            };
        }

        return null;
    }

    private static function setting(string $e, Setting $s, string $actor): ?string
    {
        return match ($e) {
            'created' => "$actor set {$s->key} to ".self::v($s->value),
            'updated' => in_array('value', array_keys($s->getChanges()), true)
                ? "$actor changed {$s->key} from ".self::v($s->getOriginal('value')).' to '.self::v($s->value)
                : null,
            'deleted' => "$actor cleared {$s->key}",
            default => null,
        };
    }

    private static function closure(string $e, ClosureDay $c, string $actor): ?string
    {
        $day = self::d($c->closed_on);

        return match ($e) {
            'created' => "$actor closed the centre on $day".($c->reason ? " ({$c->reason})" : ''),
            'deleted' => "$actor reopened $day",
            default => null,
        };
    }

    private static function message(string $e, Message $m, string $actor): ?string
    {
        if ($e !== 'created') {
            return null;
        }
        $title = $m->thread?->title ?: 'Messages';

        return $m->direction === Message::OUT || $m->user_id
            ? "$actor sent a message in “{$title}”"
            : "A reply came in on “{$title}”";
    }

    private static function login(string $e, LoginEvent $l): ?string
    {
        if ($e !== 'created') {
            return null;
        }
        $who = $l->user?->name ?: ($l->email ?: 'someone');

        return match ($l->outcome) {
            LoginEvent::SUCCESS => "$who signed in",
            LoginEvent::LOGOUT => "$who signed out",
            LoginEvent::FAILED => "Failed sign-in for $who",
            LoginEvent::KIOSK => "$who signed in at a kiosk",
            LoginEvent::KIOSK_FAILED => 'Failed kiosk sign-in'.($l->user ? " for $who" : ''),
            default => null,
        };
    }

    private static function kiosk(string $e, KioskEvent $k): ?string
    {
        if ($e !== 'created') {
            return null;
        }

        return match ($k->kind) {
            KioskEvent::TABLET => 'Room tablet: '.$k->text,
            KioskEvent::FLAG => 'Flag for the office: '.$k->text,
            default => null, // punches and room moves are told by their own records
        };
    }

    // ---- The words -------------------------------------------------------

    /**
     * "status from Active to Withdrawn, classroom from PreK to UPK-4" — the
     * fields that changed, each with its before and after. $labels maps a
     * column to a label, or to a closure that renders its value; a column
     * not listed gets its own name with the underscores taken out.
     *
     * @return list<string>
     */
    private static function fieldChanges(Model $m, array $labels): array
    {
        $out = [];
        foreach (array_keys($m->getChanges()) as $key) {
            if (in_array($key, self::QUIET, true)) {
                continue;
            }
            $label = str_replace('_', ' ', $key);
            $render = fn ($v) => self::v($v);
            if (array_key_exists($key, $labels)) {
                if ($labels[$key] instanceof \Closure) {
                    $render = $labels[$key];
                } elseif (is_string($labels[$key])) {
                    $label = $labels[$key];
                }
            }

            foreach (self::SECRET as $secret) {
                if (str_contains($key, $secret)) {
                    $out[] = $label;
                    continue 2;
                }
            }

            $before = $render($m->getOriginal($key));
            $after = $render($m->getAttribute($key));
            if ($before === $after) {
                continue;
            }
            $out[] = $key === 'archived_at' ? $after : "$label from $before to $after";
        }

        if (count($out) > 4) {
            $out = array_merge(array_slice($out, 0, 3), [(count($out) - 3).' more fields']);
        }

        return $out;
    }

    /** A value as the office would say it: dates as "Sep 15", empties as "Nothing". */
    private static function v(mixed $v): string
    {
        if ($v === null || $v === '' || $v === []) {
            return 'Nothing';
        }
        if ($v instanceof \DateTimeInterface) {
            $c = Carbon::instance($v);

            return $c->format('H:i:s') === '00:00:00' ? self::d($c) : self::d($c).' '.self::t($c);
        }
        if (is_bool($v)) {
            return $v ? 'yes' : 'no';
        }
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v));
        }

        return mb_strimwidth((string) $v, 0, 80, '…');
    }

    /** "10:00 AM", or "Nothing" when there is no time. */
    private static function t(mixed $v): string
    {
        if (blank($v)) {
            return 'Nothing';
        }
        $c = $v instanceof \DateTimeInterface ? Carbon::instance($v) : Carbon::parse($v);

        return $c->timezone(config('app.timezone'))->format('g:i A');
    }

    /** "Sep 15", with the year only when it is not this one. */
    private static function d(mixed $v): string
    {
        $c = $v instanceof \DateTimeInterface ? Carbon::instance($v) : Carbon::parse($v);

        return $c->format($c->year === now()->year ? 'M j' : 'M j, Y');
    }

    /** Minutes since midnight as "8:00 AM". */
    private static function minutes(?int $m): string
    {
        return $m === null ? '—' : Carbon::today()->addMinutes($m)->format('g:i A');
    }

    private static function childName(?Child $c): string
    {
        if (! $c) {
            return 'a child';
        }

        return trim($c->first_name.' '.$c->last_name) ?: ($c->child_name ?: 'a child');
    }

    private static function staffName(?User $u): string
    {
        return $u?->name ?: 'a staff member';
    }

    private static function subjectName(Model $m): ?string
    {
        return match (true) {
            $m instanceof Child => self::childName($m),
            $m instanceof User => $m->name,
            $m instanceof Attendance, $m instanceof DssDayMark, $m instanceof ScheduleSlot, $m instanceof ChildNote => self::childName($m->child),
            $m instanceof TimePunch, $m instanceof StaffRoomCheckin, $m instanceof StaffShift, $m instanceof StaffTimeOff, $m instanceof LeaveRequest => self::staffName($m->user),
            $m instanceof Setting => $m->key,
            default => null,
        };
    }
}
