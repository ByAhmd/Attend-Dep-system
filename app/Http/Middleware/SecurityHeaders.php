<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers that constrain what a browser will do with a page of
 * this application.
 *
 * Registered globally in bootstrap/app.php rather than on the `web` group,
 * because both Filament panels declare their own middleware stacks and never
 * join that group - and the panels are the entire product. Global also means
 * the headers reach the two things that are not panel pages: the language
 * switch redirect and the leave attachment download, and the error pages,
 * which are rendered inside the pipeline and travel back out through here
 * like any other response.
 *
 * Every header except Strict-Transport-Security is sent in every
 * environment. A policy that only exists in production is a policy nobody
 * has tested; a developer should meet exactly the Content-Security-Policy an
 * employee will meet, and find out on their own machine when something in it
 * is wrong.
 */
final class SecurityHeaders
{
    /**
     * The Content-Security-Policy, one directive per entry.
     *
     * Read this as two halves. The first half is genuinely strict and is
     * where the value is. The second half - script and style - is honestly
     * weak, and pretending otherwise would be worse than saying so.
     *
     * What is strict, and what each line actually buys:
     *
     * `default-src 'self'` is the floor every directive not named below
     * falls back to, so a resource type nobody thought about is same-origin
     * by default rather than open.
     *
     * `base-uri 'self'` stops an injected <base> tag, which is the cheapest
     * way to turn every relative script URL on the page - all of Filament's
     * and Livewire's - into a request to somebody else's server.
     *
     * `object-src 'none'`: there is no plugin content here, and <object> and
     * <embed> are a well-worn route around a script policy.
     *
     * `frame-ancestors 'none'`: nothing may frame this site. See the
     * X-Frame-Options header below for why the same rule is stated twice.
     *
     * `form-action 'self'`: an injected form cannot post the sign-in fields
     * to an attacker. Every real form here posts to this origin, and the
     * language switch is a link.
     *
     * `connect-src 'self' blob:` and `img-src 'self' data: blob:` are the
     * pair that matter most given how weak the script directive is: a script
     * that did get onto the page cannot fetch to an outside host and cannot
     * beacon out through an image URL either. `data:` is there for the
     * avatar, which InitialsAvatarProvider draws into a data: URI instead of
     * fetching a picture of somebody's initials from a third party. `blob:`
     * is for the leave attachment upload - Filament's file field is
     * FilePond, which previews a chosen JPEG or PNG through an object URL.
     *
     * `worker-src 'self' blob:` is for the same upload and is the directive
     * most easily missed: FilePond builds its image-preview worker by
     * compiling a Blob and handing it to `new Worker`, and a policy without
     * this line breaks attaching a photograph to a leave request while
     * leaving every other screen working perfectly.
     *
     * `font-src 'self'` is only possible because the typeface is served from
     * this origin now; while it came from fonts.bunny.net this line had to
     * name a third party. The two changes are one change.
     *
     * `upgrade-insecure-requests` is kept because Hostinger's CDN injects a
     * policy containing exactly that and nothing else. Two Content-Security-
     * Policy headers are intersected rather than merged, so whichever way
     * the edge treats ours - appending to it or replacing it - keeping this
     * directive means the behaviour the site has today is never lost. The
     * intersection also costs nothing: their policy carries no fetch
     * directive at all, so it can restrict nothing that ours does not.
     *
     * And what is not strict. `script-src` carries 'unsafe-inline' and
     * 'unsafe-eval', and `style-src` carries 'unsafe-inline'. This is
     * Filament, which is Livewire and Alpine: Livewire writes inline
     * <script> and <style> into the page, Alpine reads its expressions out
     * of markup attributes and compiles them with `new Function`, and this
     * application's own attendance screen carries an inline <script> of its
     * own. A nonce would make 'unsafe-inline' inert without removing the
     * need for 'unsafe-eval', and would mean forking Filament's Blade to
     * thread the nonce through every component it renders. So script
     * injection is not what this policy defends against. What it does is
     * remove every way an injected script could reach a server that is not
     * this one, and remove the tricks that turn a single injection into a
     * loaded remote payload.
     *
     * @var array<int, string>
     */
    private const array CONTENT_SECURITY_POLICY = [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "connect-src 'self' blob:",
        "worker-src 'self' blob:",
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        'upgrade-insecure-requests',
    ];

    /**
     * The browser capabilities this origin claims, and the ones it gives up.
     *
     * `geolocation=(self)` is the whole product and the one entry in this
     * file that must never be wrong. An employee opens the site on a phone
     * and the page calls navigator.geolocation.watchPosition; a policy that
     * omitted geolocation, or wrote it as `()`, would refuse every check-in
     * in the company with an error the employee cannot do anything about.
     * `(self)` is documents of this origin, which is exactly the attendance
     * page and nothing else.
     *
     * Everything else here is a capability that reads data or reaches
     * hardware, and that this product has no use for: the cameras and
     * microphones and screen capture it never records with; the sensors and
     * local font list that are used for fingerprinting rather than for
     * anything a user asked for; the device buses; payment; WebAuthn, which
     * this application does not use and which is worth denying precisely
     * because it is a credential; idle detection, which reports whether
     * somebody is at their desk and would be a genuinely ugly thing for this
     * of all products to be able to ask; and the advertising topics API.
     *
     * Deliberately absent are the interface capabilities - fullscreen,
     * autoplay, picture-in-picture, the clipboard. None of them reads
     * anything, denying them buys nothing, and the clipboard in particular
     * is how an administrator copies an invitation link.
     *
     * @var array<int, string>
     */
    private const array PERMISSIONS_POLICY = [
        'geolocation=(self)',
        'accelerometer=()',
        'bluetooth=()',
        'browsing-topics=()',
        'camera=()',
        'display-capture=()',
        'gyroscope=()',
        'hid=()',
        'idle-detection=()',
        'local-fonts=()',
        'magnetometer=()',
        'microphone=()',
        'midi=()',
        'payment=()',
        'publickey-credentials-get=()',
        'screen-wake-lock=()',
        'serial=()',
        'usb=()',
        'xr-spatial-tracking=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP adds X-Powered-By itself when expose_php is on, which it is on
        // this host and is not ours to change: it is a php.ini directive on
        // shared hosting. The header never enters the response object, so
        // there is nothing in the header bag to unset - it lives in the list
        // the SAPI will send, and header_remove() is what reaches that list.
        // Safe here because nothing has been sent yet: the response is
        // written after the whole middleware stack has returned, and that
        // holds for the streamed attachment download too.
        header_remove('X-Powered-By');

        $headers = $response->headers;

        // The site policy is a default, not a ceiling. A response that has
        // already named a policy of its own said something narrower about
        // itself than this file can: the leave attachment download answers
        // with `default-src 'none'; sandbox`, because those bytes came from
        // outside and are handed back from our own origin, and replacing
        // that with the policy a Filament page needs - inline script, eval,
        // blob workers - would be a straight loss. So the response wins.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::contentSecurityPolicy());
        }

        $headers->set('Permissions-Policy', implode(', ', self::PERMISSIONS_POLICY));

        // The attachment on a leave request is the only file this product
        // accepts from anybody, and it is handed back to a browser under a
        // type taken from what was uploaded. nosniff is what keeps a browser
        // from looking past that type and deciding for itself that a file
        // named .pdf is really a page to be rendered - inside this origin,
        // with this session behind it.
        $headers->set('X-Content-Type-Options', 'nosniff');

        // The same rule as frame-ancestors above, in the older dialect. A
        // browser that understands frame-ancestors ignores this line
        // entirely, so it is not a second policy to keep in step; it is
        // cover for the embedded browsers and filtering proxies that still
        // read only this one. An administration panel that can be framed can
        // be clicked through by whoever framed it.
        $headers->set('X-Frame-Options', 'DENY');

        // Full referrer within this origin, nothing at all leaving it. The
        // addresses here name people and records - /admin/employees/7/edit,
        // /leave-requests/12/attachment - and the product's one outbound
        // link is the Google Maps URL an administrator follows from a
        // recorded position. Under the browser default that click would
        // still announce this site to Google; under this policy it announces
        // nothing. Same-origin navigation keeps its referrer, so nothing
        // that reads it changes behaviour.
        $headers->set('Referrer-Policy', 'same-origin');

        if (! app()->isProduction()) {
            return $response;
        }

        // Production is HTTPS by definition here, because a browser refuses
        // to report a position outside a secure context. Neither
        // includeSubDomains nor preload: the first would reach names under
        // this host that do not exist today and would quietly widen if the
        // application were ever moved to the parent domain, whose other
        // occupants are not ours to make promises about; the second is a
        // submission to a list built into browser releases, which takes
        // months to leave and is a commitment out of all proportion to one
        // internal site.
        $headers->set(
            'Strict-Transport-Security',
            'max-age='.(int) config('security.hsts.max_age'),
        );

        return $response;
    }

    /**
     * The policy as one header value.
     *
     * Public because it is stated in two places and only one of them is
     * PHP: Hostinger's web server replaces this header after the
     * application has answered, so public/.htaccess sets it again at a
     * layer that runs later. This accessor is what lets a test hold the two
     * copies side by side and fail when they stop agreeing.
     */
    public static function contentSecurityPolicy(): string
    {
        return implode('; ', self::CONTENT_SECURITY_POLICY);
    }
}
