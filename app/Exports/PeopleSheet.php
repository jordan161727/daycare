<?php

namespace App\Exports;

use App\Models\Child;
use App\Models\ChildPerson;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Every adult on the roll, one row per child they belong to.
 *
 * The Children sheet has a Mother column and a Father column, which is the
 * shape a contact list is usually kept in and the shape a mail merge wants.
 * It cannot hold a child with two mothers, or one whose legal guardian is a
 * grandmother, or the four people who may collect a child on different days —
 * so this sheet exists beside it and loses none of that.
 *
 * One row per link rather than per person: the ticks are per child, and a
 * grandmother who may collect one grandchild and not the other is two
 * different answers that have to be readable separately.
 *
 * The SSN is the last four only. It is what anybody checking an identity reads
 * off it, and a spreadsheet of whole ones is a spreadsheet that gets emailed.
 */
class PeopleSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    /** @param  Collection<int, Child>  $children */
    public function __construct(private Collection $children, private ?string $nameFormat = null) {}

    /**
     * Every link belonging to the children on the other sheet.
     *
     * Built from the same collection, so the two sheets always describe the
     * same roll: filter the page to Active and this holds the adults of the
     * active children and nobody else's.
     */
    public function collection(): Collection
    {
        return $this->children
            ->flatMap(fn (Child $child) => collect($child->personLinks)
                ->map(fn (ChildPerson $link) => [$child, $link]))
            ->sortBy([
                fn (array $row) => $row[0]->last_name ?? '',
                fn (array $row) => $row[0]->first_name ?? '',
                // Guardians first, then whoever may collect, then the rest —
                // the order the child's own page lists them in.
                fn (array $row) => $row[1]->is_guardian ? 0 : 1,
                fn (array $row) => $row[1]->can_pickup ? 0 : 1,
                fn (array $row) => $row[1]->priority ?? 99,
            ])
            ->values();
    }

    public function title(): string
    {
        return 'People';
    }

    public function headings(): array
    {
        return [
            'Child LAN',
            'Child',
            'Classroom',
            'Person',
            'Relationship',
            'Guardian',
            'Can pick up',
            'Emergency',
            'Call #',
            'Restriction',
            'Address',
            'Home phone',
            'Work phone',
            'Cell',
            'Alternate phone',
            'Email',
            'Employer',
            'Title',
            'Fax',
            'Driver licence',
            'SSN (last 4)',
        ];
    }

    /** @param  array{0: Child, 1: ChildPerson}  $row */
    public function map($row): array
    {
        [$child, $link] = $row;
        $person = $link->person;

        return [
            $child->lan,
            $child->displayName($this->nameFormat),
            $child->classroom,
            $person?->name,
            $link->relationship,
            // Words rather than TRUE/FALSE: the column is read by a person, and
            // a blank reads as "no" faster than the word does.
            $link->is_guardian ? 'Yes' : '',
            $link->can_pickup ? ($link->restriction ? 'Blocked' : 'Yes') : '',
            $link->is_emergency ? 'Yes' : '',
            $link->priority,
            $link->restriction,
            // Rule 5: an adult with no address of their own lives at the
            // child's household, resolved here rather than stored on them.
            $person?->address ?: trim(collect([$child->address, $child->city, $child->zip])->filter()->implode(', ')),
            $person?->home_phone,
            $person?->work_phone,
            $person?->cell,
            $person?->alternate_phone,
            $person?->email,
            $person?->employer,
            $person?->title,
            $person?->fax,
            $person?->drivers_license,
            $person?->ssnLast4(),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:U1');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
