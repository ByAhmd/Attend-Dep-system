<?php

declare(strict_types=1);

return [
    'navigation' => [
        'label' => 'سجلات الحضور',
        'model' => 'سجل حضور',
        'plural_model' => 'سجلات الحضور',
    ],

    // شاشة الموظف.
    'page' => [
        'title' => 'الحضور',
        'greeting' => 'مرحبًا، :name',
        'today' => 'اليوم، :date',
        'current_status' => 'الحالة الحالية',
        'not_recorded' => '—',
        'status' => [
            'not_checked_in' => 'لم يتم تسجيل الحضور',
            'checked_in' => 'تم تسجيل الحضور',
            'checked_out' => 'تم تسجيل الانصراف',
        ],
        'sessions_heading' => 'جلسات اليوم',
        'sessions_empty' => 'لم تسجّل حضورك اليوم بعد.',
        'session_number' => 'الجلسة :number',
        'sessions_count' => 'عدد جلسات اليوم',
        'total_inside' => 'وقت التواجد اليوم',
        'sessions_hint' => 'يمكنك تسجيل الانصراف ثم الحضور مرة أخرى كلما احتاج يومك ذلك.',
        'radius_hint' => 'يجب أن تكون ضمن :radius مترًا من موقع الشركة لتسجيل الحضور أو الانصراف.',
        'location_not_configured' => 'لم يتم ضبط موقع الشركة بعد. تواصل مع مسؤول النظام.',
        'location_hint' => 'سيطلب هاتفك الإذن باستخدام موقعك. تفعيل الموقع الدقيق (GPS) يعطي أفضل نتيجة.',
    ],

    'actions' => [
        'check_in' => 'تسجيل الحضور',
        'check_out' => 'تسجيل الانصراف',
    ],

    'feedback' => [
        'locating' => 'جارٍ التحقق من موقعك...',
        'verifying' => 'جارٍ التحقق مع الخادم...',
        'distance' => 'موقعك يبعد :distance مترًا عن الشركة.',
        'check_in_success' => 'تم تسجيل الحضور بنجاح.',
        'check_out_success' => 'تم تسجيل الانصراف بنجاح.',
        'permission_denied' => 'إذن الوصول إلى الموقع مطلوب لتسجيل الحضور أو الانصراف.',
        'position_unavailable' => 'تعذّر تحديد موقعك. فعّل GPS/الموقع الدقيق وحاول مرة أخرى.',
        'timeout' => 'استغرق تحديد موقعك وقتًا طويلًا. انتقل إلى مكان مكشوف وحاول مرة أخرى.',
        'unsupported' => 'هذا المتصفح لا يدعم خدمات الموقع.',
        'insecure_context' => 'خدمات الموقع تتطلب اتصالًا آمنًا (HTTPS).',
        'too_many_attempts' => 'محاولات كثيرة. انتظر قليلًا ثم حاول مرة أخرى.',
        'failed' => 'حدث خطأ ما. يرجى المحاولة مرة أخرى.',
    ],

    // جملة واحدة لكل حالة في AttendanceRejectionReason.
    'rejections' => [
        'location_not_configured' => 'لم يتم ضبط موقع الشركة بعد. تواصل مع مسؤول النظام.',
        'inactive_account' => 'حسابك غير نشط. تواصل مع مسؤول النظام.',
        'already_checked_in' => 'أنت مسجّل الحضور بالفعل. سجّل الانصراف أولًا إذا كنت ستغادر.',
        'not_checked_in' => 'لم تسجّل حضورك اليوم، لذا لا يمكنك تسجيل الانصراف.',
        'already_checked_out' => 'لقد سجّلت انصرافك بالفعل. سجّل الحضور مرة أخرى عند عودتك.',
        'insufficient_accuracy' => 'دقة موقعك (±:accuracy م) غير كافية. فعّل الموقع الدقيق/GPS وحاول مرة أخرى.',
        'outside_allowed_area' => 'أنت خارج نطاق الحضور المسموح. يجب أن تكون ضمن :radius مترًا من الشركة (أنت على بعد :distance مترًا).',
    ],

    'validation' => [
        'latitude' => 'يلزم إدخال خط عرض صالح.',
        'longitude' => 'يلزم إدخال خط طول صالح.',
        'accuracy' => 'يلزم إدخال دقة موقع صالحة.',
    ],

    'history' => [
        'heading' => 'سجل الحضور',
        'empty_heading' => 'لا يوجد حضور بعد',
        'empty_description' => 'ستظهر هنا عمليات الحضور والانصراف الخاصة بك.',
    ],

    'fields' => [
        'employee' => 'الموظف',
        'date' => 'التاريخ',
        'check_in_at' => 'الحضور',
        'check_out_at' => 'الانصراف',
        'check_in_distance' => 'مسافة الحضور',
        'check_out_distance' => 'مسافة الانصراف',
        'check_in_accuracy' => 'دقة موقع الحضور',
        'check_out_accuracy' => 'دقة موقع الانصراف',
        'check_in_location' => 'موقع الحضور',
        'check_out_location' => 'موقع الانصراف',
        'status' => 'الحالة',
        'duration' => 'المدة',
    ],

    'sections' => [
        'check_in' => 'الحضور',
        'check_out' => 'الانصراف',
    ],

    'units' => [
        'meters' => ':value م',
        'accuracy' => '±:value م',
        'duration' => ':hours س :minutes د',
        'duration_minutes' => ':minutes د',
    ],

    'placeholders' => [
        'no_check_out' => 'لم يسجّل الانصراف',
    ],

    'filters' => [
        'employee' => 'الموظف',
        'date' => 'التاريخ',
        'date_range' => 'الفترة',
        'from' => 'من',
        'until' => 'إلى',
    ],

    'summaries' => [
        'total_inside' => 'إجمالي وقت التواجد',
    ],

    'groups' => [
        'date' => 'اليوم',
    ],

    'admin_actions' => [
        'view' => 'عرض',
        'open_map' => 'فتح في الخريطة',
    ],

    'empty' => [
        'heading' => 'لا توجد سجلات حضور',
        'description' => 'ستظهر السجلات بمجرد أن يبدأ الموظفون بتسجيل الحضور.',
    ],

    'pages' => [
        'list' => [
            'subheading' => 'سطر لكل جلسة، مع الموقع الذي سُجّل منه كل حضور وانصراف.',
        ],
    ],
];
