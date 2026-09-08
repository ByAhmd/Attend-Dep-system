<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The avatar in the top bar, drawn here rather than fetched.
 *
 * Filament ships UiAvatarsProvider, which points the <img> at
 * ui-avatars.com. That is one third-party request on every page load of a
 * product whose whole promise is that nothing about an employee leaves the
 * company: the request carries the person's initials in its query string,
 * it becomes a broken image on a network that blocks the host, and what it
 * returns is a pure black disc - Filament asks it for gray-950 - which is
 * the only element on the screen outside this product's palette and which
 * disappears entirely against the dark theme's own top bar.
 *
 * This provider returns a data: URI instead, so the avatar is part of the
 * HTML the server already sent and no request is made at all. Filament's
 * getUserAvatarUrl() passes a "data:image/" value through untouched, so
 * there is nothing to configure beyond naming this class on the panel.
 *
 * The disc carries its own background, which is why one image serves both
 * themes: white initials on primary-600 measure 5.33:1 wherever the disc is
 * put, clearing the 4.5:1 WCAG AA minimum on the light top bar and on the
 * dark one alike, and the disc itself measures 3.0:1 or better against
 * either surface behind it. A theme-aware avatar is impossible in an <img>
 * anyway - the document inside it cannot see the page's colour scheme.
 */
final readonly class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * The brand shade the disc is filled with, and the fallback if the panel
     * ever fails to register a primary ramp.
     *
     * primary-600 is the shade ConfiguresPanel measured for text on white;
     * here it is the background under white text, which is the same
     * measurement read the other way round.
     */
    private const string FALLBACK_BACKGROUND = '#6c4cf3';

    private const string FOREGROUND = '#ffffff';

    /**
     * Heroicon's solid "user", the glyph the rest of this panel already uses
     * for a person. Drawn on its own 24-unit grid, so it is placed by
     * translating it into the middle of the 64-unit disc.
     */
    private const string PERSON_GLYPH = 'M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z';

    /**
     * The webfont the panel loads is not available to the document inside an
     * <img>, so the stack here is the same real Arabic-capable fallback the
     * theme names after it: Segoe UI and Noto Sans Arabic for Windows and
     * Android, system-ui for SF Arabic on Apple devices.
     */
    private const string FONT_STACK = "'Segoe UI','Noto Sans Arabic',system-ui,sans-serif";

    public function get(Model|Authenticatable $record): string
    {
        $initials = self::initials(Filament::getNameForDefaultAvatar($record));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" rx="32" fill="'.self::background().'"/>'
            .($initials === ''
                ? '<path transform="translate(20 20)" fill="'.self::FOREGROUND.'" d="'.self::PERSON_GLYPH.'"/>'
                : '<text x="32" y="32" text-anchor="middle" dominant-baseline="central"'
                    .' font-family="'.self::FONT_STACK.'" font-size="26" font-weight="600"'
                    .' fill="'.self::FOREGROUND.'">'.htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8').'</text>')
            .'</svg>';

        // Base64 rather than a percent-encoded payload: the initials are
        // Arabic more often than not, and a UTF-8 run inside an attribute
        // value is one encoding argument nobody needs to have.
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The panel's own primary ramp, so the disc follows the identity rather
     * than keeping a private copy of it.
     *
     * Read from the panel rather than from FilamentColor: the panel carries
     * its declared colours from the moment it is registered, while the
     * global registry is only filled when a panel boots - so asking the
     * registry outside a request answers with Filament's stock amber, and
     * the avatar would be the one part of this product whose colour depended
     * on how it was reached. The literal shade below is the fallback for a
     * panel configured without a primary ramp at all; an avatar must not be
     * the thing that throws.
     */
    private static function background(): string
    {
        $primary = Filament::getCurrentOrDefaultPanel()?->getColors()['primary'] ?? null;

        if (! is_array($primary) || ! isset($primary[600]) || ! is_string($primary[600])) {
            return self::FALLBACK_BACKGROUND;
        }

        return Color::convertToHex($primary[600]);
    }

    /**
     * The first letter of the first name and the first letter of the last,
     * or one letter for a single-word name, or nothing at all - in which
     * case the caller draws the person glyph instead.
     *
     * Empty is a real answer, not a failure: a name may be a single emoji,
     * or punctuation, and "?" on a disc reads as an error the reader is
     * expected to do something about.
     */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        $letters = [];

        foreach ($words as $word) {
            $letter = self::firstLetter($word);

            if ($letter !== '') {
                $letters[] = $letter;
            }
        }

        if ($letters === []) {
            return '';
        }

        // Joined by a space so two Arabic letters stand as two initials
        // rather than joining into the ligature they would form side by
        // side. The pair needs no direction of its own: two Arabic letters
        // are one right-to-left run and two Latin ones are a left-to-right
        // run, so each is laid out first-initial-first on its own.
        return count($letters) === 1
            ? $letters[0]
            : $letters[0].' '.$letters[count($letters) - 1];
    }

    /**
     * One word's initial.
     *
     * Leading punctuation is skipped, as Filament's own provider does, so a
     * "[SYSTEM] Admin" convention does not turn into a bracket. The Arabic
     * definite article is skipped for the same reason: nearly every family
     * name in this company begins with it, and an "ا" as the second initial
     * would identify absolutely nobody.
     */
    private static function firstLetter(string $word): string
    {
        $word = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word);

        if (mb_strlen($word) > 2 && mb_substr($word, 0, 2) === 'ال') {
            $word = mb_substr($word, 2);
        }

        return $word === '' ? '' : mb_strtoupper(mb_substr($word, 0, 1));
    }
}
