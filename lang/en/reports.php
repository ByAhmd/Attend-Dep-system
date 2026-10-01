<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'Monthly report',
    ],

    'title' => 'Monthly report',
    'subheading' => 'One row per employee: the month\'s attendance, folded into figures. The CSV holds the same figures and nothing else.',

    'fields' => [
        'month' => 'Month',
        'employee' => 'Employee',
        'job_title' => 'Job title',
        'days_attended' => 'Days attended',
        'time_inside' => 'Time inside',
        'late_days' => 'Late days',
        'total_lateness' => 'Total lateness',
        'early_check_outs' => 'Early check-outs',
        'leave_days' => 'Leave days',
        'days_unrecorded' => 'Days unrecorded',
    ],

    'working_days_elapsed' => 'Working days so far',

    'actions' => [
        'export' => 'Export CSV',
    ],

    'empty' => 'No employees on the roster.',

    'hints' => [
        'unrecorded' => '"Days unrecorded" counts working days already passed with no session and no approved leave. It states that nothing was recorded — not where anybody was.',
        'lateness' => 'A day is late when its first check-in came after the grace period; the lateness is measured from the start of the working day. Both follow the working hours currently in the settings.',
    ],
];
