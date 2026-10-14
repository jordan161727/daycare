<?php

namespace App\Http\Controllers;

use App\Imports\ChildrenImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ChildrenController extends Controller
{
    /**
     * The spreadsheet the importer expects, with nothing in it.
     *
     * Every column it understands, in the order a registration form asks for
     * them, and one example row so the shape of a date or a list of days is
     * obvious rather than described. A centre fills this in from whatever they
     * already keep and hands it back.
     *
     * CSV rather than xlsx: it opens in Excel, Numbers, Sheets and Notepad,
     * and it cannot carry a formula or a macro that would have to be explained
     * away later.
     */
    public function importTemplate()
    {
        $columns = ChildrenImport::templateHeadings();

        $example = [
            'lan' => '10001',
            'first_name' => 'Maeve',
            'last_name' => 'Adkins',
            'dob' => '2022-04-18',
            'gender' => 'F',
            'description' => 'blonde long hair',
            'classroom' => 'PreK',
            'status' => 'Active',
            'enrolled_on' => '2026-01-06',
            'drop_off_time' => '07:30',
            'pick_up_time' => '17:30',
            'schedule_days' => 'Mon,Tue,Wed,Thu,Fri',
            'mother_name' => 'Anna Adkins',
            'mother_cell' => '555 0101',
            'pickup_1_name' => 'Anna Adkins',
            'pickup_1_relationship' => 'Mother',
            'alerts' => 'Peanut allergy',
        ];

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $columns);
        fputcsv($handle, array_map(fn (string $column) => $example[$column] ?? '', $columns));

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="children-import-template.csv"',
        ]);
    }
    /**
     * Import one spreadsheet, or several at once.
     *
     * A centre's roll arrives as a file per room, or a file per month, and
     * uploading them one at a time is six round trips for one job. So the
     * form takes as many as are dropped on it and this runs them in turn,
     * each in its own transaction: a sheet that cannot be read is reported
     * by name and the others still go in. The totals are the sum, and every
     * row error says which file it came from once there is more than one.
     *
     * `file` (one) is still accepted beside `files[]` (many), so anything
     * that posts the old way keeps working.
     */
    public function import(Request $request)
    {
        $request->validate([
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'file' => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ], [
            'files.max' => 'Up to 20 files at a time.',
            'files.*.mimes' => 'Only Excel (.xlsx, .xls) or CSV files are allowed.',
            'files.*.max' => 'Each file must not be larger than 5 MB.',
            'file.mimes' => 'Only Excel (.xlsx, .xls) or CSV files are allowed.',
            'file.max' => 'The file must not be larger than 5 MB.',
        ]);

        $files = array_values(array_filter(array_merge(
            (array) $request->file('files', []),
            [$request->file('file')],
        )));

        if ($files === []) {
            return back()->withErrors(['file' => 'Please select a file.']);
        }

        $created = $updated = $skipped = 0;
        $errors = [];
        $unread = [];
        $several = count($files) > 1;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $import = new ChildrenImport;

            try {
                DB::transaction(fn () => Excel::import($import, $file));
            } catch (\Throwable $exception) {
                report($exception);

                /*
                 * The reason, not a shrug.
                 *
                 * "Check that it is a valid Excel file" is a dead end when the
                 * file plainly is one. What actually goes wrong is a title row
                 * above the headings, or merged cells, or a sheet whose first row
                 * is blank — and each of those is a different thing to fix, so the
                 * message has to tell them apart.
                 */
                $unread[] = "{$name} could not be read: ".\Illuminate\Support\Str::limit($exception->getMessage(), 200)
                    .' — the first row of the sheet must be the column headings, with no title or blank rows above it.';

                continue;
            }

            $created += $import->created;
            $updated += $import->updated;
            $skipped += $import->skipped;

            foreach ($import->errors as $error) {
                $errors[] = $several ? "{$name} — {$error}" : $error;
            }
        }

        // Every file failed: that is an error, not an import that did nothing.
        if ($unread !== [] && count($unread) === count($files)) {
            return back()->withErrors(['file' => count($unread) === 1 ? $unread[0] : implode(' ', $unread)]);
        }

        $message = ($several ? count($files) - count($unread).' of '.count($files).' files imported: ' : 'Import complete: ')
            ."{$created} created and {$updated} updated.";

        if ($skipped > 0) {
            $message .= " {$skipped} row(s) skipped.";
        }

        if ($errors !== [] || $unread !== []) {
            $message .= ' '.count($errors).' row(s) could not be read'.($unread !== [] ? ', and '.count($unread).' file(s) not at all' : '').' — see below.';
        }

        return back()
            ->with('success', $message)
            ->with('import_errors', array_merge($unread, $errors));
    }

    public function showImport()
    {
        return view('children.import');
    }
}
