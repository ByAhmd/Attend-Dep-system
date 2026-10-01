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

    /*
    |--------------------------------------------------------------------------
    | Default working day
    |--------------------------------------------------------------------------
    |
    | When the official working day starts and ends, and how many minutes
    | after the start an arrival is still not marked late. Only the values
    | the settings row is created with; the live working day is edited from
    | the admin panel. The brief fixes it at 09:00–17:00 with lateness from
    | 09:30, so the default grace is 30 minutes. A check-out before the end
    | of the day is accepted only together with a reason.
    |
    */

    'default_work_starts_at' => '09:00',

    'default_work_ends_at' => '17:00',

    'default_late_grace_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Late grace bounds
    |--------------------------------------------------------------------------
    |
    | What the settings form will accept. Zero is a real setting and means
    | an arrival a second past the start is already late. Four hours is the
    | ceiling because a grace nobody can exceed is lateness switched off,
    | and that is not a setting this product offers.
    |
    */

    'late_grace_bounds' => [
        'min' => 0,
        'max' => 240,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default weekend
    |--------------------------------------------------------------------------
    |
    | The days of the week nobody is expected at the company, as the value
    | the settings row is created with; the live weekend is edited from the
    | admin panel. Friday and Saturday, because that is the Saudi weekend.
    | The absence list and the monthly report stay silent on these days.
    |
    */

    'default_weekend_days' => ['friday', 'saturday'],

    /*
    |--------------------------------------------------------------------------
    | Default annual leave allowance
    |--------------------------------------------------------------------------
    |
    | Working days of annual leave per Gregorian year, as the value the
    | settings row is created with; the live figure is edited from the
    | admin panel, and an employee's account may carry its own override.
    | 21 working days is the Saudi labour law minimum. The balance informs
    | the person deciding a request; it never refuses one by itself.
    |
    */

    'default_annual_leave_days' => 21,

    /*
    |--------------------------------------------------------------------------
    | Annual leave bounds
    |--------------------------------------------------------------------------
    |
    | What the settings form and the employee override will accept. Zero is
    | a real setting - no annual allowance at all - and a year has no more
    | than 365 days to allow.
    |
    */

    'annual_leave_bounds' => [
        'min' => 0,
        'max' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | `php artisan app:backup` dumps the database with the binary named
    | here - a bare name found on PATH, or a full path on a host that
    | hides its client tools - and keeps the newest `keep` sets under
    | storage/app/backups. The command writes to the local disk only;
    | copying a set off the server is deliberately left to a human or the
    | host panel, because a backup beside its database shares its disk.
    |
    */

    'backup' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'keep' => (int) env('BACKUP_KEEP', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Require two-factor authentication for administrators
    |--------------------------------------------------------------------------
    |
    | When true (the default, and what production runs), the admin panel
    | walks an administrator without an authenticator app through enrolment
    | and every sign-in asks for the code. The test suite switches it off
    | in phpunit.xml so panel tests need no enrolled authenticator; the
    | tests that cover the requirement turn it back on themselves.
    |
    */

    'require_admin_mfa' => (bool) env('ATTENDANCE_REQUIRE_ADMIN_MFA', true),

];
