<?php

namespace App\Services;

use App\Models\Child;

/**
 * Which of the twelve illustrated portraits stands in for a child without a
 * photograph — or none, when the record gives nothing to go on.
 *
 * The portraits sit on one sprite (public/images/portraits.webp), four across
 * and three down, numbered 0 to 11 reading left to right. Each is catalogued
 * here by how it reads: girl or boy, skin tone, hair colour, hair length and
 * so on.
 *
 * The group — girls or boys — comes from the `gender` column, or failing that
 * from the word "girl" or "boy" in the description. Never from the name.
 * Within the group, the description is matched against the catalogue and the
 * closest portrait wins, skin tone counting for more than hair; where nothing
 * in it matches, or there is no description, the LAN and name pick one, so it
 * is the same face on every page and after every deploy.
 *
 * The description comes in two shapes. The centre's registration sheet
 * writes it as labelled fields — "Race / Skin Tone: Brown; Hair Color:
 * Blonde; Hair Length: Long; Other Features: Braids" — and each label says
 * what its value is about, so "Brown" under skin tone is the skin, not the
 * hair. A free line ("brunet short hair boy") is read word by word instead.
 *
 * A child whose record says neither girl nor boy gets no portrait at all and
 * is drawn the plain grey silhouette instead — the honest picture of a record
 * that does not say, rather than a face that guesses.
 */
class Portrait
{
    /** @var array<int, array<int, string>> What each portrait reads as. */
    public const CATALOGUE = [
        0 => ['boy', 'brown', 'blonde', 'short', 'wavy', 'light'],
        1 => ['girl', 'black', 'pigtails', 'straight', 'asian', 'light'],
        2 => ['boy', 'black', 'short', 'curly', 'dark'],
        3 => ['girl', 'blonde', 'long', 'curly', 'light'],
        4 => ['girl', 'black', 'curly', 'puffs', 'braids', 'dark'],
        5 => ['boy', 'black', 'short', 'straight', 'light'],
        6 => ['girl', 'red', 'bun', 'curly', 'light'],
        7 => ['boy', 'black', 'short', 'wavy', 'hispanic', 'tan'],
        8 => ['boy', 'black', 'short', 'straight', 'asian'],
        9 => ['girl', 'brown', 'short', 'straight', 'light'],
        10 => ['boy', 'black', 'short', 'curly', 'dark'],
        11 => ['girl', 'black', 'long', 'braids', 'straight', 'hispanic', 'tan'],
    ];

    /**
     * Skin tone and hair colour count double: they are what a face reads as
     * first. Length, curl and braids settle what is left.
     */
    private const WEIGHTY = ['light', 'tan', 'dark', 'hispanic', 'asian', 'blonde', 'brown', 'black', 'red'];

    /**
     * Words a free-text description might use, and the catalogue tags they
     * mean. The word "black" on the centre's sheet is as often the child
     * ("black boy") as the hair, so it counts for both.
     *
     * @var array<string, array<int, string>>
     */
    private const WORDS = [
        'girl' => ['girl'], 'girls' => ['girl'], 'she' => ['girl'], 'her' => ['girl'], 'daughter' => ['girl'], 'female' => ['girl'], 'f' => ['girl'],
        'boy' => ['boy'], 'boys' => ['boy'], 'he' => ['boy'], 'his' => ['boy'], 'son' => ['boy'], 'male' => ['boy'], 'm' => ['boy'],
        'blonde' => ['blonde'], 'blond' => ['blonde'], 'fair' => ['blonde'], 'yellow' => ['blonde'], 'golden' => ['blonde'],
        'brown' => ['brown'], 'brunet' => ['brown'], 'brunette' => ['brown'], 'brunete' => ['brown'],
        'black' => ['black', 'dark'], 'dark' => ['dark'],
        'red' => ['red'], 'ginger' => ['red'], 'orange' => ['red'], 'auburn' => ['red'], 'redhead' => ['red'],
        'short' => ['short'], 'long' => ['long'], 'bald' => ['short'],
        'curly' => ['curly'], 'curls' => ['curly'], 'afro' => ['curly'], 'wavy' => ['wavy'], 'straight' => ['straight'],
        'pigtails' => ['pigtails'], 'ponytails' => ['pigtails'], 'braids' => ['braids'], 'braided' => ['braids'], 'beads' => ['braids'], 'bun' => ['bun'], 'puffs' => ['puffs'],
        'hispanic' => ['hispanic', 'tan'], 'latino' => ['hispanic', 'tan'], 'latina' => ['hispanic', 'tan'], 'mexican' => ['hispanic', 'tan'],
        'asian' => ['asian'], 'chinese' => ['asian'], 'japanese' => ['asian'], 'korean' => ['asian'], 'filipino' => ['asian'], 'vietnamese' => ['asian'],
        'white' => ['light'], 'light' => ['light'], 'pale' => ['light'], 'caucasian' => ['light'],
        'tan' => ['tan'], 'olive' => ['tan'], 'medium' => ['tan'],
    ];

    /**
     * The labelled fields the registration sheet writes, and what each
     * value under them means. A label is matched by any one of its words.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const FIELDS = [
        'skin' => [
            'white' => ['light'], 'light' => ['light'], 'caucasian' => ['light'], 'pale' => ['light'],
            'brown' => ['tan'], 'tan' => ['tan'], 'olive' => ['tan'], 'medium' => ['tan'],
            'black' => ['dark'], 'dark' => ['dark'], 'african' => ['dark'],
            'hispanic' => ['hispanic', 'tan'], 'latino' => ['hispanic', 'tan'], 'latina' => ['hispanic', 'tan'], 'mexican' => ['hispanic', 'tan'],
            'asian' => ['asian'], 'chinese' => ['asian'], 'japanese' => ['asian'], 'korean' => ['asian'], 'filipino' => ['asian'], 'vietnamese' => ['asian'],
        ],
        'color' => [
            'blonde' => ['blonde'], 'blond' => ['blonde'], 'fair' => ['blonde'], 'golden' => ['blonde'],
            'brown' => ['brown'], 'brunette' => ['brown'], 'brunet' => ['brown'], 'brunete' => ['brown'],
            'black' => ['black'], 'dark' => ['black'],
            'red' => ['red'], 'ginger' => ['red'], 'auburn' => ['red'], 'orange' => ['red'],
        ],
        'length' => [
            'short' => ['short'], 'very' => [], 'bald' => ['short'], 'buzz' => ['short'], 'cropped' => ['short'],
            'long' => ['long'], 'medium' => [], 'shoulder' => ['long'],
        ],
        'features' => [
            'braids' => ['braids'], 'braided' => ['braids'], 'beads' => ['braids'], 'cornrows' => ['braids'],
            'pigtails' => ['pigtails'], 'ponytails' => ['pigtails'], 'bun' => ['bun'], 'puffs' => ['puffs'],
            'curly' => ['curly'], 'curls' => ['curly'], 'afro' => ['curly'], 'wavy' => ['wavy'], 'straight' => ['straight'],
            'glasses' => [],
        ],
    ];

    /** Which field a label ("Race / Skin Tone", "Hair Color") is, by a word in it. */
    private const LABELS = [
        'skin' => 'skin', 'race' => 'skin', 'tone' => 'skin', 'complexion' => 'skin', 'ethnicity' => 'skin',
        'color' => 'color', 'colour' => 'color',
        'length' => 'length', 'style' => 'length',
        'features' => 'features', 'feature' => 'features', 'other' => 'features', 'hairstyle' => 'features',
    ];

    /** The portrait to draw, or null for the grey silhouette. */
    public static function pick(Child $child): ?int
    {
        $tags = self::tags($child->description);
        $group = self::group($child->gender, $tags);

        if ($group === null) {
            return null;
        }

        $pool = array_keys(array_filter(self::CATALOGUE, fn (array $reads) => in_array($group, $reads, true)));
        $seed = crc32(($child->lan ?? '').'|'.trim(($child->first_name ?? '').' '.($child->last_name ?? '')));

        // Score each portrait in the group by how much of the description it
        // answers to — skin and hair colour counting double — and keep the best. The
        // group word itself has already done its work.
        $wanted = array_values(array_diff($tags, ['girl', 'boy']));
        $best = 0;
        $scores = [];

        foreach ($pool as $index) {
            $score = 0;

            foreach (array_intersect($wanted, self::CATALOGUE[$index]) as $tag) {
                $score += in_array($tag, self::WEIGHTY, true) ? 2 : 1;
            }

            $scores[$index] = $score;
            $best = max($best, $score);
        }

        if ($best > 0) {
            $pool = array_keys(array_filter($scores, fn (int $score) => $score === $best));
        }

        return $pool[$seed % count($pool)];
    }

    /** Girl or Boy, from the column first and the description second. */
    private static function group(?string $gender, array $tags): ?string
    {
        if (in_array($gender, Child::GENDERS, true)) {
            return strtolower($gender);
        }

        $girl = in_array('girl', $tags, true);
        $boy = in_array('boy', $tags, true);

        return match (true) {
            $girl && ! $boy => 'girl',
            $boy && ! $girl => 'boy',
            default => null,
        };
    }

    /** @return array<int, string> The catalogue tags a description asks for. */
    public static function tags(?string $description): array
    {
        if (blank($description)) {
            return [];
        }

        $tags = [];
        $description = strtolower($description);

        // Labelled fields first: "Race / Skin Tone: Brown; Hair Color: Blonde".
        // Whatever is left over — a part with no label — is read as free text.
        $loose = [];

        foreach (preg_split('/[;,\n]+/', $description, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (! str_contains($part, ':')) {
                $loose[] = $part;

                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $part, 2));
            $field = null;

            foreach (self::words($label) as $word) {
                if (isset(self::LABELS[$word])) {
                    $field = self::LABELS[$word];

                    break;
                }
            }

            if ($field === null) {
                $loose[] = $value;

                continue;
            }

            foreach (self::words($value) as $word) {
                foreach (self::FIELDS[$field][$word] ?? [] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        // "brown skin" said loose must not read as brown hair.
        $loose = preg_replace('/\bbrown skin\b/', 'tan skin', implode(' ', $loose));

        foreach (self::words($loose) as $word) {
            foreach (self::WORDS[$word] ?? [] as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /** @return array<int, string> */
    private static function words(string $text): array
    {
        return preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    }
}
