<?php

namespace App\Services;

/**
 * The first password on a staff account, which somebody has to read off a
 * screen or an email and type into a phone.
 *
 * So: no characters that look like each other (0/O, 1/l/I), and grouped in
 * fours with dashes, the way a licence key is — a teacher reading it aloud to
 * a colleague should not have to say "lowercase L, not one".
 */
class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(int $groups = 3): string
    {
        $chunks = [];

        for ($group = 0; $group < $groups; $group++) {
            $chunk = '';

            for ($i = 0; $i < 4; $i++) {
                $chunk .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $chunks[] = $chunk;
        }

        return implode('-', $chunks);
    }
}
