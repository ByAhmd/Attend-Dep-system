<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | How long a browser should refuse to reach this site over plain HTTP,
    | in seconds. One year by default: this application cannot work over
    | HTTP at all, because a browser will not report a position outside a
    | secure context, so there is no future in which we would want a phone
    | to try it.
    |
    | It is a setting rather than a constant for one reason. The promise is
    | remembered by the browser and cannot be withdrawn early - lowering it
    | only helps visitors who come back and read the new value - so the
    | careful way to switch it on for the first time is a few minutes,
    | confirm that every path into the site is genuinely HTTPS, and then
    | remove the override and let the default stand. See DEPLOYMENT.md.
    |
    | The header is sent in production only. Elsewhere it is meaningless at
    | best: a browser must ignore it when it arrives over HTTP, and pinning
    | a developer's own hostname for a year is a trap with nothing behind it.
    |
    */

    'hsts' => [
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
    ],

];
