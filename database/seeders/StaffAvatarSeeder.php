<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A face for every member of staff who has not uploaded one.
 *
 * Drawn, not photographed: a simple portrait in SVG — skin, hair, a collar —
 * on a soft disc, varied by the person's name so the same person gets the
 * same face everywhere and two people are told apart at a glance. It goes
 * where a real upload would go (the public avatars folder), so the moment
 * somebody uploads a photograph it simply replaces this. Anyone who already
 * has an avatar is left alone.
 */
class StaffAvatarSeeder extends Seeder
{
    use WithoutModelEvents;

    private const SKIN = ['#f5d6c2', '#eac1a4', '#d8a584', '#c08a66', '#9a6a4b', '#70503a'];

    private const HAIR = ['#2b2118', '#4a3222', '#7a4a2a', '#b3773e', '#d6b26a', '#5a5b63', '#1f1f23', '#8c3a2a'];

    private const DISC = [['#eef1fb', '#dfe5f7'], ['#dcebfa', '#cfe2f7'], ['#e6f4f1', '#d6ece6'], ['#f7efe6', '#f0e3d3'], ['#f3ecf7', '#e8dcf0'], ['#fdf0e6', '#f8e3d2']];

    /** Jacket and the lighter lapel it folds to. */
    private const JACKET = [['#1f2a44', '#2d3b5e'], ['#1b5e91', '#2a73ad'], ['#2f3640', '#434b58'], ['#1f6f5f', '#2b8676'], ['#5a3d7a', '#6f4f94'], ['#7a4a2a', '#8f5c38']];

    public function run(): void
    {
        $disk = Storage::disk('public');
        $done = 0;

        foreach (User::teachers()->orderBy('id')->get() as $person) {
            // A photograph somebody uploaded is theirs and stays. A drawing from
            // an earlier run of this seeder is redrawn, so the style can change.
            $seeded = str_starts_with((string) $person->avatar_path, 'avatars/seed-');

            if (filled($person->avatar_path) && ! $seeded && $disk->exists($person->avatar_path)) {
                continue;
            }

            $path = 'avatars/seed-'.Str::slug($person->name).'-'.$person->id.'.svg';
            $disk->put($path, $this->portrait($person));
            $person->update(['avatar_path' => $path]);
            $done++;
        }

        $this->command?->info("Drew {$done} staff avatars.");
    }

    /**
     * One portrait, settled by the name so it is the same on every run.
     *
     * A business headshot in flat vector: head and shoulders in a jacket with
     * lapels and an open collar, on a softly graded disc. Five hairstyles,
     * glasses on some, and a quiet smile — the kind of illustration a staff
     * directory uses, rather than a cartoon. The features are small and
     * low-contrast on purpose: at forty pixels a face reads by its shape and
     * colour, and heavy eyes and mouths turn into a grimace.
     */
    private function portrait(User $person): string
    {
        $seed = crc32($person->name);
        $pick = fn (array $set, int $shift) => $set[(($seed >> $shift) & 0xffff) % count($set)];

        $skin = $pick(self::SKIN, 0);
        $hair = $pick(self::HAIR, 4);
        [$discTop, $discBottom] = $pick(self::DISC, 8);
        [$jacket, $lapel] = $pick(self::JACKET, 12);
        $style = (($seed >> 16) & 0xffff) % 5;
        $glasses = ((($seed >> 20) & 0xff) % 3) === 0;
        $shade = $this->darken($skin, 0.12);
        $id = 'g'.$person->id;

        // Hair behind the head (long styles) and the cap in front of it.
        [$behind, $front] = match ($style) {
            // Short crop.
            0 => ['', '<path d="M31 44c0-13 8-22 19-22s19 9 19 22c-3-6-8-9-19-9s-16 3-19 9z" fill="'.$hair.'"/>'],
            // Side part.
            1 => ['', '<path d="M31 45c0-14 8-23 19-23 12 0 20 8 20 21-2-4-6-6-9-7-7 3-16 5-30 9z" fill="'.$hair.'"/>'],
            // Bob to the jaw.
            2 => ['<path d="M30 46c0-15 9-24 20-24s20 9 20 24v16c0 3-2 5-5 5H35c-3 0-5-2-5-5z" fill="'.$hair.'"/>',
                  '<path d="M31 45c0-13 8-22 19-22s19 9 19 22c-4-5-9-8-19-8s-15 3-19 8z" fill="'.$hair.'"/>'],
            // Long, past the shoulders.
            3 => ['<path d="M29 46c0-16 9-25 21-25s21 9 21 25v30c0 2-1 3-3 3H32c-2 0-3-1-3-3z" fill="'.$hair.'"/>',
                  '<path d="M31 46c0-14 8-23 19-23s19 9 19 23c-3-6-8-10-19-10s-16 4-19 10z" fill="'.$hair.'"/>'],
            // Hair up in a bun.
            default => ['<circle cx="50" cy="24" r="7" fill="'.$hair.'"/>',
                  '<path d="M31 45c0-13 8-22 19-22s19 9 19 22c-4-6-9-9-19-9s-15 3-19 9z" fill="'.$hair.'"/>'],
        };

        $specs = $glasses
            ? '<g fill="none" stroke="#2b3140" stroke-width="1.3" opacity=".85"><circle cx="43" cy="47" r="4.6"/><circle cx="57" cy="47" r="4.6"/><path d="M47.6 47h4.8"/></g>'
            : '';

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100" role="img" aria-label="{$this->esc($person->name)}">
  <defs>
    <linearGradient id="{$id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{$discTop}"/><stop offset="1" stop-color="{$discBottom}"/></linearGradient>
    <clipPath id="{$id}c"><circle cx="50" cy="50" r="50"/></clipPath>
  </defs>
  <circle cx="50" cy="50" r="50" fill="url(#{$id})"/>
  <g clip-path="url(#{$id}c)">
    {$behind}
    <!-- jacket, lapels, shirt -->
    <path d="M12 104c0-19 12-30 27-33l11 7 11-7c15 3 27 14 27 33z" fill="{$jacket}"/>
    <path d="M39 71l11 7-7 13-9-15z" fill="{$lapel}"/>
    <path d="M61 71l-11 7 7 13 9-15z" fill="{$lapel}"/>
    <path d="M43 68l7 10 7-10-3-2h-8z" fill="#ffffff"/>
    <!-- neck -->
    <path d="M43 60h14v9c0 4-3 7-7 7s-7-3-7-7z" fill="{$shade}"/>
    <!-- head -->
    <ellipse cx="32.5" cy="47" rx="3" ry="3.6" fill="{$skin}"/>
    <ellipse cx="67.5" cy="47" rx="3" ry="3.6" fill="{$skin}"/>
    <path d="M32 44c0-12 8-21 18-21s18 9 18 21c0 10-5 20-18 20S32 54 32 44z" fill="{$skin}"/>
    {$front}
    <!-- features -->
    <path d="M39.5 43.5q3.5-1.6 7 0M53.5 43.5q3.5-1.6 7 0" stroke="{$hair}" stroke-width="1.1" fill="none" stroke-linecap="round" opacity=".8"/>
    <circle cx="43" cy="47.5" r="1.4" fill="#2b2118"/>
    <circle cx="57" cy="47.5" r="1.4" fill="#2b2118"/>
    <path d="M50 48v5.5" stroke="{$shade}" stroke-width="1" stroke-linecap="round"/>
    <path d="M46 57q4 3 8 0" stroke="#9a5a4a" stroke-width="1.2" fill="none" stroke-linecap="round"/>
    {$specs}
  </g>
</svg>
SVG;
    }

    /** A hex colour, darkened by a fraction, for the shadow under the chin. */
    private function darken(string $hex, float $amount): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', (int) ($r * (1 - $amount)), (int) ($g * (1 - $amount)), (int) ($b * (1 - $amount)));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
