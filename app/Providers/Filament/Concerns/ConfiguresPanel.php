<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use App\Enums\Locale;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Support\Filament\LanguageMenuItems;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What both panels share: colours, brand, theme, the language switch, and
 * the middleware stack.
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
    protected function applyPresentation(Panel $panel): Panel
    {
        return $panel
            ->colors([
                'primary' => Color::Blue,
            ])
            ->darkMode()
            ->brandName(fn (): string => (string) __('app.name'))
            ->viteTheme('resources/css/filament/theme.css')
            ->userMenuItems(LanguageMenuItems::userMenuActions())
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
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
