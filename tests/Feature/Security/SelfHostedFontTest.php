<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The typeface belongs to this application now.
 *
 * The point is not tidiness. Until this change every page in the product
 * put a <link> to fonts.bunny.net in its head, which meant an employee in
 * Riyadh paid a DNS lookup, a TLS handshake and a round trip to a host
 * nobody here controls before the stylesheet that names the Arabic faces
 * even arrived - and it meant that what the interface looked like was, in
 * the end, somebody else's uptime.
 *
 * These tests hold that line in three places: no page may name a font host,
 * the panels must emit no font stylesheet at all, and the committed build
 * must actually contain the faces - because the server has no Node, so a
 * theme rebuilt without them would silently fall back to a system font and
 * nothing else would complain.
 */
final class SelfHostedFontTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FAMILY = 'IBM Plex Sans Arabic';

    /**
     * The hosts a webfont is usually fetched from. None of them may appear
     * in a page of this product, whichever one a future edit reaches for.
     *
     * @var array<int, string>
     */
    private const array FONT_HOSTS = [
        'fonts.bunny.net',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'use.typekit.net',
    ];

    #[Test]
    public function the_sign_in_page_names_no_font_host(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        foreach (self::FONT_HOSTS as $host) {
            self::assertStringNotContainsString($host, (string) $html);
        }
    }

    #[Test]
    public function the_attendance_page_names_no_font_host(): void
    {
        $html = $this->actingAs($this->makeEmployee())->get('/')->assertOk()->getContent();

        foreach (self::FONT_HOSTS as $host) {
            self::assertStringNotContainsString($host, (string) $html);
        }
    }

    #[Test]
    public function the_administration_panel_names_no_font_host(): void
    {
        $html = $this->actingAs($this->makeAdmin())->get('/admin')->assertOk()->getContent();

        foreach (self::FONT_HOSTS as $host) {
            self::assertStringNotContainsString($host, (string) $html);
        }
    }

    #[Test]
    public function neither_panel_emits_a_font_stylesheet(): void
    {
        foreach (['employee', 'admin'] as $panel) {
            self::assertSame(
                '',
                trim(Filament::getPanel($panel)->getFontHtml()->toHtml()),
                "The {$panel} panel emitted a font <link>; the faces are in the theme.",
            );
        }
    }

    #[Test]
    public function both_panels_still_ask_for_the_same_family(): void
    {
        // Filament writes this into --font-family, which the theme's
        // --font-sans is built from. Self-hosting must not have changed
        // which typeface the interface is set in, only where it comes from.
        foreach (['employee', 'admin'] as $panel) {
            self::assertSame(self::FAMILY, Filament::getPanel($panel)->getFontFamily());
        }
    }

    #[Test]
    public function the_committed_theme_declares_the_faces_and_ships_the_files(): void
    {
        $css = (string) file_get_contents($this->builtThemePath());

        self::assertStringContainsString(self::FAMILY, $css);

        preg_match_all('#url\((/build/[^)]+\.woff2)\)#', $css, $matches);

        $files = array_unique($matches[1]);

        // Four weights across the Latin and Arabic subsets: the exact set
        // Filament was asking Bunny for, so no screen changes weight.
        self::assertCount(8, $files);

        foreach ($files as $file) {
            $path = public_path(ltrim($file, '/'));

            self::assertFileExists($path, "The theme names {$file}, which is not in the committed build.");

            // wOF2. A truncated or placeholder file would still exist.
            self::assertSame('wOF2', (string) file_get_contents($path, false, null, 0, 4));
        }
    }

    #[Test]
    public function every_face_is_declared_with_a_swap_so_text_is_never_invisible(): void
    {
        $css = (string) file_get_contents($this->builtThemePath());

        $faces = preg_match_all('/@font-face\{[^}]*'.preg_quote(self::FAMILY, '/').'[^}]*\}/', $css, $matches);

        self::assertSame(8, $faces);

        foreach ($matches[0] as $face) {
            self::assertStringContainsString('font-display:swap', str_replace(' ', '', $face));
        }
    }

    #[Test]
    public function the_policy_can_therefore_confine_fonts_to_this_origin(): void
    {
        // The coupling stated as a test: while the faces came from a third
        // party this directive had to name that party, and a scanner was
        // right to complain about it.
        self::assertStringContainsString(
            "font-src 'self'",
            (string) $this->get('/login')->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * The theme as the server will serve it, found through the manifest so
     * the test follows the fingerprint rather than a hard-coded file name.
     */
    private function builtThemePath(): string
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('build/manifest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest);
        self::assertArrayHasKey('resources/css/filament/theme.css', $manifest);

        $entry = $manifest['resources/css/filament/theme.css'];

        self::assertIsArray($entry);

        return public_path('build/'.$entry['file']);
    }
}
