<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use App\Http\Middleware\EnsureAccountIsActive;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What both panels share: colours, brand, theme, and the middleware stack.
 *
 * The auth stack signs out a deactivated account before Filament's own
 * guard would answer 403 to it - see EnsureAccountIsActive. It is marked
 * persistent so Livewire runs it on component updates too: the Check In
 * button is a Livewire call, and a deactivated employee pressing it must
 * be signed out exactly as on a page load, not handed a bare 403.
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
            ->authMiddleware([
                EnsureAccountIsActive::class,
                Authenticate::class,
            ], isPersistent: true);
    }
}
