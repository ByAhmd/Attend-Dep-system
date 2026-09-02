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
    ],

    'fields' => [
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'radius_meters' => 'Allowed radius (meters)',
        'radius_suffix' => 'm',
    ],

    'helpers' => [
        'coordinates' => 'Open Google Maps, press and hold on the company entrance, then copy the two numbers shown (for example 24.7136, 46.6753).',
        'radius' => 'Default 150 meters. Employees must be within this distance of the coordinates above to check in or out.',
        'not_configured' => 'Attendance cannot be recorded until the company location is set.',
        'preview' => 'Open the configured location in a map',
    ],

    'validation' => [
        'latitude' => 'Latitude must be a number between -90 and 90.',
        'longitude' => 'Longitude must be a number between -180 and 180.',
        'radius' => 'The radius must be a whole number between :min and :max meters.',
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
