<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Remembers the visitor's language for a year and sends them back to the
 * page they were on.
 *
 * The route constraint already limits the parameter to the supported
 * languages; the enum lookup here is what makes that a typed guarantee.
 * The return address is honoured only when it is on this site, so the link
 * can never be used to bounce someone elsewhere.
 */
final class SwitchLocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $chosen = Locale::tryFrom($locale) ?? abort(404);

        Cookie::queue(Cookie::forever(SetLocale::COOKIE, $chosen->value));

        return redirect()->to($this->returnUrl($request));
    }

    private function returnUrl(Request $request): string
    {
        $previous = url()->previous();

        return parse_url($previous, PHP_URL_HOST) === $request->getHost()
            ? $previous
            : url('/');
    }
}
