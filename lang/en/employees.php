<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Employees',
        'model' => 'Employee',
        'plural_model' => 'Employees',
    ],

    'sections' => [
        'details' => 'Employee details',
        'access' => 'Access',
        'password' => 'Password',
    ],

    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'new_password' => 'New password',
        'role' => 'Role',
        'status' => 'Status',
        'created_at' => 'Created',
    ],

    'placeholders' => [
        'name' => 'Full name as it should appear in attendance records',
        'email' => 'name@company.com',
    ],

    'helpers' => [
        'password' => 'At least 8 characters. Share it with the employee; only an administrator can reset it.',
        'status' => 'Inactive employees cannot sign in or record attendance. Their history is kept.',
        'role' => 'Administrators manage employees and settings; employees only check in and out.',
        'own_access' => 'You cannot change your own role or status.',
    ],

    'validation' => [
        'email_unique' => 'This email is already in use.',
    ],

    'filters' => [
        'role' => 'Role',
        'status' => 'Status',
    ],

    'actions' => [
        'reset_password' => 'Reset password',
        'reset_password_heading' => 'Reset password for :name',
        'reset_password_description' => 'The current password stops working immediately.',
        'activate' => 'Activate',
        'activate_heading' => 'Activate :name?',
        'deactivate' => 'Deactivate',
        'deactivate_heading' => 'Deactivate :name?',
        'deactivate_description' => 'They will be signed out and unable to check in until reactivated. Their attendance history is kept.',
    ],

    'notifications' => [
        'password_reset' => 'Password updated',
        'password_reset_body' => 'The new password for :name is in effect immediately.',
        'activated' => ':name is now active',
        'deactivated' => ':name is now inactive',
    ],

    'empty' => [
        'heading' => 'No employees yet',
        'description' => 'Add the first employee so they can check in.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Everyone who can sign in, and what they may do.',
        ],
    ],
];
