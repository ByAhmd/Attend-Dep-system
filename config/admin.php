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
    | The one account that may delete an account, appoint or remove other
    | administrators, and deactivate one, reset one's password or change the
    | address one signs in with, and that nobody may deactivate, demote or
    | delete.
    |
    | That last power is the reason this setting is safe to be an address:
    | no administrator can move it off the row that holds it, so the pin
    | cannot be claimed from inside the product.
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
    | can be deleted or promoted through the interface at all, and no
    | administrator can be deactivated, given a new password, or have their
    | address corrected there either. An administrator switched off while
    | the setting is blank stays off, and one who forgets their password has
    | no route back in through the site.
    |
    | The employees are unaffected: every administrator keeps every power
    | over their accounts. The way out is this line - set it and run
    | `php artisan config:clear`. `php artisan app:create-admin` is not that
    | way out: it mints a new administrator at a new address, which keeps a
    | deployment running but does not reach the stranded account.
    |
    | Read through config() and never through env() outside this file, so it
    | survives `php artisan config:cache` on the server.
    |
    */

    'super_admin_email' => env('SUPER_ADMIN_EMAIL'),

];
