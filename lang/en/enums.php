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

    'employment_type' => [
        'employee' => 'Employee',
        'intern' => 'Intern',
    ],

    'request_status' => [
        'pending' => 'Under review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'correction_reason' => [
        'forgot_to_record' => 'Forgot to check in or out',
        'location_problem' => 'The location could not be determined',
        'application_problem' => 'A problem with the application',
        'connection_problem' => 'The internet connection dropped',
        'remote_work' => 'Working remotely',
        'external_visit' => 'A visit to an external site',
        'overtime_after_check_out' => 'Extra work after checking out',
    ],

    'leave_type' => [
        'annual' => 'Annual leave',
        'sick' => 'Sick leave',
        'exam' => 'Examination leave',
        'bereavement_immediate' => 'Bereavement — spouse, parent or child',
        'bereavement_sibling' => 'Bereavement — sibling',
        'unpaid' => 'Unpaid leave',
    ],
];
