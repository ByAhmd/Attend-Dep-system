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

    /*
    |--------------------------------------------------------------------------
    | Super administrator
    |--------------------------------------------------------------------------
    |
    | The one account that may delete an account and appoint or remove other
    | administrators, and that nobody may deactivate, demote or delete.
    |
    | It is an address pinned in the server's .env, not a column in the
    | database, and that is the whole point: a row can be edited by another
    | administrator or by anybody holding phpMyAdmin, whereas .env can only
    | be changed by whoever can reach the server. Whoever holds this address
    | is the super administrator regardless of what `users.role` and
    | `users.status` say, so tampering with those columns cannot lock the
    | owner out.
    |
    | Leave it blank and the system has no super administrator: no account
    | can be deleted or promoted through the interface at all.
    |
    | Read through config() and never through env() outside this file, so it
    | survives `php artisan config:cache` on the server.
    |
    */

    'super_admin_email' => env('SUPER_ADMIN_EMAIL'),

];
