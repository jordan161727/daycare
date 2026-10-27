<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Services\ClassroomAssignment;
use App\Services\DssAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The DSS attendance page — Phase 8.1 of docs/work.md: the county's month,
 * for the one person who reconciles it. Behind the see-dss gate, which the
 * routes carry; nothing here checks it again.
 */
class DssController extends Controller
{
    public function __construct(private DssAttendance $dss) {}

    public function index(Request $request)
    {
        [$year, $month] = $this->monthOf($request);
        $filter = $this->filter($request);

        return view('dss.index', [
            'data' => $this->dss->month($year, $month, $filter['rooms'], $filter['sub'], $filter['q']),
            'filter' => $filter,
            'viewer' => $request->user()->name,
        ]);
    }

    /** The grid as data, for the month and filter asked for. */
    public function grid(Request $request)
    {
        [$year, $month] = $this->monthOf($request);
        $filter = $this->filter($request);

        return response()->json($this->dss->month($year, $month, $filter['rooms'], $filter['sub'], $filter['q']));
    }

    /**
     * One or many cells changed at once: the whole selection, a paste, an
     * undo. Each lands on the child's attendance rows; the grid comes back
     * whole so the week totals are the server's, not the page's guess.
     */
    public function apply(Request $request)
    {
        [$year, $month] = $this->monthOf($request);
        $filter = $this->filter($request);

        $data = $request->validate([
            'ops' => ['required', 'array', 'min:1', 'max:800'],
            'ops.*.child_id' => ['required', 'integer'],
            'ops.*.date' => ['required', 'date_format:Y-m-d'],
            'ops.*.state' => ['required', 'array'],
            'ops.*.state.st' => ['required', Rule::in(['pres', 'abs', 'none', 'clear'])],
            'ops.*.state.segs' => ['nullable', 'array', 'max:2'],
            'ops.*.state.segs.*.in' => ['nullable', 'string', 'max:12'],
            'ops.*.state.segs.*.out' => ['nullable', 'string', 'max:12'],
        ]);

        $children = Child::whereIn('id', collect($data['ops'])->pluck('child_id')->unique())->get()->keyBy('id');

        try {
            foreach ($data['ops'] as $op) {
                $child = $children[$op['child_id']] ?? null;
                if (! $child) {
                    continue;
                }
                $this->dss->apply($child, $op['date'], $op['state'], $request->user());
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['saved' => true] + $this->dss->month($year, $month, $filter['rooms'], $filter['sub'], $filter['q']));
    }

    /** What the export would hold: the range, the checks, and the first rows. */
    public function preview(Request $request)
    {
        [$year, $month] = $this->monthOf($request);
        $filter = $this->filter($request);
        $export = $this->dss->export($year, $month, $filter['rooms'], $filter['sub'], $request->input('range') === 'week' ? 'week' : 'past', $request->boolean('abs', true));

        $lines = array_merge([$export['header']], array_slice($export['rows'], 0, 12));
        $preview = implode("\n", array_map(fn ($l) => implode(',', $l), $lines));
        if (count($export['rows']) > 12) {
            $preview .= "\n… ".(count($export['rows']) - 12).' more rows';
        }

        return response()->json([
            'range' => $export['range'],
            'checks' => $export['checks'],
            'preview' => $preview,
            'rows' => count($export['rows']),
        ]);
    }

    /** The file itself: .csv for KinderConnect, .json for a browser script. */
    public function export(Request $request)
    {
        [$year, $month] = $this->monthOf($request);
        $filter = $this->filter($request);
        $export = $this->dss->export($year, $month, $filter['rooms'], $filter['sub'], $request->input('range') === 'week' ? 'week' : 'past', $request->boolean('abs', true));
        $name = 'kinderconnect-attendance-'.sprintf('%04d-%02d', $year, $month);

        if ($request->input('format') === 'json') {
            return response()->json([
                'month' => sprintf('%04d-%02d', $year, $month),
                'from' => $export['from'],
                'to' => $export['to'],
                'classes' => $filter['rooms'] ?: 'All',
                'records' => $export['records'],
            ], 200, ['Content-Disposition' => 'attachment; filename="'.$name.'.json"'], JSON_PRETTY_PRINT);
        }

        return response(DssAttendance::csv($export['header'], $export['rows']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
        ]);
    }

    /** @return array{0: int, 1: int} */
    private function monthOf(Request $request): array
    {
        $month = (string) $request->input('month', today()->format('Y-m'));
        $at = preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::createFromFormat('Y-m', $month) : today();

        return [(int) $at->year, (int) $at->month];
    }

    /** @return array{rooms: list<string>, sub: bool, q: string} */
    private function filter(Request $request): array
    {
        $rooms = (array) $request->input('rooms', []);

        return [
            'rooms' => array_values(array_intersect(ClassroomAssignment::rooms(), $rooms)),
            'sub' => $request->has('sub') ? $request->boolean('sub') : true,
            'q' => trim((string) $request->input('q', '')),
        ];
    }
}
