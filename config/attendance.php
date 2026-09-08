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
    | Presence ping interval
    |--------------------------------------------------------------------------
    |
    | Seconds between the position readings the attendance page takes while a
    | session is open. Five minutes is often enough to show that someone who
    | checked in is still on site, and rare enough that the GPS radio is idle
    | almost all of the time. Pings are supporting evidence only: the page can
    | report a position solely while it is open and the phone is awake, so the
    | gaps between them mean nothing on their own.
    |
    */

    'ping_interval_seconds' => (int) env('ATTENDANCE_PING_INTERVAL_SECONDS', 300),

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

    /*
    |--------------------------------------------------------------------------
    | Default monthly correction allowance
    |--------------------------------------------------------------------------
    |
    | How many attendance corrections one employee may ask for in a Gregorian
    | month. Only the value the settings row is created with; the live figure
    | is edited from the admin panel, because this is a business policy the
    | owner changes without an SSH session - the radius side of the line, not
    | the accuracy-ceiling side. Zero switches correction requests off
    | entirely.
    |
    */

    'default_correction_requests_per_month' => 3,

    /*
    |--------------------------------------------------------------------------
    | Correction allowance bounds
    |--------------------------------------------------------------------------
    |
    | What the settings form will accept. Zero is a real setting and means
    | "no corrections at all". Thirty-one is one request for every day of the
    | longest month; above that a ration nobody can exhaust is an unlimited
    | allowance wearing a number.
    |
    */

    'correction_quota_bounds' => [
        'min' => 0,
        'max' => 31,
    ],

];
