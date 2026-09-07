<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Presence pings',
        'model' => 'Presence ping',
        'plural_model' => 'Presence pings',
    ],

    'fields' => [
        'employee' => 'Employee',
        'recorded_at' => 'Recorded at',
        'distance' => 'Distance',
        'accuracy' => 'Accuracy',
        'position' => 'Position',
    ],

    'values' => [
        'inside' => 'Inside the area',
        'outside' => 'Outside the area',
    ],

    'filters' => [
        'employee' => 'Employee',
        'position' => 'Position',
        'inside' => 'Inside the area',
        'outside' => 'Outside the area',
        'date_range' => 'Date range',
        'from' => 'From',
        'until' => 'Until',
    ],

    'actions' => [
        'open_map' => 'Open in map',
    ],

    /*
     | Said on the employee's own screen, because the page samples their
     | position while a session is open and they are entitled to know it.
     */
    'employee' => [
        'hint' => 'While you are checked in and this page is open, your location is recorded every few minutes.',
    ],

    'empty' => [
        'heading' => 'No presence pings',
        'description' => 'A position is recorded here every few minutes while an employee is checked in and keeps the attendance page open.',
    ],

    'pages' => [
        'list' => [
            /*
             | The honest limit of the feature, kept where it is read: a
             | browser reports a position only while its page is open and
             | the device is awake, so pings present are evidence and pings
             | absent are not.
             */
            'subheading' => 'Positions reported by an employee’s phone while they are checked in and the attendance page is open. These are supporting evidence only: a gap is not absence — the page may have been closed, the screen asleep or the signal lost.',
        ],
    ],
];
