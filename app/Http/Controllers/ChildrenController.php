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
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ], [
            'file.required' => 'Please select a file.',
            'file.mimes' => 'Only Excel (.xlsx, .xls) or CSV files are allowed.',
            'file.max' => 'The file must not be larger than 5 MB.',
        ]);

        $import = new ChildrenImport;

        try {
            DB::transaction(fn () => Excel::import($import, $request->file('file')));
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'file' => 'The file could not be imported. Check that it is a valid Excel or CSV file.',
            ]);
        }

        $message = "Import complete: {$import->created} created and {$import->updated} updated.";

        if ($import->skipped > 0) {
            $message .= " {$import->skipped} row(s) skipped.";
        }

        if ($import->errors !== []) {
            $message .= ' '.count($import->errors).' row(s) could not be read — see below.';
        }

        return back()
            ->with('success', $message)
            ->with('import_errors', $import->errors);
    }

    public function showImport()
    {
        return view('children.import');
    }
}
