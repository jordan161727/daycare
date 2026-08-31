<?php

namespace Database\Seeders;

use App\Models\HolidayRule;
use App\Models\User;
use App\Services\HolidayCalendar;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The Canadian statutory holidays, as rules that repeat every year.
 *
 * The federal list is seeded always: those ten are observed in every province
 * and territory, so they are the safe floor for a centre wherever it is. The
 * province in config('daycare.province') adds its own on top — the February
 * family day under whichever of its seven names, the August civic holiday, and
 * Quebec's substitutions.
 *
 * Re-running is safe: every rule carries a key, so a second run updates the
 * rule it wrote last time rather than adding another beside it. It does bring
 * back a rule the director has since deleted — a seeder states what the list
 * should be — but it never re-closes a single day reopened by hand, because a
 * year already written out is never revisited.
 */
class CanadianHolidaySeeder extends Seeder
{
    use WithoutModelEvents;

    private const MON = 1;

    /**
     * Observed in every province and territory.
     *
     * Thanksgiving and Remembrance Day are optional rather than statutory in
     * parts of Atlantic Canada, but a daycare closes for them, so they are here
     * rather than filed under a province.
     */
    private const FEDERAL = [
        ['key' => 'ca-new-years-day', 'reason' => "New Year's Day", 'type' => HolidayRule::FIXED, 'month' => 1, 'day' => 1],
        ['key' => 'ca-good-friday', 'reason' => 'Good Friday', 'type' => HolidayRule::EASTER, 'offset_days' => -2],
        ['key' => 'ca-victoria-day', 'reason' => 'Victoria Day', 'type' => HolidayRule::ON_OR_BEFORE, 'month' => 5, 'day' => 24, 'weekday' => self::MON],
        ['key' => 'ca-canada-day', 'reason' => 'Canada Day', 'type' => HolidayRule::FIXED, 'month' => 7, 'day' => 1],
        ['key' => 'ca-labour-day', 'reason' => 'Labour Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 9, 'weekday' => self::MON, 'nth' => 1],
        ['key' => 'ca-truth-reconciliation', 'reason' => 'National Day for Truth and Reconciliation', 'type' => HolidayRule::FIXED, 'month' => 9, 'day' => 30],
        ['key' => 'ca-thanksgiving', 'reason' => 'Thanksgiving Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 10, 'weekday' => self::MON, 'nth' => 2],
        ['key' => 'ca-remembrance-day', 'reason' => 'Remembrance Day', 'type' => HolidayRule::FIXED, 'month' => 11, 'day' => 11],
        ['key' => 'ca-christmas-day', 'reason' => 'Christmas Day', 'type' => HolidayRule::FIXED, 'month' => 12, 'day' => 25],
        ['key' => 'ca-boxing-day', 'reason' => 'Boxing Day', 'type' => HolidayRule::FIXED, 'month' => 12, 'day' => 26],
    ];

    /**
     * What each province adds to the federal list.
     *
     * The February holiday and the August one are the same two days almost
     * everywhere and named differently in every jurisdiction, which is why they
     * are spelled out per province rather than shared — the name is the part
     * the parents read on the notice.
     */
    private const PROVINCIAL = [
        'AB' => [
            ['key' => 'ca-ab-family-day', 'reason' => 'Family Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-ab-heritage-day', 'reason' => 'Heritage Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'BC' => [
            ['key' => 'ca-bc-family-day', 'reason' => 'Family Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-bc-day', 'reason' => 'British Columbia Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'MB' => [
            ['key' => 'ca-mb-louis-riel-day', 'reason' => 'Louis Riel Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-mb-terry-fox-day', 'reason' => 'Terry Fox Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'NB' => [
            ['key' => 'ca-nb-family-day', 'reason' => 'Family Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-nb-day', 'reason' => 'New Brunswick Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'NL' => [
            ['key' => 'ca-nl-st-patricks-day', 'reason' => "St. Patrick's Day", 'type' => HolidayRule::FIXED, 'month' => 3, 'day' => 17],
        ],
        'NS' => [
            ['key' => 'ca-ns-heritage-day', 'reason' => 'Nova Scotia Heritage Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
        ],
        'NT' => [
            ['key' => 'ca-nt-indigenous-peoples-day', 'reason' => 'National Indigenous Peoples Day', 'type' => HolidayRule::FIXED, 'month' => 6, 'day' => 21],
            ['key' => 'ca-nt-civic-holiday', 'reason' => 'Civic Holiday', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'NU' => [
            ['key' => 'ca-nu-nunavut-day', 'reason' => 'Nunavut Day', 'type' => HolidayRule::FIXED, 'month' => 7, 'day' => 9],
            ['key' => 'ca-nu-civic-holiday', 'reason' => 'Civic Holiday', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'ON' => [
            ['key' => 'ca-on-family-day', 'reason' => 'Family Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-on-civic-holiday', 'reason' => 'Civic Holiday', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'PE' => [
            ['key' => 'ca-pe-islander-day', 'reason' => 'Islander Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
        ],
        'QC' => [
            ['key' => 'ca-qc-patriots-day', 'reason' => 'National Patriots\' Day', 'type' => HolidayRule::ON_OR_BEFORE, 'month' => 5, 'day' => 24, 'weekday' => self::MON],
            ['key' => 'ca-qc-fete-nationale', 'reason' => 'Fête nationale du Québec', 'type' => HolidayRule::FIXED, 'month' => 6, 'day' => 24],
        ],
        'SK' => [
            ['key' => 'ca-sk-family-day', 'reason' => 'Family Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 2, 'weekday' => self::MON, 'nth' => 3],
            ['key' => 'ca-sk-day', 'reason' => 'Saskatchewan Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 1],
        ],
        'YT' => [
            ['key' => 'ca-yt-discovery-day', 'reason' => 'Discovery Day', 'type' => HolidayRule::NTH_WEEKDAY, 'month' => 8, 'weekday' => self::MON, 'nth' => 3],
        ],
    ];

    /**
     * Federal entries a province observes under its own name instead.
     *
     * Quebec keeps the Monday before 25 May but calls it National Patriots' Day,
     * so seeding both would be one closed day wearing two rules.
     */
    private const REPLACES = [
        'QC' => ['ca-victoria-day'],
    ];

    public function run(): void
    {
        $province = strtoupper(trim((string) config('daycare.province', '')));
        $calendar = app(HolidayCalendar::class);
        $replaced = self::REPLACES[$province] ?? [];

        $wanted = array_filter(self::FEDERAL, fn ($rule) => ! in_array($rule['key'], $replaced, true));
        $wanted = array_merge($wanted, self::PROVINCIAL[$province] ?? []);

        // A federal rule the province renames may already be on the calendar
        // from an earlier run under a different province. Through forget(), so
        // its future closed days go and their ticks come back.
        foreach (HolidayRule::whereIn('key', $replaced)->get() as $rule) {
            $calendar->forget($rule);
        }

        // Attributed to the director if there is one, so the holidays page reads
        // the same as a day somebody entered by hand.
        $admin = User::where('role', 'admin')->orderBy('id')->first();

        foreach ($wanted as $rule) {
            // The nulls matter: a rule edited from fixed to nth-weekday would
            // otherwise keep a stale day, and produce two dates a year.
            HolidayRule::updateOrCreate(['key' => $rule['key']], array_merge([
                'month' => null,
                'day' => null,
                'weekday' => null,
                'nth' => null,
                'offset_days' => null,
                // Only a fixed date can land at the weekend; the moving rules
                // all resolve to a weekday by construction.
                'observed' => ($rule['type'] ?? HolidayRule::FIXED) === HolidayRule::FIXED,
                'created_by' => $admin?->id,
            ], $rule));
        }

        // Writes the years out. A rule already materialised does nothing here,
        // so a second run costs a query per rule and changes nothing.
        $result = $calendar->materialise($admin);

        $this->command?->info(sprintf(
            '%d holiday rule(s) seeded for %s — %d closed day(s) written, %d scheduled day(s) cleared.',
            count($wanted),
            $province !== '' ? $province : 'the federal list',
            $result['closed'],
            $result['cleared'],
        ));
    }
}
