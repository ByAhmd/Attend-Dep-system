<?php

declare(strict_types=1);

return [
    'user_role' => [
        'admin' => 'Administrator',
        'employee' => 'Employee',
    ],

    'user_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'pending' => 'Awaiting invitation',
    ],

    'attendance_status' => [
        'checked_in' => 'Checked in',
        'checked_out' => 'Checked out',
        'missing_check_out' => 'Missing check-out',
    ],

    'attendance_action' => [
        'check_in' => 'Check-in',
        'check_out' => 'Check-out',
    ],

    'attendance_rejection_reason' => [
        'location_not_configured' => 'Company location not configured',
        'inactive_account' => 'Inactive account',
        'already_checked_in' => 'Already checked in',
        'not_checked_in' => 'Not checked in',
        'already_checked_out' => 'Already checked out',
        'insufficient_accuracy' => 'Insufficient location accuracy',
        'outside_allowed_area' => 'Outside the allowed area',
    ],
];
