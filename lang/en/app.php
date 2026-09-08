<?php

declare(strict_types=1);

return [
    'name' => 'Makani',
    'language' => 'Language',
    'switch_language' => 'Switch language to :language',

    /*
     | The user-menu entry that leads from one panel to the other. Each names
     | its destination rather than the act of switching, and "My attendance"
     | keeps the employee screen apart from "Attendance records" in the
     | administration sidebar.
     */
    'panels' => [
        'admin' => 'Dashboard',
        'employee' => 'My attendance',
    ],
];
