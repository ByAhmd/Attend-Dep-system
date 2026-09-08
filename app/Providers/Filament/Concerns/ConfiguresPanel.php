<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use App\Enums\Locale;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Support\Filament\InitialsAvatarProvider;
use App\Support\Filament\LanguageMenuItems;
use Filament\FontProviders\BunnyFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What both panels share: colours, typeface, brand, theme, the language
 * switch, and the middleware stack.
 *
 * SetLocale runs after the cookie and session middleware and is persistent,
 * so Livewire re-renders keep the chosen language. The auth stack signs out
 * a deactivated account before Filament's own guard would answer 403 to it
 * - see EnsureAccountIsActive - and is persistent for the same reason: the
 * Check In button is a Livewire call, and a deactivated employee pressing
 * it must be signed out exactly as on a page load, not handed a bare 403.
 */
trait ConfiguresPanel
{
    /**
     * The Makani primary ramp: one indigo-violet hue (OKLCH 284.5) carried
     * across eleven shades, written out rather than taken from Filament's
     * stock set so the identity belongs to this product.
     *
     * The hue sits between Filament's Indigo (277) and Violet (293), close
     * to the Zinc grey the interface is built on (285.8), so brand surfaces
     * and neutral ones read as one family. It is deliberately far from the
     * three colours that carry meaning here - green for inside the area,
     * amber for a missing check-out, red for a refused attempt - which stay
     * exactly as Filament ships them: a primary button must never be
     * mistaken for a verdict.
     *
     * The two shades that carry text are measured, not guessed: 600 gives
     * 5.30:1 on white, and 400 gives 7.69:1 on the Zinc 950 page and 6.86:1
     * on the Zinc 900 surfaces above it. All three clear the 4.5:1 WCAG AA
     * minimum for body text, so the ramp is safe at both ends before
     * Filament reaches it - and Filament then re-picks a shade per component
     * against the surface it actually lands on (Color::findShade), which can
     * only hold the contrast it already has.
     *
     * @var array<int, string>
     */
    private const PRIMARY_COLOR = [
        50 => 'oklch(0.976 0.013 284.5)',   // #f6f6ff
        100 => 'oklch(0.946 0.031 284.5)',  // #eaebff
        200 => 'oklch(0.901 0.06 284.5)',   // #d9daff
        300 => 'oklch(0.831 0.106 284.5)',  // #bfbeff
        400 => 'oklch(0.727 0.17 284.5)',   // #9d94ff
        500 => 'oklch(0.635 0.213 284.5)',  // #826eff
        600 => 'oklch(0.553 0.235 284.5)',  // #6c4cf3
        700 => 'oklch(0.487 0.222 284.5)',  // #5b39d5
        800 => 'oklch(0.425 0.187 284.5)',  // #4a31ae
        900 => 'oklch(0.377 0.152 284.5)',  // #3d2c8d
        950 => 'oklch(0.271 0.104 284.5)',  // #241a56
    ];

    protected function applyPresentation(Panel $panel): Panel
    {
        return $panel
            ->colors([
                'primary' => self::PRIMARY_COLOR,
            ])
            // Arabic and Latin drawn on one skeleton: the screens mix a time
            // like 08:02 with the label beside it in every row, and Plex
            // Arabic keeps the two scripts on the same rhythm and its digits
            // unambiguous at table sizes. Bunny is Filament's own provider
            // for a custom family, serves the Arabic and Latin subsets
            // separately behind unicode-range, and sets no cookies.
            ->font('IBM Plex Sans Arabic', provider: BunnyFontProvider::class)
            ->darkMode()
            ->brandName(fn (): string => (string) __('app.name'))
            ->brandLogo(fn (): View => view('filament.partials.brand-mark'))
            ->brandLogoHeight('1.75rem')
            ->favicon(fn (): string => asset('favicon.svg'))
            ->viteTheme('resources/css/filament/theme.css')
            // Drawn here, not fetched from ui-avatars.com - see the provider
            // for what that request was costing.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->userMenuItems(LanguageMenuItems::userMenuActions())
            // Every signed-out screen - sign in, and the reset page an
            // invited employee lands on - is a Filament "simple" page, so
            // one hook puts the language switch on all of them. The user
            // menu that carries it afterwards does not exist yet, and the
            // person who cannot read the page is the one who needs it most.
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn (): View => view('filament.partials.language-switch', [
                    'other' => Locale::current()->other(),
                ]),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                SetLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                EnsureAccountIsActive::class,
                Authenticate::class,
            ], isPersistent: true);
    }
}
