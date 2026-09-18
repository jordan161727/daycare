<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The roll and everybody attached to it, in one workbook.
 *
 * Two sheets because there are two questions, and one table cannot answer
 * both.
 *
 * **Children** is a row per child with a Mother column and a Father column —
 * the shape a contact list has always been kept in, and the one a mail merge
 * or a clipboard by the door actually wants. It is a lossy summary: a child
 * with two mothers, or one whose guardian is a grandmother, does not fit in
 * two columns.
 *
 * **People** is a row per adult per child, with every field on the person and
 * every tick on the link. Nothing is flattened away, so it is the sheet to
 * read when the summary looks wrong.
 *
 * Both are built from the same collection of children, so they can never
 * describe different rolls — filter the page to Active and both sheets hold
 * the active children.
 */
class ChildrenExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @param  Collection<int, \App\Models\Child>  $children  with personLinks.person
     *                                                        already loaded
     * @param  string|null  $nameFormat  how names read, taken from the reader
     *                                   once rather than asked per row
     */
    public function __construct(private Collection $children, private ?string $nameFormat = null) {}

    /** The collection both sheets are built from, for tests and callers. */
    public function collection(): Collection
    {
        return $this->children;
    }

    public function sheets(): array
    {
        return [
            new ChildrenSheet($this->children, $this->nameFormat),
            new PeopleSheet($this->children, $this->nameFormat),
        ];
    }
}
