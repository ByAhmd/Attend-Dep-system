<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default attendance radius
    |--------------------------------------------------------------------------
    |
    | Metres from the company location within which check-in and check-out
    | are accepted. This is only the value the settings row is created with;
    | the administrator changes the live radius from the admin panel. The
    | brief fixes the default at 150 metres.
    |
    */

    'default_radius_meters' => 150,

    /*
    |--------------------------------------------------------------------------
    | Maximum accepted location accuracy
    |--------------------------------------------------------------------------
    |
    | The browser reports the radius, in metres, within which it is confident
    | the device is. A reading looser than this says nothing useful about a
    | 150-metre boundary and is refused with a request to enable precise
    | location. 100 m accepts a phone indoors on Wi-Fi positioning and rejects
    | the kilometre-scale guesses that IP-based positioning produces.
    |
    */

    'max_accuracy_meters' => (int) env('ATTENDANCE_MAX_ACCURACY_METERS', 100),

    /*
    |--------------------------------------------------------------------------
    | Radius bounds
    |--------------------------------------------------------------------------
    |
    | What the settings form will accept. Below 20 m a normal phone cannot
    | reliably be inside; above 5 km the check no longer means "at work".
    |
    */

    'radius_bounds' => [
        'min' => 20,
        'max' => 5000,
    ],

];
