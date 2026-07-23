<?php

namespace App\Http\Controllers;

use App\Imports\ChildrenImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ChildrenController extends Controller
{
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
            $message .= " {$import->skipped} inactive row(s) skipped.";
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
