<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global rather than on the `web` group: both Filament panels declare
        // their own middleware stacks and never join that group, and the
        // panels are the whole product.
        //
        // Prepended rather than appended so it is the outermost middleware
        // in the stack. Everything thrown further in - a 404 from the router,
        // a 403 from a policy, the 503 of `artisan down` - is caught by the
        // pipeline and rendered into a response that travels back out through
        // here, so the error pages carry the same headers the ordinary ones
        // do. Appended, it would sit inside the middleware that throw those,
        // and exactly the responses shown to somebody poking at the site
        // would be the ones without a policy on them.
        $middleware->prepend(SecurityHeaders::class);

        // Route middleware is re-sorted by the kernel priority list. Filament's
        // Authenticate carries the AuthenticatesRequests priority and is pulled
        // ahead of anything without a slot - whatever order the panel declares.
        // The sign-out guard needs the slot just before authentication, so a
        // deactivated account is signed out and told why instead of being
        // answered 403 with a live session still behind it.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureAccountIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
