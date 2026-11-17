<?php

namespace App\Console\Commands;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The data the office has to tidy by hand (QA #41, 12 Nov 2026).
 *
 * Four lists, all read-only — nothing here writes, so it is safe to run on
 * the live server at any hour:
 *
 *  1. Children whose age does not fit the room they are in, by the app's own
 *     age bands (ClassroomAssignment::automaticFor). A director's override is
 *     a decision, not a mistake, but it is still listed and marked so.
 *  2. Guardian mobiles that disagree: one name with two numbers, across the
 *     people records and the old mother/father columns.
 *  3. Nicknames that are a placeholder rather than a name ("N/A", "-", …).
 *  4. The shapes the mobile numbers are written in, counted.
 */
class ChildrenDataReport extends Command
{
    protected $signature = 'children:data-report';

    protected $description = 'Read-only report: children in the wrong room for their age, conflicting guardian phones, placeholder nicknames, phone formats';

    /** Nicknames that mean "none", compared lower-cased and trimmed. */
    private const PLACEHOLDER_NICKNAMES = ['n/a', 'na', 'none', '-', ''];

    public function handle(): int
    {
        $today = Carbon::today();

        $this->info('Children data report — '.$today->format('D j M Y').' (read-only, nothing is changed)');
        $this->newLine();

        $this->roomsByAge($today);
        $this->conflictingPhones();
        $this->placeholderNicknames();
        $this->phoneFormats();

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------
     * 1. Age vs room
     * --------------------------------------------------------------- */

    private function roomsByAge(Carbon $today): void
    {
        $this->heading('1. Children whose age does not fit their room (Active and Pending)');

        $rows = Child::query()
            ->whereIn('status', ['Active', 'Pending'])
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Child $child) use ($today) {
                $dob = $child->birthDate();
                $byAge = $child->automaticClassroom($today);
                $room = $child->classroom;
                $override = $child->hasClassroomOverrideOn($today);

                if ($room !== null && $room === $byAge) {
                    return null;
                }

                $note = match (true) {
                    $dob === null => 'no date of birth',
                    $byAge === null => 'age outside every room band',
                    $override && $child->classroomOverrideIsStale($today) => 'override (age has passed it)',
                    $override => 'override',
                    blank($room) => 'no room',
                    default => 'room not updated (classrooms:sync)',
                };

                return [
                    $child->lan,
                    $this->childName($child),
                    $child->status,
                    $dob ? $this->age($dob, $today) : '—',
                    $room ?: '—',
                    $byAge ?: '—',
                    $note,
                ];
            })
            ->filter()
            ->values();

        $this->table(['LAN', 'Child', 'Status', 'Age', 'Room', 'Room by age', 'Note'], $rows->all());
        $this->line('  '.$rows->count().' child(ren). "override" means a director placed them by hand on purpose.');
        $this->newLine();
    }

    private function age(Carbon $dob, Carbon $today): string
    {
        if ($dob->gt($today)) {
            return 'not born yet';
        }

        $diff = $dob->copy()->startOfDay()->diff($today);

        return $diff->y.'y '.$diff->m.'m';
    }

    /* ---------------------------------------------------------------
     * 2. Conflicting phones
     * --------------------------------------------------------------- */

    private function conflictingPhones(): void
    {
        $this->heading('2. Conflicting guardian mobiles');

        $children = Child::query()->where('status', '<>', 'Archived')->get()->keyBy('id');
        $people = Person::query()->get()->keyBy('id');
        $links = ChildPerson::query()->get();
        $lansByPerson = $links->groupBy('person_id')
            ->map(fn (Collection $l) => $l->map(fn (ChildPerson $link) => $children[$link->child_id]->lan ?? null)->filter()->unique()->sort()->values());

        // Every mobile on file with the name it belongs to, wherever it lives.
        $entries = collect();

        foreach ($people as $person) {
            if (filled($person->cell) && filled(Person::normalizeName($person->name))) {
                $lans = ($lansByPerson[$person->id] ?? collect())->implode(', ');
                $entries->push([
                    'key' => Person::normalizeName($person->name),
                    'name' => $person->name,
                    'digits' => Person::digits($person->cell),
                    'raw' => $person->cell,
                    'where' => "person #{$person->id}".($lans !== '' ? " (LAN {$lans})" : ''),
                ]);
            }
        }

        foreach ($children as $child) {
            foreach (['mother', 'father'] as $who) {
                $name = $child->{$who.'_name'};
                $cell = $child->{$who.'_cell'};

                if (filled($cell) && filled(Person::normalizeName($name))) {
                    $entries->push([
                        'key' => Person::normalizeName($name),
                        'name' => $name,
                        'digits' => Person::digits($cell),
                        'raw' => $cell,
                        'where' => "LAN {$child->lan} {$who}_cell",
                    ]);
                }
            }
        }

        $rows = [];
        $names = 0;

        foreach ($entries->groupBy('key')->sortKeys() as $group) {
            if ($group->pluck('digits')->unique()->count() < 2) {
                continue;
            }

            $names++;

            foreach ($group->groupBy('digits') as $same) {
                $rows[] = [
                    $same->first()['name'],
                    $same->pluck('raw')->unique()->implode(' / '),
                    $same->pluck('where')->unique()->implode('; '),
                ];
            }
        }

        $this->line('  a) The same name with different numbers (a leading US 1 counts as the same number):');
        $this->table(['Name', 'Mobile as written', 'Found on'], $rows);
        $this->line("  {$names} name(s) with more than one number.");
        $this->newLine();

        // The old columns against the person they were linked to.
        $rows = [];
        $linksByChild = $links->groupBy('child_id');

        foreach ($children as $child) {
            foreach (['mother' => 'Mother', 'father' => 'Father'] as $who => $relationship) {
                $cell = $child->{$who.'_cell'};

                if (blank($cell)) {
                    continue;
                }

                $person = $this->linkedPerson($child, $who, $relationship, $linksByChild[$child->id] ?? collect(), $people);

                if ($person && filled($person->cell) && Person::digits($person->cell) !== Person::digits($cell)) {
                    $rows[] = [
                        $child->lan,
                        $this->childName($child),
                        $relationship,
                        $person->name,
                        $cell,
                        $person->cell,
                    ];
                }
            }
        }

        $this->line('  b) The old mother/father mobile on the child record against the linked person:');
        $this->table(['LAN', 'Child', 'Block', 'Person', 'Old column', 'Person record'], $rows);
        $this->line('  '.count($rows).' disagreement(s).');
        $this->newLine();
    }

    /**
     * The person a mother/father block was turned into: the linked person of
     * the same name, else the one linked as Mother/Father.
     */
    private function linkedPerson(Child $child, string $who, string $relationship, Collection $links, Collection $people): ?Person
    {
        $linked = $links->map(fn (ChildPerson $link) => ['link' => $link, 'person' => $people[$link->person_id] ?? null])
            ->filter(fn (array $l) => $l['person'] !== null);
        $name = Person::normalizeName($child->{$who.'_name'});

        if ($name !== '') {
            $match = $linked->first(fn (array $l) => Person::normalizeName($l['person']->name) === $name);

            if ($match) {
                return $match['person'];
            }
        }

        $byRelationship = $linked->filter(fn (array $l) => strcasecmp(trim((string) $l['link']->relationship), $relationship) === 0);

        return $byRelationship->count() === 1 ? $byRelationship->first()['person'] : null;
    }

    /* ---------------------------------------------------------------
     * 3. Placeholder nicknames
     * --------------------------------------------------------------- */

    private function placeholderNicknames(): void
    {
        $this->heading('3. Placeholder nicknames');

        $rows = Child::query()
            ->whereNotNull('nickname')
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            // The stored text, not the accessor: Child now reads "N/A" back as
            // no nickname (QA #20), and this table is about what is stored.
            ->filter(fn (Child $child) => in_array(mb_strtolower(trim((string) $child->getRawOriginal('nickname'))), self::PLACEHOLDER_NICKNAMES, true))
            ->map(fn (Child $child) => [$child->lan, $this->childName($child), $child->status, '"'.$child->getRawOriginal('nickname').'"'])
            ->values();

        $this->table(['LAN', 'Child', 'Status', 'Nickname'], $rows->all());
        $this->line('  '.$rows->count().' child(ren). These should be cleared so screens show the real name.');
        $this->newLine();
    }

    /* ---------------------------------------------------------------
     * 4. Phone formats
     * --------------------------------------------------------------- */

    private function phoneFormats(): void
    {
        $this->heading('4. How mobile numbers are written');

        $cells = collect()
            ->merge(Person::query()->whereNotNull('cell')->pluck('cell')->map(fn ($c) => ['source' => 'people.cell', 'cell' => $c]))
            ->merge(Child::query()->where('status', '<>', 'Archived')->whereNotNull('mother_cell')->pluck('mother_cell')->map(fn ($c) => ['source' => 'children.mother_cell', 'cell' => $c]))
            ->merge(Child::query()->where('status', '<>', 'Archived')->whereNotNull('father_cell')->pluck('father_cell')->map(fn ($c) => ['source' => 'children.father_cell', 'cell' => $c]))
            ->filter(fn (array $c) => trim((string) $c['cell']) !== '');

        $rows = $cells->groupBy(fn (array $c) => self::shape($c['cell']))
            ->map(fn (Collection $group, string $shape) => [
                $shape,
                $group->count(),
                $group->where('source', 'people.cell')->count(),
                $group->where('source', '<>', 'people.cell')->count(),
                $group->filter(fn (array $c) => Person::e164($c['cell']) === null)->count(),
            ])
            ->sortByDesc(fn (array $row) => $row[1])
            ->values();

        $this->table(['Format', 'Total', 'People records', 'Old child columns', 'Not a textable US number'], $rows->all());
        $this->line('  '.$rows->count().' format(s) in use. Texts are sent as +1 and ten digits whichever way a number is written;');
        $this->line('  only the last column (wrong length or an invalid area code) cannot be delivered.');
        $this->newLine();
    }

    /** The shape a number is written in, digits replaced by d. */
    public static function shape(?string $phone): string
    {
        $phone = trim((string) $phone);

        return match (true) {
            (bool) preg_match('/^\(\d{3}\) \d{3}-\d{4}$/', $phone) => '(ddd) ddd-dddd',
            (bool) preg_match('/^\d{3}-\d{3}-\d{4}$/', $phone) => 'ddd-ddd-dddd',
            (bool) preg_match('/^\+1\d{10}$/', $phone) => '+1dddddddddd',
            (bool) preg_match('/^\d{10}$/', $phone) => 'dddddddddd',
            default => 'other: '.preg_replace('/\d/', 'd', $phone),
        };
    }

    /* --------------------------------------------------------------- */

    private function heading(string $text): void
    {
        $this->line('<options=bold>'.$text.'</>');
    }

    private function childName(Child $child): string
    {
        return trim($child->first_name.' '.$child->last_name);
    }
}
