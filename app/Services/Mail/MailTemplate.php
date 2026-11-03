<?php

namespace App\Services\Mail;

/**
 * Fills a pair of plain templates in resources/mail: name.html and name.txt.
 *
 * Plain files rather than Blade, so whoever edits the wording does not need
 * to know Blade — and so {{first_name}} is a placeholder, not PHP. Every value
 * is escaped in the HTML version: a name is typed by a person, and
 * "<b>Ana</b>" in a staff record must arrive as text, not markup.
 *
 * The look (Jordan, 31 Oct 2026): a periwinkle page, the centre's logo, a
 * white note card and a butter-yellow slip for the password or the expiry, in
 * the app's own palette (resources/css/app.css: la-accent #1b5e91, la-ink,
 * la-muted, la-warn-*). Tables, bgcolor and inline styles only, for Outlook.
 * The logo is the one image, and it travels inside the email (LOGO_CID), so no
 * client has to fetch it from our server. Notes about the design live here,
 * not as HTML comments, which would go out in every email.
 */
class MailTemplate
{
    /**
     * The centre's logo, sent inside the email and shown with
     * src="cid:angelnest-logo". A 360px copy of public/images/littleangels-logo.png
     * (shown at 180px, so sharp on a phone) at about half the weight.
     */
    public const LOGO_CID = 'angelnest-logo';

    /**
     * @param  list<string>  $placeholders  Every one must have a value.
     * @param  array<string, string>  $values
     * @return array{html: string, text: string, inline: list<array{cid: string, path: string, type: string}>}
     */
    public static function fill(string $name, array $placeholders, array $values): array
    {
        $missing = array_diff($placeholders, array_keys($values));

        if ($missing !== []) {
            throw new \InvalidArgumentException("The {$name} email is missing: ".implode(', ', $missing));
        }

        $html = $text = [];

        foreach ($placeholders as $key) {
            $html['{{'.$key.'}}'] = e((string) $values[$key]);
            $text['{{'.$key.'}}'] = (string) $values[$key];
        }

        // strtr, not str_replace: one pass, so a value that happens to contain
        // "{{signin_url}}" is left as typed.
        $filled = [
            'html' => strtr(file_get_contents(resource_path("mail/{$name}.html")), $html),
            'text' => strtr(file_get_contents(resource_path("mail/{$name}.txt")), $text),
            'inline' => [],
        ];

        if (str_contains($filled['html'], 'cid:'.self::LOGO_CID)) {
            $filled['inline'][] = ['cid' => self::LOGO_CID, 'path' => resource_path('mail/logo.png'), 'type' => 'image/png'];
        }

        return $filled;
    }
}
