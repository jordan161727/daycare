<?php

namespace App\Exports;

use App\Models\Child;
use App\Models\ChildPerson;
use App\Models\Person;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The roll, as a spreadsheet.
 *
 * The same rows the Children page shows, in the same order, under the same
 * headings — because the file is nearly always opened next to the screen it
 * came from, and a column that has been renamed or resorted on the way out is
 * one somebody has to reconcile by hand.
 *
 * That includes the filter: exporting while the roll is showing Active gives
 * the active children, not all of them. The alternative — a button that
 * quietly ignores the chip above it — is how a centre ends up sending a
 * funder a list of children who left last year.
 *
 * It also includes who is asking. A teacher's export holds their own rooms,
 * for the same reason their roster does; the visibility rule is applied by the
 * controller before the rows ever reach this class.
 */
class ChildrenSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * @param  Collection<int, Child>  $children
     * @param  string|null  $nameFormat  how the Student column reads, taken
     *                                   from the reader once rather than asked
     *                                   per row. Row mapping then depends on
     *                                   nothing ambient, so the same rows can
     *                                   be written from a console command or a
     *                                   queue — neither of which has a signed-in
     *                                   reader to ask.
     */
    public function __construct(private Collection $children, private ?string $nameFormat = null) {}

    public function collection(): Collection
    {
        return $this->children;
    }

    public function title(): string
    {
        return 'Children';
    }

    /**
     * The columns of the roster, left to right.
     *
     * Schedule is the one that splits. On screen it is two lines in one cell —
     * the hours, and the days under them — which reads well and sorts not at
     * all. In a spreadsheet they are two columns, because the first thing
     * anybody does with this file is sort or filter by one of them.
     */
    public function headings(): array
    {
        return [
            'LAN',
            'Student',
            'First name',
            'Last name',
            'Nickname',
            'Gender',
            'Classroom',
            // Blank unless a director departed from the age rule. Worth a
            // column of its own: without it, a child in a room their date of
            // birth does not explain looks like a mistake.
            'Room set by hand',
            'Date of birth',
            'Age',
            'Status',
            'Enrolled on',
            'Withdrawn on',
            'Hours',
            'Days',
            'Expected hours/week',
            // The household, which is also what an adult with no address of
            // their own falls back to.
            'Address',
            'Household phone',
            'Email',
            // The two numbers the state knows a subsidised child by.
            'DSS case no',
            'DSS CIN',
            'Parents status',
            'Responsible for payment',
            /*
             * The parents, flattened.
             *
             * One row per child with a Mom and a Dad column is the shape every
             * contact list the centre has ever kept is in, and it is what makes
             * this file usable in a mail merge or on a clipboard by the door.
             *
             * It is a lossy view and deliberately so: a child with two mothers,
             * or with a grandmother as the legal guardian, cannot be written in
             * two columns. Those are on the People sheet, which has a row for
             * every adult and loses nothing. This sheet is the summary; that
             * one is the record.
             */
            'Mother',
            'Mother phone',
            'Father',
            'Father phone',
            'Parent emails',
            // The three lists the child page shows, as text. Names only —
            // their numbers are on the People sheet.
            'Guardians',
            'Can pick up',
            'Emergency contacts',
            'Restrictions',
            'Alerts',
            'Notes',
            'Important notes',
            // How the family found the centre. Asked on the registration
            // form, so it travels with the rest of the record.
            'Where advertised',
            'Who referred',
            // Whether a scanned enrollment has been read by a person yet.
            'Import status',
        ];
    }

    /** @param  Child  $child */
    public function map($child): array
    {
        return [
            $child->lan,
            $child->displayName($this->nameFormat),
            // Split as well as joined: the displayed name follows whatever name
            // format the reader has chosen, and a mail merge needs the parts.
            $child->first_name,
            $child->last_name,
            $child->nickname,
            $child->gender,
            $child->classroom,
            $child->classroom_override,
            // The date itself rather than the formatted label, so Excel sorts
            // it as a date and the reader can format it however they like.
            $child->birth_date?->format('Y-m-d') ?? $child->dob?->format('Y-m-d'),
            $child->ageInWords(),
            $child->status,
            $child->enrolled_on?->format('Y-m-d'),
            $child->withdrawn_on?->format('Y-m-d'),
            $child->scheduleLabel(),
            $child->scheduleDaysLabel(),
            $child->expected_hours_per_week,
            trim(collect([$child->address, $child->city, $child->zip])->filter()->implode(', ')),
            $child->telephone,
            $child->email_address,
            $child->dss_case_no,
            $child->dss_cin,
            $child->parents_status,
            $child->responsible_for_payment,

            $this->parent($child, 'Mother')?->name,
            $this->phoneOf($this->parent($child, 'Mother')),
            $this->parent($child, 'Father')?->name,
            $this->phoneOf($this->parent($child, 'Father')),
            // Whatever addresses the centre actually writes to, in one cell.
            $this->links($child)
                ->map(fn (ChildPerson $link) => $link->person?->email)
                ->filter()
                ->unique()
                ->implode("\n"),

            $this->names($child, fn (ChildPerson $link) => $link->is_guardian),
            // The same rule the door is meant to use: a restriction beats the
            // tick, so somebody under a court order is not on this list however
            // the box was left.
            $this->names($child, fn (ChildPerson $link) => $link->can_pickup && blank($link->restriction)),
            // In the order they are rung, with the number that says so.
            $this->links($child)
                ->filter(fn (ChildPerson $link) => $link->is_emergency)
                ->sortBy(fn (ChildPerson $link) => $link->priority ?? 99)
                ->map(fn (ChildPerson $link) => ($link->priority ? $link->priority.'. ' : '').$link->person?->name)
                ->implode("\n"),
            // Never silently dropped: a court order is the one thing on this
            // sheet somebody has to read before handing a child over.
            $this->links($child)
                ->filter(fn (ChildPerson $link) => filled($link->restriction))
                ->map(fn (ChildPerson $link) => $link->person?->name.': '.$link->restriction)
                ->implode("\n"),

            // One per line inside the cell, the way the column stacks them on
            // screen. A child with three allergies is one row either way.
            collect($child->alertList())
                ->map(fn (array $alert) => $alert['label'].': '.$alert['text'])
                ->implode("\n"),
            $child->other_notes,
            $child->important_notes,
            $child->where_advertised,
            $child->who_referred,
            $child->import_status,
        ];
    }

    /** This child's links, from whatever was eager-loaded onto them. */
    private function links(Child $child): Collection
    {
        return $child->personLinks instanceof Collection
            ? $child->personLinks
            : collect($child->personLinks);
    }

    /**
     * The one person in this relationship, or nobody.
     *
     * First rather than all of them: two people could be recorded as Mother,
     * and a column that quietly joined them would read as one person with a
     * strange name. The People sheet has both.
     */
    private function parent(Child $child, string $relationship): ?Person
    {
        return $this->links($child)
            ->first(fn (ChildPerson $link) => $link->relationship === $relationship)?->person;
    }

    /** The number to ring first: the mobile, or whatever else is on file. */
    private function phoneOf(?Person $person): ?string
    {
        return $person?->cell ?: $person?->home_phone ?: $person?->work_phone;
    }

    /** @param  callable(ChildPerson): bool  $wanted */
    private function names(Child $child, callable $wanted): string
    {
        return $this->links($child)
            ->filter($wanted)
            ->map(fn (ChildPerson $link) => $link->person?->name)
            ->filter()
            ->implode("\n");
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = 'AJ';
        $lastRow = $this->children->count() + 1;

        // The alerts column holds newlines, which are nothing without this.
        $sheet->getStyle('AB2:AI'.max($lastRow, 2))->getAlignment()->setWrapText(true);

        // Frozen under the headings: a roll of seventy-five is read by
        // scrolling, and a row whose column names have gone off the top is a
        // row of values nobody can name.
        $sheet->freezePane('A2');

        // Filter buttons on the headings, because the first thing anybody does
        // with a roll is narrow it to one room.
        $sheet->setAutoFilter('A1:'.$lastColumn.'1');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
