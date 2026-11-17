<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Data a tester found that the office has to clean by hand (16 Nov 2026).
 *
 * Five lists, strictly read-only: every read is the query builder (or a static
 * User helper that only reads), nothing is saved, nothing fires an event that
 * writes. Safe to run on the live server at any hour.
 *
 *  1. Possible duplicate children (same name and date of birth; same name,
 *     different date of birth).
 *  2. The same alert / note / restriction / custody text on children of three
 *     or more different families — likely pasted onto the wrong records.
 *  3. Alert entries that say nothing ("! Unknown", "N/A", "-") or carry a
 *     common misspelling. The suggestion is printed, never applied.
 *  4. Test and placeholder records: test messages, placeholder staff names,
 *     all-lowercase names, duplicate staff names, initials that are not the
 *     name's own.
 *  5. Active children with no date of birth, no guardian, or no emergency
 *     contact.
 *
 * It never prints phone numbers, SSNs, PINs or addresses: free text is
 * printed with any long run of digits replaced by "[number]".
 *
 * The older children:data-report (rooms vs age, conflicting phones,
 * placeholder nicknames, phone formats) is left as it is; run both.
 */
class DataQualityReport extends Command
{
    protected $signature = 'data:quality-report';

    protected $description = 'Read-only report: duplicate children, repeated alert/note texts, junk or misspelled alerts, test records and placeholder staff, Active children missing DOB/guardian/emergency contact';

    /** Alert words that say nothing, compared after trimming "!", "." etc. and lower-casing. */
    public const PLACEHOLDER_TEXTS = [
        '', 'unknown', 'unkown', 'seasonal', 'n/a', 'na', 'n.a', 'none', 'nil', 'null', 'no', 'yes',
        'tbd', 'tba', 'test', 'x', 'xx', 'xxx', '?', '??', '-', '--', '...', 'other', 'see above', 'same',
    ];

    /** Common misspellings, matched as whole words, with what was probably meant. */
    public const MISSPELLINGS = [
        '/\b(excema|exzema|eczma|ecxema|ezcema|exema|eczmea)\b/i' => 'eczema',
        '/\b(asthama|athsma|asmtha|astma|ashma|azthma|asthmah|asma)\b/i' => 'asthma',
        '/\b(allergie\(s\)|allergie|alergie|alergy|allergey|allergys|alergies|allegies|allergis|allery|alergic|allergik)\b/i' => 'allergy / allergies / allergic',
        '/\b(epi[\s-]+pen|eppi[\s-]*pen|epipin|epi-pin)\b/i' => 'EpiPen',
        '/\b(inhailer|inhealer|inhaller|inheler)\b/i' => 'inhaler',
        '/\b(penut|peanutt|pnut)s?\b/i' => 'peanut',
        '/\b(diabetis|diabeties|diabetic\'s)\b/i' => 'diabetes',
        '/\b(siezures?|seizers?|sezures?)\b/i' => 'seizure',
        '/\b(intollerant|intolerent|intollerance)\b/i' => 'intolerant / intolerance',
        '/\b(benadril|benedryl|bendryl)\b/i' => 'Benadryl',
        '/\b(penicilin|pennicillin|penecillin)\b/i' => 'penicillin',
        '/\b(amoxicilin|amoxycillin|amoxacillin)\b/i' => 'amoxicillin',
        '/\b(medicaton|medecine|medicene|medcine)\b/i' => 'medication / medicine',
        '/\b(lactos|lactoce)\b/i' => 'lactose',
    ];

    /** Message bodies that are a test, compared trimmed and lower-cased. */
    public const TEST_MESSAGES = ['hi', 'test', 'testing', 'test test', 'test message', 'hello test', 'asdf', 'qwerty'];

    public function handle(): int
    {
        $today = Carbon::today();

        $this->info('Data quality report — '.$today->format('D j M Y').' (read-only, nothing is changed)');
        $this->newLine();

        $children = DB::table('children')->orderBy('id')->get()->keyBy('id');
        $links = DB::table('child_people')->orderBy('id')->get();

        $this->duplicateChildren($children, $links);
        $this->repeatedTexts($children, $links);
        $this->junkAlerts($children);
        $this->testRecords($children);
        $this->activeGaps($children, $links);

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------
     * 1. Possible duplicate children
     * --------------------------------------------------------------- */

    private function duplicateChildren(Collection $children, Collection $links): void
    {
        $this->heading('1. Possible duplicate children');

        $attendanceDays = $this->attendanceDays();
        $linkCounts = $links->groupBy('child_id')->map->count();

        $named = $children->filter(fn ($c) => self::nameKey($this->childName($c)) !== '');
        $byName = $named->groupBy(fn ($c) => self::nameKey($this->childName($c)));

        $row = fn ($c) => [
            $c->lan,
            $this->childName($c),
            $this->dob($c) ?? '—',
            $c->status,
            $c->classroom ?: '—',
            $this->hours($c),
            $c->created_at ? substr((string) $c->created_at, 0, 10) : '—',
            $attendanceDays[$c->id] ?? 0,
            $linkCounts[$c->id] ?? 0,
        ];
        $headers = ['LAN', 'Child', 'DOB', 'Status', 'Room', 'Hours', 'Created', 'Attendance days', 'People links'];

        // a) Same name, same date of birth.
        $rows = [];
        $groups = 0;

        foreach ($byName->sortKeys() as $group) {
            foreach ($group->groupBy(fn ($c) => $this->dob($c) ?? 'none') as $dob => $same) {
                if ($dob === 'none' || $same->count() < 2) {
                    continue;
                }

                $groups++;

                if ($rows !== []) {
                    $rows[] = array_fill(0, count($headers), '');
                }

                foreach ($same->sortBy('lan') as $c) {
                    $rows[] = $row($c);
                }
            }
        }

        $this->line('  a) Same first and last name AND the same date of birth — most likely the same child entered twice:');
        $this->table($headers, $rows);
        $this->line("  {$groups} group(s). Keep the one with attendance and people links; the others are probably extra copies.");
        $this->newLine();

        // b) Same name, different (or missing) date of birth.
        $rows = [];
        $groups = 0;

        foreach ($byName->sortKeys() as $group) {
            if ($group->count() < 2 || $group->map(fn ($c) => $this->dob($c) ?? 'none')->unique()->count() < 2) {
                continue;
            }

            $groups++;

            if ($rows !== []) {
                $rows[] = array_fill(0, count($headers), '');
            }

            foreach ($group->sortBy('lan') as $c) {
                $rows[] = $row($c);
            }
        }

        $this->line('  b) Same name, different date of birth — weaker: could be a typo in the date, or two children:');
        $this->table($headers, $rows);
        $this->line("  {$groups} name(s).");
        $this->newLine();
    }

    /** @return array<int, int> distinct days per child, from any attendance table there is. */
    private function attendanceDays(): array
    {
        $days = [];

        foreach (['attendances', 'door_attendances'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'attendance_date')) {
                continue;
            }

            foreach (DB::table($table)->select('child_id', 'attendance_date')->get() as $r) {
                $days[$r->child_id][substr((string) $r->attendance_date, 0, 10)] = true;
            }
        }

        return array_map('count', $days);
    }

    private function hours(object $c): string
    {
        $in = $c->drop_off_time ?? null;
        $out = $c->pick_up_time ?? null;

        if (blank($in) && blank($out)) {
            return '—';
        }

        return substr((string) $in, 0, 5).'–'.substr((string) $out, 0, 5);
    }

    /** Lower-cased with every space removed: "Jace  Miller" and "jace miller" are one key. */
    public static function nameKey(?string $name): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim((string) $name))) ?? '';
    }

    /* ---------------------------------------------------------------
     * 2. The same text on unrelated children
     * --------------------------------------------------------------- */

    private function repeatedTexts(Collection $children, Collection $links): void
    {
        $this->heading('2. The same alert / note / restriction text on children of 3+ different families');

        $family = $this->families($children, $links);
        $entries = collect();
        $add = function (int $childId, string $where, ?string $text) use ($entries, $children) {
            $key = self::textKey($text);

            if ($key !== '' && isset($children[$childId])) {
                $entries->push(['key' => $key, 'text' => trim((string) $text), 'child' => $childId, 'where' => $where]);
            }
        };

        foreach ($children as $c) {
            foreach ($this->alerts($c) as $alert) {
                $add($c->id, 'alert', $alert['text']);
            }

            $add($c->id, 'custody note', $c->custody_note ?? null);
            $add($c->id, 'important notes', $c->important_notes ?? null);
        }

        if (Schema::hasTable('child_notes')) {
            foreach (DB::table('child_notes')->select('child_id', 'body')->get() as $note) {
                $add($note->child_id, 'child note', $note->body);
            }
        }

        foreach ($links as $link) {
            $add($link->child_id, 'restriction', $link->restriction);
        }

        $rows = $entries->groupBy('key')
            ->map(function (Collection $group) use ($family, $children) {
                $childIds = $group->pluck('child')->unique();
                $families = $childIds->map(fn ($id) => $family[$id] ?? $id)->unique()->count();

                return [
                    'families' => $families,
                    'row' => [
                        self::safeText($group->first()['text']),
                        $group->countBy('where')->map(fn ($n, $w) => "{$w} ×{$n}")->implode(', '),
                        $childIds->count(),
                        $families,
                        $childIds->map(fn ($id) => $children[$id]->lan)->sort()->values()->implode(', '),
                    ],
                ];
            })
            ->filter(fn (array $g) => $g['families'] >= 3)
            ->sortByDesc('families')
            ->pluck('row')
            ->values();

        $this->table(['Text', 'Found as', 'Children', 'Families', 'LANs'], $rows->all());
        $this->line('  '.$rows->count().' text(s). Brothers and sisters (children sharing a guardian) count as one family.');
        $this->line('  A common medical line ("Asthma") can be right on many records; a name ("No pickup by …") rarely is.');
        $this->newLine();
    }

    /**
     * Children joined into families: any two that share a guardian person are
     * siblings, and so on transitively. Returns child id => family id.
     *
     * @return array<int, int>
     */
    private function families(Collection $children, Collection $links): array
    {
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        foreach ($children as $c) {
            $find($c->id);
        }

        foreach ($links->where('is_guardian', true)->groupBy('person_id') as $byPerson) {
            $ids = $byPerson->pluck('child_id')->unique()->values();

            foreach ($ids->slice(1) as $id) {
                $parent[$find($id)] = $find($ids[0]);
            }
        }

        $out = [];

        foreach (array_keys($parent) as $id) {
            $out[$id] = $find($id);
        }

        return $out;
    }

    /** Lower-cased, spaces collapsed, punctuation trimmed from both ends. */
    public static function textKey(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text, " \t\n\r\0\x0B!.,;:-*?\"'");
    }

    /* ---------------------------------------------------------------
     * 3. Useless or misspelled alerts
     * --------------------------------------------------------------- */

    private function junkAlerts(Collection $children): void
    {
        $this->heading('3. Alert entries that say nothing, or are misspelled');

        $placeholders = [];
        $misspelt = [];

        foreach ($children as $c) {
            foreach ($this->alerts($c) as $alert) {
                if (self::isPlaceholder($alert['text'])) {
                    $placeholders[] = [$c->lan, $this->childName($c), $c->status, $alert['type'], '"'.self::safeText($alert['text']).'"'];
                }

                foreach (['text', 'reaction', 'action'] as $field) {
                    foreach (self::misspellings($alert[$field] ?? null) as $found => $suggestion) {
                        $misspelt[] = [$c->lan, $this->childName($c), $c->status, $field, '"'.self::safeText($alert[$field]).'"', $found, $suggestion];
                    }
                }
            }
        }

        $this->line('  a) Placeholders — the alert has no real content (blank, "! Unknown", "N/A", "-", "?" …):');
        $this->table(['LAN', 'Child', 'Status', 'Type', 'Alert text'], $placeholders);
        $this->line('  '.count($placeholders).' alert(s). Replace with the real fact or remove.');
        $this->newLine();

        $this->line('  b) Likely misspellings (suggestion only — nothing is changed):');
        $this->table(['LAN', 'Child', 'Status', 'Field', 'Text', 'Word', 'Probably'], $misspelt);
        $this->line('  '.count($misspelt).' word(s).');
        $this->newLine();
    }

    public static function isPlaceholder(?string $text): bool
    {
        return in_array(self::textKey($text), self::PLACEHOLDER_TEXTS, true)
            || preg_match('/\pL|\d/u', (string) $text) !== 1;
    }

    /** @return array<string, string> the misspelt word as written => the suggestion */
    public static function misspellings(?string $text): array
    {
        $out = [];

        foreach (self::MISSPELLINGS as $pattern => $suggestion) {
            if (preg_match_all($pattern, (string) $text, $m)) {
                foreach ($m[0] as $word) {
                    $out[$word] = $suggestion;
                }
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------
     * 4. Test and placeholder records
     * --------------------------------------------------------------- */

    private function testRecords(Collection $children): void
    {
        $this->heading('4. Test and placeholder records');

        // a) Messages.
        $rows = [];

        if (Schema::hasTable('messages')) {
            $threads = DB::table('message_threads')->select('id', 'kind', 'child_id')->get()->keyBy('id');
            $staff = DB::table('users')->pluck('name', 'id');

            foreach (DB::table('messages')->when(Schema::hasColumn('messages', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))->select('id', 'thread_id', 'direction', 'body', 'user_id', 'sent_at', 'created_at')->orderBy('id')->get() as $m) {
                $why = self::testMessageReason($m->body);

                if ($why === null) {
                    continue;
                }

                $thread = $threads[$m->thread_id] ?? null;
                $child = $thread && $thread->child_id ? ($children[$thread->child_id] ?? null) : null;

                $rows[] = [
                    $m->id,
                    $m->thread_id.($thread ? " ({$thread->kind})" : ''),
                    $child ? $child->lan.' '.$this->childName($child) : '—',
                    $m->direction,
                    $m->user_id ? ($staff[$m->user_id] ?? "user #{$m->user_id}") : '—',
                    substr((string) ($m->sent_at ?? $m->created_at), 0, 16),
                    '"'.self::safeText($m->body).'"',
                    $why,
                ];
            }
        }

        $this->line('  a) Messages that look like a test ("Hi", "Test", keyboard mash):');
        $this->table(['Message', 'Thread', 'Child', 'Dir', 'Sent by', 'Sent', 'Body', 'Why'], $rows);
        $this->line('  '.count($rows).' message(s). An incoming "Hi" from a parent may be real — check before deleting.');
        $this->newLine();

        // b) Staff names.
        $users = DB::table('users')->whereIn('role', ['admin', 'teacher'])->select('id', 'name', 'role', 'created_at')->orderBy('id')->get();
        $rows = [];

        foreach ($users as $u) {
            foreach (self::staffNameProblems((string) $u->name) as $problem) {
                $rows[] = [$u->id, $u->name, $u->role, substr((string) $u->created_at, 0, 10), $problem];
            }
        }

        foreach ($users->groupBy(fn ($u) => self::nameKey($u->name))->filter(fn ($g, $k) => $k !== '' && $g->count() > 1) as $same) {
            foreach ($same as $u) {
                $others = $same->where('id', '<>', $u->id)->pluck('id')->map(fn ($id) => "#{$id}")->implode(', ');
                $rows[] = [$u->id, $u->name, $u->role, substr((string) $u->created_at, 0, 10), "duplicate name (also {$others})"];
            }
        }

        // The badge letters are worked out across the whole team so no two
        // are alike (User::staffInitials); a clash pushes a later account to
        // its next option, e.g. the second "Beth Jones" becomes "BJ2".
        $shown = User::staffInitials(fresh: true);
        $natural = [];

        foreach ($users as $u) {
            $natural[$u->id] = User::initialsOptions((string) $u->name)[0];
        }

        foreach ($users as $u) {
            $display = $shown[$u->id] ?? null;

            if ($display === null || $display === $natural[$u->id]) {
                continue;
            }

            $clash = $users->filter(fn ($o) => $o->id !== $u->id && $natural[$o->id] === $natural[$u->id])
                ->map(fn ($o) => "{$o->name} #{$o->id}")->implode(', ');

            $rows[] = [$u->id, $u->name, $u->role, substr((string) $u->created_at, 0, 10),
                "badge shows \"{$display}\", not \"{$natural[$u->id]}\"".($clash !== '' ? " (taken by {$clash})" : ''),
            ];
        }

        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        $this->line('  b) Staff accounts: placeholder names, all-lowercase names, duplicates, badge initials not their own:');
        $this->table(['User', 'Name', 'Role', 'Created', 'Problem'], $rows);
        $this->line('  '.count($rows).' problem(s).');
        $this->newLine();
    }

    /** Why a message body looks like a test, or null when it does not. */
    public static function testMessageReason(?string $body): ?string
    {
        $text = mb_strtolower(trim((string) $body));
        $bare = trim($text, " \t\n\r\0\x0B!.?,");

        if (in_array($bare, self::TEST_MESSAGES, true)) {
            return 'test word';
        }

        return self::isKeyboardMash($bare) ? 'keyboard mash' : null;
    }

    /**
     * One "word" of six or more letters with no vowel ("zxczxcxz") or made of
     * a two- or three-letter chunk said over and over ("asdasdasd").
     */
    public static function isKeyboardMash(?string $text): bool
    {
        $text = mb_strtolower(trim((string) $text));

        if (! preg_match('/^[a-z]{6,}$/', $text)) {
            return false;
        }

        return ! preg_match('/[aeiou]/', $text)
            || (bool) preg_match('/^([a-z]{2,3})\1+[a-z]{0,2}$/', $text);
    }

    /** @return list<string> */
    public static function staffNameProblems(string $name): array
    {
        $trimmed = trim($name);
        $problems = [];

        if ($trimmed === '') {
            return ['no name'];
        }

        if (preg_match('/^test\b|\btest$|^(teacher|staff|admin|user|demo|sample)$|\b(teacher|staff)$/i', $trimmed)) {
            $problems[] = 'placeholder name';
        }

        if (preg_match('/\pL/u', $trimmed) && $trimmed === mb_strtolower($trimmed)) {
            $problems[] = 'all lowercase';
        }

        return $problems;
    }

    /* ---------------------------------------------------------------
     * 5. Active children missing basics
     * --------------------------------------------------------------- */

    private function activeGaps(Collection $children, Collection $links): void
    {
        $this->heading('5. Active children missing a date of birth, a guardian, or an emergency contact');

        $byChild = $links->groupBy('child_id');
        $rows = [];
        $counts = ['dob' => 0, 'guardian' => 0, 'emergency' => 0];

        foreach ($children->where('status', 'Active')->sortBy('lan') as $c) {
            $mine = ($byChild[$c->id] ?? collect())->filter(fn ($l) => blank($l->restriction));
            $missing = [];

            if ($this->dob($c) === null) {
                $missing[] = 'date of birth';
                $counts['dob']++;
            }

            if (! $mine->contains(fn ($l) => (bool) $l->is_guardian)) {
                $missing[] = 'guardian';
                $counts['guardian']++;
            }

            if (! $mine->contains(fn ($l) => (bool) $l->is_emergency)) {
                $missing[] = 'emergency contact';
                $counts['emergency']++;
            }

            if ($missing !== []) {
                $rows[] = [$c->lan, $this->childName($c), $c->classroom ?: '—', ($byChild[$c->id] ?? collect())->count(), implode(', ', $missing)];
            }
        }

        $this->table(['LAN', 'Child', 'Room', 'People links', 'Missing'], $rows);
        $this->line("  {$counts['dob']} with no date of birth, {$counts['guardian']} with no guardian, {$counts['emergency']} with no emergency contact.");
        $this->line('  A person with a restriction on the link (a court order) does not count as guardian or emergency contact.');
        $this->newLine();
    }

    /* --------------------------------------------------------------- */

    /**
     * The stored alerts as rows of strings; a bare string entry is a text.
     *
     * @return list<array{type: string, text: string, reaction: ?string, action: ?string}>
     */
    private function alerts(object $child): array
    {
        $alerts = json_decode((string) ($child->alerts ?? ''), true);

        if (! is_array($alerts)) {
            return [];
        }

        $out = [];

        foreach ($alerts as $alert) {
            if (is_string($alert)) {
                $alert = ['text' => $alert];
            }

            if (! is_array($alert)) {
                continue;
            }

            $out[] = [
                'type' => (string) ($alert['type'] ?? 'note'),
                'text' => is_scalar($alert['text'] ?? null) ? (string) $alert['text'] : '',
                'reaction' => is_scalar($alert['reaction'] ?? null) ? (string) $alert['reaction'] : null,
                'action' => is_scalar($alert['action'] ?? null) ? (string) $alert['action'] : null,
            ];
        }

        return $out;
    }

    private function dob(object $c): ?string
    {
        $dob = filled($c->birth_date ?? null) ? $c->birth_date : ($c->dob ?? null);

        return filled($dob) ? substr((string) $dob, 0, 10) : null;
    }

    /**
     * Free text as it may be printed: one line, at most 70 characters, and
     * any run of seven or more digits (a phone, an SSN) blanked.
     */
    public static function safeText(?string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $text)) ?? '';
        $text = preg_replace_callback(
            '/[+(]*\d[\d\s().\/-]*\d/',
            fn (array $m) => strlen(preg_replace('/\D/', '', $m[0])) >= 7 ? '[number]' : $m[0],
            $text,
        ) ?? '';

        return Str::limit($text, 70);
    }

    private function heading(string $text): void
    {
        $this->line('<options=bold>'.$text.'</>');
    }

    private function childName(object $c): string
    {
        $name = trim(($c->first_name ?? '').' '.($c->last_name ?? ''));

        return $name !== '' ? $name : trim((string) ($c->child_name ?? ''));
    }
}
