<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bootstrap administrator
    |--------------------------------------------------------------------------
    |
    | The first administrator may be provisioned from the environment when the
    | database is seeded. Seeders read config(), never env() directly, so the
    | mapping lives here. Leave the values blank to skip, and prefer
    | `php artisan app:create-admin` on a production server.
    |
    */

    'name' => env('ADMIN_NAME'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
