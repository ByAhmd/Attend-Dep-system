<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Rejected attempts',
        'model' => 'Rejected attempt',
        'plural_model' => 'Rejected attempts',
    ],

    'fields' => [
        'employee' => 'Employee',
        'action' => 'Action',
        'reason' => 'Reason',
        'distance' => 'Distance',
        'accuracy' => 'Accuracy',
        'recorded_at' => 'Recorded at',
    ],

    'filters' => [
        'employee' => 'Employee',
        'reason' => 'Reason',
        'action' => 'Action',
        'date_range' => 'Date range',
        'from' => 'From',
        'until' => 'Until',
    ],

    'actions' => [
        'open_map' => 'Open in map',
    ],

    'empty' => [
        'heading' => 'No rejected attempts',
        'description' => 'Check-ins and check-outs refused because of location will be listed here.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Check-ins and check-outs refused because of the device location or its accuracy.',
        ],
    ],
];
