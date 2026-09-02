<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Browser geolocation only works in a secure context, so production
        // is HTTPS by definition. Generating https URLs here keeps redirects
        // and asset links on the secure origin behind any proxy or CDN.
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
