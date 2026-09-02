<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Http\Controllers\SwitchLocaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Every screen belongs to a Filament panel: the employee panel serves the
| site root (/login, /) and the admin panel serves /admin - see
| app/Providers/Filament. The one route here is the language switch, which
| must work for visitors as well as for signed-in users.
|
*/

Route::get('/locale/{locale}', SwitchLocaleController::class)
    ->whereIn('locale', Locale::values())
    ->name('locale.switch');
