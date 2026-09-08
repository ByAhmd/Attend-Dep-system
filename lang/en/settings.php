<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Attendance settings',
        'model' => 'Attendance settings',
        'plural_model' => 'Attendance settings',
    ],

    'sections' => [
        'location' => 'Company location',
        'radius' => 'Allowed radius',
        'corrections' => 'Correction requests',
    ],

    'fields' => [
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'radius_meters' => 'Allowed radius (meters)',
        'radius_suffix' => 'm',
        'correction_requests_per_month' => 'Correction requests per month',
    ],

    /*
     | The company's own coordinates, shown as the shape a pasted value
     | should have: a latitude near 24 and a longitude near 46 in Riyadh,
     | so a pair entered the wrong way round looks wrong before it is saved.
     */
    'placeholders' => [
        'latitude' => '24.7136000',
        'longitude' => '46.6753000',
    ],

    'helpers' => [
        'coordinates' => 'Open Google Maps, press and hold on the company entrance, then copy the two numbers shown (for example 24.7136, 46.6753).',
        'radius' => 'Default 150 meters. Employees must be within this distance of the coordinates above to check in or out.',
        'not_configured' => 'Attendance cannot be recorded until the company location is set.',
        'preview' => 'Open the configured location in a map',
        'correction_requests_per_month' => 'The most requests one employee may send in a Gregorian month. A request counts whether it is approved or rejected. Zero switches correction requests off entirely.',
    ],

    'validation' => [
        'latitude' => 'Latitude must be a number between -90 and 90.',
        'longitude' => 'Longitude must be a number between -180 and 180.',
        'radius' => 'The radius must be a whole number between :min and :max meters.',
        'correction_quota_required' => 'Give a number, even if it is zero.',
        'correction_quota_min' => 'The lowest value is zero, which switches correction requests off.',
        'correction_quota_max' => 'The highest value is :max, one for every day of the month.',
        'correction_quota_integer' => 'Give a whole number.',
    ],

    'notifications' => [
        'saved' => 'Attendance settings saved',
    ],

    'pages' => [
        'edit' => [
            'title' => 'Attendance settings',
            'subheading' => 'Where the company is, and how close employees must be to check in or out.',
        ],
    ],
];
