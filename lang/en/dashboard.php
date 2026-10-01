<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'subheading' => 'Today at a glance: :date, Riyadh time.',

    'stats' => [
        'employees_total' => 'Employees',
        'employees_total_hint' => 'All employee accounts',
        'employees_active' => 'Active employees',
        'employees_active_hint' => 'Can sign in and check in',
        'checked_in_today' => 'Checked in today',
        'checked_in_today_hint' => 'Employees who recorded a session today',
        'checked_out_today' => 'Checked out today',
        'checked_out_today_hint' => 'Employees who completed a session today',
        'pending_corrections' => 'Corrections awaiting a decision',
        'pending_corrections_hint' => 'Requests nobody has decided yet',
        'pending_leave' => 'Leave awaiting a decision',
        'pending_leave_hint' => 'Requests nobody has decided yet',
        'currently_checked_in' => 'Currently checked in',
        'currently_checked_in_hint' => 'Checked in and not yet out',
    ],

    /*
     | The not-checked-in list. "Have not checked in" and never "absent":
     | the absence of a record is the whole of what the system can prove.
     | On a day nobody was expected the empty state names the day, so an
     | empty list never means two different things.
     */
    'absent' => [
        'heading' => 'Have not checked in today',
        'description' => 'Active employees with no session today. Approved leave is not listed; an approved exit and return does not excuse the day.',
        'job_title' => 'Job title',
        'empty_heading' => 'Everyone expected today has checked in',
        'empty_description' => 'Every active employee has a session today or is on approved leave.',
        'non_working_heading' => 'Nobody is expected today — :day',
        'non_working_description' => 'Today is outside the working week, so nobody is reported as not having checked in.',
    ],

    /*
     | The late-arrivals list. Its description states the arithmetic out
     | loud - marked after :threshold, measured from :start - because a
     | reader comparing "arrived 09:45" with "late 45 m" must find the rule
     | written down rather than have to reverse-engineer it.
     */
    'late' => [
        'heading' => 'Late arrivals today',
        'description' => 'The first check-in of the day, when it came after :threshold. Lateness is measured from the start of the working day at :start.',
        'first_check_in' => 'First check-in',
        'lateness' => 'Late by',
        'empty_heading' => 'Nobody arrived late today',
        'empty_description' => 'Everyone who checked in today did so on time.',
    ],

    'shortcuts' => [
        'employees' => 'Employees',
        'attendance' => 'Attendance records',
        'settings' => 'Settings',
    ],
];
