<?php

namespace App\Imports;

use App\Models\Child;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * The whole registration, out of a spreadsheet.
 *
 * A centre's records start life in Excel and stay there for years, so an
 * import that took a name and a room and left ninety other columns on the
 * floor meant re-typing every guardian, every pickup authorisation and every
 * phone number by hand. This takes the lot.
 *
 * Three rules hold it together:
 *
 *   The LAN is the key. It is the number on the cabinet, the parent letter and
 *   every sheet the centre keeps, so a row with a LAN that is already here
 *   updates that child rather than making a second one.
 *
 *   A blank cell means "not in this spreadsheet", never "clear what is on
 *   record". A file exported from one system rarely carries every column of
 *   another, and an import that emptied a mother's phone number because the
 *   sheet had no such column would be worse than no import at all.
 *
 *   A column nobody recognises is ignored rather than refused. Real
 *   spreadsheets carry working notes, totals and a column somebody added in
 *   2019, and none of that should stop the ninety that are good.
 */
class ChildrenImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * Every column this understands, and what else a sheet might call it.
     *
     * The key is the column on the record; the values are headings that mean
     * it. Headings arrive from the package already lowercased with spaces as
     * underscores, so "Mother Cell" and "mother cell" both land as mother_cell
     * and only the genuinely different names need listing.
     */
    private const ALIASES = [
        'lan' => ['lan', 'learner_account_number', 'student_id', 'id_number', 'child_id'],
        // One column on the centre's sheet, two on the record — see splitName.
        'child_name' => ['child_name', 'full_name', 'name', 'student_name'],
        'first_name' => ['first_name', 'firstname', 'given_name'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name'],
        'nickname' => ['nickname', 'preferred_name', 'goes_by'],
        // "Birth Date" on the centre's sheet is the child's date of birth,
        // which is what every room assignment and age reads.
        'dob' => ['dob', 'date_of_birth', 'birthdate', 'birth_day', 'birth_date'],
        'gender' => ['gender', 'sex'],
        'classroom' => ['classroom', 'room', 'class'],
        'status' => ['status', 'enrolment_status', 'enrollment_status'],
        'enrolled_on' => ['enrolled_on', 'enrolment_date', 'enrollment_date', 'start_date', 'date_enrolled'],
        'withdrawn_on' => ['withdrawn_on', 'withdrawal_date', 'end_date', 'date_withdrawn'],
        // The centre keeps these three in one cell — see splitAddress.
        'address' => ['address', 'street_address', 'home_address', 'address_city_zip'],
        'city' => ['city', 'town'],
        'zip' => ['zip', 'zip_code', 'postcode', 'postal_code'],
        'telephone' => ['telephone', 'phone', 'home_phone', 'contact_number'],
        'email_address' => ['email_address', 'email', 'parent_email'],
        'dss_case_no' => ['dss_case_no', 'dss_case', 'case_no', 'case_number'],
        'dss_cin' => ['dss_cin', 'cin'],
        'drop_off_time' => ['drop_off_time', 'drop_off', 'dropoff', 'time_in', 'time_from'],
        'pick_up_time' => ['pick_up_time', 'pick_up', 'pickup', 'time_out', 'time_to'],
        'expected_hours_per_week' => ['expected_hours_per_week', 'hours_per_week', 'weekly_hours'],
        'schedule_days' => ['schedule_days', 'days', 'days_attending', 'attending_days', 'days_enrolled'],
        'parents_status' => ['parents_status', 'marital_status'],
        'responsible_for_payment' => ['responsible_for_payment', 'payer', 'responsible_party'],
        'emergency_contact' => ['emergency_contact', 'emergency_name'],
        'secondary_emergency_contact' => ['secondary_emergency_contact', 'emergency_contact_2'],
        'emergency_telephone' => ['emergency_telephone', 'emergency_phone'],
        'emergency_relationship' => ['emergency_relationship'],
        // A heading of "Emergency License #" loses its hash on the way in.
        'emergency_license_number' => ['emergency_license_number', 'emergency_license'],
        'other_notes' => ['other_notes', 'notes', 'comments'],
        'important_notes' => ['important_notes'],
        // Read to one side and turned into an alert entry, because `alerts`
        // is a list of {type, text} rather than a line of prose.
        'allergy_text' => ['alerts', 'allergies', 'medical_alerts', 'known_allergies'],
        // How the family found the centre. "advetised" is the spelling on
        // the centre's own sheet, and correcting their file is not this
        // importer's business.
        'where_advertised' => ['where_advertised', 'where_advetised', 'how_did_you_hear'],
        'who_referred' => ['who_referred', 'referred_by', 'referral'],
    ];

    /** Columns that follow one pattern, listed rather than written out sixty times. */
    private const PARENT_FIELDS = [
        'name', 'address', 'home_phone', 'employer', 'work_phone',
        'fax', 'cell', 'email', 'title', 'ssn',
    ];

    private const PICKUP_FIELDS = [
        'name', 'address', 'telephone', 'alternate', 'relationship', 'license_number',
    ];

    /** Read as dates, whatever the spreadsheet stored them as. */
    private const DATES = ['dob', 'birth_date', 'enrolled_on', 'withdrawn_on'];

    /** Read as times — "8:00", "08:00 AM", or Excel's own fraction of a day. */
    private const TIMES = ['drop_off_time', 'pick_up_time'];

    /**
     * The headings the template offers, in the order a registration asks them.
     *
     * The canonical name of each column — the aliases exist so a centre's own
     * spreadsheet still imports, but a blank template should teach one way of
     * writing them rather than five.
     *
     * @return array<int, string>
     */
    public static function templateHeadings(): array
    {
        $headings = [
            // The child
            'lan', 'first_name', 'last_name', 'nickname', 'dob', 'gender',
            'classroom', 'status', 'enrolled_on', 'withdrawn_on',
            // Where they live
            'address', 'city', 'zip', 'telephone', 'email_address',
            // The day they are here
            'drop_off_time', 'pick_up_time', 'schedule_days', 'expected_hours_per_week',
            // Funding
            'dss_case_no', 'dss_cin',
        ];

        foreach (['mother', 'father'] as $parent) {
            foreach (self::PARENT_FIELDS as $part) {
                $headings[] = $parent.'_'.$part;
            }
        }

        $headings = array_merge($headings, [
            'parents_status', 'responsible_for_payment',
            'emergency_contact', 'emergency_telephone', 'emergency_relationship',
            'emergency_license_number', 'secondary_emergency_contact',
        ]);

        foreach ([1, 2, 3] as $slot) {
            foreach (self::PICKUP_FIELDS as $part) {
                $headings[] = 'pickup_'.$slot.'_'.$part;
            }
        }

        return array_merge($headings, ['alerts', 'important_notes', 'other_notes']);
    }
    public function collection(Collection $rows)
    {
        $map = $this->columnMap();

        foreach ($rows as $index => $row) {
            // The first row holds the headings, so the second is row 2.
            $rowNumber = $index + 2;
            $row = collect($row)->all();

            $data = [];

            foreach ($map as $heading => $field) {
                if (! array_key_exists($heading, $row)) {
                    continue;
                }

                $value = $row[$heading];

                // Blank means "not in this spreadsheet", so the record keeps
                // whatever it already has.
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }

                $data[$field] = is_string($value) ? trim($value) : $value;
            }

            // A row with nothing on it is the empty tail every spreadsheet has.
            if ($data === []) {
                continue;
            }

            try {
                $data = $this->normalise($data);
            } catch (\Throwable $e) {
                $this->errors[] = "Row {$rowNumber}: ".$e->getMessage();

                continue;
            }

            $validator = Validator::make($data, $this->rules());

            if ($validator->fails()) {
                $this->errors[] = "Row {$rowNumber}: ".implode(' ', $validator->errors()->all());

                continue;
            }

            $child = $this->find($data);

            if ($child) {
                $child->fill($data)->save();
                $this->updated++;

                continue;
            }

            // Nothing on record to update, so this row is making a child —
            // and a child cannot be made without a name. An update needs
            // none, because the name is already there.
            if (blank($data['first_name'] ?? null) && blank($data['last_name'] ?? null)) {
                $this->errors[] = "Row {$rowNumber}: no name, so there is nothing to file this child under.";

                continue;
            }

            // A centre's own sheet rarely carries a LAN — the number is this
            // app's, not theirs — so one is issued here. See nextLan.
            $data['lan'] = $data['lan'] ?? $this->nextLan();

            Child::create($data + ['status' => $data['status'] ?? 'Active']);
            $this->created++;
        }
    }

    /**
     * The alert list this child should end up with, given an allergy line.
     *
     * Whatever they already have, plus this one — unless it is already on
     * there. A centre's spreadsheet carries the allergy; the court orders and
     * medical notes were typed into this app, and an import must not throw
     * them away.
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function withAllergy(string $text, ?string $lan, array $data): array
    {
        $existing = $this->find($data)?->alerts ?? [];

        if (! is_array($existing)) {
            $existing = [];
        }

        foreach ($existing as $alert) {
            if (trim((string) ($alert['text'] ?? '')) === trim($text)) {
                return $existing;
            }
        }

        $existing[] = ['type' => 'allergy', 'text' => trim($text)];

        return $existing;
    }
    /**
     * The child this row is about, if the centre already has them.
     *
     * By LAN where the sheet carries one: it is the number on the cabinet and
     * the parent letter, and it is exact.
     *
     * By name and birthday otherwise, which is what the centre's own sheet
     * gives us. It is not a key and never will be — two children called Ava
     * Cruz born on the same day would collide — but the alternative is that
     * importing the same file twice creates every child twice, and that is the
     * failure people actually hit.
     */
    private function find(array $data): ?Child
    {
        if (filled($data['lan'] ?? null)) {
            return Child::where('lan', $data['lan'])->first();
        }

        $query = Child::where('first_name', $data['first_name'] ?? null)
            ->where('last_name', $data['last_name'] ?? null);

        if (filled($data['dob'] ?? null)) {
            $query->whereDate('dob', $data['dob']);
        }

        return $query->first();
    }

    /**
     * The next learner account number.
     *
     * Counted off the highest one in use rather than the number of rows, so a
     * centre that has deleted a child does not have the next one land on a
     * number a parent letter already carries.
     */
    private function nextLan(): string
    {
        static $next = null;

        if ($next === null) {
            $highest = Child::query()
                ->selectRaw('MAX(CAST(lan AS UNSIGNED)) AS top')
                ->value('top');

            // 10001 upwards, matching what the centre's records already use.
            $next = max((int) $highest, 10000) + 1;
        }

        return (string) $next++;
    }

    /**
     * "Adkins, Maeve" or "Maeve Adkins" into a first and a last name.
     *
     * Every list this app draws is sorted by surname, so one column of names
     * has to be split to be of any use. A comma is the centre saying which way
     * round it is; without one the last word is taken as the surname, which is
     * right far more often than not and is visible on the child's record for
     * anybody it is wrong for.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('~\s+~', ' ', $name));

        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));

            return [$first, $last];
        }

        $parts = explode(' ', $name);

        if (count($parts) === 1) {
            return [$name, ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
    /**
     * Heading → field, including the sixty parent and pickup columns.
     *
     * Built once per import rather than per row: a file of four hundred
     * children would otherwise rebuild it four hundred times.
     *
     * @return array<string, string>
     */
    private function columnMap(): array
    {
        $map = [];

        foreach (self::ALIASES as $field => $headings) {
            foreach ($headings as $heading) {
                $map[$heading] = $field;
            }
        }

        foreach (['mother', 'father'] as $parent) {
            foreach (self::PARENT_FIELDS as $part) {
                $map[$parent.'_'.$part] = $parent.'_'.$part;
            }
        }

        foreach ([1, 2, 3] as $slot) {
            foreach (self::PICKUP_FIELDS as $part) {
                $map['pickup_'.$slot.'_'.$part] = 'pickup_'.$slot.'_'.$part;
                // "Pickup 1 License #" loses its hash on the way in.
                $map['pickup_'.$slot.'_license'] = 'pickup_'.$slot.'_license_number';
                // "Pickup 1 Name" and "Authorized Pickup 1 Name" both happen.
                $map['authorized_pickup_'.$slot.'_'.$part] = 'pickup_'.$slot.'_'.$part;
            }
        }

        return $map;
    }

    /** Turn what a spreadsheet stores into what the record expects. */
    private function normalise(array $data): array
    {
        /*
         * "Known Allergies" is one entry on a list, not the list itself.
         *
         * Added to what is already there rather than replacing it, so a
         * second import does not wipe a court order somebody typed in by
         * hand — and not added twice if the same text is already on it.
         */
        if (filled($data['allergy_text'] ?? null)) {
            $data['alerts'] = $this->withAllergy($data['allergy_text'], $data['lan'] ?? null, $data);
        }

        unset($data['allergy_text']);

        // One column of names on the sheet, a first and a last on the record.
        // Only where the sheet did not give them separately.
        if (filled($data['child_name'] ?? null)
            && blank($data['first_name'] ?? null)
            && blank($data['last_name'] ?? null)) {
            [$data['first_name'], $data['last_name']] = $this->splitName($data['child_name']);
        }

        foreach (self::DATES as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->asDate($data[$field], $field);
            }
        }

        foreach (self::TIMES as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->asTime($data[$field]);
            }
        }

        // "Mon, Wed, Fri", "1,3,5", "MWF" — all of them mean the same three days.
        if (isset($data['schedule_days'])) {
            $data['schedule_days'] = $this->asDays($data['schedule_days']);
        }

        // A plain age comes back as a number and the string rule would reject it.
        foreach (['age', 'lan', 'zip', 'telephone', 'expected_hours_per_week'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (string) $data[$field];
            }
        }

        if (isset($data['status'])) {
            $data['status'] = ucfirst(strtolower($data['status']));
        }

        return $data;
    }

    private function asDate(mixed $value, string $field): ?string
    {
        if (is_numeric($value)) {
            // Excel keeps a date as a count of days since 1900.
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            // Named the way the spreadsheet names it, not the way the column
            // is spelled: whoever fixes the file is looking at a heading, not
            // at a database.
            $labels = [
                'dob' => 'date of birth',
                'birth_date' => 'birth date',
                'enrolled_on' => 'enrolment date',
                'withdrawn_on' => 'withdrawal date',
            ];

            throw new \RuntimeException(
                "the ".($labels[$field] ?? str_replace('_', ' ', $field))." \"{$value}\" is not a date the importer can read."
            );
        }
    }

    private function asTime(mixed $value): ?string
    {
        if (is_numeric($value)) {
            // Excel keeps a time as a fraction of a day.
            return Carbon::instance(Date::excelToDateTimeObject((float) $value))->format('H:i');
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            throw new \RuntimeException("\"{$value}\" is not a time the importer can read.");
        }
    }

    /**
     * The days a child comes, however the sheet writes them.
     *
     * @return array<int, int> ISO weekdays, ascending
     */
    private function asDays(mixed $value): array
    {
        $text = strtolower((string) $value);

        // "M-F" and "Mon-Fri" are the commonest arrangement there is.
        if (preg_match('~^m\s*-\s*f$|^mon\s*-\s*fri$|daily|every\s*day|weekdays$~', trim($text))) {
            return [1, 2, 3, 4, 5];
        }

        $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5];
        $days = [];

        foreach (preg_split('~[,/;|\s]+~', $text, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (is_numeric($part) && (int) $part >= 1 && (int) $part <= 5) {
                $days[] = (int) $part;

                continue;
            }

            foreach ($names as $name => $iso) {
                if (str_starts_with($part, $name)) {
                    $days[] = $iso;
                }
            }
        }

        // "MWF" written as one word, which no separator will split.
        if ($days === [] && preg_match('~^[mtwrf]+$~', trim($text))) {
            $letters = ['m' => 1, 't' => 2, 'w' => 3, 'r' => 4, 'f' => 5];

            foreach (str_split(trim($text)) as $letter) {
                $days[] = $letters[$letter];
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            // Issued here when the sheet has none, so it cannot be required of
            // the file — see nextLan.
            'lan' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'dob' => ['nullable', 'date'],
            'birth_date' => ['nullable', 'date'],
            'enrolled_on' => ['nullable', 'date'],
            'withdrawn_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
            'email_address' => ['nullable', 'string', 'max:255'],
            'mother_email' => ['nullable', 'string', 'max:255'],
            'father_email' => ['nullable', 'string', 'max:255'],
            'schedule_days' => ['nullable', 'array'],
            'schedule_days.*' => ['integer', 'between:1,5'],
        ];
    }
}
