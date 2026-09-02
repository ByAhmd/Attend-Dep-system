<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Attendance records',
        'model' => 'Attendance record',
        'plural_model' => 'Attendance records',
    ],

    // The employee screen.
    'page' => [
        'title' => 'Attendance',
        'greeting' => 'Hello, :name',
        'today' => 'Today, :date',
        'current_status' => 'Current status',
        'check_in_time' => 'Check-in',
        'check_out_time' => 'Check-out',
        'not_recorded' => '—',
        'status' => [
            'not_checked_in' => 'Not checked in',
            'checked_in' => 'Checked in',
            'checked_out' => 'Checked out',
        ],
        'radius_hint' => 'You must be within :radius meters of the company to check in or out.',
        'location_not_configured' => 'The company location has not been configured yet. Contact your administrator.',
        'location_hint' => 'Your phone will ask for permission to use your location. Precise location (GPS) gives the best result.',
    ],

    'actions' => [
        'check_in' => 'Check In',
        'check_out' => 'Check Out',
    ],

    'feedback' => [
        'locating' => 'Checking your location...',
        'verifying' => 'Verifying with the server...',
        'distance' => 'Your location is :distance meters from the company.',
        'check_in_success' => 'Check-in successful.',
        'check_out_success' => 'Check-out successful.',
        'permission_denied' => 'Location permission is required to check in/out.',
        'position_unavailable' => 'Your location could not be determined. Turn on GPS/precise location and try again.',
        'timeout' => 'Finding your location took too long. Move to an open area and try again.',
        'unsupported' => 'This browser does not support location services.',
        'insecure_context' => 'Location services need a secure (HTTPS) connection.',
        'too_many_attempts' => 'Too many attempts. Wait a moment and try again.',
        'failed' => 'Something went wrong. Please try again.',
    ],

    // One sentence per AttendanceRejectionReason case.
    'rejections' => [
        'location_not_configured' => 'The company location has not been configured yet. Contact your administrator.',
        'inactive_account' => 'Your account is inactive. Contact your administrator.',
        'already_checked_in' => 'You have already checked in today.',
        'not_checked_in' => 'You have not checked in today, so you cannot check out.',
        'already_checked_out' => 'You have already checked out today.',
        'insufficient_accuracy' => 'Your location accuracy (±:accuracy m) is not good enough. Enable precise location/GPS and try again.',
        'outside_allowed_area' => 'You are outside the allowed attendance area. You must be within :radius meters of the company (you are :distance meters away).',
    ],

    'validation' => [
        'latitude' => 'A valid latitude is required.',
        'longitude' => 'A valid longitude is required.',
        'accuracy' => 'A valid location accuracy is required.',
    ],

    'history' => [
        'heading' => 'Attendance history',
        'empty_heading' => 'No attendance yet',
        'empty_description' => 'Your check-ins and check-outs will appear here.',
    ],

    'fields' => [
        'employee' => 'Employee',
        'date' => 'Date',
        'check_in_at' => 'Check-in',
        'check_out_at' => 'Check-out',
        'check_in_distance' => 'Check-in distance',
        'check_out_distance' => 'Check-out distance',
        'check_in_accuracy' => 'Check-in accuracy',
        'check_out_accuracy' => 'Check-out accuracy',
        'check_in_location' => 'Check-in location',
        'check_out_location' => 'Check-out location',
        'status' => 'Status',
    ],

    'sections' => [
        'check_in' => 'Check-in',
        'check_out' => 'Check-out',
    ],

    'units' => [
        'meters' => ':value m',
        'accuracy' => '±:value m',
    ],

    'placeholders' => [
        'no_check_out' => 'Not checked out',
    ],

    'filters' => [
        'employee' => 'Employee',
        'date' => 'Date',
        'date_range' => 'Date range',
        'from' => 'From',
        'until' => 'Until',
    ],

    'admin_actions' => [
        'view' => 'View',
        'open_map' => 'Open in map',
    ],

    'empty' => [
        'heading' => 'No attendance records',
        'description' => 'Records appear as soon as employees start checking in.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'Every check-in and check-out, with the location it was recorded from.',
        ],
    ],
];
