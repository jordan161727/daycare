<?php

namespace App\Exports;

use App\Models\AttendanceComment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The month's comments as one list, after the room sheets.
 *
 * What was written on the back of the paper sheet has nowhere to go on the
 * grid, which is a column a day with no room for a sentence. So the workbook
 * carries it as its own sheet: a line a comment, with the day, the child,
 * who wrote it and when, oldest first — the back of the sheet, typed up.
 */
class MonthCommentsSheet implements FromArray, WithTitle, WithStyles, WithColumnWidths
{
    /** @param  Collection<int, AttendanceComment>  $comments  with attendance, child and author loaded */
    public function __construct(private Collection $comments) {}

    public function title(): string
    {
        return 'Comments';
    }

    public function array(): array
    {
        $timezone = config('app.timezone');
        $rows = [['Date', 'Child', 'Room', 'Session', 'Tag', 'Written by', 'Time', 'Comment']];

        foreach ($this->comments as $comment) {
            $rows[] = [
                $comment->attendance->attendance_date->format('Y-m-d'),
                trim($comment->child->last_name.', '.$comment->child->first_name),
                $comment->child->classroom ?: 'Unassigned',
                $comment->attendance->session === 'FULL' ? 'Day' : $comment->attendance->session,
                $comment->tag->label(),
                $comment->author?->name ?? 'Staff',
                $comment->created_at->timezone($timezone)->format('g:i A'),
                $comment->body,
            ];
        }

        if (count($rows) === 1) {
            $rows[] = ['No comments this month.', '', '', '', '', '', '', ''];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('H')->getAlignment()->setWrapText(true);

        return [1 => ['font' => ['bold' => true]]];
    }

    public function columnWidths(): array
    {
        return ['A' => 12, 'B' => 24, 'C' => 12, 'D' => 9, 'E' => 12, 'F' => 18, 'G' => 10, 'H' => 70];
    }
}
