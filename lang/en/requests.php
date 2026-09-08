<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'My requests',
        'subheading' => 'What you asked for, and what became of it.',
    ],

    'tiles' => [
        'heading' => 'Quick actions',
        'correction' => 'Fix a record',
        'leave' => 'Request leave',
        'my_requests' => 'My requests',
        'attendance' => 'Attendance',
        'badge_pending' => ':count awaiting a decision',
        'badge_quota' => ':count left',
        'badge_no_quota' => 'No allowance this month',
        // Every tile carries its second line, including when nothing is
        // waiting: a figure that appears only when it is bad teaches a
        // reader to distrust its absence.
        'badge_no_pending' => 'Nothing awaiting a decision',
        'badge_corrections_off' => 'Correction requests are off',
        'caption_correction' => 'A check-in or check-out time you want fixed',
        'caption_leave' => 'A whole day, or leaving and coming back',
        'caption_attendance' => 'Back to the attendance screen',
    ],

    'fields' => [
        'status' => 'Status',
        'decision_note' => "The administrator's note",
    ],

    'placeholders' => [
        'no_note' => 'No note',
    ],

    'feedback' => [
        'too_many_attempts' => 'Too many attempts. Wait a moment and try again.',
    ],
];
