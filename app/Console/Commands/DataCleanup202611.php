<?php

namespace App\Console\Commands;

use App\Models\Child;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The QA retest's data cleanup (Nov 2026), as one reviewable command.
 *
 *   php artisan data:cleanup-2026-11 --dry-run          prints every change, writes nothing
 *   php artisan data:cleanup-2026-11                    makes the default changes
 *   php artisan data:cleanup-2026-11 --also=<option>    adds a held change (see HELD)
 *   php artisan data:cleanup-2026-11 --undo=<batch>     puts a run back
 *
 * Nothing is deleted. Children are archived (Child::archive's fields),
 * messages are archived (messages.archived_at), and every field changed is
 * one row in data_cleanup_log with its old and new value, so --undo can
 * restore it — and refuses a field somebody has changed since.
 *
 * Nothing is invented: a value nobody knows (a real date of birth, which
 * Jace Miller is real, what "Unknown" is allergic to) is flagged for staff,
 * never filled in. Records are found by rule and LAN, not by row id, so the
 * same command reads the same on a copy of the data.
 */
class DataCleanup202611 extends Command
{
    protected $signature = 'data:cleanup-2026-11
        {--dry-run : Print what would change and write nothing}
        {--also=* : Include a held change: '.self::HELD_LIST.'}
        {--undo= : Undo the run with this batch id}';

    protected $description = 'QA data cleanup (Nov 2026): Pharaoh Lumpkins, Jace Miller duplicates, room ages, seeded alerts, test messages, staff names — dry-run first, audit-logged, undoable';

    /** Changes left out unless named with --also, because a person has to decide them first. */
    public const HELD = [
        'jace-archive-others' => 'Archive the Jace Miller records that are not kept (their history stays on them)',
        '10023-room' => 'Move Brinton Bentley Sanford (LAN 10023) to UPK-4 — only once staff confirm the DOB',
        'age-others' => 'Move the other children whose room was set by hand against their age',
        'seeded-alerts' => 'Remove the other demo-seeder alerts (Peanuts, Asthma, Leaves early on Tuesdays)',
        'greetings' => 'Archive the bare "hi" messages',
        'beth-names' => 'Give the two Beth Jones a room in their display name',
    ];

    private const HELD_LIST = 'jace-archive-others, 10023-room, age-others, seeded-alerts, greetings, beth-names';

    /** The demo seeder's example alerts (ChildRecordDemoSeeder::ALERTS), by text => action. */
    private const SEEDED_ALERTS = [
        'No pickup by Jordan Key' => 'Call the office at once if he arrives; order on file',
        'Peanuts — all tree nuts' => 'EpiPen in the office cabinet; call 911 then mom',
        'Asthma' => 'Inhaler in the room first-aid bag, two puffs',
        'Leaves early on Tuesdays' => 'Grandma collects at 2:30',
    ];

    /** @var list<array{item: string, table: string, id: int, label: string, field: string, old: mixed, new: mixed, note: ?string}> */
    private array $changes = [];

    /** @var array<string, list<string>> section => lines for staff */
    private array $flags = [];

    /** @var array<string, list<string>> section => lines of information */
    private array $info = [];

    private string $today;

    public function handle(): int
    {
        // A fresh plan every call: the command object can be reused in one process.
        $this->changes = $this->flags = $this->info = [];

        if ($this->option('undo')) {
            return $this->undo((string) $this->option('undo'));
        }

        $also = collect((array) $this->option('also'))->flatMap(fn ($v) => explode(',', (string) $v))->map(fn ($v) => trim($v))->filter()->values();
        $unknown = $also->diff(array_keys(self::HELD));
        if ($unknown->isNotEmpty()) {
            $this->error('Unknown --also: '.$unknown->implode(', ').'. Choose from: '.self::HELD_LIST);

            return self::FAILURE;
        }

        $this->today = Carbon::now(config('app.timezone'))->toDateString();
        $dry = (bool) $this->option('dry-run');

        $this->pharaoh();
        $this->jaceMiller($also->contains('jace-archive-others'));
        $this->ages($also->contains('10023-room'), $also->contains('age-others'));
        $this->seededAlerts($also->contains('seeded-alerts'));
        $this->alertValues();
        $this->messages($also->contains('greetings'));
        $this->staff($also->contains('beth-names'));

        $this->report($dry, $also->all());

        if ($dry) {
            $this->newLine();
            $this->line('DRY RUN — nothing was changed.');

            return self::SUCCESS;
        }

        return $this->apply();
    }

    /* ---- Data 1: Active past the last day ---- */

    private function pharaoh(): void
    {
        $item = 'D1 enrollment';

        // Exactly what the nightly children:sync-enrollments does (Enrollment::sync).
        foreach (DB::table('children')->where('status', 'Active')->whereNotNull('withdrawn_on')->whereDate('withdrawn_on', '<', $this->today)->orderBy('lan')->get() as $c) {
            $this->change($item, 'children', $c, 'status', 'Active', 'Inactive',
                'Last day '.Carbon::parse($c->withdrawn_on)->format('M j, Y').' ('.($c->withdrawal_reason ?: 'no reason').') has passed; the nightly sync never ran. Dates and schedule kept as recorded.');
        }
    }

    /* ---- Data 2: Jace Miller ---- */

    private function jaceMiller(bool $archiveOthers): void
    {
        $item = 'D2 duplicates';
        $kids = DB::table('children')->whereRaw("REPLACE(LOWER(TRIM(first_name)), ' ', '') = 'jace'")
            ->whereRaw("REPLACE(LOWER(TRIM(last_name)), ' ', '') = 'miller'")->orderBy('lan')->get();

        if ($kids->isEmpty()) {
            return;
        }

        $history = fn (object $c) => [
            'attendance' => DB::table('attendances')->where('child_id', $c->id)->count(),
            'door' => DB::table('door_attendances')->where('child_id', $c->id)->count(),
            'DSS marks' => DB::table('dss_day_marks')->where('child_id', $c->id)->count(),
            'booked days' => DB::table('schedule_slots')->where('child_id', $c->id)->where('is_scheduled', true)->count(),
            'people' => DB::table('child_people')->where('child_id', $c->id)->count(),
            'documents' => DB::table('child_documents')->where('child_id', $c->id)->count(),
            'notes' => DB::table('child_notes')->where('child_id', $c->id)->count(),
            'messages' => DB::table('messages')->whereIn('thread_id', DB::table('message_threads')->where('child_id', $c->id)->pluck('id'))->count(),
            'photo' => filled($c->photo_path) ? 1 : 0,
        ];

        // One group per date of birth: two different birthdays are two children by the duplicate rule.
        foreach ($kids->groupBy(fn ($c) => substr((string) ($c->birth_date ?? $c->dob), 0, 10)) as $born => $group) {
            $scored = $group->map(fn ($c) => ['c' => $c, 'h' => $history($c), 'total' => array_sum($history($c))])
                ->sortByDesc('total')->values();
            $keep = $scored->first();

            $this->info[$item][] = 'Born '.Carbon::parse($born)->format('m/d/Y').' — '.$group->count().' record(s):';
            foreach ($scored as $row) {
                $c = $row['c'];
                $before = DB::table('attendances')->where('child_id', $c->id)->whereDate('attendance_date', '<', substr((string) $c->created_at, 0, 10))->count();
                $this->info[$item][] = sprintf('  LAN %s (id %d) %s · %s · %s–%s · created %s · %s%s%s',
                    $c->lan, $c->id, $c->status, $c->classroom, substr((string) $c->drop_off_time, 0, 5), substr((string) $c->pick_up_time, 0, 5),
                    substr((string) $c->created_at, 0, 16),
                    collect($row['h'])->map(fn ($n, $k) => "$n $k")->implode(', '),
                    $before > 0 ? " · {$before} attendance day(s) dated before the record was created" : '',
                    $c->id === $keep['c']->id ? '  ← most history: KEEP' : '');
            }

            $others = $scored->slice(1);
            if ($others->isEmpty()) {
                continue;
            }

            // Moving the others' history onto the one kept would put two
            // sign-ins on one child for the same day: said, not done.
            $keepDays = DB::table('attendances')->where('child_id', $keep['c']->id)->pluck('attendance_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique();
            foreach ($others as $row) {
                $days = DB::table('attendances')->where('child_id', $row['c']->id)->pluck('attendance_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique();
                $samePeople = DB::table('child_people')->where('child_id', $row['c']->id)->pluck('person_id')
                    ->intersect(DB::table('child_people')->where('child_id', $keep['c']->id)->pluck('person_id'))->count();
                $this->flags[$item][] = sprintf('LAN %s → LAN %s: %d of its %d attendance days clash with days LAN %s already has; %d of its people are already on LAN %s. History is NOT moved — a merge needs your approval of the clashing days.',
                    $row['c']->lan, $keep['c']->lan, $days->intersect($keepDays)->count(), $days->count(), $keep['c']->lan, $samePeople, $keep['c']->lan);

                if ($archiveOthers) {
                    $this->archiveChild($item, $row['c'], 'Duplicate of LAN '.$keep['c']->lan.' (same name and birthday); history left on this record.');
                }
            }
        }

        $this->flags[$item][] = 'All of them have 7:00 AM–8:00 PM hours, outside the center\'s opening hours. Every record has history, so none is archived by default. To archive all but the kept one per birthday: --also=jace-archive-others.';
    }

    /* ---- Data 3: room against age ---- */

    private function ages(bool $sanford, bool $others): void
    {
        $item = 'D3 rooms';
        $named = ['10004' => true, '10023' => $sanford];

        foreach (Child::query()->whereIn('status', ['Active', 'Pending'])->orderBy('lan')->get() as $child) {
            $fit = $child->roomFit(Carbon::now(config('app.timezone')));
            $auto = $child->automaticClassroom();
            $born = $child->birthDate()?->format('m/d/Y') ?? 'no DOB';
            $who = "LAN {$child->lan} {$child->last_name}, {$child->first_name} (DOB {$born}, {$child->ageInWords()})";

            if (($fit['state'] ?? 'ok') === 'ok') {
                if ($auto !== null && $child->classroom_override === null && $auto !== $child->classroom) {
                    $this->info[$item][] = "{$who}: in {$child->classroom}; the nightly room sync will move them to {$auto} (no change here).";
                }

                continue;
            }

            if ($auto === null) {
                $this->flags[$item][] = "{$who}: in {$child->classroom} — {$fit['text']} Age-out / withdrawal is a staff decision; not changed.";

                continue;
            }

            $include = array_key_exists($child->lan, $named) ? $named[$child->lan] : $others;
            $row = DB::table('children')->where('id', $child->id)->first();

            if ($child->lan === '10023') {
                $this->flags[$item][] = "{$who}: in Infant. The DOB reads the same in both date fields and in the old 'age' column of the import, so it cannot be checked from the data — staff please check the birth certificate. If it is right: --also=10023-room moves him to UPK-4.";
            }

            if (! $include) {
                if ($child->lan !== '10023') {
                    $this->flags[$item][] = "{$who}: in {$child->classroom} (set by hand); by age {$auto}. Held — --also=age-others to move.";
                }

                continue;
            }

            // Back to "by age": the hand-set room is cleared, so the room
            // follows their age from here on (ClassroomAssignment).
            $this->change($item, 'children', $row, 'classroom_override', $child->classroom_override, null, "{$fit['text']}");
            $this->change($item, 'children', $row, 'classroom', $child->classroom, $auto, "Room by age: {$auto}.");
        }
    }

    /* ---- Data 4 (and the rest of the seeder's examples) ---- */

    private function seededAlerts(bool $alsoOthers): void
    {
        $item = 'D4 seeded alerts';
        $byText = [];

        foreach (DB::table('children')->whereNotNull('alerts')->orderBy('lan')->get() as $c) {
            $alerts = json_decode((string) $c->alerts, true) ?: [];
            $kept = [];
            $removed = [];

            foreach ($alerts as $alert) {
                $text = (string) ($alert['text'] ?? '');
                $seeded = isset(self::SEEDED_ALERTS[$text]) && ($alert['action'] ?? null) === self::SEEDED_ALERTS[$text];

                if ($seeded) {
                    $byText[$text][] = $c->lan.' (plan '.($alert['plan_on'] ?? '—').')';
                }

                if ($seeded && ($text === 'No pickup by Jordan Key' || $alsoOthers)) {
                    $removed[] = $text;
                } else {
                    $kept[] = $alert;
                }
            }

            if ($removed !== []) {
                $this->change($item, 'children', $c, 'alerts', $c->alerts, $kept === [] ? null : json_encode(array_values($kept)),
                    'Remove demo-seeder alert(s): '.implode('; ', $removed));
            }
        }

        foreach ($byText as $text => $lans) {
            $this->info[$item][] = count($lans)." × \"{$text}\" — the demo seeder's exact wording: ".implode(', ', $lans);
        }
        $this->info[$item][] = 'How they got there: ChildRecordDemoSeeder put its four example alerts on every 19th child ($i % 19). Their "plan on" dates (Aug 26 / Aug 28) are the seeder\'s "two months ago" for runs on Oct 26 and Oct 28 — the same two dates the records were last bulk-updated. No user, note or activity-log entry added them. The seeder no longer does this, and refuses to run in production.';
        if (! $alsoOthers) {
            $this->info[$item][] = 'Only "No pickup by Jordan Key" is removed by default. The seeder\'s Peanuts, Asthma and "Leaves early on Tuesdays" copies are the same kind of invention: --also=seeded-alerts removes them too.';
        }

        // A real restriction written where the alerts are not.
        foreach (DB::table('children')->where(fn ($q) => $q->where('other_notes', 'like', '%order of protection%')->orWhere('other_notes', 'like', '%no pick%up%')->orWhere('custody_note', 'like', '%order%'))->get() as $c) {
            $this->flags[$item][] = "LAN {$c->lan} {$c->first_name} {$c->last_name}: the notes say \"".Str::limit(trim((string) ($c->other_notes ?: $c->custody_note)), 90).'" — a real pickup restriction? It is not an alert, so no screen warns about it. Staff to confirm and add it as a court-order alert.';
        }
    }

    /* ---- Data 5: alert values ---- */

    private function alertValues(): void
    {
        $item = 'D5 alert values';
        $medical = [];
        $general = [];

        foreach (DB::table('children')->whereNotNull('alerts')->orderBy('lan')->get() as $c) {
            $alerts = json_decode((string) $c->alerts, true) ?: [];
            $fixed = false;

            foreach ($alerts as $i => $alert) {
                $text = trim((string) ($alert['text'] ?? ''));
                $type = (string) ($alert['type'] ?? 'note');
                $who = "LAN {$c->lan} {$c->first_name} {$c->last_name}";

                if (strcasecmp($text, 'Excema') === 0) {
                    $alerts[$i]['text'] = 'Eczema';
                    $fixed = true;
                    $this->flags[$item][] = "{$who}: \"Excema\" → \"Eczema\". It is filed as an allergy; eczema is a skin condition — staff to say whether it is an allergy or a medical alert.";
                }
                if (in_array(strtolower($text), ['unknown', 'seasonal'], true)) {
                    $this->flags[$item][] = "{$who}: allergy \"{$text}\" — what to? Staff to ask the family; not guessed.";
                }

                $line = "{$c->lan} {$c->first_name} {$c->last_name}: {$text}";
                if (in_array($type, ['allergy', 'medical', 'medication'], true)) {
                    $medical[] = "{$line} ({$type})";
                } else {
                    $general[] = "{$line} ({$type})";
                }
            }

            if ($fixed) {
                $current = $this->pending('children', $c->id, 'alerts') ?? $c->alerts;
                $decoded = json_decode((string) $current, true) ?: [];
                foreach ($decoded as $i => $a) {
                    if (strcasecmp(trim((string) ($a['text'] ?? '')), 'Excema') === 0) {
                        $decoded[$i]['text'] = 'Eczema';
                    }
                }
                $this->change($item, 'children', $c, 'alerts', $current, json_encode(array_values($decoded)), 'Spelling: Excema → Eczema');
            }
        }

        $this->info[$item][] = 'Medical (allergy / medical / medication) — '.count($medical).': '.implode(' · ', $medical);
        $this->info[$item][] = 'General notes / court orders — '.count($general).': '.implode(' · ', $general);
    }

    /* ---- Data 6: messages ---- */

    private function messages(bool $greetings): void
    {
        $item = 'D6 messages';

        foreach (DB::table('messages')->when(Schema::hasColumn('messages', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))->orderBy('id')->get() as $m) {
            $body = strtolower(trim((string) $m->body));
            $from = $m->direction === 'in' ? 'from '.(DB::table('people')->where('id', $m->person_id)->value('name') ?? 'a parent') : 'from staff user '.$m->user_id;
            $where = "message {$m->id} (thread {$m->thread_id}, {$m->channel}, {$from}, ".substr((string) ($m->sent_at ?? $m->created_at), 0, 16).')';

            if (in_array($body, ['test', 'zxczxcxz'], true)) {
                $this->change($item, 'messages', $m, 'archived_at', null, Carbon::now()->toDateTimeString(), "\"{$m->body}\" — no content.");
            } elseif ($body === 'hi') {
                if ($greetings) {
                    $this->change($item, 'messages', $m, 'archived_at', null, Carbon::now()->toDateTimeString(), "\"{$m->body}\" — a bare greeting.");
                } else {
                    $this->flags[$item][] = "\"{$m->body}\" {$where}: a bare greeting — archive? (--also=greetings)";
                }
            } elseif (in_array($body, ['hello', 'hello!', 'hi update', 'ths is noted'], true)) {
                $this->flags[$item][] = "\"{$m->body}\" {$where}: looks like testing but reads as a message — your call; not changed.";
            }
        }
    }

    /* ---- Data 6: staff ---- */

    private function staff(bool $bethNames): void
    {
        $item = 'D6 staff';
        $placeholder = '/^(Infant|Transition|Toddler|PreK|UPK-4|School Age) Teacher$/';

        foreach (DB::table('users')->orderBy('id')->get() as $u) {
            $use = [
                'punches' => DB::table('time_punches')->where('user_id', $u->id)->count(),
                'shifts' => DB::table('staff_shifts')->where('user_id', $u->id)->count(),
                'logins' => DB::table('login_events')->where('user_id', $u->id)->where('outcome', 'success')->count(),
            ];

            if (preg_match($placeholder, (string) $u->name)) {
                $used = array_sum($use) > 0;
                $this->flags[$item][] = "\"{$u->name}\" (user {$u->id}, {$u->email}): {$use['punches']} punches, {$use['shifts']} shifts, {$use['logins']} logins — ".($used ? 'has history, so NOT set inactive. Your decision.' : 'no history.');
                if (! $used && $u->employment !== 'INACTIVE') {
                    $this->change($item, 'users', $u, 'employment', $u->employment, 'INACTIVE', 'Placeholder account with no punches, shifts or logins.');
                }
            }

            // Lower-case display names: capitalised. The login is the email, which is not touched.
            $name = (string) $u->name;
            if ($name !== '' && $name === mb_strtolower($name) && ! str_contains($name, '@')) {
                $this->change($item, 'users', $u, 'name', $name, Str::title($name), 'Display name capitalised; login ('.$u->email.') unchanged.');
            }

            // LEAD was never a contract type: StaffRule::LEGACY_EMPLOYMENT reads it as FT.
            if ($u->employment === 'LEAD') {
                $this->change($item, 'users', $u, 'employment', 'LEAD', 'FT', 'LEAD is a legacy contract value that the screens read as FT (StaffRule::LEGACY_EMPLOYMENT); Staff attendance showed it as "Lead" while the Staff page shows the job, '.($u->job_role ?: '—').'.');
                $this->flags[$item][] = "\"{$name}\" (user {$u->id}): job \"{$u->job_role}\" but account role \"{$u->role}\" — a Director usually has the admin account. Phone on file is \"{$u->phone}\". Your decision; not changed.";
            }
        }

        // Two people, one name.
        foreach (DB::table('users')->get()->groupBy(fn ($u) => mb_strtolower(trim((string) $u->name)))->filter(fn ($g) => $g->count() > 1) as $group) {
            $same = $group->pluck('email')->unique()->count() === 1 || $group->pluck('phone')->filter()->unique()->count() === 1 && $group->pluck('start_date')->unique()->count() === 1;
            $this->flags[$item][] = '"'.$group->first()->name.'" × '.$group->count().': '.$group->map(fn ($u) => "user {$u->id} {$u->email} · {$u->phone} · started {$u->start_date} · {$u->classroom} · {$u->job_role}")->implode(' | ')
                .($same ? ' — looks like ONE person: merge proposed for your approval.' : ' — different email, phone, start date and room: two people. Badge initials are now BJ and BJ2 (code fix); a room in the display name is held: --also=beth-names.');

            if ($bethNames && ! $same) {
                foreach ($group as $u) {
                    if (filled($u->classroom)) {
                        $this->change($item, 'users', $u, 'name', $u->name, "{$u->name} ({$u->classroom})", 'Two staff share this name; the room tells them apart.');
                    }
                }
            }
        }
    }

    /* ---- the plan, the run, the undo ---- */

    private function change(string $item, string $table, object $row, string $field, mixed $old, mixed $new, ?string $note = null): void
    {
        if ($old === $new) {
            return;
        }

        $label = $table === 'children'
            ? "LAN {$row->lan} {$row->first_name} {$row->last_name}"
            : ($table === 'users' ? "user {$row->id} {$row->name}" : "{$table} {$row->id}");

        $this->changes[] = ['item' => $item, 'table' => $table, 'id' => (int) $row->id, 'label' => $label, 'field' => $field, 'old' => $old, 'new' => $new, 'note' => $note];
    }

    private function archiveChild(string $item, object $row, string $note): void
    {
        if ($row->status === Child::ARCHIVED) {
            return;
        }
        $this->change($item, 'children', $row, 'status_before_archive', $row->status_before_archive, $row->status, $note);
        $this->change($item, 'children', $row, 'status', $row->status, Child::ARCHIVED, null);
        $this->change($item, 'children', $row, 'archived_at', $row->archived_at, Carbon::now()->toDateTimeString(), null);
    }

    /** The new value a field is already down to change to in this run, if any. */
    private function pending(string $table, int $id, string $field): mixed
    {
        foreach (array_reverse($this->changes, true) as $i => $c) {
            if ($c['table'] === $table && $c['id'] === $id && $c['field'] === $field) {
                unset($this->changes[$i]);
                $this->changes = array_values($this->changes);

                return $c['new'];
            }
        }

        return null;
    }

    private function report(bool $dry, array $also): void
    {
        $this->line(($dry ? 'DRY RUN — ' : '').'Data cleanup 2026-11 · '.$this->today.' · database '.DB::connection()->getDatabaseName()
            .($also === [] ? '' : ' · also: '.implode(', ', $also)));

        $this->newLine();
        $this->line('CHANGES ('.count($this->changes).' field'.(count($this->changes) === 1 ? '' : 's').')');
        foreach (collect($this->changes)->groupBy('item') as $item => $rows) {
            $this->line("== {$item}");
            foreach ($rows as $c) {
                $this->line(sprintf('  %s [%s #%d] %s: %s → %s%s', $c['label'], $c['table'], $c['id'], $c['field'], $this->show($c['old']), $this->show($c['new']), $c['note'] ? '   — '.$c['note'] : ''));
            }
        }

        foreach (['FOR STAFF / YOUR DECISION (not changed)' => $this->flags, 'INFORMATION' => $this->info] as $title => $sections) {
            $this->newLine();
            $this->line($title);
            foreach ($sections as $item => $lines) {
                $this->line("== {$item}");
                foreach ($lines as $line) {
                    $this->line('  - '.$line);
                }
            }
        }

        $this->newLine();
        $this->line('HELD (add with --also=…):');
        foreach (self::HELD as $key => $what) {
            $this->line("  {$key}: {$what}".(in_array($key, $also, true) ? '  [included]' : ''));
        }
    }

    private function show(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        $value = (string) $value;
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return '['.collect($decoded)->map(fn ($a) => is_array($a) ? ($a['text'] ?? json_encode($a)) : $a)->map(fn ($t) => '"'.$t.'"')->implode(', ').']';
        }

        return '"'.$value.'"';
    }

    private function apply(): int
    {
        if (! Schema::hasTable('data_cleanup_log') || ! Schema::hasColumn('messages', 'archived_at')) {
            $this->error('Run `php artisan migrate` first: data_cleanup_log and messages.archived_at are needed.');

            return self::FAILURE;
        }
        if ($this->changes === []) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        $batch = 'cleanup-2026-11-'.Carbon::now()->format('Ymd-His');
        $by = get_current_user() ?: 'shell';

        DB::transaction(function () use ($batch, $by) {
            foreach ($this->changes as $c) {
                $current = DB::table($c['table'])->where('id', $c['id'])->value($c['field']);
                if ($this->norm($current) !== $this->norm($c['old'])) {
                    throw new \RuntimeException("{$c['label']} {$c['field']} changed since the plan was made — nothing written. Run again.");
                }
                DB::table($c['table'])->where('id', $c['id'])->update([$c['field'] => $c['new']]);
                DB::table('data_cleanup_log')->insert([
                    'batch' => $batch, 'item' => $c['item'], 'table_name' => $c['table'], 'record_id' => $c['id'], 'field' => $c['field'],
                    'old_value' => $c['old'], 'new_value' => $c['new'], 'note' => $c['note'] ? Str::limit($c['note'], 495) : null,
                    'run_by' => $by, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->newLine();
        $this->info(count($this->changes)." change(s) written. Batch: {$batch}");
        $this->line("Undo with: php artisan data:cleanup-2026-11 --undo={$batch}");

        return self::SUCCESS;
    }

    private function undo(string $batch): int
    {
        if (! Schema::hasTable('data_cleanup_log')) {
            $this->error('No data_cleanup_log table.');

            return self::FAILURE;
        }

        $rows = DB::table('data_cleanup_log')->where('batch', $batch)->whereNull('undone_at')->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            $this->error("Nothing to undo for batch {$batch}.");

            return self::FAILURE;
        }

        $skipped = 0;
        DB::transaction(function () use ($rows, &$skipped) {
            foreach ($rows as $r) {
                $current = DB::table($r->table_name)->where('id', $r->record_id)->value($r->field);
                if ($this->norm($current) !== $this->norm($r->new_value)) {
                    $this->warn("Skipped {$r->table_name} #{$r->record_id} {$r->field}: changed since the cleanup.");
                    $skipped++;

                    continue;
                }
                DB::table($r->table_name)->where('id', $r->record_id)->update([$r->field => $r->old_value]);
                DB::table('data_cleanup_log')->where('id', $r->id)->update(['undone_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->info(($rows->count() - $skipped).' change(s) undone'.($skipped ? ", {$skipped} skipped" : '').'.');

        return $skipped ? self::FAILURE : self::SUCCESS;
    }

    /** Values as stored, compared loosely enough for a date read back with or without seconds. */
    private function norm(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = (string) $v;
        $json = json_decode($v, true);

        return is_array($json) ? json_encode($json) : $v;
    }
}
