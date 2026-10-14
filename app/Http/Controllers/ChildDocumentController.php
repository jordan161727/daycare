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

    /** Session key holding the order the pending imports are reviewed in. */
    private const ORDER_KEY = 'child_imports_order';

    public function create()
    {
        // Whatever is still waiting from an earlier upload, by name: a pile
        // left half-reviewed used to sit unseen in the session and surface
        // after the next save as a form nobody remembered uploading.
        $waiting = collect(self::pendingTokens())->map(fn (string $token) => [
            'token' => $token,
            'name' => session(self::SESSION_KEY.'.'.$token.'.name'),
        ])->values()->all();

        return view('children.document-import', ['waiting' => $waiting]);
    }

    /** Drop every form still waiting — the pile was a mistake, or has been dealt with on paper. */
    public function clear()
    {
        $tokens = self::pendingTokens();
        foreach ($tokens as $token) {
            self::discard($token);
        }

        return redirect()->route('children.document-import.create')
            ->with('success', count($tokens).' waiting form'.(count($tokens) === 1 ? '' : 's').' discarded.');
    }

    /** The name of the next form waiting, for the messages that say what comes next. */
    public static function nextName(): ?string
    {
        $next = self::nextToken();

        return $next ? session(self::SESSION_KEY.'.'.$next.'.name') : null;
    }

    /**
     * One enrollment form, or a pile of them.
     *
     * Enrollment comes in batches — a new term is a dozen forms on the desk —
     * and reading them one upload at a time is a dozen trips through this
     * screen. So the form takes as many as are dropped on it. Each is read on
     * its own: a scan Gemini cannot make out is named and set aside, the rest
     * are queued for review in the order they were given, and the first opens
     * at once. Saving (or skipping) one review moves to the next.
     *
     * `document` (one) is still accepted beside `documents[]` (many).
     */
    public function store(Request $request)
    {
        $request->validate([
            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
            'document' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ], [
            'documents.max' => 'Up to 20 forms at a time.',
            'documents.*.mimes' => 'Only PDF, PNG, and JPG files are allowed.',
            'documents.*.max' => 'Each file must not be larger than 10 MB.',
        ]);
        if (blank(config('services.gemini.key'))) return back()->withErrors(['document' => 'Set GEMINI_API_KEY in .env before importing documents.']);

        $files = array_values(array_filter(array_merge((array) $request->file('documents', []), [$request->file('document')])));
        if ($files === []) return back()->withErrors(['document' => 'Choose an enrollment form first.']);

        /*
         * What the files are. A two-page form photographed on a phone is two
         * images of one child, and read apart the second page — allergies,
         * the pediatrician, the insurance — never reaches the record. So
         * several files are the pages of one form unless the uploader says
         * each is a form of its own. One file is one form either way.
         */
        $forms = $request->input('pages', 'one') === 'separate' || count($files) === 1
            ? array_map(fn ($file) => [$file], $files)
            : [$files];

        $queued = [];
        $unread = [];

        foreach ($forms as $pages) {
            $names = array_map(fn ($page) => $page->getClientOriginalName(), $pages);

            try {
                $data = $this->extract($pages);
            } catch (\Throwable $exception) {
                Log::warning('Child document import failed.', ['files' => $names, 'message' => $exception->getMessage()]);
                $unread[] = implode(' + ', $names);
                continue;
            }

            // Keep the pages around so they can be shown next to the extracted fields.
            $token = (string) Str::uuid();
            $stored = [];
            foreach ($pages as $index => $page) {
                $extension = $page->extension() ?: $page->getClientOriginalExtension();
                $stored[] = [
                    'path' => $page->storeAs('child-imports', $token.($index ? '-'.($index + 1) : '').'.'.$extension),
                    'mime' => $page->getClientMimeType(),
                    'name' => $page->getClientOriginalName(),
                ];
            }

            session()->put(self::SESSION_KEY.'.'.$token, [
                'fields' => $data,
                // The first page under the old keys, so nothing that reads them changes.
                'path' => $stored[0]['path'],
                'mime' => $stored[0]['mime'],
                'name' => count($stored) === 1 ? $stored[0]['name'] : $stored[0]['name'].' + '.(count($stored) - 1).' more page'.(count($stored) === 2 ? '' : 's'),
                'pages' => $stored,
            ]);
            $queued[] = $token;
        }

        if ($queued === []) {
            return back()->withErrors(['document' => count($forms) === 1
                ? 'The document could not be read. Check the Gemini API configuration and try again.'
                : 'None of the documents could be read ('.implode(', ', $unread).'). Check the Gemini API configuration and try again.']);
        }

        // The order they were given, after whatever is already waiting.
        session()->put(self::ORDER_KEY, array_values(array_unique(array_merge(session(self::ORDER_KEY, []), $queued))));

        return redirect()->route('children.document-import.review', $queued[0])
            ->with('unread_documents', $unread);
    }

    /**
     * Gemini reads the form — every page of it in one request — and the
     * fields come back keyed and dated for the review screen.
     *
     * The second page carries things the record has no field of its own
     * for: the known allergies, the pediatrician, the insurance, the
     * sunscreen and ointment brands, special needs. They used to be dropped
     * on the floor. Now they are asked for by name and folded into the two
     * notes — the allergies and anything medical under "Important notes",
     * where a relief teacher looks, and the rest under "Other notes" — so
     * nothing written on the form is lost between the scan and the record.
     *
     * @param  list<\Illuminate\Http\UploadedFile>  $pages
     */
    private function extract(array $pages): array
    {
        $fields = ['child_name','first_name','last_name','nickname','address','city','zip','telephone','birth_date','mother_name','mother_address','mother_home_phone','mother_employer','mother_work_phone','mother_fax','mother_cell','mother_email','mother_title','mother_ssn','father_name','father_address','father_home_phone','father_employer','father_work_phone','father_fax','father_cell','father_email','father_title','father_ssn','email_address','parents_status','responsible_for_payment','emergency_contact','secondary_emergency_contact','emergency_telephone','emergency_relationship','emergency_license_number','pickup_1_name','pickup_1_address','pickup_1_telephone','pickup_1_alternate','pickup_1_relationship','pickup_1_license_number','pickup_2_name','pickup_2_address','pickup_2_telephone','pickup_2_alternate','pickup_2_relationship','pickup_2_license_number','pickup_3_name','pickup_3_address','pickup_3_telephone','pickup_3_alternate','pickup_3_relationship','pickup_3_license_number','other_notes','important_notes','enrolled_on'];

        // Asked for by name, then folded into the notes below: the record has
        // no column for them, and a key the model is not asked for it does not fill.
        $extras = ['known_allergies', 'special_needs', 'pediatrician', 'pediatrician_address', 'pediatrician_telephone', 'dentist', 'dentist_telephone', 'insurance_carrier', 'insurance_membership', 'insurance_group', 'child_health_plus_info', 'emergency_treatment_consent', 'sunscreen_brand', 'diaper_ointment_brand'];

        $parts = [['text' => 'Extract this daycare enrollment form as JSON. '.(count($pages) > 1 ? 'The '.count($pages).' images are the pages of ONE form for ONE child, in order; read them together. ' : '').'Return only these keys; use empty strings for missing values and YYYY-MM-DD for birth_date and enrolled_on: '.implode(', ', array_merge($fields, $extras)).'. known_allergies is the "Known Allergies" line; special_needs is anything written under SPECIAL NEEDS; pediatrician, pediatrician_address and pediatrician_telephone are the MEDICAL INFORMATION pediatrician line, dentist and dentist_telephone the dentist line; insurance_carrier, insurance_membership and insurance_group are the CONSENT FOR EMERGENCY TREATMENT insurance line; child_health_plus_info is yes or no; emergency_treatment_consent is yes or no as initialled; sunscreen_brand and diaper_ointment_brand are the SUNSCREEN/DIAPER RASH OINTMENT PERMISSION brands. On the form, mother_ssn and father_ssn are the fields labelled "Social Security #" (also written "Social Security Number", "SS#" or "SSN") in the mother and father sections; copy the digits as written, keeping any dashes. enrolled_on is the field labelled simply "Date" on the second page, directly below the line "Authorized parent signature" in the block that begins "The above information is accurate to the best of my knowledge"; it is the date the parent signed and dated the form, and it is the enrollment date. Read it from there even though the label says only "Date". Ignore the "Start Date" and other boxes in the "FOR OFFICE USE ONLY" section, and do not use the birth date.']];
        foreach ($pages as $page) {
            $parts[] = ['inline_data' => ['mime_type' => $page->getMimeType(), 'data' => base64_encode(file_get_contents($page->getRealPath()))]];
        }

        $response = Http::timeout(120)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])->post('https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':generateContent', [
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ])->throw();
        $data = json_decode(data_get($response->json(), 'candidates.0.content.parts.0.text'), true, 512, JSON_THROW_ON_ERROR);
        $data = self::foldExtrasIntoNotes(array_intersect_key($data, array_flip(array_merge($fields, $extras))));
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

        return $data;
    }

    /**
     * The page-two details, written into the notes.
     *
     * Allergies and anything medical go under Important notes, which the
     * roll shows a relief teacher; the insurance and the brands go under
     * Other notes. Each is a labelled line, after whatever the model already
     * put in the note, and a blank is left out rather than written as
     * "Pediatrician: ".
     */
    public static function foldExtrasIntoNotes(array $data): array
    {
        $line = function (string $label, array $keys) use ($data): ?string {
            $values = array_values(array_filter(array_map(fn ($key) => trim((string) ($data[$key] ?? '')), $keys), fn ($v) => $v !== '' && strtolower($v) !== 'n/a'));

            return $values === [] ? null : $label.': '.implode(', ', $values);
        };

        $important = array_filter([
            $line('Known allergies', ['known_allergies']),
            $line('Special needs', ['special_needs']),
            $line('Pediatrician', ['pediatrician', 'pediatrician_address', 'pediatrician_telephone']),
            $line('Dentist', ['dentist', 'dentist_telephone']),
        ]);
        $other = array_filter([
            $line('Insurance', ['insurance_carrier']),
            $line('Membership #', ['insurance_membership']),
            $line('Group #', ['insurance_group']),
            $line('Emergency treatment consent', ['emergency_treatment_consent']),
            $line('Child Health Plus info', ['child_health_plus_info']),
            $line('Sunscreen brand', ['sunscreen_brand']),
            $line('Diaper rash ointment brand', ['diaper_ointment_brand']),
        ]);

        foreach (['important_notes' => $important, 'other_notes' => $other] as $note => $lines) {
            if ($lines === []) continue;
            $existing = trim((string) ($data[$note] ?? ''));
            $data[$note] = ($existing === '' ? '' : $existing."\n").implode("\n", $lines);
        }

        return $data;
    }

    /** Side-by-side check: extracted details on the left, the source document on the right. */
    public function review(string $token)
    {
        $import = $this->pendingImport($token);

        $pending = self::pendingTokens();

        return view('children.document-review', [
            'token' => $token,
            'extracted' => $import['fields'],
            'documentName' => $import['name'],
            'isPdf' => str_contains($import['mime'], 'pdf'),
            // Every page of the form, each with its own address, for the panel.
            'pages' => collect($import['pages'] ?? [['path' => $import['path'], 'mime' => $import['mime'], 'name' => $import['name']]])
                ->map(fn (array $page, int $index) => ['name' => $page['name'], 'isPdf' => str_contains($page['mime'], 'pdf'), 'url' => route('children.document-import.file', [$token, 'page' => $index + 1])])
                ->values()->all(),
            'nextLan' => Child::nextLan(),
            // Where this form sits in the pile, and how many are still to come.
            'position' => (int) array_search($token, $pending, true) + 1,
            'pending' => count($pending),
            'waiting' => max(0, count($pending) - ((int) array_search($token, $pending, true) + 1)),
        ]);
    }

    /** Streams the uploaded document inline so the review page can embed it. */
    public function file(string $token, Request $request)
    {
        $import = $this->pendingImport($token);
        $pages = $import['pages'] ?? [['path' => $import['path'], 'mime' => $import['mime'], 'name' => $import['name']]];
        $page = $pages[max(0, (int) $request->query('page', 1) - 1)] ?? null;
        abort_if($page === null || ! Storage::exists($page['path']), 404);

        return Storage::response($page['path'], $page['name'], ['Content-Type' => $page['mime']]);
    }

    /**
     * Set a form aside without saving a child from it — a duplicate, a blank
     * page, a scan of the wrong thing — and move to the next one waiting.
     */
    public function skip(string $token)
    {
        $import = $this->pendingImport($token);
        self::discard($token);

        $next = self::nextToken();

        return $next
            ? redirect()->route('children.document-import.review', $next)->with('success', $import['name'].' skipped.')
            : redirect()->route('children.document-import.create')->with('success', $import['name'].' skipped. No more forms waiting.');
    }

    /** Drops a pending import and its stored file once the child record is saved (or the form skipped). */
    public static function discard(?string $token): void
    {
        if (blank($token)) return;

        $import = session()->pull(self::SESSION_KEY.'.'.$token);
        session()->put(self::ORDER_KEY, array_values(array_diff(session(self::ORDER_KEY, []), [$token])));

        foreach ($import['pages'] ?? [['path' => $import['path'] ?? null]] as $page) {
            if (filled($page['path'] ?? null)) {
                Storage::delete($page['path']);
            }
        }
    }

    /** The next form waiting for review, if any — for the save and skip redirects. */
    public static function nextToken(): ?string
    {
        return self::pendingTokens()[0] ?? null;
    }

    /** Every form still waiting, in the order it was given, dropping any whose file has gone. */
    public static function pendingTokens(): array
    {
        return array_values(array_filter(
            session(self::ORDER_KEY, []),
            fn (string $token) => filled(session(self::SESSION_KEY.'.'.$token.'.path')) && Storage::exists(session(self::SESSION_KEY.'.'.$token.'.path')),
        ));
    }

    private function pendingImport(string $token): array
    {
        $import = session(self::SESSION_KEY.'.'.$token);

        abort_if(blank($import) || ! Storage::exists($import['path']), 404);

        return $import;
    }
}
