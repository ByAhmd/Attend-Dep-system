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
        'pending' => 'بانتظار التفعيل',
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

    'employment_type' => [
        'employee' => 'موظف',
        'intern' => 'متدرّب',
    ],

    'request_status' => [
        'pending' => 'قيد المراجعة',
        'approved' => 'مقبول',
        'rejected' => 'مرفوض',
    ],

    'correction_reason' => [
        'forgot_to_record' => 'نسيان تسجيل الحضور أو الانصراف',
        'location_problem' => 'تعذّر تحديد الموقع',
        'application_problem' => 'مشكلة في التطبيق',
        'connection_problem' => 'انقطاع الاتصال بالإنترنت',
        'remote_work' => 'عمل عن بُعد',
        'external_visit' => 'زيارة موقع خارجي',
        'overtime_after_check_out' => 'عمل إضافي بعد تسجيل الانصراف',
    ],

    'leave_type' => [
        'annual' => 'إجازة سنوية',
        'sick' => 'إجازة مرضية',
        'exam' => 'إجازة اختبارات',
        'bereavement_immediate' => 'وفاة زوج أو أحد الأصول أو الفروع',
        'bereavement_sibling' => 'وفاة أخ أو أخت',
        'unpaid' => 'إجازة بدون راتب',
    ],
];
