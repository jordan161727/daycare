<?php

namespace App\Http\Controllers;

use App\Models\Child;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ChildDocumentController extends Controller
{
    /** Session key holding the pending imports for this user. */
    private const SESSION_KEY = 'child_imports';

    public function create() { return view('children.document-import'); }

    public function store(Request $request)
    {
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240']]);
        if (blank(config('services.gemini.key'))) return back()->withErrors(['document' => 'Set GEMINI_API_KEY in .env before importing documents.']);

        $fields = ['child_name','first_name','last_name','nickname','address','city','zip','telephone','birth_date','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number','pickup_1_name','pickup_1_address','pickup_1_telephone','pickup_1_alternate','pickup_1_relationship','pickup_1_license_number','pickup_2_name','pickup_2_address','pickup_2_telephone','pickup_2_alternate','pickup_2_relationship','pickup_2_license_number','pickup_3_name','pickup_3_address','pickup_3_telephone','pickup_3_alternate','pickup_3_relationship','pickup_3_license_number','other_notes','important_notes','enrolled_on'];
        $file = $request->file('document');
        try {
            $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])->post('https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent', [
                'contents' => [['parts' => [
                    ['text' => 'Extract this daycare enrollment form as JSON. Return only these keys; use empty strings for missing values and YYYY-MM-DD for birth_date and enrolled_on: '.implode(', ', $fields).'. On the form, mother_ssn and father_ssn are the fields labelled "Social Security #" (also written "Social Security Number", "SS#" or "SSN") in the mother and father sections; copy the digits as written, keeping any dashes. enrolled_on is the field labelled simply "Date" on the second page, directly below the line "Authorized parent signature" in the block that begins "The above information is accurate to the best of my knowledge"; it is the date the parent signed and dated the form, and it is the enrollment date. Read it from there even though the label says only "Date". Ignore the "Start Date" and other boxes in the "FOR OFFICE USE ONLY" section, and do not use the birth date.'],
                    ['inline_data' => ['mime_type' => $file->getMimeType(), 'data' => base64_encode(file_get_contents($file->getRealPath()))]],
                ]]],
                'generationConfig' => ['responseMimeType' => 'application/json'],
            ])->throw();
            $data = json_decode(data_get($response->json(), 'candidates.0.content.parts.0.text'), true, 512, JSON_THROW_ON_ERROR);
            $data = array_intersect_key($data, array_flip($fields));
            // Both dates come back however they were written on the form; the
            // <input type="date"> on the review screen only accepts Y-m-d.
            foreach (['birth_date', 'enrolled_on'] as $dateField) {
                if (blank($data[$dateField] ?? null)) continue;
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'F j Y', 'F j, Y', 'M j Y', 'M j, Y', 'j F Y', 'j M Y'] as $format) {
                    try { $data[$dateField] = Carbon::createFromFormat($format, trim($data[$dateField]))->format('Y-m-d'); break; } catch (\Throwable) { }
                }
                if ($data[$dateField] !== date('Y-m-d', strtotime($data[$dateField]))) {
                    try { $data[$dateField] = Carbon::parse(trim($data[$dateField]))->format('Y-m-d'); } catch (\Throwable) { }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Child document import failed.', ['message' => $exception->getMessage()]);
            return back()->withErrors(['document' => 'The document could not be read. Check the Gemini API configuration and try again.']);
        }
        // Keep the document around so it can be shown next to the extracted fields.
        $token = (string) Str::uuid();
        $extension = $file->extension() ?: $file->getClientOriginalExtension();
        $path = $file->storeAs('child-imports', $token.'.'.$extension);

        session()->put(self::SESSION_KEY.'.'.$token, [
            'fields' => $data,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'name' => $file->getClientOriginalName(),
        ]);

        return redirect()->route('children.document-import.review', $token);
    }

    /** Side-by-side check: extracted details on the left, the source document on the right. */
    public function review(string $token)
    {
        $import = $this->pendingImport($token);

        return view('children.document-review', [
            'token' => $token,
            'extracted' => $import['fields'],
            'documentName' => $import['name'],
            'isPdf' => str_contains($import['mime'], 'pdf'),
            'nextLan' => Child::nextLan(),
        ]);
    }

    /** Streams the uploaded document inline so the review page can embed it. */
    public function file(string $token)
    {
        $import = $this->pendingImport($token);

        return Storage::response($import['path'], $import['name'], ['Content-Type' => $import['mime']]);
    }

    /** Drops a pending import and its stored file once the child record is saved. */
    public static function discard(?string $token): void
    {
        if (blank($token)) return;

        $import = session()->pull(self::SESSION_KEY.'.'.$token);

        if (filled($import['path'] ?? null)) {
            Storage::delete($import['path']);
        }
    }

    private function pendingImport(string $token): array
    {
        $import = session(self::SESSION_KEY.'.'.$token);

        abort_if(blank($import) || ! Storage::exists($import['path']), 404);

        return $import;
    }
}
