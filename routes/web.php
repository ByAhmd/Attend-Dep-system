<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Http\Controllers\LeaveAttachmentController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Every screen belongs to a Filament panel: the employee panel serves the
| site root (/login, /, /requests) and the admin panel serves /admin - see
| app/Providers/Filament. Two things are not screens and live here.
|
| The language switch, which must work for visitors as well as for
| signed-in users; and the download of a leave request's supporting
| document, which is a file and not a page, and which therefore needs a
| route of its own rather than a Livewire component.
|
*/

Route::get('/locale/{locale}', SwitchLocaleController::class)
    ->whereIn('locale', Locale::values())
    ->name('locale.switch');

/*
 * The attachment on one leave request.
 *
 * Declared here rather than left to the automatic storage route Laravel
 * offers for a private disk: that one is registered from a service provider
 * and skipped entirely once routes are cached, which every deployment of
 * this application does, and it authorises by signed URL rather than by
 * asking who is looking. A route written in this file is compiled into the
 * route cache like any other and keeps working there.
 *
 * The middleware is the employee panel's own front door, in the order the
 * panel uses it: the chosen language, then the sign-out of an account that
 * was deactivated while signed in, then authentication - Filament's, so an
 * unauthenticated visitor is redirected to the login page they already know
 * instead of being answered with a bare 401. Authorisation is not here; it
 * is the policy, asked in the controller.
 *
 * The id is constrained to digits so that anything shaped like a path -
 * with slashes, dots or an encoded traversal in it - fails to match this
 * route at all, and is answered 404 by the router before a controller,
 * a model or the filesystem is ever reached.
 */
Route::get('/leave-requests/{leaveRequest}/attachment', LeaveAttachmentController::class)
    ->whereNumber('leaveRequest')
    ->middleware([SetLocale::class, EnsureAccountIsActive::class, Authenticate::class])
    ->name('leave-attachments.show');
