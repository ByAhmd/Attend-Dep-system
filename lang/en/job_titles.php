<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Job titles',
        'model' => 'Job title',
        'plural_model' => 'Job titles',
    ],

    'sections' => [
        'details' => 'Title details',
    ],

    'fields' => [
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'holders' => 'Held by',
        'status' => 'Status',
        'created_at' => 'Added',
    ],

    'placeholders' => [
        'name_ar' => 'التسويق',
        'name_en' => 'Marketing',
    ],

    'helpers' => [
        'name' => "This title appears under the employee's name and each reader sees it in their own language, so both names are required.",
        'is_active' => 'A retired title stays on the people who hold it and stops being offered when a new employee is added.',
    ],

    'badges' => [
        'active' => 'Active',
        'retired' => 'Retired',
    ],

    'validation' => [
        'name_ar_unique' => 'A title with this Arabic name already exists.',
        'name_en_unique' => 'A title with this English name already exists.',
    ],

    'filters' => [
        'is_active' => 'Status',
        'any' => 'All titles',
        'active_only' => 'Active only',
        'retired_only' => 'Retired only',
    ],

    'actions' => [
        'toggle_retire' => 'Retire the title',
        'toggle_retire_heading' => 'Retire ":name"?',
        'toggle_retire_description' => 'It stays on the people who hold it now and disappears from the list when a new employee is added.',
        'toggle_activate' => 'Bring back',
        'toggle_activate_heading' => 'Bring ":name" back?',
        'toggle_activate_description' => 'The title returns to the list of titles that can be given.',
        'delete' => 'Delete',
        'delete_heading' => 'Delete ":name"?',
        'delete_description' => 'No account holds this title, so deleting it affects no record.',
        'delete_blocked_description' => '{1} One account holds this title, and it cannot be deleted while anyone does. To stop using it, retire it instead.|[2,*] :count accounts hold this title, and it cannot be deleted while anyone does. To stop using it, retire it instead.',
        'delete_confirm' => 'Delete the title',
        'show_holders' => 'Show who holds it',
    ],

    'notifications' => [
        'deleted' => '":name" has been deleted',
        'retired' => '":name" has been retired',
        'activated' => '":name" is available again',
        'delete_blocked' => '":name" could not be deleted',
        'delete_blocked_body' => 'The title was given to an account a moment ago. Reload the page to see who holds it.',
    ],

    'empty' => [
        'heading' => 'No job titles yet',
        'description' => 'Add the titles the company uses so they appear on the employee form.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'The titles an account can be given. A title describes a person; it grants nothing.',
        ],
    ],
];
