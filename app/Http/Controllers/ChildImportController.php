<?php

namespace App\Http\Controllers;
use App\Imports\ChildrenImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;

class ChildImportController extends Controller
{
     public function index()
    {
        return view('children.import');
    }

    public function store(Request $request)
{
    $request->validate([
        'file' => [
            'required',
            'file',
            'mimes:xlsx,xls,csv',
            'max:5120', // 5 MB
        ],
    ], [
        'file.required' => 'Please select a file.',
        'file.mimes' => 'Only Excel (.xlsx, .xls) or CSV files are allowed.',
        'file.max' => 'The file must not be larger than 5 MB.',
    ]);

    // Temporary success until Laravel Excel is installed
    return back()->with('success', 'File uploaded successfully! Import logic will be added next.');
}
    }

