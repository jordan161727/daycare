<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser;

/**
 * Turns one combined payroll PDF into a slip per employee.
 *
 * Payroll systems export the whole run as a single document, one or more pages
 * per person, in no order anybody can rely on. The only thing on the page that
 * identifies who it belongs to is the legal name printed on it — which is why
 * User::payrollName() exists and why matching is done on tokens rather than a
 * string comparison.
 *
 * Nothing here sends anything. Getting the split wrong means a teacher receives
 * a colleague's pay, so the result is always reviewed on screen before a single
 * email goes out.
 */
class PayrollPdf
{
    /**
     * Read every page, work out whose it is, and group them into slips.
     *
     * @return list<array{pages: list<int>, user_id: ?int, matched_name: ?string, period_start: ?string, period_end: ?string, check_date: ?string}>
     */
    public function analyse(string $path, Collection $staff): array
    {
        $pages = $this->pageText($path);
        $index = $this->nameIndex($staff);

        $perPage = [];

        foreach ($pages as $number => $text) {
            $match = $this->matchStaff($text, $index);

            $perPage[] = [
                'page' => $number,
                'user_id' => $match['user']?->id,
                'matched_name' => $match['name'],
                'dates' => $this->dates($text),
            ];
        }

        return $this->group($perPage);
    }

    /**
     * Consecutive pages belonging to the same person become one slip.
     *
     * Consecutive, not merely equal: a payroll run that prints Maria on pages 1
     * and 7 is far more likely to be a bad match on page 7 than a two-part
     * payslip, and merging them would attach a stranger's page to her email.
     * Kept separate, the mistake is visible in the review step.
     *
     * @param  list<array>  $perPage
     * @return list<array>
     */
    private function group(array $perPage): array
    {
        $slips = [];

        foreach ($perPage as $page) {
            $last = $slips ? $slips[count($slips) - 1] : null;
            $continues = $last
                && $last['user_id'] === $page['user_id']
                && $page['user_id'] !== null
                && end($last['pages']) === $page['page'] - 1;

            if ($continues) {
                $slips[count($slips) - 1]['pages'][] = $page['page'];

                // A later page often carries the dates the first one omitted.
                foreach (['period_start', 'period_end', 'check_date'] as $field) {
                    $slips[count($slips) - 1][$field] ??= $page['dates'][$field];
                }

                continue;
            }

            $slips[] = [
                'pages' => [$page['page']],
                'user_id' => $page['user_id'],
                'matched_name' => $page['matched_name'],
                'period_start' => $page['dates']['period_start'],
                'period_end' => $page['dates']['period_end'],
                'check_date' => $page['dates']['check_date'],
            ];
        }

        return $slips;
    }

    /**
     * Text of each page, zero-indexed.
     *
     * @return array<int, string>
     */
    public function pageText(string $path): array
    {
        try {
            $pages = (new Parser)->parseFile($path)->getPages();
        } catch (\Throwable $e) {
            throw new RuntimeException('That file could not be read as a PDF: '.$e->getMessage(), previous: $e);
        }

        $text = [];

        foreach ($pages as $number => $page) {
            // A scanned payroll export has no text layer at all. Returning an
            // empty string leaves the page unmatched and visible in review,
            // which is the honest outcome — better than guessing an owner.
            $text[$number] = (string) $page->getText();
        }

        return $text;
    }

    /**
     * Staff indexed by the name tokens a payslip might print.
     *
     * @return list<array{user: User, name: string, tokens: list<string>}>
     */
    private function nameIndex(Collection $staff): array
    {
        return $staff->map(function (User $person) {
            $name = $person->payrollName();

            return [
                'user' => $person,
                'name' => $name,
                'tokens' => $this->significantTokens($name),
            ];
        })->filter(fn ($entry) => count($entry['tokens']) >= 2)->values()->all();
    }

    /**
     * Name parts worth matching on.
     *
     * Middle initials are dropped: "Maria G. Santos" on file against "Maria
     * Santos" on the page is the same person, and requiring the initial would
     * fail every payroll system that omits it. First and last carry the signal.
     *
     * @return list<string>
     */
    private function significantTokens(string $name): array
    {
        $parts = preg_split('/[\s,]+/', $this->normalise($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn ($part) => mb_strlen($part) > 1));
    }

    /** Upper case, accents folded, punctuation gone — page text is inconsistent. */
    private function normalise(string $value): string
    {
        $value = @iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', mb_strtoupper($value))));
    }

    /**
     * Whose payslip this page is.
     *
     * Every token must appear, so "Lee" alone never claims a page belonging to
     * "Grace Lee" — but a page mentioning both a "Grace Lee" and a "Paul Lee"
     * would match only the one whose full set of tokens is present.
     *
     * An ambiguous page — two people equally well matched — is returned
     * unmatched on purpose. A wrong match sends someone else's pay to a
     * teacher; an unmatched page sends nothing and asks a human.
     *
     * @param  list<array>  $index
     * @return array{user: ?User, name: ?string}
     */
    private function matchStaff(string $text, array $index): array
    {
        $haystack = $this->normalise($text);

        if ($haystack === '') {
            return ['user' => null, 'name' => null];
        }

        $hits = [];

        foreach ($index as $entry) {
            $matched = collect($entry['tokens'])->every(
                fn ($token) => str_contains($haystack, $token)
            );

            if ($matched) {
                $hits[] = $entry;
            }
        }

        if (count($hits) !== 1) {
            // On a tie, the longer name is the more specific one — "Anna Maria
            // Rodriguez Lee" beating "Anna Lee" is a real distinction, not noise.
            usort($hits, fn ($a, $b) => count($b['tokens']) <=> count($a['tokens']));

            $decisive = count($hits) >= 2 && count($hits[0]['tokens']) > count($hits[1]['tokens']);

            if (! $decisive) {
                return ['user' => null, 'name' => null];
            }
        }

        return ['user' => $hits[0]['user'], 'name' => $hits[0]['name']];
    }

    /**
     * Pay period and check date, read off the page.
     *
     * Labels vary between payroll providers, so each is tried in turn and a
     * miss is null rather than a guess — the director can still type a period
     * label by hand, and a wrong date on a payslip email is worse than none.
     *
     * @return array{period_start: ?string, period_end: ?string, check_date: ?string}
     */
    public function dates(string $text): array
    {
        $flat = preg_replace('/\s+/', ' ', $text);
        $date = '(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})';

        $range = null;

        foreach (['pay(?:roll)? period', 'period (?:ending|covered)', 'period'] as $label) {
            if (preg_match("/{$label}[^0-9]{0,20}{$date}\s*(?:-|to|through|–)\s*{$date}/i", $flat, $found)) {
                $range = [$found[1], $found[2]];
                break;
            }
        }

        // No label, but a bare range near the top is almost always the period.
        if (! $range && preg_match("/{$date}\s*(?:-|to|through|–)\s*{$date}/", $flat, $found)) {
            $range = [$found[1], $found[2]];
        }

        $check = null;

        foreach (['check date', 'pay date', 'payment date', 'date paid'] as $label) {
            if (preg_match("/{$label}[^0-9]{0,20}{$date}/i", $flat, $found)) {
                $check = $found[1];
                break;
            }
        }

        return [
            'period_start' => $this->parseDate($range[0] ?? null),
            'period_end' => $this->parseDate($range[1] ?? null),
            'check_date' => $this->parseDate($check),
        ];
    }

    /** US-format dates, since that is what the payslips carry. Null on anything odd. */
    private function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        foreach (['m/d/Y', 'm-d-Y', 'm/d/y', 'm-d-y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * A new PDF holding only the given pages of the source.
     *
     * @param  list<int>  $pages  Zero-based, in the order they should appear.
     */
    public function extract(string $path, array $pages): string
    {
        $pdf = new Fpdi;
        $total = $pdf->setSourceFile($path);

        foreach ($pages as $index) {
            // FPDI counts from one; everything above counts from zero.
            $number = $index + 1;

            if ($number < 1 || $number > $total) {
                continue;
            }

            $template = $pdf->importPage($number);
            $size = $pdf->getTemplateSize($template);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        return $pdf->Output('S');
    }

    public function pageCount(string $path): int
    {
        return (new Fpdi)->setSourceFile($path);
    }
}
