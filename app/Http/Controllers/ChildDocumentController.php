<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChildDocumentController extends Controller
{
    public function create() { return view('children.document-import'); }

    public function store(Request $request)
    {
        $request->validate(['document' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240']]);
        if (blank(config('services.gemini.key'))) return back()->withErrors(['document' => 'Set GEMINI_API_KEY in .env before importing documents.']);

        $fields = ['child_name','first_name','last_name','nickname','address','city','zip','telephone','birth_date','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number','pickup_1_name','pickup_1_address','pickup_1_telephone','pickup_1_alternate','pickup_1_relationship','pickup_1_license_number','pickup_2_name','pickup_2_address','pickup_2_telephone','pickup_2_alternate','pickup_2_relationship','pickup_2_license_number','pickup_3_name','pickup_3_address','pickup_3_telephone','pickup_3_alternate','pickup_3_relationship','pickup_3_license_number','other_notes','important_notes'];
        $file = $request->file('document');
        try {
            $response = Http::timeout(90)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])->post('https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent', [
                'contents' => [['parts' => [
                    ['text' => 'Extract this daycare enrollment form as JSON. Return only these keys; use empty strings for missing values and YYYY-MM-DD for birth_date: '.implode(', ', $fields)],
                    ['inline_data' => ['mime_type' => $file->getMimeType(), 'data' => base64_encode(file_get_contents($file->getRealPath()))]],
                ]]],
                'generationConfig' => ['responseMimeType' => 'application/json'],
            ])->throw();
            $data = json_decode(data_get($response->json(), 'candidates.0.content.parts.0.text'), true, 512, JSON_THROW_ON_ERROR);
            $data = array_intersect_key($data, array_flip($fields));
            if (filled($data['birth_date'] ?? null)) {
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'F j Y', 'F j, Y', 'M j Y', 'M j, Y', 'j F Y', 'j M Y'] as $format) {
                    try { $data['birth_date'] = Carbon::createFromFormat($format, trim($data['birth_date']))->format('Y-m-d'); break; } catch (\Throwable) { }
                }
                if ($data['birth_date'] !== date('Y-m-d', strtotime($data['birth_date']))) {
                    try { $data['birth_date'] = Carbon::parse(trim($data['birth_date']))->format('Y-m-d'); } catch (\Throwable) { }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Child document import failed.', ['message' => $exception->getMessage()]);
            return back()->withErrors(['document' => 'The document could not be read. Check the Gemini API configuration and try again.']);
        }
        return redirect()->route('children.create')->with('extracted_child', $data)->with('success', 'Review the extracted information, then save the child record.');
    }
}
