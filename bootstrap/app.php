<?php

use App\Http\Middleware\EnsureAccountIsActive;
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
