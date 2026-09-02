<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that was deactivated while signed in.
 *
 * Filament's Authenticate middleware would answer 403 through
 * canAccessPanel(); this runs first so the person is signed out and told
 * why, instead of being left on an error page with a live session behind it.
 * Registered on both panels, ahead of Authenticate.
 */
final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user instanceof User && ! $user->isActive()) {
            Filament::auth()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Notification::make()
                ->title(__('auth.inactive'))
                ->danger()
                ->send();

            return redirect()->to(Filament::getLoginUrl() ?? '/');
        }

        return $next($request);
    }
}
