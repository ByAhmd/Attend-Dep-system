<?php

declare(strict_types=1);

return [
    'user_role' => [
        'admin' => 'مسؤول',
        'employee' => 'موظف',
    ],

    'user_status' => [
        'active' => 'نشط',
        'inactive' => 'غير نشط',
    ],

    'attendance_status' => [
        'checked_in' => 'مسجَّل الحضور',
        'checked_out' => 'مسجَّل الانصراف',
        'missing_check_out' => 'بدون انصراف',
    ],

    'attendance_action' => [
        'check_in' => 'تسجيل حضور',
        'check_out' => 'تسجيل انصراف',
    ],

    'attendance_rejection_reason' => [
        'location_not_configured' => 'موقع الشركة غير مضبوط',
        'inactive_account' => 'حساب غير نشط',
        'already_checked_in' => 'تم تسجيل الحضور مسبقًا',
        'not_checked_in' => 'لم يتم تسجيل الحضور',
        'already_checked_out' => 'تم تسجيل الانصراف مسبقًا',
        'insufficient_accuracy' => 'دقة الموقع غير كافية',
        'outside_allowed_area' => 'خارج النطاق المسموح',
    ],
];
