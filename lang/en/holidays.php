<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Official holidays',
        'model' => 'Holiday',
        'plural_model' => 'Official holidays',
    ],

    'fields' => [
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'starts_on' => 'First day',
        'ends_on' => 'Last day',
        'days' => 'Days',
    ],

    'helpers' => [
        'single_day' => 'For a one-day holiday, enter the same date in both fields.',
    ],

    'validation' => [
        'name_required' => 'The holiday needs its name in both languages.',
        'name_length' => 'A holiday name may be at most 100 characters.',
        'date_required' => 'Both ends of the holiday are required.',
        'range_ordered' => 'The last day must be on or after the first.',
    ],

    'actions' => [
        'edit' => 'Edit',
        'delete' => 'Delete',
    ],

    'empty' => [
        'heading' => 'No holidays entered',
        'description' => 'On the days entered here, nobody is reported as not having checked in.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Days nobody is expected at the company. A holiday never blocks a check-in; it only keeps these days out of the absence list and the monthly report.',
        ],
    ],
];
