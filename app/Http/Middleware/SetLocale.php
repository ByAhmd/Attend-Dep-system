<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language the visitor chose.
 *
 * The choice lives in a cookie written by SwitchLocaleController, so it
 * works before sign-in on the login page and survives sign-out. Anything
 * that is not one of the two supported languages leaves the configured
 * default in place. Registered on both panels as persistent middleware, so
 * Livewire re-renders (Check In, table pages) keep the language too.
 */
final class SetLocale
{
    public const string COOKIE = 'attendance_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = Locale::tryFrom((string) $request->cookie(self::COOKIE));

        if ($chosen instanceof Locale) {
            app()->setLocale($chosen->value);
        }

        return $next($request);
    }
}
