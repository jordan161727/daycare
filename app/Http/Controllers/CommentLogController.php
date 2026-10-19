<?php

namespace App\Http\Controllers;

use App\Models\AttendanceComment;
use App\Models\Child;
use App\Models\User;
use App\Services\ClassroomAssignment;
use App\Services\CommentDays;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The comments log: every note of a day, or a span of days, across every
 * child — the back of the whole paper sheet read at once.
 *
 * Opened on today. A preset chooses the day (yesterday, the last school
 * day, this week) or a range is typed; the room, the kind of note, who
 * wrote it and a word in it narrow the list. The same filters feed the
 * export, as a CSV or a PDF.
 */
class CommentLogController extends Controller
{
    public const PRESETS = ['today', 'yesterday', 'last-school-day', 'week', 'custom'];

    public function index(Request $request)
    {
        Gate::authorize('read', AttendanceComment::class);

        $filters = $this->filters($request);
        $comments = $this->query($filters)->get();

        return view('comments.index', [
            'filters' => $filters,
            'comments' => $comments,
            'children' => $comments->pluck('child_id')->unique()->count(),
            'rooms' => ClassroomAssignment::rooms(),
            'types' => AttendanceComment::TYPES,
            // Whoever has written a note, so the box offers real names only.
            'authors' => User::whereIn('id', AttendanceComment::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'presets' => $this->presetLabels(),
            'when' => $this->whenLabel($filters),
            'today' => today()->toDateString(),
            // The avatars for the dialog, one a child, rendered once here.
            'avatars' => $comments->pluck('child')->unique('id')->filter()->mapWithKeys(fn (Child $child) => [
                $child->id => preg_replace('/>\s+</', '><', trim(view('components.child-avatar', ['child' => $child, 'size' => 'h-24 w-24'])->render())),
            ]),
        ]);
    }

    /** The same list as a file: a CSV, or a PDF a page at a time. */
    public function export(Request $request)
    {
        Gate::authorize('read', AttendanceComment::class);

        $filters = $this->filters($request);
        $comments = $this->query($filters)->get();
        $stem = 'comments-'.$filters['from'].($filters['to'] !== $filters['from'] ? '-to-'.$filters['to'] : '');

        if ($request->input('format') === 'pdf') {
            return $this->pdf($comments, $filters, $stem.'.pdf');
        }

        return $this->csv($comments, $stem.'.csv');
    }

    /**
     * The filters, read off the address and made whole: a preset becomes a
     * from and a to, so the list and the export read the same days.
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'preset' => ['nullable', Rule::in(self::PRESETS)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'class' => ['nullable', 'string', 'max:40'],
            'type' => ['nullable', Rule::in(array_keys(AttendanceComment::TYPES))],
            'author' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $today = today();
        $preset = $data['preset'] ?? null;

        // A bare ?date= is the log of that one day — the link the door's
        // panel and the dialog send. From and to are a typed range.
        if (! $preset) {
            $preset = match (true) {
                filled($data['date'] ?? null) && $data['date'] !== $today->toDateString() => 'custom',
                filled($data['from'] ?? null) || filled($data['to'] ?? null) => 'custom',
                default => 'today',
            };
        }

        [$from, $to] = match ($preset) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last-school-day' => [CommentDays::lastSchoolDay($today), CommentDays::lastSchoolDay($today)],
            'week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->endOfWeek(Carbon::SUNDAY)],
            'custom' => [
                Carbon::parse($data['from'] ?? $data['date'] ?? $today->toDateString()),
                Carbon::parse($data['to'] ?? $data['date'] ?? $data['from'] ?? $today->toDateString()),
            ],
            default => [$today, $today],
        };

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'preset' => $preset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'class' => $data['class'] ?? '',
            'type' => $data['type'] ?? '',
            'author' => (int) ($data['author'] ?? 0),
            'q' => trim((string) ($data['q'] ?? '')),
        ];
    }

    private function query(array $filters): Builder
    {
        return AttendanceComment::query()
            ->with(['author', 'child'])
            ->whereNotNull('date')
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->when($filters['class'] !== '', fn (Builder $q) => $q->whereHas('child', fn (Builder $c) => $c->where('classroom', $filters['class'])))
            ->ofType($filters['type'] ?: null)
            ->when($filters['author'] > 0, fn (Builder $q) => $q->where('user_id', $filters['author']))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $q->where(fn (Builder $w) => $w
                    ->where('body', 'like', $term)
                    ->orWhereHas('child', fn (Builder $c) => $c
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhereRaw(self::fullNameSql().' like ?', [$term])));
            })
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** The child's whole name in SQL, in the words the live database and the test one each use. */
    private static function fullNameSql(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? "CONCAT(first_name, ' ', last_name)"
            : "(first_name || ' ' || last_name)";
    }

    private function presetlabels(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'last-school-day' => 'Last school day',
            'week' => 'This week',
            'custom' => 'Custom range…',
        ];
    }

    /** "Friday, Oct 16", or "Oct 12 – Oct 16" for a span. */
    private function whenLabel(array $filters): string
    {
        $from = Carbon::parse($filters['from']);
        $to = Carbon::parse($filters['to']);

        return $filters['from'] === $filters['to']
            ? $from->format('l, M j')
            : $from->format('M j').' – '.$to->format('M j'.($from->year !== $to->year ? ', Y' : ''));
    }

    private function csv($comments, string $name)
    {
        $timezone = config('app.timezone');

        return response()->streamDownload(function () use ($comments, $timezone) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Time', 'Child', 'LAN', 'Class', 'Type', 'Comment', 'Written by', 'Role', 'Edited']);

            foreach ($comments as $comment) {
                fputcsv($out, [
                    $comment->date?->toDateString(),
                    $comment->created_at->timezone($timezone)->format('g:i A'),
                    $comment->child?->displayName() ?? '',
                    $comment->child?->lan ?? '',
                    $comment->child?->classroom ?? '',
                    $comment->typeLabel(),
                    $comment->body,
                    $comment->author?->name ?? 'Staff',
                    AttendanceComment::roleLabel($comment->author_role ?? $comment->author?->role),
                    $comment->wasEdited() ? 'yes' : '',
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The list on paper: one row a comment, wrapped, with the day and the
     * filters along the top. FPDF writes Latin-1, so the words are
     * transliterated on the way — a curly quote becomes a straight one.
     */
    private function pdf($comments, array $filters, string $name)
    {
        $timezone = config('app.timezone');
        $latin = fn (?string $text) => (string) @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string) $text);

        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetMargins(12, 12, 12);
        $pdf->AddPage();

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(0, 8, $latin('Comments · '.$this->whenLabel($filters)), 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        $narrow = array_filter([
            $filters['class'] !== '' ? 'Class: '.$filters['class'] : null,
            $filters['type'] !== '' ? 'Type: '.AttendanceComment::TYPES[$filters['type']] : null,
            $filters['q'] !== '' ? 'Search: "'.$filters['q'].'"' : null,
        ]);
        $pdf->Cell(0, 5, $latin($comments->count().' comment'.($comments->count() === 1 ? '' : 's').' · '.$comments->pluck('child_id')->unique()->count().' children'.($narrow ? ' · '.implode(' · ', $narrow) : '')), 0, 1);
        $pdf->Ln(3);

        $widths = [24, 16, 46, 22, 20, 100, 45];
        $heads = ['Date', 'Time', 'Child', 'Class', 'Type', 'Comment', 'By'];
        $header = function () use ($pdf, $widths, $heads) {
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetFillColor(242, 244, 249);
            foreach ($heads as $i => $head) {
                $pdf->Cell($widths[$i], 7, $head, 1, 0, 'L', true);
            }
            $pdf->Ln();
            $pdf->SetFont('Helvetica', '', 8.5);
        };
        $header();

        foreach ($comments as $comment) {
            $cells = [
                $comment->date?->format('D, M j') ?? '',
                $comment->created_at->timezone($timezone)->format('g:i A'),
                $comment->child?->displayName() ?? '',
                $comment->child?->classroom ?? '',
                $comment->typeLabel(),
                $comment->body,
                ($comment->author?->name ?? 'Staff').' · '.AttendanceComment::roleLabel($comment->author_role ?? $comment->author?->role),
            ];

            // The tallest cell sets the row; the comment is the one that wraps.
            $lines = max(1, (int) ceil($pdf->GetStringWidth($latin($comment->body)) / ($widths[5] - 2)) + substr_count($comment->body, "\n"));
            $height = 5 * $lines;

            if ($pdf->GetY() + $height > $pdf->GetPageHeight() - 14) {
                $pdf->AddPage();
                $header();
            }

            $x = $pdf->GetX();
            $y = $pdf->GetY();
            foreach ($cells as $i => $text) {
                $pdf->SetXY($x, $y);
                if ($i === 5) {
                    $pdf->MultiCell($widths[$i], 5, $latin($text), 1, 'L');
                } else {
                    $pdf->Cell($widths[$i], $height, $latin($text), 1, 0, 'L');
                }
                $x += $widths[$i];
            }
            $pdf->SetXY(12, $y + $height);
        }

        return response($pdf->Output('S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
